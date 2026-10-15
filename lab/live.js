/* Pestaña «En vivo»: lee el feed real a través de api.php, enseña el algoritmo de la app frente a las fuentes oficiales
   (horario publicado y retraso que da el feed) y mide quién acierta cuando los buses pasan por sus paradas. */
(function () {
'use strict';
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const fmt = (n, d = 0) => { if (n === null || n === undefined || !isFinite(n)) return '—'; const s = Number(n).toFixed(d), [a, b] = s.split('.'), g = a.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); return b ? g + ',' + b : g; };
const hms = s => { s = Math.round(s); const h = Math.floor(s / 3600) % 24, m = Math.floor(s / 60) % 60; return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
const sgnMin = s => (s > 0 ? '+' : s < 0 ? '−' : '') + fmt(Math.abs(s) / 60, 1) + ' min';
const METODOS = [['horario', 'Horario oficial publicado', '--ink-3'], ['oficial', 'Retraso que da el feed oficial', '--s2'], ['bideplus', 'Bide+ (nuestro algoritmo)', '--s3']];
const COLS = [['todo', 'Todo (hasta 20 min)'], ['0-5 min', 'Faltan < 5 min'], ['5-10 min', 'Faltan 5–10 min'], ['10-20 min', 'Faltan 10–20 min']];

window.vistaVivo = function (view, onLeave) {
  view.innerHTML = `<div class="eyebrow">Fuentes oficiales contra Bide+</div><h1>En vivo</h1>
  <p class="lede">Lee ahora mismo el feed de Open Data Bizkaia y calcula, para cada bus, cuándo llegará a sus próximas paradas de tres formas: lo que dice el <b>horario oficial</b>, lo que dice el <b>retraso oficial del feed</b> (el campo Delay) y lo que calcula <b>Bide+</b> con el algoritmo real de la app (GPS, parada registrada y tiempos aprendidos). Si lo dejas grabando, cada vez que un bus pasa por la parada se mide cuánto se equivocó cada uno.</p>
  <div class="card" style="margin-top:18px"><div class="controls"><button class="b p" id="v-rec" type="button">● Grabar</button><button class="b" id="v-now" type="button">Leer ahora</button><button class="b" id="v-reset" type="button">Borrar lo grabado</button><span class="hint" id="v-state" style="margin:0">Parado. Pulsa «Grabar» y déjalo 10–20 minutos con la pestaña abierta.</span></div></div>
  <div class="grid"><div class="card c12"><h2>Quién acierta</h2><p id="v-vhint"></p><div class="scroll" id="v-ver"></div><div id="v-bars"></div><div class="say" id="v-say"></div></div>
  <div class="card c12"><h2>Buses ahora mismo</h2><p>Para la próxima parada de cada bus. «Retraso oficial» es el que da el feed; «retraso Bide+» es el que calcula la app. Ordenados por el desvío de Bide+.</p>
  <div class="controls"><label class="grow">Buscar línea o bus <input type="search" id="v-q" placeholder="3411, 1101…"></label></div><div class="cards" id="v-cards"></div><div class="scroll" id="v-tab"></div></div></div>`;
  const $ = id => view.querySelector('#' + id); let timer = null, data = null, busy = false, alive = true;
  onLeave(() => { alive = false; clearInterval(timer); });

  const cell = (g, m) => g && g[m];
  function renderVer(v) {
    const G = v.grupos || {}, tiene = v.pares > 0;
    $('v-vhint').textContent = tiene ? `${fmt(v.pares)} predicciones comparadas con el paso real del bus por la parada (el paso se conoce con ±30 s, que es la resolución del feed). Menos error es mejor; «dentro de ±2 min» es el porcentaje de llegadas que acertaron con 2 minutos o menos de diferencia.` : 'Todavía no hay pasos medidos. Hace falta que un bus pase por una parada después de haber sido leído: con unos minutos grabando salen las primeras cifras.';
    if (!tiene) { $('v-ver').innerHTML = ''; $('v-bars').innerHTML = ''; $('v-say').textContent = ''; return; }
    const th = COLS.map(([k, t]) => `<th colspan="2">${t}${G[k] ? ' · n=' + fmt(G[k].horario ? G[k].horario.n : 0) : ''}</th>`).join('');
    const sub = COLS.map(() => '<th>error mediano</th><th>±2 min</th>').join('');
    const rows = METODOS.map(([m, t, c]) => `<tr><td><i style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(${c});margin-right:8px"></i>${t}</td>${COLS.map(([k]) => { const x = cell(G[k], m); return x ? `<td>${fmt(x.mediana / 60, 1)} min</td><td><b>${fmt(x.dentro2, 0)} %</b></td>` : '<td>—</td><td>—</td>'; }).join('')}</tr>`).join('');
    $('v-ver').innerHTML = `<table><thead><tr><th rowspan="2">Fuente</th>${th}</tr><tr>${sub}</tr></thead><tbody>${rows}</tbody></table>`;
    const all = G.todo; if (!all) return;
    $('v-bars').innerHTML = `<div class="chart" style="margin-top:14px">${METODOS.map(([m, t, c]) => { const x = all[m]; return x ? `<div style="display:flex;align-items:center;gap:10px;margin:6px 0"><span style="width:230px;font-size:13px;color:var(--ink-2)">${t}</span><div style="flex:1;background:var(--paper);border-radius:6px;height:22px;position:relative"><div style="width:${x.dentro2}%;height:100%;border-radius:6px;background:var(${c})"></div></div><b style="width:52px;text-align:right;font-variant-numeric:tabular-nums">${fmt(x.dentro2, 0)} %</b></div>` : ''; }).join('')}<div class="hint">Llegadas acertadas con ±2 min (todo el rango de 0 a 20 min).</div></div>`;
    const a = all.horario, b = all.bideplus, o = all.oficial;
    $('v-say').innerHTML = a && b ? `Con el horario oficial el bus llega a la hora dicha ±2 min el <em>${fmt(a.dentro2)} %</em> de las veces. ${o ? `El retraso del feed <em>${fmt(o.dentro2)} %</em> (${o.dentro2 === a.dentro2 ? 'idéntico: el feed no aporta retraso' : 'distinto del horario'}). ` : ''}Bide+ acierta el <em>${fmt(b.dentro2)} %</em>, con un error mediano de ${fmt(b.mediana / 60, 1)} min frente a ${fmt(a.mediana / 60, 1)} min del horario.` : '';
  }
  function renderBuses() {
    if (!data) return; const q = $('v-q').value.trim().toLowerCase(), B = data.buses;
    const off0 = B.filter(b => b.retrasoFeed !== 0).length, big = B.filter(b => b.paradas[0] && Math.abs(b.paradas[0].retrasoApp) > 120).length, gps = B.filter(b => b.paradas[0] && b.paradas[0].capa === 'GPS').length;
    $('v-cards').innerHTML = [[fmt(B.length), 'buses en servicio'], [fmt(off0), 'con retraso distinto de 0 en el feed oficial'], [fmt(big), 'con más de 2 min de desvío según Bide+'], [fmt(gps), 'localizados por GPS']].map(([a, b]) => `<div><b>${a}</b><span>${b}</span></div>`).join('');
    const rows = B.filter(b => !q || b.linea.toLowerCase().includes(q) || String(b.bus).toLowerCase().includes(q)).filter(b => b.paradas[0]).sort((a, b) => Math.abs(b.paradas[0].retrasoApp) - Math.abs(a.paradas[0].retrasoApp)).slice(0, 80).map(b => { const p = b.paradas[0], r = p.retrasoApp;
      return `<tr><td>${esc(b.linea)}</td><td>${esc(b.bus)}</td><td style="text-align:left;white-space:normal">${esc(p.parada)}</td><td>${hms(p.horario)}</td><td>${sgnMin(b.retrasoFeed)}</td><td><b>${r === null ? '—' : sgnMin(r)}</b></td><td>${hms(p.bideplus)}</td><td><span class="tag${p.capa === 'horario' ? ' w' : ''}">${esc(p.capa)}</span></td></tr>`; }).join('');
    $('v-tab').innerHTML = `<table><thead><tr><th>Línea</th><th>Bus</th><th>Próxima parada</th><th>Horario oficial</th><th>Retraso oficial</th><th>Retraso Bide+</th><th>Llegada Bide+</th><th>Capa</th></tr></thead><tbody>${rows}</tbody></table>`;
  }
  async function poll(log = true) {
    if (busy || !alive) return; busy = true;
    try {
      const r = await fetch('api.php?log=' + (log ? 1 : 0)), j = await r.json(); if (!r.ok || j.error) throw new Error(j.error || r.status);
      data = j; renderVer(j.verificacion); renderBuses(); $('v-state').textContent = (timer ? 'Grabando · ' : '') + `última lectura del feed ${hms(j.t)} · ${fmt(j.buses.length)} buses`;
    } catch (e) { $('v-state').textContent = 'No se pudo leer: ' + e.message + ' (¿estás abriendo la página con php -S localhost:8090 desde la raíz del repo?)'; }
    busy = false;
  }
  $('v-rec').addEventListener('click', e => { if (timer) { clearInterval(timer); timer = null; e.target.textContent = '● Grabar'; $('v-state').textContent = 'Parado.'; } else { e.target.textContent = '■ Parar'; timer = setInterval(() => poll(true), 60000); poll(true); } });
  $('v-now').addEventListener('click', () => poll(false));
  $('v-reset').addEventListener('click', async () => { await fetch('api.php?action=reset'); poll(false); });
  $('v-q').addEventListener('input', renderBuses);
  fetch('api.php?action=verify').then(r => r.json()).then(renderVer).catch(() => {}); poll(false);
};
})();
