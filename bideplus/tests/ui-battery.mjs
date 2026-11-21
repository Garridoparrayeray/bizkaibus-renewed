import { execFileSync } from 'node:child_process';
import { launchBrowser } from './browser.mjs';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9362);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const { browser, targets } = await launchBrowser(PORT, 'bide-tests-');
const shutdown = code => { try { browser.kill(); } catch (e) { } process.exit(code); };

if (!targets) { console.log('MAL no se pudo conectar con el navegador'); shutdown(1); }

const ws = new WebSocket(targets.find(x => x.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.onopen = r);
let id = 0;
const pending = new Map();
const problems = [];
let where = '';
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`[${where}] EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') problems.push(`[${where}] console.error ${(m.params.args[0]?.value || m.params.args[0]?.description || '').toString().slice(0, 140)}`);
    if (m.method === 'Network.responseReceived') {
        const { status, url } = m.params.response;
        if (status >= 400 && !/favicon|_vercel/.test(url)) problems.push(`[${where}] HTTP ${status} ${url.replace(BASE, '')}`);
    }
    if (m.method === 'Network.loadingFailed' && m.params.errorText !== 'net::ERR_ABORTED' && !/_vercel|unpkg|googleapis|gstatic|tile\.openstreetmap|cartocdn/.test(m.params.errorText)) {
        problems.push(`[${where}] carga fallida ${m.params.errorText}`);
    }
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
};
await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');

const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
    if (process.env.VERBOSE) console.log(line);
};
const skip = (name, why) => results.push(`SKIP ${name} — ${why}`);
const until = async (expr, max = 15000) => {
    const start = Date.now();
    while (Date.now() - start < max) {
        if ((await ev(expr)) === true) return true;
        await sleep(250);
    }
    return false;
};
const go = async (url, wait = 2800) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };
const search = async (q, wait = 3200) => {
    await ev(`(()=>{const i=document.getElementById('search-input');i.value='${q}';i.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('search-form').requestSubmit();})()`);
    await sleep(wait);
};

// Una muestra de cada red (tests/network-samples.php): una red nueva entra sola. Con APPS, solo de esas.
// Con ONLY_APPS=1 (y APPS) se prueban solo esas redes, sin las comprobaciones comunes (enlaces, idioma, menú…).
const sampleArgs = [new URL('./network-samples.php', import.meta.url).pathname];
if (process.env.APPS) sampleArgs.push(`--redes=${process.env.APPS}`);
const onlyApps = Boolean(process.env.ONLY_APPS && process.env.APPS);
const samples = JSON.parse(execFileSync(process.env.PHP_PATH || 'php', sampleArgs, { encoding: 'utf8' }));
const apps = Object.keys(samples).map((key) => ({ key, url: `/?red=${key}`, q: samples[key].fullQuery, panel: samples[key].panel, beyond: samples[key].beyondPublished, serviceDate: samples[key].serviceDate }));

const sizes = [{ n: 'movil', w: 390, h: 844, m: true }, { n: 'pc', w: 1366, h: 800, m: false }];
let sizeFilter = null;
if (process.env.SIZES) sizeFilter = process.env.SIZES.split(',');
let appFilter = null;
if (process.env.APPS) appFilter = process.env.APPS.split(',');
for (const size of (sizes.filter((x) => !sizeFilter || sizeFilter.includes(x.n)))) {
    await send('Emulation.setDeviceMetricsOverride', { width: size.w, height: size.h, deviceScaleFactor: 1, mobile: size.m });
    for (const a of apps) {
        if (appFilter && !appFilter.includes(a.key)) continue;
        const tag = `${a.key}/${size.n}`;
        where = tag;
        await go(a.url);
        let headerPosition = 'static';
        if (size.m) headerPosition = 'sticky';
        check(`${tag} carga con estilos`, (await ev("getComputedStyle(document.querySelector('header')).position")) === headerPosition);
        check(`${tag} sin scroll horizontal`, (await ev('document.documentElement.scrollWidth - document.documentElement.clientWidth')) <= 0);
        await search(a.q);
        check(`${tag} aparecen resultados de busqueda`, (await ev("document.querySelectorAll('#search-results .pill').length")) > 0);
        await ev("document.querySelector('#search-results .pill').click()");
        await sleep(3500);
        check(`${tag} se abre la ficha de la parada`, (await ev(`!document.getElementById('${a.panel}').hidden`)) === true);
        let favBtn = 'platform-favorite';
        if (a.panel === 'live-card') {
            favBtn = 'live-favorite';
        }
        await ev(`document.getElementById('${favBtn}').click()`);
        await sleep(600);
        check(`${tag} guardar favorito`, (await ev("document.querySelectorAll('#favorites-list li:not(#favorites-empty)').length")) === 1);
        await ev("document.getElementById('menu-open').click()");
        await sleep(400);
        check(`${tag} menu lateral abre`, (await ev("document.getElementById('side-menu').open")) === true);
        await ev("document.getElementById('menu-close').click()");
        await sleep(300);
        let pick = '#platform-timetable-lines .pill, #platform-timetable-link:not([hidden])';
        if (a.panel === 'live-card') {
            pick = '#live-timetable-link:not([hidden]), #live-more-list a, #live-more-list button';
        }
        await until(`!!document.querySelector('${pick}')`);
        // De noche, sin próximas salidas, la tarjeta lista las líneas de la parada; las que terminan en ella no tienen salidas
        // desde aquí (tabla vacía con razón). Se prueba cada candidata hasta dar con una que salga de la parada. A cada
        // tabla se le dan 15 s: en GitHub, con el feed de Renfe lento, el servidor de pruebas tarda en contestar.
        const candidates = await ev(`document.querySelectorAll('${pick}').length`);
        // Una red sin servicio hoy se mira con su primer día con servicio desde la primera línea: si no, cada candidata
        // esperaría en balde a que su tabla de hoy tenga filas (más de 10 min en una red sin servicio el domingo).
        let todayValue = '';
        const setServiceDate = `(()=>{const d=document.getElementById('filter-date');d.value='${a.serviceDate}';`
            + "d.dispatchEvent(new Event('change',{bubbles:true}));})()";
        for (let index = 0; index < Math.max(candidates, 1); index++) {
            for (let attempt = 0; attempt < 3; attempt++) {
                await ev(`(()=>{const c=document.querySelectorAll('${pick}')[${index}];if(c)c.click();})()`);
                if (await until("!document.getElementById('timetable-section').hidden", 5000)) break;
            }
            await ev("(()=>{document.getElementById('filter-hour-from').value='00:00';document.getElementById('filter-hour-from').dispatchEvent(new Event('change',{bubbles:true}));document.getElementById('filter-hour-to').value='23:59';document.getElementById('filter-hour-to').dispatchEvent(new Event('change',{bubbles:true}));})()");
            const shownDate = await ev("document.getElementById('filter-date').value");
            if (todayValue === '') todayValue = shownDate;
            if (a.serviceDate !== shownDate) await ev(setServiceDate);
            if (await until("document.querySelectorAll('#timetable-body tr').length > 0")) break;
        }
        check(`${tag} horario completo se abre`, (await ev("!document.getElementById('timetable-section').hidden")) === true);
        await sleep(500);
        const rows = await ev("document.querySelectorAll('#timetable-body tr').length");
        check(`${tag} tabla con filas de todo el dia`, rows > 0, `${rows} filas`);
        // El día de los horarios es el de Madrid, no el del navegador (el de GitHub va en UTC: de 0 a 1 h es otro día).
        const localToday = await ev("new Date().toLocaleDateString('sv-SE', { timeZone: 'Europe/Madrid' })");
        check(`${tag} la fecha por defecto del horario es hoy (hora de Madrid)`, todayValue === localToday, `${todayValue} vs ${localToday}`);
        // Hoy el aviso solo sale si el horario publicado ya terminó (bases del repositorio sin reconstruir): entonces debe citar una fecha pasada.
        const noteToday = await ev("(() => { const note = document.getElementById('timetable-note'); if (note.hidden) return ''; return note.textContent; })()");
        const publishedUntil = (noteToday.match(/(\d{2})\/(\d{2})\/(\d{4})/) || []).slice(1).reverse().join('-');
        let noteDetail = '';
        if (noteToday) noteDetail = `publicado hasta ${publishedUntil}`;
        check(`${tag} aviso de fecha sin publicar oculto hoy`, noteToday === '' || (publishedUntil !== '' && publishedUntil < localToday), noteDetail);
        // El primer día que el horario de esta red ya no cubre (Bilbobus publica hasta junio de 2027).
        await ev(`(()=>{const d=document.getElementById('filter-date');d.value='${a.beyond}';d.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        await until("!document.getElementById('timetable-note').hidden");
        check(`${tag} aviso de fecha sin publicar visible en el futuro`, (await ev("document.getElementById('timetable-note').hidden")) === false, (await ev("document.getElementById('timetable-note').textContent")).slice(0, 60));
        await ev(setServiceDate);
        let noteBack = "!document.getElementById('timetable-note').hidden";
        if (noteToday === '') noteBack = "document.getElementById('timetable-note').hidden";
        await until(noteBack);
        check(`${tag} aviso vuelve a ocultarse al volver a hoy`, (await ev("document.getElementById('timetable-note').hidden")) === (noteToday === ''));
        await ev("document.querySelector('#timetable-body tr').click()");
        await until("document.getElementById('vehicle-modal').open");
        check(`${tag} modal del tren abre`, (await ev("document.getElementById('vehicle-modal').open")) === true, await ev("document.getElementById('modal-line').textContent"));
        if (a.key === 'metro') {
            // «Sin seguimiento en vivo» también contiene «en vivo»: solo cuenta un tren que de verdad va en directo.
            const liveModal = await ev("(() => { const t = document.getElementById('modal-vehicle').textContent; return t.includes('en vivo') && t !== I18n.t('app.noLiveTracking'); })()");
            if (liveModal) {
                const pastBefore = await ev("document.querySelectorAll('#modal-stops .stop-past').length");
                // Se adelanta el reloj hasta pasada la próxima parada (de noche el tren puede tardar más de 10 min en llegar a ella).
                const nextMin = await ev("(() => { const li = [...document.querySelectorAll('#modal-stops li')].find((x) => !x.classList.contains('stop-past') && / · \\d+ min$/.test(x.textContent)); if (!li) return 10; return Number(li.textContent.match(/ · (\\d+) min$/)[1]); })()");
                const jumpMs = (Math.max(nextMin, 0) + 2) * 60000;
                await ev(`(()=>{window.__realNow = Date.now.bind(Date);Date.now=()=>window.__realNow()+${jumpMs};})()`);
                await sleep(11500);
                const pastAfter = await ev("document.querySelectorAll('#modal-stops .stop-past').length");
                check(`${tag} el recorrido abierto sigue el reloj (paradas pasadas ${pastBefore} -> ${pastAfter})`, pastAfter > pastBefore);
                await ev("Date.now = window.__realNow");
            } else {
                skip(`${tag} el recorrido abierto sigue el reloj`, 'el tren no va en directo ahora');
            }
        }
        await ev("document.getElementById('modal-close').click()");
        await sleep(300);
        if (a.key === 'bus') {
            await ev("document.getElementById('schedule-text-toggle').click()");
            await sleep(6000);
            const open = (await ev("document.getElementById('schedule-modal').open")) === true;
            const blocks = await ev("document.querySelectorAll('#schedule-modal-content .schedule-block').length");
            if (open && blocks > 0) {
                check(`${tag} horario oficial en texto`, true, `${blocks} bloques`);
                // Solo el periodo de hoy y los que vienen: ninguna fecha ya pasada y nada de años anteriores.
                const stale = await ev(`(() => {
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    const ends = [...document.querySelectorAll('#schedule-modal-content .schedule-dates')].map((node) => {
                        const dates = [...node.textContent.matchAll(/(\\d{1,2})\\/(\\d{1,2})\\/(\\d{4})/g)].map((m) => new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1])));
                        return dates[dates.length - 1];
                    });
                    return ends.filter((end) => end && end < today).length;
                })()`);
                check(`${tag} horario oficial sin periodos ya pasados`, stale === 0, `${stale} periodos terminados`);
            } else {
                skip(`${tag} horario oficial en texto`, 'feed externo sin datos o sin internet');
            }
            await ev("document.getElementById('schedule-modal-close').click()");
        }
        await ev("document.getElementById('legal-open').click()");
        await sleep(300);
        check(`${tag} aviso legal abre`, (await ev("document.getElementById('legal-panel').open")) === true);
        await ev("document.getElementById('legal-close').click()");
        await ev("document.getElementById('home-link').click()");
        await sleep(400);
        // Las demás apps más «Todas las apps»: tantas como redes.
        const cards = await ev("[...document.querySelectorAll('#network-switcher .net-card:not([hidden])')].length");
        const networks = await ev('BideNetworks.slugs.length');
        check(`${tag} selector de app muestra las demás apps y el menú`, cards === networks, `${cards} de ${networks}`);
        await ev('localStorage.clear()');
    }
}

// El resumen y la salida (con ONLY_APPS, justo después de las redes).
const finish = () => {
    const unique = [...new Set(problems)];
    const bad = results.filter(r => r.startsWith('MAL')).length;
    const ok = results.filter(r => r.startsWith('OK')).length;
    const skipped = results.filter(r => r.startsWith('SKIP')).length;
    console.log(results.join('\n'));
    console.log(`\nRESULTADO UI: ${ok} OK, ${bad} MAL, ${skipped} SKIP, ${unique.length} problemas de consola/red`);
    for (const p of unique.slice(0, 25)) console.log(' -', p);
    ws.close();
    let exitCode = 0;
    if (bad || unique.length) exitCode = 1;
    shutdown(exitCode);
};
if (onlyApps) finish();

where = 'deep';
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
// La estación de Euskotren, por su nombre: el operador renumera sus ids (Amara-Donostia era la 2581 y ahora es la 2802).
const amaraFound = ((await (await fetch(`${BASE}/api/search?q=amara&red=euskotren`)).json()).stops || []).find((stop) => /^Amara/.test(stop.name));
let amaraId = 'ES:Euskotren:StopPlace:2802:';
if (amaraFound) amaraId = amaraFound.id;
for (const [url, panel, name] of [
    ['/stops/4255', 'live-card', 'parada bus'],
    ['/stops/19?red=metro', 'platform-panel', 'estacion metro'],
    [`/stops/${amaraId}?red=euskotren`, 'platform-panel', 'estacion euskotren'],
    [`/stops/${encodeURIComponent(amaraId)}?red=euskotren`, 'platform-panel', 'estacion euskotren con el id codificado (enlace de Favoritos)'],
    ['/stops/ES:Euskotren:StopPlace:1468:?red=tranvia-bilbao', 'platform-panel', 'parada tranvia bilbao'],
    ['/stops/ES:Euskotren:StopPlace:1560:?red=tranvia-vitoria', 'platform-panel', 'parada tranvia vitoria'],
    ['/stops/13200?red=renfe', 'platform-panel', 'estacion renfe'],
    ['/lines/MB?red=metro', 'timetable-section', 'linea metro'],
    ['/lines/3516', 'timetable-section', 'linea bus'],
]) {
    await go(url, 4500);
    check(`enlace directo ${name}`, (await ev(`!document.getElementById('${panel}').hidden`)) === true && (await ev("getComputedStyle(document.querySelector('header')).position")) === 'sticky');
}

where = 'pantalla';
// La pantalla de TV con muchas salidas de destino largo: ninguna medida deja barra de desplazamiento.
const fakeRows = `(() => { const f = window.fetch; window.fetch = async (...a) => { const r = await f(...a); if (!/departures/.test(String(a[0]))) return r; const at = Math.floor(Date.now() / 1000) + 300; const j = { departures: [], platforms: [] }; for (let i = 0; i < 12; i++) j.departures.push({ lineId: 'L' + i, lineCode: 'A39' + i, headsign: 'Destino con un nombre muy largo número ' + i, tripKey: 'T' + i, scheduledTime: '12:00', etaMinutes: 5 + i, etaAt: at + i * 60, status: 'live', delayMinutes: 2, platformId: null }); return new Response(JSON.stringify(j), { status: 200, headers: { 'content-type': 'application/json' } }); }; })();`;
const fakeScript = await send('Page.addScriptToEvaluateOnNewDocument', { source: fakeRows });
for (const [w, h] of [[1920, 1080], [1280, 720], [1080, 1920]]) {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: false });
    await go(`/pantalla/${samples.bus.stopId}`, 3500);
    const fit = await ev('[document.documentElement.scrollHeight, window.innerHeight, document.querySelectorAll(".screen-row").length]');
    check(`pantalla ${w}x${h} sin desbordar`, fit[0] <= fit[1] && fit[2] > 0, `${fit[0]} de ${fit[1]} px, ${fit[2]} filas`);
}
// En el móvil o la tableta, con la barra del navegador a la vista, lo que se ve (innerHeight) es menos que 100vh.
const barScript = await send('Page.addScriptToEvaluateOnNewDocument', { source: `Object.defineProperty(window, 'innerHeight', { get: () => document.documentElement.clientHeight - 56 });` });
for (const [w, h] of [[390, 844], [820, 1180]]) {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: true });
    await go(`/pantalla/${samples.bus.stopId}`, 3500);
    const fit = await ev('[document.documentElement.scrollHeight, document.documentElement.clientHeight, document.querySelectorAll(".screen-row").length]');
    check(`pantalla en móvil ${w}x${h} con la barra del navegador: más de una fila y sin desbordar`, fit[0] <= fit[1] && fit[2] > 1, `${fit[0]} de ${fit[1]} px, ${fit[2]} filas`);
}
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: barScript.identifier });
await send('Emulation.setDeviceMetricsOverride', { width: 1080, height: 1920, deviceScaleFactor: 1, mobile: false });
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: fakeScript.identifier });

