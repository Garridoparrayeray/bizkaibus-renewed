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
// Sin el service worker: las peticiones a /api/ que bloquea la prueba no pueden salir por él.
await send('Network.enable');
await send('Network.setBypassServiceWorker', { bypass: true });
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

// Sin /api/ (robots.txt), la parada, la línea y la red siguen con su contenido del servidor y sin «sin conexión».
blockApi = true;
for (const url of ['/stops/2877', `/lines/${lineId}`, '/stops/13?red=metro', '/?red=metro']) {
    await ev('localStorage.clear(); true');
    await go(url, 4000);
    check(`${url} sin /api/: se ve el contenido del servidor`, await ev("(() => { const c = document.getElementById('page-content'); return !c.hidden && c.getBoundingClientRect().height > 100; })()"));
    check(`${url} sin /api/: con enlaces <a> a paradas o líneas`, (await ev("document.querySelectorAll('#page-content a[href*=\"/lines/\"], #page-content a[href*=\"/stops/\"]').length")) > 0);
    check(`${url} sin /api/: sin el aviso de «sin conexión»`, await ev("document.getElementById('net-banner').hidden"));
}
blockApi = false;

// Las listas de la app son enlaces de verdad; un clic abre dentro de la app y esconde el contenido de otra parada.
await go('/stops/2877', 3500);
await ev("(() => { const i = document.getElementById('search-input'); i.value = 'moyua'; i.dispatchEvent(new Event('input', { bubbles: true })); })(); true");
check('los resultados de búsqueda son <a href>', await waitTrue("[...document.querySelectorAll('#search-results a.pill')].some((a) => /^\\/stops\\/\\d+$/.test(a.getAttribute('href')))"));
await ev("document.querySelector('#search-results a.pill').click(); true");
check('el clic abre la parada en la app (pushState) y esconde el contenido de la anterior', await waitTrue("location.pathname !== '/stops/2877' && location.pathname.startsWith('/stops/') && !document.getElementById('live-card').hidden && document.getElementById('page-content').hidden"));
check('las líneas de la parada son <a href>', await waitTrue("document.querySelectorAll('#live-timetable-lines a[href^=\"/lines/\"], #live-more-list a[href^=\"/lines/\"], #live-timetable-link').length > 0"));

// Historial: abrir otra parada y cerrar la tarjeta añaden una entrada; «atrás» vuelve. La pestaña dice lo que se ve.
await ev("history.back(); true"); await sleep(2500);
check('«atrás» vuelve a la parada anterior', await waitTrue("location.pathname === '/stops/2877' && !document.getElementById('live-card').hidden"), await ev('location.pathname'));
await ev("history.forward(); true"); await sleep(2500);
check('al navegar dentro de la app, el título es el de la parada abierta', await waitTrue("location.pathname !== '/stops/2877' && document.title.startsWith(document.getElementById('live-headsign') ? '' : '') && document.title.includes('Horarios') && !document.title.includes('Intermodal')"), await ev('document.title'));
await ev("document.getElementById('live-close').click(); true"); await sleep(1000);
check('cerrar la tarjeta lleva a la red con una entrada nueva', (await ev('location.pathname + location.search')) === '/?red=bus');
await ev("history.back(); true"); await sleep(2500);
check('y «atrás» vuelve a abrir la parada', await waitTrue("location.pathname.startsWith('/stops/') && !document.getElementById('live-card').hidden"));

// Ctrl+clic en un enlace del contenido del servidor: otra pestaña, sin navegar en esta.
await go('/stops/2877', 3000);
await ev("window.__opened = []; window.open = (url) => { window.__opened.push(url); return null; }; true");
await ev("document.querySelector('#page-content a').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true, button: 0 })); true");
await sleep(800);
check('Ctrl+clic abre en otra pestaña y no navega en esta', (await ev("location.pathname === '/stops/2877' && window.__opened.length === 1 && window.__opened[0].includes('/lines/')")) === true, await ev('JSON.stringify(window.__opened)'));

// En una parada, lo más grande que se pinta pronto es el contenido del servidor (H1 y horario), no un aviso tardío.
await send('Page.addScriptToEvaluateOnNewDocument', { source: "window.__lcp = []; new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__lcp.push(e); }).observe({ type: 'largest-contentful-paint', buffered: true });" });
for (const url of ['/stops/171', '/stops/7?red=metro']) {
    await go(url, 5000);
    check(`${url}: el LCP es el contenido del servidor`, (await ev("(() => { const last = window.__lcp[window.__lcp.length - 1]; return !!last && !!last.element && !!last.element.closest('#page-content'); })()")) === true, await ev("(() => { const last = window.__lcp[window.__lcp.length - 1]; return last && last.element ? last.element.className || last.element.tagName : 'sin LCP'; })()"));
}

// El horario de hoy de una copia vieja (otro día) no se enseña.
const stale = await send('Page.addScriptToEvaluateOnNewDocument', { source: "const realDate = Date.prototype.toLocaleDateString; Date.prototype.toLocaleDateString = function (locale, options) { if (locale === 'sv-SE') return '2000-01-01'; return realDate.call(this, locale, options); };" });
blockApi = true;
await go('/stops/2877', 3500);
check('un horario que no es de hoy se esconde', (await ev("document.querySelector('#page-content [data-date]').hidden")) === true);
blockApi = false;
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: stale.identifier });

// El aviso de idioma no entra en la página hasta que ha cargado.
await ev('localStorage.clear(); true');
await send('Page.navigate', { url: BASE + '/stops/2877' });
await sleep(700);
check('el aviso de idioma no está mientras carga la página', (await ev("!document.querySelector('.bide-lang-offer')")) === true);
check('y sale después, en inglés', await waitTrue("(document.querySelector('.bide-lang-offer-switch') || {}).textContent === 'View in English'"));
await ev('localStorage.clear(); true');

for (const line of results) console.log(line);
const failed = results.filter(r => r.startsWith('MAL')).length;
console.log(`\nRESULTADO SEO en el navegador: ${results.length - failed}/${results.length} OK`);
let exitCode = 0;
if (failed) exitCode = 1;
shutdown(exitCode);
