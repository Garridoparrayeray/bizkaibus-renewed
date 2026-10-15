/* Laboratorio de Bide+: vistas de resumen, líneas, buses y simulador, sobre los viajes guardados de la captura horaria. */
(function () {
'use strict';
const L = window.LAB, view = document.getElementById('view'), tipEl = document.getElementById('tip'), NS = 'http://www.w3.org/2000/svg';

/* ---------- utilidades ---------- */
const fmt = (n, d = 0) => { if (!isFinite(n)) return '—'; const s = Number(n).toFixed(d), [a, b] = s.split('.'), g = a.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); return b ? g + ',' + b : g; };
const MES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const dia = iso => { const [, m, d] = iso.split('-'); return Number(d) + ' ' + MES[Number(m) - 1]; };
const hhmm = min => String(Math.floor(min / 60) % 24).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0');
const BAND = { madrugada: 'Madrugada', punta_manana: 'Punta de mañana', manana: 'Mañana', mediodia: 'Mediodía', punta_tarde: 'Punta de tarde', noche: 'Noche' };
const bandName = b => { const [t, n] = b.split('.'); return (t === 'fin_semana' ? 'Fin de semana · ' : 'Laborable · ') + (BAND[n] || n); };
const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function dec(b64, T) { const bin = atob(b64), u = new Uint8Array(bin.length); for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i); return new T(u.buffer); }
const lowMedian = a => { if (!a.length) return NaN; const b = Float64Array.from(a).sort(); return b[Math.floor(0.5 * (b.length - 1))]; };   // como percentile(0,5) del recalibrado
function corr(x, y) { const n = x.length, mx = x.reduce((a, b) => a + b, 0) / n, my = y.reduce((a, b) => a + b, 0) / n; let sxy = 0, sx = 0, sy = 0; for (let i = 0; i < n; i++) { sxy += (x[i] - mx) * (y[i] - my); sx += (x[i] - mx) ** 2; sy += (y[i] - my) ** 2; } return sxy / Math.sqrt(sx * sy); }
const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
function el(tag, attrs, parent, text) { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); if (text != null) e.textContent = text; if (parent) parent.appendChild(e); return e; }
function mk(host, h) { host.textContent = ''; const w = Math.max(260, Math.floor(host.clientWidth)); const s = el('svg', { width: w, height: h, viewBox: `0 0 ${w} ${h}`, role: 'img' }, host); return { s, w, h }; }
function tip(host, html, x, y) { const r = host.getBoundingClientRect(); tipEl.innerHTML = html; tipEl.classList.add('on'); const w = tipEl.offsetWidth; let lx = r.left + x + 12; if (lx + w > innerWidth - 8) lx = r.left + x - w - 12; tipEl.style.left = Math.max(4, lx) + 'px'; tipEl.style.top = Math.max(4, r.top + y - 10) + 'px'; }
const untip = () => tipEl.classList.remove('on');
function onResize(host, fn) { let w = 0; const ro = new ResizeObserver(() => { const nw = Math.round(host.clientWidth); if (nw && nw !== w) { w = nw; fn(); } }); ro.observe(host); cleanups.push(() => ro.disconnect()); }
let cleanups = [];
function player(btn, stepFn) { let t = null; const stop = () => { clearInterval(t); t = null; btn.textContent = '▶ Reproducir'; }; btn.addEventListener('click', () => { if (t) { stop(); return; } btn.textContent = '❚❚ Pausar'; stepFn(true); t = setInterval(() => { if (!stepFn(false)) stop(); }, 600); }); cleanups.push(stop); }

/* ---------- datos ---------- */
const N = L.n, ND = L.dias.length, D = { dia: dec(L.dia, Uint8Array), linea: dec(L.linea, Uint8Array), bus: dec(L.bus, Uint16Array), franja: dec(L.franja, Uint8Array), hora: dec(L.hora, Uint16Array), razon: dec(L.razon, Uint16Array), muestras: dec(L.muestras, Uint8Array) };
const R = i => D.razon[i] / 1000, HALF = Math.floor(ND / 2);
const byLine = L.lineas.map(() => []), byBus = L.vehiculos.map(() => []), cell = new Map();
for (let i = 0; i < N; i++) { byLine[D.linea[i]].push(i); byBus[D.bus[i]].push(i); const k = D.linea[i] * 32 + D.franja[i]; (cell.get(k) || cell.set(k, []).get(k)).push(i); }
const cellMed = new Map(); for (const [k, a] of cell) if (a.length >= 8) cellMed.set(k, lowMedian(a.map(R)));
const rel = new Float64Array(N).fill(NaN); for (let i = 0; i < N; i++) { const m = cellMed.get(D.linea[i] * 32 + D.franja[i]); if (m) rel[i] = R(i) / m; }
const RANGE = { lo: 0.6, hi: 1.2 }, SHR = 10, MIN_TRACKS_LINE = 25, MIN_VEH_LINE = 5, MIN_TRACKS_BAND = 8;
const kProd = (ratios, lo = RANGE.lo, hi = RANGE.hi) => { const n = ratios.length, raw = lowMedian(ratios), sh = 1 + (raw - 1) * n / (n + SHR); return Math.round(Math.max(lo, Math.min(hi, sh)) * 1000) / 1000; };
const lineBands = li => { const m = new Map(); for (const i of byLine[li]) (m.get(D.franja[i]) || m.set(D.franja[i], []).get(D.franja[i])).push(i); return m; };
const nVeh = idx => new Set(idx.map(i => D.bus[i])).size;
const lineIndex = new Map(L.lineas.map((c, i) => [c, i])), busIndex = new Map(L.vehiculos.map((c, i) => [c, i]));
// estabilidad entre mitades: línea (mediana de la razón) y bus (índice respecto a su línea y franja)
function halves() {
  const lx = [], ly = [], bx = [], by = [], lcodes = [], bcodes = [];
  byLine.forEach((idx, li) => { const a = idx.filter(i => D.dia[i] < HALF).map(R), b = idx.filter(i => D.dia[i] >= HALF).map(R); if (a.length >= 10 && b.length >= 10) { lx.push(Math.log(lowMedian(a))); ly.push(Math.log(lowMedian(b))); lcodes.push(li); } });
  byBus.forEach((idx, bi) => { const a = idx.filter(i => D.dia[i] < HALF && isFinite(rel[i])).map(i => Math.log(rel[i])), b = idx.filter(i => D.dia[i] >= HALF && isFinite(rel[i])).map(i => Math.log(rel[i])); if (a.length >= 6 && b.length >= 6) { bx.push(lowMedian(a)); by.push(lowMedian(b)); bcodes.push(bi); } });
  return { lx, ly, lcodes, bx, by, bcodes, rl: corr(lx, ly), rb: corr(bx, by) };
}
const H = halves();