where = 'idioma';
await send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => { const f = window.fetch; window.fetch = async (...a) => { const r = await f(...a); if (/departures/.test(String(a[0]))) { const j = await r.clone().json(); if (!(j.departures || []).length) { const at = Math.floor(Date.now() / 1000) + 300; j.departures = [{ lineId: '3411', lineCode: 'A3411', lineName: 'A3411', headsign: 'Prueba', tripKey: '3411-0-0', scheduledTime: '00:00', etaMinutes: 5, etaAt: at, status: 'live', delayMinutes: 3, direction: null, platformId: null }]; } for (const d of (j.departures || [])) { d.status = 'live'; d.delayMinutes = 3; } return new Response(JSON.stringify(j), { status: 200, headers: { 'content-type': 'application/json' } }); } return r; }; })();` });
await go('/stops/4255', 4500);
const delayEs = await ev("document.getElementById('live-delay').textContent");
await ev("document.getElementById('lang-toggle').click()"); await sleep(600);
const delayEu = await ev("document.getElementById('live-delay').textContent");
check('el texto del retraso cambia de idioma sin recargar', delayEs !== '' && delayEu !== '' && delayEs !== delayEu, `${delayEs} -> ${delayEu}`);
await ev('localStorage.clear()');

where = 'menu';
await go('/', 2500);
check('menu Bide+ carga y tiene una ficha por red', (await ev("document.querySelectorAll('a.tile[data-app]').length === BideNetworks.slugs.length")) === true);
await ev("document.querySelector('[data-view-btn=list]').click()"); await sleep(600);
check('menu vista lista', (await ev("document.documentElement.getAttribute('data-view')")) === 'list');
await ev("document.querySelector('[data-view-btn=tiles]').click()"); await sleep(600);
check('menu vista fichas', (await ev("document.documentElement.getAttribute('data-view')")) === 'tiles');

where = 'miamor';
await go('/?red=metro&tema=miamor', 2800);
check('tema mi amor: la app normal con su capa de colores', (await ev("['style-app.css', 'style-miamor.css'].every(f => [...document.styleSheets].some(s => (s.href || '').endsWith(f)))")) === true);
await search('abando');
await ev("document.querySelector('#search-results .pill').click()"); await sleep(3500);
check('tema mi amor abre parada', (await ev("!document.getElementById('platform-panel').hidden")) === true);

where = '404';
for (const [u, nombre] of [['/stops/99999999', 'parada inexistente'], ['/nada-por-aqui', 'ruta desconocida']]) {
    const res = await fetch(BASE + u);
    check(`404 real: ${nombre}`, res.status === 404, `HTTP ${res.status}`);
}

finish();
