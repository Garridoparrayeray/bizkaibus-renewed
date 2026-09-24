import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9368);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-alerts-'));
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
let registrationId = null;
const mock = { bus: null, metro: null, status: 200 };
const requested = [];

ws.onmessage = async e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
    if (m.method === 'ServiceWorker.workerRegistrationUpdated') {
        const reg = (m.params.registrations || []).find(r => r.scopeURL.startsWith(BASE));
        if (reg) registrationId = reg.registrationId;
    }
    if (m.method === 'Fetch.requestPaused') {
        const { requestId, request } = m.params;
        requested.push(request.url);
        const body = request.url.includes('red=metro') ? mock.metro : request.url.includes('line=') ? mock.bus : null;
        if (body === null && mock.status === 200) {
            send('Fetch.continueRequest', { requestId });
        } else {
            send('Fetch.fulfillRequest', {
                requestId,
                responseCode: mock.status,
                responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
                body: Buffer.from(JSON.stringify({ alerts: body || [] })).toString('base64'),
            });
        }
    }
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.exceptionDetails ? 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0] : (r.result && r.result.value);
};
const waitTrue = async (expr, ms = 9000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if ((await ev(expr)) === true) return true;
        await sleep(300);
    }
    return false;
};
const results = [];
const check = (name, ok, extra = '') => results.push(`${ok ? 'OK ' : 'MAL'} ${name}${extra ? ' — ' + extra : ''}`);
const skip = (name, why) => results.push(`SKIP ${name} — ${why}`);
const go = async (url, wait = 3000) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };

