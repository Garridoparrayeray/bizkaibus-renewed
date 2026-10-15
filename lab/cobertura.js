/* Pestaña «Cobertura»: de los viajes que el horario dice que están en marcha, cuántos tienen datos en vivo en el feed. */
(function () {
'use strict';
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const fmt = (n, d = 0) => { if (n === null || n === undefined || !isFinite(n)) return '—'; const s = Number(n).toFixed(d), [a, b] = s.split('.'), g = a.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); return b ? g + ',' + b : g; };
const pct = (a, b) => b ? 100 * a / b : NaN;
const hm = s => String(Math.floor(s / 3600) % 24).padStart(2, '0') + ':' + String(Math.floor(s / 60) % 60).padStart(2, '0');

window.vistaCobertura = function (view, onLeave) {
  view.innerHTML = `<div class="eyebrow">¿Se ve cada bus del horario?</div><h1>Cobertura</h1>
  <p class="lede">El horario oficial dice qué viajes deberían estar circulando ahora. El feed en vivo dice cuáles tienen un aparato que da su posición. Un viaje que no aparece en el feed sale en la app con «Solo horario»: se ve en la lista, pero sin retraso ni adelanto. Aquí se cuenta cuántos son, por línea.</p>
  <div class="card" style="margin-top:18px"><div class="controls"><button class="b p" id="c-rec" type="button">● Grabar</button><button class="b" id="c-now" type="button">Leer ahora</button><button class="b" id="c-reset" type="button">Borrar lo grabado</button><span class="hint" id="c-state" style="margin:0">Pulsa «Grabar» y déjalo horas, mejor varios días y a distintas horas, para ver qué líneas fallan siempre.</span></div></div>
  <div class="cards" id="c-cards" style="margin-top:14px"></div>
  <div class="grid"><div class="card c12"><h2>Por línea</h2><p>Ordenadas de menos a más cobertura. «Acumulado» suma todas las lecturas grabadas; cuantas más, más fiable. Los viajes sin dato se cuentan solo si el horario dice que ya han salido y siguen en ruta.</p>
  <div class="controls"><label class="grow">Buscar línea <input type="search" id="c-q" placeholder="A3129, Mungia…"></label><label><input type="checkbox" id="c-only"> solo líneas con algún viaje sin dato</label></div><div class="scroll" id="c-tab"></div>
  <p class="say" id="c-say"></p></div></div>`;
  const $ = id => view.querySelector('#' + id); let timer = null, data = null, busy = false, alive = true;
  onLeave(() => { alive = false; clearInterval(timer); });
  function render() {
    if (!data) return; const A = data.ahora, L = data.lineas, q = $('c-q').value.trim().toLowerCase(), only = $('c-only').checked;
    $('c-cards').innerHTML = [[fmt(A.esperados), 'viajes que deberían estar en ruta'], [fmt(pct(A.conDato, A.esperados), 0) + ' %', 'con datos en vivo (GPS: ' + fmt(pct(A.gps, A.esperados), 0) + ' %)'], [fmt(A.esperados - A.conDato), 'sin ningún dato en vivo'], [fmt(L.filter(l => l.conDato === 0).length) + ' de ' + fmt(L.length), 'líneas sin ningún viaje con datos ahora'], [fmt(data.lecturas), 'lecturas grabadas · ' + fmt(data.dias) + ' día(s)']].map(([a, b]) => `<div><b>${a}</b><span>${b}</span></div>`).join('');
    const rows = L.filter(l => (!q || (l.codigo + ' ' + l.nombre).toLowerCase().includes(q)) && (!only || l.conDato < l.esperados)).sort((a, b) => (a.acumulado ? pct(a.acumulado.conDato, a.acumulado.esperados) : pct(a.conDato, a.esperados)) - (b.acumulado ? pct(b.acumulado.conDato, b.acumulado.esperados) : pct(b.conDato, b.esperados)) || b.esperados - a.esperados).slice(0, 150).map(l => {
      const ac = l.acumulado, p = pct(l.conDato, l.esperados), pa = ac ? pct(ac.conDato, ac.esperados) : NaN;
      const bar = v => isFinite(v) ? `<span style="display:inline-block;width:70px;height:8px;background:var(--paper);border-radius:4px;vertical-align:middle;margin-right:8px"><span style="display:block;height:100%;width:${v}%;background:var(--${v < 50 ? 's2' : 's3'});border-radius:4px"></span></span>${fmt(v, 0)} %` : '—';
      return `<tr><td>${esc(l.codigo)}</td><td style="text-align:left;white-space:normal">${esc(l.nombre)}</td><td>${l.conDato} de ${l.esperados}</td><td>${bar(p)}</td><td>${ac ? bar(pa) + ' <small style="color:var(--ink-3)">(' + fmt(ac.conDato) + '/' + fmt(ac.esperados) + ')</small>' : '—'}</td><td>${l.otraSalida || ''}</td></tr>`; }).join('');
    $('c-tab').innerHTML = `<table><thead><tr><th>Línea</th><th style="text-align:left">Nombre</th><th>Ahora</th><th>Cobertura ahora</th><th>Acumulado</th><th title="Viajes sin dato para los que el feed trae el mismo número de viaje pero con otra hora de salida">Otra salida</th></tr></thead><tbody>${rows}</tbody></table>`;
    const cause = A.esperados - A.conDato;
    $('c-say').innerHTML = `A las ${hm(A.t)} faltaban <em>${fmt(cause)}</em> viajes. En <em>${fmt(A.otraSalida)}</em> de ellos el feed sí trae un viaje con el mismo número pero otra hora de salida (el bus quizá va con otro viaje, o aún no ha cambiado de servicio); en los otros <em>${fmt(cause - A.otraSalida)}</em> no hay nada. Este análisis no distingue entre un bus sin aparato, uno apagado y uno que no ha empezado: solo dice que la app no tiene datos en vivo de él.`;
  }
  async function poll(log = true) {
    if (busy || !alive) return; busy = true;
    try { const r = await fetch('api.php?action=coverage&log=' + (log ? 1 : 0)), j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || r.status); data = j; render(); $('c-state').textContent = (timer ? 'Grabando · ' : '') + 'última lectura del feed ' + hm(j.ahora.t); }
    catch (e) { $('c-state').textContent = 'No se pudo leer: ' + e.message; } busy = false;
  }
  $('c-rec').addEventListener('click', e => { if (timer) { clearInterval(timer); timer = null; e.target.textContent = '● Grabar'; } else { e.target.textContent = '■ Parar'; timer = setInterval(() => poll(true), 5 * 60000); poll(true); } });
  $('c-now').addEventListener('click', () => poll(false));
  $('c-reset').addEventListener('click', async () => { await fetch('api.php?action=coverage&op=reset'); poll(false); });
  $('c-q').addEventListener('input', render); $('c-only').addEventListener('change', render);
  poll(false);
};
})();
