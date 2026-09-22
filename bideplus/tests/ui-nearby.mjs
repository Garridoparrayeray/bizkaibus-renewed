import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9374);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-nearby-'));
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
const problems = [];
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.exceptionDetails ? 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0] : (r.result && r.result.value);
};
const waitTrue = async (expr, ms = 12000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if ((await ev(expr)) === true) return true;
        await sleep(300);
    }
    return false;
};
const results = [];
const check = (name, ok, extra = '') => results.push(`${ok ? 'OK ' : 'MAL'} ${name}${extra ? ' — ' + extra : ''}`);
const go = async (url, wait = 3000) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };
const primeGeolocation = () => Promise.race([ev("new Promise((res) => navigator.geolocation.getCurrentPosition(() => res(true), () => res(false), { maximumAge: 0, timeout: 5000 }))"), sleep(7000).then(() => 'sin respuesta')]);
const resultsText = "(document.querySelector('#search-results .pill') || document.getElementById('search-results') || {}).innerText || ''";

await send('Runtime.enable'); await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
await send('Browser.grantPermissions', { permissions: ['geolocation'], origin: BASE });

const cases = [
    { key: 'bus', url: '/?red=bus', lat: 43.26347, lon: -2.93506, expect: 'MOYUA', panel: 'live-card' },
    { key: 'metro', url: '/?red=metro', lat: 43.32595, lon: -3.00961, expect: 'Areeta', panel: 'platform-panel' },
    { key: 'euskotren', url: '/?red=euskotren', lat: 43.313179, lon: -1.981685, expect: 'Amara', panel: 'platform-panel' },
];
for (const c of cases) {
    await send('Emulation.setGeolocationOverride', { latitude: c.lat, longitude: c.lon, accuracy: 20 });
    await go(c.url);
    await primeGeolocation();
    await ev("document.getElementById('nearby-btn').click()");
    check(`${c.key}: aparecen paradas cercanas`, await waitTrue("document.querySelectorAll('#search-results .pill').length > 0"));
    const first = await ev(resultsText);
    check(`${c.key}: la primera parada es la mas cercana`, first.toLowerCase().includes(c.expect.toLowerCase()), first.split('\n')[0]);
    check(`${c.key}: muestra distancia y proxima salida`, /\d+ (m|km) · /.test(first), first.replace(/\n/g, ' | ').slice(0, 100));
    await ev("document.querySelector('#search-results .pill').click()");
    check(`${c.key}: al pulsar se abre la ficha de la parada`, await waitTrue(`!document.getElementById('${c.panel}').hidden`));
}

await send('Emulation.setGeolocationOverride', { latitude: 40.4, longitude: -3.7, accuracy: 20 });
await go('/?red=bus');
await ev("document.getElementById('nearby-btn').click()");
check('ubicacion fuera de la zona: muestra aviso', await waitTrue("(document.querySelector('#search-results .search-message') || {}).innerText?.includes('fuera de la zona') === true"));

await send('Browser.setPermission', { permission: { name: 'geolocation' }, setting: 'denied', origin: BASE });
await go('/?red=bus');
await ev("document.getElementById('nearby-btn').click()");
check('permiso denegado: muestra aviso', await waitTrue("(document.querySelector('#search-results .search-message') || {}).innerText?.includes('denegado') === true"));

const bad = results.filter(r => r.startsWith('MAL')).length;
const unique = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO cerca de mi: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${unique.length} excepciones`);
for (const p of unique.slice(0, 10)) console.log(' -', p);
ws.close();
shutdown(bad || unique.length ? 1 : 0);
