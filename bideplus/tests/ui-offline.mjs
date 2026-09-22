import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9365);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-offline-'));
const sleep = ms => new Promise(r => setTimeout(r, ms));

const flags = ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profileDir}`, 'about:blank'];
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
    return r.exceptionDetails ? 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0] : (r.result && r.result.value);
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
const check = (name, ok, extra = '') => results.push(`${ok ? 'OK ' : 'MAL'} ${name}${extra ? ' — ' + extra : ''}`);
const go = async (url, wait = 3000) => { currentHosts = new Set(); await send('Page.navigate', { url: BASE + url }); await sleep(wait); };

for (const [url, name] of [['/', 'menu Bide+'], ['/?red=bus', 'Bizkaibus+'], ['/?red=metro', 'Metro+'], ['/?red=euskotren', 'Euskotren+'], ['/pagina-que-no-existe', 'pagina 404']]) {
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

const PHP = process.env.PHP_PATH || (existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
const APP_DIR = resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const OFFLINE_PORT = Number(process.env.OFFLINE_PORT || 8213);
const OFFLINE_BASE = `http://localhost:${OFFLINE_PORT}`;
const ownServer = spawn(PHP, ['-S', `localhost:${OFFLINE_PORT}`, 'dev-router.php'], { cwd: APP_DIR, stdio: 'ignore' });
for (let i = 0; i < 40; i++) {
    await sleep(500);
    try { if ((await fetch(OFFLINE_BASE + '/')).ok) break; } catch (e) { }
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

const bad = results.filter(r => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO offline: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL`);
ws.close();
shutdown(bad ? 1 : 0);
