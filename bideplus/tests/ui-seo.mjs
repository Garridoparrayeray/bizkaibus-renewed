import { launchBrowser } from './browser.mjs';

// Lo que ve un buscador que ejecuta JavaScript: el título y el idioma de la página no cambian tras el JS, aunque el
// navegador esté en otro idioma; y sin poder leer /api/ (robots.txt) la parada o la línea siguen con su contenido.
const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9376);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const { browser, targets } = await launchBrowser(PORT, 'bide-seo-');
const shutdown = code => { try { browser.kill(); } catch (e) { } process.exit(code); };

if (!targets) { console.log('MAL no se pudo conectar con el navegador'); shutdown(1); }

const ws = new WebSocket(targets.find(x => x.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.onopen = r);
let id = 0;
const pending = new Map();
let blockApi = false;
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    // Como un buscador que respeta «Disallow: /api/»: la petición no sale, pero hay red.
    if (m.method === 'Fetch.requestPaused') {
        const blocked = blockApi && new URL(m.params.request.url).pathname.startsWith('/api/');
        if (blocked) send('Fetch.failRequest', { requestId: m.params.requestId, errorReason: 'BlockedByClient' });
        else send('Fetch.continueRequest', { requestId: m.params.requestId });
    }
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
};
const waitTrue = async (expr, ms = 10000) => {
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
// El título y el idioma que da el servidor, sin JavaScript.
const served = async (url) => {
    const html = await (await fetch(BASE + url)).text();
    const title = (html.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
    const lang = (html.match(/<html lang="([a-z]+)"/) || [])[1] || '';
    return { title: title.replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"'), lang };
};

await send('Runtime.enable'); await send('Page.enable');
await send('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
await go('/?red=bus', 1500);
await ev('localStorage.clear(); true');
// Un buscador o un móvil en inglés, sin idioma elegido.
await send('Page.addScriptToEvaluateOnNewDocument', { source: "Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });" });

const sample = JSON.parse(await (await fetch(BASE + '/api/lines')).text());
const lineId = encodeURIComponent(sample.lines[0].id);
for (const url of ['/stops/2877', `/lines/${lineId}`, '/stops/13?red=metro', '/stops/2877?lang=eu', '/?red=renfe']) {
    const server = await served(url);
    // Sin idioma elegido: cada visita, como la primera de un buscador.
    await ev('localStorage.clear(); true');
    await go(url, 3500);
    const title = await ev('document.title');
    check(`${url}: el título no cambia tras el JS`, title === server.title, `${server.title} -> ${title}`);
    check(`${url}: el idioma no cambia tras el JS`, (await ev('document.documentElement.lang')) === server.lang);
}

// Si la persona cambia de idioma, la pestaña y la dirección pasan a las de ese idioma (?lang=).
const french = await served('/stops/2877?lang=fr');
await go('/stops/2877', 3000);
await ev("I18n.setLang('fr'); true");
check('al elegir francés, el título es el de la página en francés', await waitTrue(`document.title === ${JSON.stringify(french.title)}`), await ev('document.title'));
check('y la dirección lleva ?lang=fr', (await ev('location.search')) === '?lang=fr');
await ev('localStorage.clear(); true');

for (const line of results) console.log(line);
const failed = results.filter(r => r.startsWith('MAL')).length;
console.log(`\nRESULTADO SEO en el navegador: ${results.length - failed}/${results.length} OK`);
let exitCode = 0;
if (failed) exitCode = 1;
shutdown(exitCode);