/* ---------- gráficos genéricos ---------- */
function scatter(host, o) {
  const { s, w, h } = mk(host, o.h || 300), m = { l: 44, r: 12, t: 10, b: 36 }, pw = w - m.l - m.r, ph = h - m.t - m.b, lg = o.log, f = v => lg ? Math.log(v) : v, g = v => lg || o.logY ? Math.log(v) : v;
  const X = v => m.l + (f(Math.max(o.x0, Math.min(o.x1, v))) - f(o.x0)) / (f(o.x1) - f(o.x0)) * pw, Y = v => m.t + ph - (g(Math.max(o.y0, Math.min(o.y1, v))) - g(o.y0)) / (g(o.y1) - g(o.y0)) * ph;
  (o.yt || []).forEach(v => { el('line', { x1: m.l, x2: w - m.r, y1: Y(v), y2: Y(v), class: 'grid-l' }, s); el('text', { x: m.l - 6, y: Y(v) + 4, 'text-anchor': 'end' }, s, fmt(v, v % 1 ? 1 : 0)); });
  (o.xt || []).forEach(v => { el('line', { x1: X(v), x2: X(v), y1: m.t, y2: m.t + ph, class: 'grid-l' }, s); el('text', { x: X(v), y: h - 18, 'text-anchor': 'middle' }, s, fmt(v, v % 1 ? 1 : 0)); });
  el('text', { x: m.l + pw / 2, y: h - 3, 'text-anchor': 'middle' }, s, o.xl); const yl = el('text', { x: 10, y: m.t + ph / 2, 'text-anchor': 'middle', transform: `rotate(-90 10 ${m.t + ph / 2})` }, s, o.yl);
  if (o.diag) el('line', { x1: X(o.x0), y1: Y(o.y0), x2: X(o.x1), y2: Y(o.y1), stroke: css('--ink-3'), 'stroke-dasharray': '5 4' }, s);
  (o.lines || []).forEach(ln => el('line', { x1: X(ln.x0 ?? o.x0), x2: X(ln.x1 ?? o.x1), y1: Y(ln.y0), y2: Y(ln.y1 ?? ln.y0), stroke: css(ln.c || '--ink-3'), 'stroke-dasharray': ln.dash || '', 'stroke-width': 1.5 }, s));
  o.pts.forEach(p => { const c = el('circle', { cx: X(p.x), cy: Y(p.y), r: p.r || 3.5, fill: css(p.c || '--s1'), 'fill-opacity': p.sel ? 1 : (p.o ?? .55), stroke: p.sel ? css('--ink') : 'none', 'stroke-width': p.sel ? 2 : 0, style: o.onPick ? 'cursor:pointer' : '' }, s);
    c.addEventListener('pointermove', e => { const b = host.getBoundingClientRect(); tip(host, p.tip, e.clientX - b.left, e.clientY - b.top); }); c.addEventListener('pointerleave', untip); if (o.onPick) c.addEventListener('click', () => o.onPick(p)); });
}
function legendHtml(items) { return `<div class="legend">${items.map(([c, k, t]) => `<span><i class="${k}" style="border-color:var(${c});${k === 'dot' ? `background:var(${c})` : ''}"></i>${t}</span>`).join('')}</div>`; }
const cardsHtml = a => `<div class="cards">${a.map(([v, t]) => `<div><b>${v}</b><span>${t}</span></div>`).join('')}</div>`;

