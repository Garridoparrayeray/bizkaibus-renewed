import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9365);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-offline-'));
const sleep = ms => new Promise(r => setTimeout(r, ms));

// En castellano: la app sigue el idioma del navegador y las pruebas comprueban los textos en castellano.
const flags = ['--headless=new', '--disable-gpu', '--lang=es-ES', '--accept-lang=es-ES,es', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profileDir}`, 'about:blank'];
if (process.env.CI) flags.unshift('--no-sandbox');
const browser = spawn(CHROME, flags, { stdio: 'ignore' });
const shutdown = code => { try { browser.kill(); } catch (e) { } process.exit(code); };

let targets;
for (let i = 0; i < 40 && !targets; i++) {
    await sleep(500);
    try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); } catch (e) { }
}
if (!targets) { console.log('MAL no se pudo conectar con el navegador'); shutdown(1); }

const ws = new WebSocket(targets.find(x => x.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.onopen = r);
let id = 0;
const pending = new Map();
let currentHosts = new Set();
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Network.requestWillBeSent') {
        try { currentHosts.add(new URL(m.params.request.url).host); } catch (e) { }
    }
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
};
await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });

const waitTrue = async (expr, ms = 9000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if ((await ev(expr)) === true) return true;
        await sleep(300);
    }
    return false;
};

const baseHost = new URL(BASE).host;
const allowed = h => h === baseHost || /(^|\.)tile\.openstreetmap\.org$/.test(h) || /cartocdn\.com$/.test(h);
const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};
const go = async (url, wait = 3000) => { currentHosts = new Set(); await send('Page.navigate', { url: BASE + url }); await sleep(wait); };

for (const [url, name] of [['/', 'menu Bide+'], ['/?red=bus', 'Bizkaibus+'], ['/?red=metro', 'Metro+'], ['/?red=euskotren', 'Euskotren+'], ['/?red=tranvia-bilbao', 'Tranvía Bilbao+'], ['/?red=tranvia-vitoria', 'Tranvía Vitoria+'], ['/?red=renfe', 'Renfe Cercanías+'], ['/?red=lurraldebus', 'Lurraldebus+'], ['/pagina-que-no-existe', 'pagina 404']]) {
    await go(url);
    const external = [...currentHosts].filter(h => !allowed(h) && h.includes('.'));
    check(`${name}: solo se piden recursos propios`, external.length === 0, external.join(', '));
}

await go('/?red=metro');
const fonts = await ev(`({
    inter: document.fonts.check('16px Inter'),
    bricolage: document.fonts.check('800 16px "Bricolage Grotesque"'),
    loaded: [...document.fonts].filter(f => f.status === 'loaded').map(f => f.family.replace(/"/g, '')).filter((v, i, a) => a.indexOf(v) === i)
})`);
check('tipografias Inter y Bricolage disponibles', fonts.inter === true && fonts.bricolage === true, `cargadas: ${fonts.loaded.join(', ')}`);
check('las tipografias vienen de /fonts (woff2 propios)', fonts.loaded.length >= 2);

await go('/', 2500);
const hanken = await ev(`document.fonts.check('16px "Hanken Grotesk"')`);
check('menu: Hanken Grotesk disponible', hanken === true);

await go('/lines/3516', 6000);
check('Leaflet local cargado (window.L)', (await ev("typeof L === 'object' && typeof L.map === 'function'")) === true);
check('el mapa de la linea se dibuja', await waitTrue("!!document.querySelector('#line-map .leaflet-pane')"));
check('la ruta de la linea se dibuja sobre el mapa', await waitTrue("document.querySelectorAll('#line-map path.leaflet-interactive').length > 0"));

let PHP = process.env.PHP_PATH || 'php';
if (!process.env.PHP_PATH && existsSync('C:/xampp/php/php.exe')) PHP = 'C:/xampp/php/php.exe';
const APP_DIR = resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const OFFLINE_PORT = Number(process.env.OFFLINE_PORT || 8213);
const OFFLINE_BASE = `http://localhost:${OFFLINE_PORT}`;
const ownServer = spawn(PHP, ['-S', `localhost:${OFFLINE_PORT}`, 'dev-router.php'], { cwd: APP_DIR, stdio: 'ignore' });
// Sin leer la respuesta, Node 22.23 se cae al apagar este servidor.
for (let i = 0; i < 40; i++) {
    await sleep(500);
    try {
        const response = await fetch(OFFLINE_BASE + '/');
        await response.arrayBuffer();
        if (response.ok) break;
    } catch (e) { }
}
const goOwn = async (url, wait = 3000) => { await send('Page.navigate', { url: OFFLINE_BASE + url }); await sleep(wait); };
const searchOwn = async q => {
    await ev(`(()=>{const i=document.getElementById('search-input');i.value='${q}';i.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('search-form').requestSubmit();})()`);
    await sleep(2500);
};

await goOwn('/?red=metro');
await sleep(2500);
await goOwn('/?red=metro');
await searchOwn('abando');
check('busqueda online funciona (rellena la cache)', await waitTrue("document.querySelectorAll('#search-results .pill').length > 0"));

// Horarios sin conexión: se guarda Bizkaibus desde Ajustes y se abre una parada con el servidor caído.
await goOwn('/?red=bus');
await ev("document.querySelector('[data-bide-tab=ajustes]').click()");
await sleep(800);
await ev("(() => { const input = [...document.querySelectorAll('.bide-offline-item')].find(li => li.textContent.includes('Bizkaibus')).querySelector('input'); input.click(); return true; })()");
check('horarios sin conexion: Bizkaibus se guarda desde Ajustes', await waitTrue("!!BideOffline.saved().bus"));
await ev("(() => { const list = BideOffline.saved(); list.bus.generated = '2000-01-01'; localStorage.setItem('bide-offline-networks', JSON.stringify(list)); localStorage.removeItem('bide-offline-checked'); window.dispatchEvent(new Event('online')); return true; })()");
check('horarios sin conexion: un horario mas nuevo se baja solo al volver la conexion', await waitTrue("BideOffline.saved().bus.generated !== '2000-01-01'", 20000));
await goOwn('/stops/3388', 4000);
check('con conexion: no se puede marcar si un bus paso (manda el directo)', await waitTrue("!document.getElementById('live-card').hidden") && (await ev("document.getElementById('live-offline-marks').hidden && document.querySelectorAll('#live-more-list .offline-mark').length === 0")));
await ev("(() => { window.__departuresCalls = 0; const original = Api.stopDepartures; Api.stopDepartures = (...args) => { window.__departuresCalls++; return original(...args); }; window.dispatchEvent(new Event('online')); return true; })()");
check('al recuperar la conexion se piden ya las salidas, por si vuelve el directo', await waitTrue('window.__departuresCalls > 0', 4000));
await goOwn('/?red=bus');

ownServer.kill();
await sleep(1500);

await goOwn('/?red=metro', 3500);
check('servidor caido: la app carga desde el service worker', await waitTrue("!!document.getElementById('search-input')"));
check('servidor caido: los estilos siguen aplicados', (await ev("getComputedStyle(document.querySelector('header')).position")) === 'sticky');
check('servidor caido: las tipografias siguen disponibles', (await ev(`document.fonts.check('800 16px "Bricolage Grotesque"')`)) === true);
await searchOwn('abando');
check('servidor caido: una busqueda ya hecha responde desde la cache', await waitTrue("document.querySelectorAll('#search-results .pill').length > 0"));
await searchOwn('zzqqxx');
check('servidor caido: una consulta nueva muestra el aviso de conexion', await waitTrue("!document.getElementById('net-banner').hidden"));
await goOwn('/stops/1137', 4000);
check('servidor caido: la parada sale del horario guardado', await waitTrue("!document.getElementById('live-card').hidden && ['ESTIMADO', 'SOLO HORARIO'].includes(document.getElementById('live-badge').textContent) && /\\d/.test(document.getElementById('live-minutes').textContent)"));
// Bizkaibus guarda también lo que tardan de verdad los buses: sin conexión sale la hora estimada con la oficial al lado.
check('servidor caido: Bizkaibus enseña la hora estimada con los tramos aprendidos', await ev("document.getElementById('live-badge').textContent === 'ESTIMADO' || document.getElementById('live-more-list').textContent.includes('≈')"));
check('servidor caido: avisa de que es el horario guardado y sin directo', await ev("document.getElementById('net-banner').textContent.includes('sin directo')"));
// Sin conexión la persona puede decir si un bus ya pasó: se esconde, y «mostrar» lo devuelve.
check('servidor caido: cada salida se puede marcar como pasada', await ev("!document.getElementById('live-mark').hidden && document.querySelectorAll('#live-more-list .offline-mark').length > 0"));
const rowsBefore = await ev("document.querySelectorAll('#live-more-list li').length");
await ev("document.querySelector('#live-more-list .offline-mark').click()");
check('servidor caido: marcar «ya pasó» esconde ese bus', await waitTrue(`document.querySelectorAll('#live-more-list li').length === ${rowsBefore} - 1 || !document.getElementById('live-marks-undo').hidden`));
check('servidor caido: ofrece mostrar los marcados', await ev("!document.getElementById('live-marks-undo').hidden && document.getElementById('live-marks-undo').textContent.includes('(1)')"));
await ev("document.getElementById('live-marks-undo').click()");
check('servidor caido: «mostrar» los devuelve', await waitTrue("document.getElementById('live-marks-undo').hidden"));

const bad = results.filter(r => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO offline: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL`);
ws.close();
let exitCode = 0;
if (bad) exitCode = 1;
shutdown(exitCode);