await send('Runtime.enable'); await send('Page.enable'); await send('ServiceWorker.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
await send('Browser.grantPermissions', { permissions: ['notifications'], origin: BASE });

const A = { summary: 'Incidencia', description: 'Obras en Gernika A' };
const B = { summary: 'Incidencia', description: 'Corte de calle B' };
const extra = n => ({ summary: 'Incidencia', description: `Aviso extra ${n}` });

await go('/?red=bus');
await send('Fetch.enable', { patterns: [{ urlPattern: '*/api/alerts*' }] });

await ev(`(async () => { await AlertsStore.set('enabled', true); await AlertsStore.resetBaseline(); await AlertsStore.setFavorites('bus', ['3513']); await AlertsStore.setFavorites('metro', []); await AlertsStore.setFavorites('euskotren', []); await AlertsStore.setFavorites('tranvia-bilbao', []); await AlertsStore.setFavorites('tranvia-vitoria', []); })()`);

mock.bus = [A];
const first = await ev(`AlertsStore.checkAlerts().then(r => r.length)`);
check('primera comprobacion: los avisos que ya existen no notifican (linea base)', first === 0, `${first}`);

mock.bus = [A, B];
const second = await ev(`AlertsStore.checkAlerts().then(r => r.map(x => x.body))`);
check('un aviso nuevo se detecta una sola vez', Array.isArray(second) && second.length === 1 && second[0] === B.description, JSON.stringify(second));
const repeated = await ev(`AlertsStore.checkAlerts().then(r => r.length)`);
check('el mismo aviso no se repite', repeated === 0, `${repeated}`);

mock.bus = [A, B, extra(1), extra(2), extra(3), extra(4), extra(5)];
const many = await ev(`AlertsStore.checkAlerts().then(r => r.length)`);
check('varios avisos nuevos a la vez se detectan todos', many === 5, `${many}`);
const notified = await ev(`(async () => {
    const calls = [];
    const fake = { showNotification: async (title, options) => { calls.push({ title, tag: options.tag }); } };
    const alerts = ['a', 'b', 'c', 'd', 'e'].map(k => ({ network: 'bus', title: 'Incidencia', body: 'x' + k, key: 'bus:' + k }));
    await AlertsStore.notify(fake, alerts);
    return calls;
})()`);
check('se muestran como mucho 3 notificaciones y un resumen', notified.length === 4 && notified[0].title.startsWith('Bizkaibus+') && notified[3].tag === 'bide-more-alerts', `${notified.length} notificaciones`);

await ev(`AlertsStore.setFavorites('metro', ['MB'])`);
mock.metro = [{ title: 'Incidencia', description: 'Metro M1' }];
const metroFirst = await ev(`AlertsStore.checkAlerts().then(r => r.length)`);
check('Metro: la primera comprobacion tambien es linea base', metroFirst === 0, `${metroFirst}`);
mock.metro = [{ title: 'Incidencia', description: 'Metro M1' }, { title: 'Incidencia', description: 'Metro M2' }];
const metroSecond = await ev(`AlertsStore.checkAlerts().then(r => r.map(x => x.network + ':' + x.body))`);
check('Metro: un aviso nuevo se detecta', Array.isArray(metroSecond) && metroSecond.length === 1 && metroSecond[0] === 'metro:Metro M2', JSON.stringify(metroSecond));
check('Euskotren sin lineas favoritas no consulta avisos', !requested.some(u => u.includes('red=euskotren')));
check('Tranvia Bilbao sin lineas favoritas no consulta avisos', !requested.some(u => u.includes('red=tranvia-bilbao')));
check('Tranvia Vitoria sin lineas favoritas no consulta avisos', !requested.some(u => u.includes('red=tranvia-vitoria')));

mock.status = 500;
mock.bus = [A, B, extra(9)];
const failed = await ev(`AlertsStore.checkAlerts().then(r => r.length).catch(e => 'error')`);
mock.status = 200;
check('si la API falla no lanza error ni notifica', failed === 0, `${failed}`);
const afterFailure = await ev(`AlertsStore.checkAlerts().then(r => r.map(x => x.body))`);
check('tras un fallo, el aviso no se pierde (se detecta despues)', Array.isArray(afterFailure) && afterFailure.includes('Aviso extra 9'), JSON.stringify(afterFailure));

await send('Fetch.disable');
await ev(`AlertsStore.set('enabled', false)`);

await go('/?red=bus');
await ev("document.getElementById('menu-open').click()");
await sleep(500);
check('interruptor de avisos visible en el menu lateral', (await ev("!!document.getElementById('alerts-toggle') && document.getElementById('alerts-toggle').offsetParent !== null")) === true);
await ev("document.getElementById('alerts-toggle').click()");
check('activar avisos: queda marcado y muestra la nota', await waitTrue("document.getElementById('alerts-toggle').checked === true && !document.getElementById('alerts-note').hidden"), await ev("document.getElementById('alerts-note').textContent"));
check('activar avisos: se guarda como activado', (await ev(`AlertsStore.get('enabled')`)) === true);
await ev("document.getElementById('alerts-toggle').click()");
check('desactivar avisos: se guarda como desactivado', await waitTrue(`document.getElementById('alerts-toggle').checked === false && document.getElementById('alerts-note').hidden`));
check('desactivar avisos: enabled = false', (await ev(`AlertsStore.get('enabled')`)) === false);

await send('Browser.setPermission', { permission: { name: 'notifications' }, setting: 'denied', origin: BASE });
await go('/?red=bus');
await ev("document.getElementById('menu-open').click()");
await sleep(500);
await ev("document.getElementById('alerts-toggle').click()");
check('permiso denegado: no se activa y explica por que', await waitTrue("document.getElementById('alerts-toggle').checked === false && document.getElementById('alerts-note').textContent.includes('bloqueadas')"));
await send('Browser.grantPermissions', { permissions: ['notifications'], origin: BASE });

await go('/?red=bus');
await ev(`(async () => { await AlertsStore.set('enabled', true); await AlertsStore.resetBaseline(); await AlertsStore.setFavorites('bus', ['3513']); })()`);
const realAlerts = await ev(`fetch('/api/alerts?line=3513').then(r => r.json()).then(d => d.alerts.length).catch(() => -1)`);
if (!(realAlerts > 0) || !registrationId) {
    skip('service worker: notificacion al llegar un aviso nuevo', realAlerts > 0 ? 'no se obtuvo el registro del service worker' : 'la linea 3513 no tiene avisos en el feed real ahora mismo');
} else {
    await ev(`AlertsStore.checkAlerts()`);
    await ev(`(async () => { const seen = await AlertsStore.get('seen'); delete seen[Object.keys(seen)[0]]; await AlertsStore.set('seen', seen); })()`);
    await ev(`navigator.serviceWorker.ready.then(r => r.getNotifications()).then(list => list.forEach(n => n.close()))`);
    await send('ServiceWorker.dispatchPeriodicSyncEvent', { origin: BASE, registrationId, tag: 'line-alerts-check' });
    const shown = await waitTrue(`navigator.serviceWorker.ready.then(r => r.getNotifications()).then(list => list.length >= 1)`, 10000);
    check('service worker: un aviso nuevo genera una notificacion', shown);
    await ev(`navigator.serviceWorker.ready.then(r => r.getNotifications()).then(list => list.forEach(n => n.close()))`);
    await send('ServiceWorker.dispatchPeriodicSyncEvent', { origin: BASE, registrationId, tag: 'line-alerts-check' });
    await sleep(4000);
    const again = await ev(`navigator.serviceWorker.ready.then(r => r.getNotifications()).then(list => list.length)`);
    check('service worker: una segunda sincronizacion no repite la notificacion', again === 0, `${again}`);
}

await ev(`AlertsStore.set('enabled', false)`);
const bad = results.filter(r => r.startsWith('MAL')).length;
const uniqueProblems = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO avisos: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${results.filter(r => r.startsWith('SKIP')).length} SKIP, ${uniqueProblems.length} excepciones`);
for (const p of uniqueProblems.slice(0, 10)) console.log(' -', p);
ws.close();
shutdown(bad || uniqueProblems.length ? 1 : 0);