/* ---------- vista: resumen ---------- */
function vistaResumen() {
  view.innerHTML = `
  <div class="eyebrow">Bide+ · Bizkaibus</div><h1>Laboratorio</h1>
  <p class="lede">Un sitio para tocar los datos con los que aprende el algoritmo: ${fmt(N)} viajes guardados por la captura horaria, de ${fmt(L.vehiculos.length)} buses y ${fmt(L.lineas.length)} líneas, entre el ${dia(L.dias[0])} y el ${dia(L.dias[ND - 1])}. Aquí ves qué se aprende de una línea, qué no se aprende de un bus, y qué pasa si se cambia el rango del factor k.</p>
  <ul class="facts" style="list-style:none;padding:0;display:flex;flex-wrap:wrap;gap:8px 28px;margin:18px 0 0"><li><b style="font:700 26px var(--display)">${fmt(N)}</b><br><span style="font-size:13px;color:var(--ink-3)">viajes guardados</span></li><li><b style="font:700 26px var(--display)">${fmt(L.lineas.length)}</b><br><span style="font-size:13px;color:var(--ink-3)">líneas</span></li><li><b style="font:700 26px var(--display)">${fmt(L.vehiculos.length)}</b><br><span style="font-size:13px;color:var(--ink-3)">buses</span></li><li><b style="font:700 26px var(--display)">${ND}</b><br><span style="font-size:13px;color:var(--ink-3)">días</span></li></ul>
  <div class="grid">
    <div class="card c6"><h2>Las líneas se parecen a sí mismas</h2><p>Cada punto es una línea: su razón real / horario en la primera mitad de los días (horizontal) y en la segunda (vertical). Si una línea es de verdad más lenta que su horario, lo será en las dos mitades.</p>
      <div class="big" id="rl"></div><div class="hint">correlación entre mitades · ${H.lx.length} líneas con 10 viajes o más en cada mitad</div><div class="chart" id="s-l"></div></div>
    <div class="card c6"><h2>Los buses, no</h2><p>Cada punto es un bus: cuánto más rápido o lento que su línea fue en la primera mitad (horizontal) y en la segunda (vertical). Si un bus concreto fuera más rápido, saldría una diagonal.</p>
      <div class="big" id="rb"></div><div class="hint">correlación entre mitades · ${H.bx.length} buses con 6 viajes o más en cada mitad</div><div class="chart" id="s-b"></div></div>
    <div class="card c12"><h2>El rango del factor k</h2><p>Hoy el k de cada línea y franja se encoge hacia 1 según cuántos viajes tiene y se limita entre 0,6 y 1,2. Cambia los límites y mira cuántas líneas quedan recortadas y si cambia el error. Se entrena con los días hasta el ${dia(L.dias[ND - 3])} y se prueba con los dos últimos.</p>
      <div class="controls"><label>Mínimo <input type="number" id="klo" min="0.1" max="1" step="0.05" value="0.6"></label><label>Máximo <input type="number" id="khi" min="1" max="6" step="0.05" value="1.2"></label><button class="b" id="kreset" type="button">Volver a 0,6 – 1,2</button></div>
      <div id="kout"></div></div>
  </div>`;
  $('#rl').textContent = fmt(H.rl, 2); $('#rb').textContent = fmt(H.rb, 2);
  const draw = () => {
    scatter($('#s-l'), { h: 300, x0: .5, x1: 3, y0: .5, y1: 3, log: true, xt: [.5, 1, 2], yt: [.5, 1, 2], xl: 'primera mitad (real / horario)', yl: 'segunda mitad', diag: true,
      pts: H.lcodes.map((li, k) => ({ x: Math.exp(H.lx[k]), y: Math.exp(H.ly[k]), c: '--s1', tip: `<b>Línea ${esc(L.lineas[li])}</b><br>1ª mitad ${fmt(Math.exp(H.lx[k]), 2)} · 2ª mitad ${fmt(Math.exp(H.ly[k]), 2)}` })), onPick: p => { location.hash = '#lineas/' + L.lineas[H.lcodes[Math.round(0)]]; } });
    const sd = H.bx.concat(H.by); scatter($('#s-b'), { h: 300, x0: .6, x1: 1.7, y0: .6, y1: 1.7, log: true, xt: [.7, 1, 1.5], yt: [.7, 1, 1.5], xl: 'primera mitad (índice del bus)', yl: 'segunda mitad', diag: true,
      pts: H.bcodes.map((bi, k) => ({ x: Math.exp(H.bx[k]), y: Math.exp(H.by[k]), c: '--s2', tip: `<b>Bus ${esc(L.vehiculos[bi])}</b><br>1ª mitad ${fmt(Math.exp(H.bx[k]), 2)} · 2ª mitad ${fmt(Math.exp(H.by[k]), 2)}` })) });
  };
  onResize($('#s-l'), draw);
  const kcalc = () => {
    const lo = Math.max(.05, +$('#klo').value || .6), hi = Math.max(lo, +$('#khi').value || 1.2); RANGE.lo = lo; RANGE.hi = hi;
    const cells = [...cellMed.keys()].map(k => ({ k, raw: cellMed.get(k), n: cell.get(k).length })), out = cells.filter(c => c.raw < lo || c.raw > hi), above = out.filter(c => c.raw > hi).length, below = out.length - above;
    // k de entrenamiento (días hasta ND-3) y error medio |k - razón| en los dos últimos días
    const trainIdx = i => D.dia[i] < ND - 2, tr = new Map(), trB = new Map();
    for (let i = 0; i < N; i++) if (trainIdx(i)) { const k = D.linea[i] * 32 + D.franja[i]; (tr.get(k) || tr.set(k, []).get(k)).push(i); (trB.get(D.franja[i]) || trB.set(D.franja[i], []).get(D.franja[i])).push(i); }
    const kband = new Map([...trB].filter(([, a]) => a.length >= MIN_TRACKS_BAND).map(([b, a]) => [b, kProd(a.map(R), lo, hi)]));
    const kcell = new Map([...tr].filter(([, a]) => a.length >= MIN_TRACKS_LINE && nVeh(a) >= MIN_VEH_LINE).map(([k, a]) => [k, kProd(a.map(R), lo, hi)]));
    let e0 = 0, e1 = 0, n = 0; for (let i = 0; i < N; i++) if (!trainIdx(i)) { const k = kcell.get(D.linea[i] * 32 + D.franja[i]) ?? kband.get(D.franja[i]) ?? 1; e1 += Math.abs(k - R(i)); e0 += Math.abs(1 - R(i)); n++; }
    const worst = out.sort((a, b) => b.n - a.n).slice(0, 8);
    $('#kout').innerHTML = cardsHtml([[`${fmt(out.length)} de ${fmt(cells.length)}`, 'combinaciones línea × franja con la mediana fuera del rango'], [`${fmt(above)} arriba · ${fmt(below)} abajo`, 'de ellas, por encima del máximo y por debajo del mínimo'], [fmt(e0 / n, 3), 'error medio sin corregir (k = 1), en razón real / horario'], [fmt(e1 / n, 3), `error medio con este rango (${fmt(lo, 2)} a ${fmt(hi, 2)})`]]) +
      (worst.length ? `<div class="scroll"><table><thead><tr><th>Las más recortadas</th><th>Viajes</th><th>Mediana real</th><th>k que sale</th></tr></thead><tbody>${worst.map(c => `<tr><td>Línea ${esc(L.lineas[Math.floor(c.k / 32)])} · ${bandName(L.bandas[c.k % 32])}</td><td>${c.n}</td><td>${fmt(c.raw, 2)}</td><td>${fmt(kProd(cell.get(c.k).map(R), lo, hi), 2)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="say">Con este rango ninguna combinación queda recortada.</p>') +
      `<p class="say">${Math.abs(e1 - e0) / e0 < .02 ? 'El error casi no cambia con el rango: el factor k corrige poco, la mayor parte del trabajo la hacen los tiempos aprendidos por tramo.' : 'El error cambia con el rango: mira cuánto compensa.'}</p>`;
  };
  ['#klo', '#khi'].forEach(s => $(s).addEventListener('input', kcalc)); $('#kreset').addEventListener('click', () => { $('#klo').value = .6; $('#khi').value = 1.2; kcalc(); });
  draw(); kcalc();
}
const $ = (s, r = document) => r.querySelector(s);

/* ---------- vista: líneas ---------- */
let selLine = null, selBand = -1;
function lineSummary(li) { const idx = byLine[li], r = idx.map(R); return { li, n: idx.length, v: nVeh(idx), raw: lowMedian(r) }; }
function vistaLineas(arg) {
  const all = byLine.map((_, li) => lineSummary(li)).filter(s => s.n > 0).sort((a, b) => b.n - a.n);
  if (arg && lineIndex.has(arg)) selLine = lineIndex.get(arg); if (selLine == null) selLine = all[0].li;
  view.innerHTML = `
  <div class="eyebrow">Cómo aprende cada línea</div><h1>Líneas</h1>
  <p class="lede">El factor k de una línea se aprende con sus viajes: la mediana de lo que tardó de verdad dividido entre lo que decía el horario, encogida hacia 1 mientras hay pocos viajes y limitada a un rango. Elige una línea y mira cómo se va formando.</p>
  <div class="grid"><div class="card c5"><h2>Todas las líneas</h2><p>Cada punto es una línea: viajes guardados y razón real / horario. Naranja si queda fuera del rango del k.</p><div class="chart" id="ov"></div>
      ${legendHtml([['--s1', 'dot', 'Dentro del rango'], ['--s2', 'dot', 'Fuera del rango']])}
      <div class="controls"><input type="search" id="q" placeholder="Buscar línea…" aria-label="Buscar línea"></div><ul class="list" id="ll"></ul></div>
    <div class="card c7" id="det"></div></div>`;
  const list = $('#ll'), drawList = () => { const q = $('#q').value.trim(); list.innerHTML = all.filter(s => !q || L.lineas[s.li].includes(q)).map(s => `<li><button type="button" data-l="${s.li}" ${s.li === selLine ? 'aria-current="true"' : ''}><span>Línea ${esc(L.lineas[s.li])}</span><small>${s.n} viajes · ${fmt(s.raw, 2)}</small></button></li>`).join(''); };
  list.addEventListener('click', e => { const b = e.target.closest('button'); if (b) { selLine = +b.dataset.l; selBand = -1; history.replaceState(null, '', '#lineas/' + L.lineas[selLine]); drawList(); drawOv(); drawDetail(); } });
  $('#q').addEventListener('input', drawList);
  const drawOv = () => scatter($('#ov'), { h: 290, x0: 3, x1: 1000, y0: .4, y1: 4, log: true, xt: [3, 10, 30, 100, 300, 1000], yt: [.5, 1, 2, 4], xl: 'viajes guardados', yl: 'real / horario', lines: [{ y0: RANGE.lo, c: '--ink-3', dash: '3 4' }, { y0: RANGE.hi, c: '--ink-3', dash: '3 4' }, { y0: 1, c: '--track' }],
    pts: all.map(s => ({ x: s.n, y: s.raw, c: s.raw < RANGE.lo || s.raw > RANGE.hi ? '--s2' : '--s1', sel: s.li === selLine, r: s.li === selLine ? 6 : 3.6, li: s.li, tip: `<b>Línea ${esc(L.lineas[s.li])}</b><br>${s.n} viajes · ${s.v} buses<br>mediana ${fmt(s.raw, 2)}` })), onPick: p => { selLine = p.li; selBand = -1; history.replaceState(null, '', '#lineas/' + L.lineas[selLine]); drawList(); drawOv(); drawDetail(); } });
  onResize($('#ov'), drawOv); drawList(); drawOv(); drawDetail();
}
let dayUpTo = ND - 1;
function drawDetail() {
  const li = selLine, code = L.lineas[li], bands = lineBands(li), bandIds = [...bands.keys()].sort((a, b) => bands.get(b).length - bands.get(a).length);
  if (selBand !== -1 && !bands.has(selBand)) selBand = -1; const idx = selBand === -1 ? byLine[li] : bands.get(selBand), sum = lineSummary(li);
  const saved = L.k.lines[code] || {}, glob = L.k.global || {}, savedBand = selBand === -1 ? null : (saved[L.bandas[selBand]] ?? null);
  $('#det').innerHTML = `<h2>Línea ${esc(code)}</h2><p>${fmt(sum.n)} viajes guardados de ${fmt(sum.v)} buses, entre el ${dia(L.dias[Math.min(...byLine[li].map(i => D.dia[i]))])} y el ${dia(L.dias[Math.max(...byLine[li].map(i => D.dia[i]))])}.</p>
    <div class="chips" id="bchips"><button type="button" data-b="-1" aria-pressed="${selBand === -1}">Todas las franjas</button>${bandIds.map(b => `<button type="button" data-b="${b}" aria-pressed="${selBand === b}">${bandName(L.bandas[b])} · ${bands.get(b).length}</button>`).join('')}</div>
    <div class="controls"><button class="b p" id="lplay" type="button">▶ Reproducir</button><label class="grow">Hasta el día <b id="lday"></b><input type="range" id="lrange" min="0" max="${ND - 1}" value="${dayUpTo}" aria-label="Día hasta el que aprende"></label></div>
    ${legendHtml([['--s2', 'dash', 'Mediana sin encoger'], ['--s1', '', 'k que usaría la app'], ['--s3', 'dash', savedBand != null ? 'k guardado hoy' : 'k general de la franja'], ['--ink-3', 'dash', 'Límites del rango']])}
    <div class="chart" id="lc"></div><div class="chart" id="lb" style="margin-top:2px"></div><div id="lread"></div>
    <h3 style="margin-top:18px">Cómo se reparten sus viajes</h3><div class="chart" id="lh"></div>
    <h3 style="margin-top:18px">Por franja</h3><div class="scroll" id="lt"></div><div id="lbus"></div>`;
  $('#bchips').addEventListener('click', e => { const b = e.target.closest('button'); if (b) { selBand = +b.dataset.b; drawDetail(); } });
  // reproducción del aprendizaje por días
  const upTo = d => idx.filter(i => D.dia[i] <= d), series = [];
  for (let d = 0; d < ND; d++) { const a = upTo(d), r = a.map(R); series.push({ d, n: a.length, v: nVeh(a), raw: a.length ? lowMedian(r) : NaN, k: a.length ? kProd(r) : NaN }); }
  const target = savedBand ?? (selBand === -1 ? null : glob[L.bandas[selBand]] ?? null);
  const drawChart = () => {
    const { s, w, h } = mk($('#lc'), 230), m = { l: 40, r: 12, t: 10, b: 24 }, pw = w - m.l - m.r, ph = h - m.t - m.b, finite = series.filter(p => isFinite(p.raw));
    const ymax = Math.min(4, Math.max(1.5, ...finite.map(p => p.raw)) * 1.08), ymin = Math.max(0.2, Math.min(.5, ...finite.map(p => p.raw)) * .92), X = d => m.l + d / (ND - 1) * pw, Y = v => m.t + ph - (Math.min(ymax, Math.max(ymin, v)) - ymin) / (ymax - ymin) * ph;
    for (let v = Math.ceil(ymin * 2) / 2; v <= ymax; v += .5) { el('line', { x1: m.l, x2: w - m.r, y1: Y(v), y2: Y(v), class: 'grid-l' }, s); el('text', { x: m.l - 6, y: Y(v) + 4, 'text-anchor': 'end' }, s, fmt(v, 1)); }
    series.forEach((p, d) => { if (ND < 10 || d % 2 === 0) el('text', { x: X(d), y: h - 6, 'text-anchor': 'middle' }, s, dia(L.dias[d])); });
    [RANGE.lo, RANGE.hi].forEach(v => el('line', { x1: m.l, x2: w - m.r, y1: Y(v), y2: Y(v), stroke: css('--ink-3'), 'stroke-dasharray': '3 4' }, s));
    if (target != null) { el('line', { x1: m.l, x2: w - m.r, y1: Y(target), y2: Y(target), stroke: css('--s3'), 'stroke-width': 2, 'stroke-dasharray': '6 4' }, s); }
    el('line', { x1: X(dayUpTo), x2: X(dayUpTo), y1: m.t, y2: m.t + ph, stroke: css('--ink-3'), 'stroke-dasharray': '2 3' }, s);
    const path = (key, c, dash, wd) => { const pts = series.map((p, d) => [X(d), Y(p[key]), isFinite(p[key])]); const seg = (a, b, o) => { let dstr = '', st = false; for (let i = a; i <= b; i++) { if (!pts[i][2]) { st = false; continue; } dstr += (st ? 'L' : 'M') + pts[i][0] + ' ' + pts[i][1]; st = true; } if (dstr) el('path', { d: dstr, fill: 'none', stroke: css(c), 'stroke-width': wd, 'stroke-dasharray': dash, opacity: o, 'stroke-linejoin': 'round' }, s); };
      seg(0, dayUpTo, 1); if (dayUpTo < ND - 1) seg(dayUpTo, ND - 1, .25); const p = series[dayUpTo]; if (isFinite(p[key])) el('circle', { cx: X(dayUpTo), cy: Y(p[key]), r: 5, fill: css(c), stroke: css('--card'), 'stroke-width': 2 }, s); };
    path('raw', '--s2', '5 4', 2); path('k', '--s1', '', 3);
    series.forEach((p, d) => { const r = el('rect', { x: X(d) - pw / (ND - 1) / 2, y: m.t, width: pw / (ND - 1), height: ph, fill: 'transparent', style: 'cursor:pointer' }, s); r.addEventListener('pointermove', e => { const b = $('#lc').getBoundingClientRect(); tip($('#lc'), `<b>Hasta el ${dia(L.dias[d])}</b><br>${p.n} viajes · ${p.v} buses<br>mediana ${fmt(p.raw, 2)}<br>k ${fmt(p.k, 3)}`, e.clientX - b.left, e.clientY - b.top); }); r.addEventListener('pointerleave', untip); r.addEventListener('click', () => setDay(d)); });
    const b2 = mk($('#lb'), 64), bm = { l: 40, r: 12, t: 6, b: 4 }, bpw = b2.w - bm.l - bm.r, mx = Math.max(MIN_TRACKS_LINE * 1.4, ...series.map(p => p.n)), BY = v => b2.h - bm.b - v / mx * (b2.h - bm.t - bm.b);
    series.forEach((p, d) => { const bw = bpw / ND * .62, cx = bm.l + (d + .5) * bpw / ND; el('rect', { x: cx - bw / 2, y: BY(p.n), width: bw, height: b2.h - bm.b - BY(p.n), rx: 2, fill: css('--s1'), opacity: d <= dayUpTo ? .9 : .2 }, b2.s); });
    el('line', { x1: bm.l, x2: b2.w - bm.r, y1: BY(MIN_TRACKS_LINE), y2: BY(MIN_TRACKS_LINE), stroke: css('--ink-3'), 'stroke-dasharray': '3 4' }, b2.s); el('text', { x: bm.l + 4, y: BY(MIN_TRACKS_LINE) - 4, class: 'lab' }, b2.s, `${MIN_TRACKS_LINE} viajes y ${MIN_VEH_LINE} buses: k propio`); el('text', { x: bm.l, y: 10, 'text-anchor': 'start' }, b2.s, 'viajes por día');
  };
  const setDay = d => { dayUpTo = d; $('#lrange').value = d; const p = series[d]; $('#lday').textContent = dia(L.dias[d]);
    const own = p.n >= MIN_TRACKS_LINE && p.v >= MIN_VEH_LINE;
    $('#lread').innerHTML = cardsHtml([[fmt(p.n), 'viajes hasta este día'], [fmt(p.v), 'buses distintos'], [fmt(p.raw, 2), 'mediana sin encoger'], [fmt(p.k, 3), 'k con encogimiento y límites'], [own ? 'propio' : 'general', own ? 'ya usa su k' : 'todavía usa el k general']]) +
      `<p class="say">${!isFinite(p.raw) ? 'Todavía no hay viajes.' : p.raw > RANGE.hi || p.raw < RANGE.lo ? `La mediana (<em>${fmt(p.raw, 2)}</em>) queda fuera del rango, así que el k se queda en <em>${fmt(p.k, 2)}</em>, el límite.` : `Con ${p.n} viajes la mediana es <em>${fmt(p.raw, 2)}</em>, pero el k es <em>${fmt(p.k, 3)}</em>: se acerca a la mediana a medida que hay más viajes.`}</p>`; drawChart(); };
  player($('#lplay'), reset => { if (reset && dayUpTo >= ND - 1) { setDay(0); return true; } if (dayUpTo >= ND - 1) return false; setDay(dayUpTo + 1); return dayUpTo < ND - 1; });
  $('#lrange').addEventListener('input', e => setDay(+e.target.value)); onResize($('#lc'), drawChart); setDay(dayUpTo);
  // histograma de razones
  const drawHist = () => { const { s, w, h } = mk($('#lh'), 150), m = { l: 40, r: 12, t: 8, b: 24 }, pw = w - m.l - m.r, ph = h - m.t - m.b, bins = new Array(31).fill(0); idx.forEach(i => bins[Math.min(30, Math.floor(R(i) / .1))]++);
    const mxb = Math.max(...bins, 1), X = v => m.l + Math.min(3.1, v) / 3.1 * pw, bw = pw / 31;
    el('rect', { x: X(RANGE.lo), y: m.t, width: X(RANGE.hi) - X(RANGE.lo), height: ph, fill: css('--grid'), opacity: .8 }, s);
    bins.forEach((c, b) => { const hh = c / mxb * ph; el('rect', { x: m.l + b * bw + 1, y: m.t + ph - hh, width: bw - 2, height: hh, rx: 2, fill: css('--s1'), opacity: .8 }, s); });
    for (let v = 0; v <= 3; v++) el('text', { x: X(v), y: h - 6, 'text-anchor': 'middle' }, s, v === 3 ? '3+' : v); const med = lowMedian(idx.map(R)); el('line', { x1: X(med), x2: X(med), y1: m.t, y2: m.t + ph, stroke: css('--s2'), 'stroke-width': 2.5 }, s); el('text', { x: X(med) + 5, y: m.t + 12, class: 'lab' }, s, 'mediana ' + fmt(med, 2));
    const out = idx.filter(i => R(i) < RANGE.lo || R(i) > RANGE.hi).length; el('text', { x: w - m.r, y: m.t + 12, 'text-anchor': 'end', class: 'lab' }, s, `${fmt(100 * out / idx.length)} % de los viajes fuera del rango`); };
  onResize($('#lh'), drawHist); drawHist();
  // tabla por franja
  $('#lt').innerHTML = `<table><thead><tr><th>Franja</th><th>Viajes</th><th>Buses</th><th>Mediana</th><th>k guardado</th><th>Estado</th></tr></thead><tbody>${bandIds.map(b => { const a = bands.get(b), raw = lowMedian(a.map(R)), v = nVeh(a), sk = saved[L.bandas[b]], own = a.length >= MIN_TRACKS_LINE && v >= MIN_VEH_LINE; return `<tr><td>${bandName(L.bandas[b])}</td><td>${a.length}</td><td>${v}</td><td>${fmt(raw, 2)}</td><td>${sk != null ? fmt(sk, 3) : (glob[L.bandas[b]] != null ? fmt(glob[L.bandas[b]], 3) + ' (general)' : '—')}</td><td>${own ? (raw < RANGE.lo || raw > RANGE.hi ? '<span class="tag w">k propio, recortado</span>' : '<span class="tag">k propio</span>') : '<span class="tag">usa el general</span>'}</td></tr>`; }).join('')}</tbody></table>`;
  // buses de esta línea
  const bc = new Map(); byLine[li].forEach(i => (bc.get(D.bus[i]) || bc.set(D.bus[i], []).get(D.bus[i])).push(i)); const top = [...bc].sort((a, b) => b[1].length - a[1].length).slice(0, 8);
  $('#lbus').innerHTML = `<h3 style="margin-top:18px">Buses que más la han hecho</h3><div class="scroll"><table><thead><tr><th>Bus</th><th>Viajes</th><th>Razón mediana</th><th>Respecto a la línea</th></tr></thead><tbody>${top.map(([b, a]) => { const rr = lowMedian(a.map(R)), rl = a.filter(i => isFinite(rel[i])).map(i => rel[i]), ri = rl.length ? lowMedian(rl) : NaN; return `<tr><td><a href="#buses/${esc(L.vehiculos[b])}">Bus ${esc(L.vehiculos[b])}</a></td><td>${a.length}</td><td>${fmt(rr, 2)}</td><td>${isFinite(ri) ? (ri >= 1 ? '+' : '') + fmt((ri - 1) * 100) + ' %' : '—'}</td></tr>`; }).join('')}</tbody></table></div>`;
}

/* ---------- vista: buses ---------- */
let selBus = null;
function vistaBuses(arg) {
  const vs = byBus.map((idx, b) => { const r = idx.filter(i => isFinite(rel[i])).map(i => rel[i]); return { b, n: idx.length, lines: new Set(idx.map(i => D.linea[i])).size, ix: r.length >= 3 ? lowMedian(r) : NaN }; }).filter(v => v.n > 0).sort((a, b) => b.n - a.n);
  if (arg && busIndex.has(arg)) selBus = busIndex.get(arg); if (selBus == null) selBus = vs[0].b;
  view.innerHTML = `<div class="eyebrow">Qué se aprende (y qué no) de un bus</div><h1>Buses</h1>
  <p class="lede">Cada viaje tiene su bus. Si un bus concreto fuera más rápido o más lento que su línea de forma estable, podríamos aprenderlo. Pero la correlación entre mitades es <b>${fmt(H.rb, 2)}</b>: lo que parece «un bus rápido» es ruido de unos pocos viajes. Elige uno y míralo.</p>
  <div class="grid"><div class="card c4"><h2>Buses</h2><p>Con su número de viajes y su índice: 1 es igual que su línea; 1,10 es un 10 % más lento.</p><div class="controls"><input type="search" id="q" placeholder="Buscar bus…" aria-label="Buscar bus"></div><ul class="list" id="bl" style="max-height:520px"></ul></div>
  <div class="card c8" id="bd"></div></div>`;
  const list = $('#bl'), drawList = () => { const q = $('#q').value.trim(); list.innerHTML = vs.filter(v => !q || L.vehiculos[v.b].includes(q)).map(v => `<li><button type="button" data-b="${v.b}" ${v.b === selBus ? 'aria-current="true"' : ''}><span>Bus ${esc(L.vehiculos[v.b])}</span><small>${v.n} viajes · ${isFinite(v.ix) ? fmt(v.ix, 2) : '—'}</small></button></li>`).join(''); };
  list.addEventListener('click', e => { const b = e.target.closest('button'); if (b) { selBus = +b.dataset.b; history.replaceState(null, '', '#buses/' + L.vehiculos[selBus]); drawList(); drawBus(); } }); $('#q').addEventListener('input', drawList); drawList(); drawBus();
}
function drawBus() {
  const bi = selBus, idx = byBus[bi], code = L.vehiculos[bi], withRel = idx.filter(i => isFinite(rel[i])), lines = new Map(); idx.forEach(i => lines.set(D.linea[i], (lines.get(D.linea[i]) || 0) + 1));
  const ix = withRel.length ? lowMedian(withRel.map(i => rel[i])) : NaN, ixA = lowMedian(withRel.filter(i => D.dia[i] < HALF).map(i => rel[i])), ixB = lowMedian(withRel.filter(i => D.dia[i] >= HALF).map(i => rel[i]));
  const topLines = [...lines].sort((a, b) => b[1] - a[1]), colorOf = li => li === topLines[0][0] ? '--s1' : (topLines[1] && li === topLines[1][0] ? '--s2' : '--ink-3');
  $('#bd').innerHTML = `<h2>Bus ${esc(code)}</h2><p>${fmt(idx.length)} viajes en ${lines.size} líneas, entre el ${dia(L.dias[Math.min(...idx.map(i => D.dia[i]))])} y el ${dia(L.dias[Math.max(...idx.map(i => D.dia[i]))])}.</p>
    ${cardsHtml([[fmt(idx.length), 'viajes'], [fmt(lines.size), 'líneas'], [isFinite(ix) ? fmt(ix, 2) : '—', 'índice respecto a su línea'], [isFinite(ixA) ? fmt(ixA, 2) : '—', 'primera mitad de los días'], [isFinite(ixB) ? fmt(ixB, 2) : '—', 'segunda mitad']])}
    ${legendHtml([['--s1', 'dot', 'Línea ' + esc(L.lineas[topLines[0][0]])], ...(topLines[1] ? [['--s2', 'dot', 'Línea ' + esc(L.lineas[topLines[1][0]])]] : []), ['--ink-3', 'dot', 'Otras'], ['--ink', '', 'Índice acumulado']])}
    <div class="chart" id="bt"></div><p class="say" id="bsay"></p><div class="scroll" id="btab"></div>`;
  const draw = () => { const pts = withRel.map(i => ({ x: D.dia[i] + D.hora[i] / 1440, y: rel[i], c: colorOf(D.linea[i]), o: .7, tip: `<b>Línea ${esc(L.lineas[D.linea[i]])}</b><br>${dia(L.dias[D.dia[i]])} ${hhmm(D.hora[i])}<br>razón ${fmt(R(i), 2)} · índice ${fmt(rel[i], 2)}` }));
    const sorted = withRel.slice().sort((a, b) => (D.dia[a] + D.hora[a] / 1440) - (D.dia[b] + D.hora[b] / 1440)), cum = []; sorted.forEach((i, k) => { cum.push({ x: D.dia[i] + D.hora[i] / 1440, y: lowMedian(sorted.slice(0, k + 1).map(j => rel[j])) }); });
    scatter($('#bt'), { h: 280, x0: 0, x1: ND, y0: .3, y1: 3.4, logY: true, xt: [...Array(ND).keys()].filter(d => ND < 10 || d % (innerWidth < 600 ? 4 : 2) === 0), yt: [.5, 1, 2], xl: 'día', yl: 'índice del viaje (1 = como su línea)', lines: [{ y0: 1, c: '--track' }], pts });
    const svg = $('#bt svg'), w = +svg.getAttribute('width'), m = { l: 44, r: 12, t: 10, b: 36 }, pw = w - m.l - m.r, ph = 280 - m.t - m.b, f = Math.log, X = v => m.l + v / ND * pw, Y = v => m.t + ph - (f(Math.max(.3, Math.min(3.4, v))) - f(.3)) / (f(3.4) - f(.3)) * ph;
    if (cum.length) el('path', { d: cum.map((p, k) => (k ? 'L' : 'M') + X(p.x) + ' ' + Y(p.y)).join(''), fill: 'none', stroke: css('--ink'), 'stroke-width': 2.5, 'stroke-linejoin': 'round' }, svg);
    $('#bt svg').querySelectorAll('text').forEach(t => { if (!isNaN(+t.textContent) && +t.textContent >= 0 && +t.textContent < ND && t.getAttribute('y') > 250) t.textContent = dia(L.dias[+t.textContent]); }); };
  onResize($('#bt'), draw); draw();
  $('#bsay').innerHTML = withRel.length < 6 ? 'Con tan pocos viajes no se puede decir nada de este bus.' : `Su índice es <em>${fmt(ix, 2)}</em> (${ix >= 1 ? 'un ' + fmt((ix - 1) * 100) + ' % más lento' : 'un ' + fmt((1 - ix) * 100) + ' % más rápido'} que su línea). ${isFinite(ixA) && isFinite(ixB) ? `Pero en la primera mitad de los días era <em>${fmt(ixA, 2)}</em> y en la segunda <em>${fmt(ixB, 2)}</em>${Math.sign(ixA - 1) !== Math.sign(ixB - 1) ? ': cambia de lado, no es un bus «rápido» o «lento».' : '.'}` : ''}`;
  $('#btab').innerHTML = `<table><thead><tr><th>Línea</th><th>Viajes</th><th>Razón mediana</th></tr></thead><tbody>${topLines.slice(0, 8).map(([li, n]) => `<tr><td><a href="#lineas/${esc(L.lineas[li])}">Línea ${esc(L.lineas[li])}</a></td><td>${n}</td><td>${fmt(lowMedian(idx.filter(i => D.linea[i] === li).map(R)), 2)}</td></tr>`).join('')}</tbody></table>`;
}

/* ---------- navegación ---------- */
const FRAMES = { dias: '../docs/aprendizaje.html', red: '../docs/red-neuronal.html' };
function route() {
  cleanups.forEach(f => f()); cleanups = []; untip();
  const [tab = 'resumen', arg] = decodeURIComponent(location.hash.slice(1)).split('/');
  document.querySelectorAll('#tabs a').forEach(a => a.toggleAttribute('aria-current', a.dataset.tab === tab) || (a.dataset.tab !== tab && a.removeAttribute('aria-current'))); document.querySelectorAll('#tabs a').forEach(a => { if (a.dataset.tab === tab) a.setAttribute('aria-current', 'page'); });
  view.className = FRAMES[tab] ? 'full' : '';
  if (FRAMES[tab]) { view.innerHTML = `<iframe src="${FRAMES[tab]}" title="${tab === 'dias' ? 'Aprendizaje por días' : 'Red neuronal'}"></iframe>`; return; }
  if (tab === 'cobertura') { window.vistaCobertura(view, f => cleanups.push(f)); return; }
  if (tab === 'vivo') { window.vistaVivo(view, f => cleanups.push(f)); return; }
  ({ lineas: vistaLineas, buses: vistaBuses, resumen: vistaResumen }[tab] || vistaResumen)(arg); window.scrollTo(0, 0);
}
addEventListener('hashchange', route);
$('#theme').addEventListener('click', () => { const dark = (document.documentElement.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark'; document.documentElement.dataset.theme = dark ? 'light' : 'dark'; try { localStorage.setItem('lab-theme', document.documentElement.dataset.theme); } catch (e) {} route(); });
try { const t = localStorage.getItem('lab-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
route();
})();
