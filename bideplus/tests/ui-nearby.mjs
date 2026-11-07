import { launchBrowser } from './browser.mjs';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9374);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const { browser, targets } = await launchBrowser(PORT, 'bide-nearby-');
const shutdown = code => { try { browser.kill(); } catch (e) { } process.exit(code); };

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
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
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
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};
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
    { key: 'tranvia-bilbao', url: '/?red=tranvia-bilbao', lat: 43.254113, lon: -2.921513, expect: 'Atxuri', panel: 'platform-panel' },
    { key: 'tranvia-vitoria', url: '/?red=tranvia-vitoria', lat: 42.876277, lon: -2.679599, expect: 'Abetxuko', panel: 'platform-panel' },
    { key: 'renfe', url: '/?red=renfe', lat: 43.2601304, lon: -2.9285592, expect: 'Abando', panel: 'platform-panel' },
    { key: 'lurraldebus', url: '/?red=lurraldebus', lat: 43.31619, lon: -1.97738, expect: 'Donostia', panel: 'live-card' },
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

// «No usar mi ubicación» de Ajustes: con el permiso dado, «Cerca de mí» no la pide y dice dónde volver a activarla.
await ev("localStorage.setItem('bide_location_off', '1'); true");
await go('/?red=bus');
await ev("window.__pidio = false; const orig = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation); navigator.geolocation.getCurrentPosition = (...a) => { window.__pidio = true; return orig(...a); }; document.getElementById('nearby-btn').click(); true");
check('ubicacion desactivada en Ajustes: no la pide y lo dice', await waitTrue("(document.querySelector('#search-results .search-message') || {}).innerText?.includes('Ajustes') === true && window.__pidio === false"));
await ev("localStorage.removeItem('bide_location_off'); true");

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
let exitCode = 0;
if (bad || unique.length) exitCode = 1;
shutdown(exitCode);
