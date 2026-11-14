import { launchBrowser } from './browser.mjs';

// El planificador del inicio, en castellano y en euskera: escribir origen y destino en la tarjeta «¿A dónde vas?» con
// las sugerencias debajo, buscar, las opciones debajo de la tarjeta (desplegar, etiquetas, línea de tiempo), los
// filtros y las redes, la pantalla del viaje elegido, «atrás», «Cómo llegar aquí» desde una parada y un enlace
// compartido. Con el directo simulado (/api/planner/live, interceptado): las etiquetas «En directo · N min de retraso» y
// «Según horario», «Solo en directo», un directo que falla, el transbordo perdido, que se para en segundo plano y un
// día con servicios mínimos (aviso, «puede no pasar» y primero lo que no depende solo del horario).
// Necesita el planificador publicado (planificador/build/data): sin él, lo dice y no prueba nada.

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9378);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const PLANNER_MS = 90000;

const manifest = await fetch(BASE + '/planificador/build/data/manifest.json').catch(() => null);
if (!manifest || !manifest.ok) {
    console.log('SIN DATOS del planificador en /planificador/build/data: no se prueba el planificador del inicio');
    process.exit(0);
}

const { browser, targets } = await launchBrowser(PORT, 'bide-route-');
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
    if (m.method === 'Fetch.requestPaused') { fulfilLive(m.params); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (!r) return undefined;
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
};
const waitTrue = async (expr, ms = 10000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if ((await ev(expr)) === true) return true;
        await sleep(250);
    }
    return false;
};
// El directo simulado: «mixed» retrasa 4 min los metros y deja lo demás con su horario, «late» los retrasa 25 min (el
// transbordo se pierde), «reduced» es «mixed» con servicios mínimos hoy en las demás redes y «fail» responde con un
// error. Cuenta las peticiones.
const live = { mode: 'mixed', count: 0 };
const madridDay = () => Math.floor(Date.parse(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date()) + 'T00:00:00Z') / 86400000);
const liveBody = (sUrl) => {
    const aLegs = (new URL(sUrl).searchParams.get('legs') || '').split(',').filter(Boolean).map((sLeg) => sLeg.split('|').map(decodeURIComponent));
    let iDelay = 240;
    if (live.mode === 'late') iDelay = 1500;
    const updates = aLegs.filter((a) => a[0] === 'metro').map((a) => ({ network: a[0], trip: a[4], delay: iDelay, cancelled: false }));
    const reduced = {};
    if (live.mode === 'reduced') {
        const sToday = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date());
        (new URL(sUrl).searchParams.get('networks') || '').split(',').filter((sNet) => sNet && sNet !== 'metro').forEach((sNet) => { reduced[sNet] = [sToday]; });
    }
    return JSON.stringify({ day: madridDay(), generated: Math.floor(Date.now() / 1000), updates, reduced });
};
const fulfilLive = (params) => {
    live.count += 1;
    let iCode = 200;
    let sBody = liveBody(params.request.url);
    if (live.mode === 'fail') {
        iCode = 500;
        sBody = '{"error":"Internal error"}';
    }
    send('Fetch.fulfillRequest', {
        requestId: params.requestId, responseCode: iCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
        body: Buffer.from(sBody).toString('base64'),
    });
};
// Espera a que pasen QUIET_MS sin ninguna petición del directo (como mucho QUIET_MAX_MS).
const QUIET_MS = 3000;
const QUIET_MAX_MS = 20000;
const waitQuiet = async () => {
    const iStart = Date.now();
    let iSeen = live.count;
    let iSince = Date.now();
    while (Date.now() - iSince < QUIET_MS && Date.now() - iStart < QUIET_MAX_MS) {
        await sleep(250);
        if (live.count !== iSeen) {
            iSeen = live.count;
            iSince = Date.now();
        }
    }
};
const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};
const go = async (url, wait = 2500) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };
const shown = sel => `(() => { const n = document.querySelector(${JSON.stringify(sel)}); return !!n && !n.closest('[hidden]') && n.getBoundingClientRect().height > 0; })()`;
const text = sel => ev(`(document.querySelector(${JSON.stringify(sel)}) || {}).textContent || ''`);
const typeInto = async (sel, value) => {
    await ev(`(() => { const n = document.querySelector(${JSON.stringify(sel)}); n.focus(); n.value = ''; return true; })()`);
    await send('Input.insertText', { text: value });
};
// La primera sugerencia de lo escrito (no la de «Tu ubicación» que sale con el campo vacío).
const firstSuggestion = (list, word) => `[...document.querySelectorAll('${list} li[role=option]:not([aria-disabled])')].find((li) => li.textContent.includes('${word}'))`;
const optionsReady = "document.querySelectorAll('#route-options .route-option').length > 0 && document.getElementById('route-searching').hidden";
// Las opciones ya pintadas y quietas: la búsqueda que lanza cerrar los filtros puede empezar un poco después, y tocar
// una opción que se va a repintar no abre nada. Quietas es la misma primera opción durante SETTLED_MS.
const SETTLED_MS = 1500;
const waitOptionsSettled = async () => {
    const iStart = Date.now();
    let iSince = Date.now();
    await ev('window.__routeFirst = null; true');
    while (Date.now() - iStart < PLANNER_MS) {
        const bSame = await ev(`(() => { const o = document.querySelector('#route-options .route-option'); const b = ${optionsReady} && o === window.__routeFirst; window.__routeFirst = o; return b; })()`);
        if (!bSame) {
            iSince = Date.now();
        } else if (Date.now() - iSince >= SETTLED_MS) {
            return true;
        }
        await sleep(250);
    }
    return false;
};

await send('Runtime.enable'); await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });

// Origen y destino escritos (sin «Tu ubicación», que pediría permiso): Gobela → Gernika.
const searchFromCard = async (sLang) => {
    await go('/');
    await ev(`localStorage.clear(); localStorage.setItem('bide_splash_seen', '1'); localStorage.setItem('bide_lang', '${sLang}'); true`);
    await go('/');
    check(`${sLang} · la tarjeta «¿A dónde vas?» está en el inicio, encima de «Tus redes»`,
        await ev("(() => { const card = document.querySelector('.planner'); return !!card && !!(card.compareDocumentPosition(document.getElementById('tr')) & Node.DOCUMENT_POSITION_FOLLOWING); })()"));
    check(`${sLang} · sin buscar no hay opciones`, (await ev(shown('#route'))) === false);
    await typeInto('#from-input', 'Gobela');
    check(`${sLang} · el origen sugiere debajo del campo`, await waitTrue(`!!${firstSuggestion('#from-list', 'Gobela')}`, PLANNER_MS));
    await ev(`${firstSuggestion('#from-list', 'Gobela')}.click(); true`);
    await typeInto('#to-input', 'Gernika');
    check(`${sLang} · el destino sugiere en todas las redes, debajo del campo`, await waitTrue(`!!${firstSuggestion('#to-list', 'Gernika')}`, PLANNER_MS));
    check(`${sLang} · con sugerencias se esconde el botón de intercambiar`, (await ev(shown('.planner-swap'))) === false);
    await ev(`${firstSuggestion('#to-list', 'Gernika')}.click(); true`);
    await ev("document.querySelector('.planner-go').click(); true");
    check(`${sLang} · las opciones salen debajo de la tarjeta, en el inicio`, await waitTrue(optionsReady, PLANNER_MS) && (await ev("location.pathname")) === '/');
};

for (const [sLang, sTitle, sChoose, sTrip] of [['es', 'Cómo llegar', 'Elegir este viaje', 'Tu viaje a'], ['eu', 'Nola iritsi', 'Aukeratu bidaia hau', 'Zure bidaia']]) {
    await searchFromCard(sLang);
    check(`${sLang} · título «${sTitle}» con Filtros y compartir`, (await text('#route-title')) === sTitle && (await ev(shown('#route-filters-open'))) && (await ev(shown('#route-share'))));
    check(`${sLang} · hasta cinco opciones`, await ev("document.querySelectorAll('#route-options .route-option').length <= 5"));
    check(`${sLang} · la primera, desplegada con su línea de tiempo, el coste y «${sChoose}»`, await ev(`(() => { const o = document.querySelector('.route-option'); return o.classList.contains('is-open') && o.querySelectorAll('.route-step').length >= 2 && !!o.querySelector('.route-cost') && o.querySelector('.route-choose').textContent === ${JSON.stringify(sChoose)}; })()`));
    check(`${sLang} · cada opción con horas, duración y pastillas de línea`, await ev("[...document.querySelectorAll('.route-option')].every((o) => /\\d\\d:\\d\\d → \\d\\d:\\d\\d/.test(o.querySelector('.route-option-times').textContent) && o.querySelectorAll('.route-line').length > 0)"));
    await ev("document.querySelectorAll('.route-option-head')[1].click(); true");
    check(`${sLang} · otra opción se despliega al tocarla`, await ev("document.querySelectorAll('.route-option')[1].classList.contains('is-open') && document.querySelectorAll('.route-option-head')[1].getAttribute('aria-expanded') === 'true'"));
    check(`${sLang} · la dirección guarda la búsqueda`, /[?&]de=/.test(await ev('location.search')) && /[?&]a_nombre=/.test(await ev('location.search')));

    await ev("document.getElementById('route-filters-open').click(); true");
    check(`${sLang} · Filtros sube como hoja`, await waitTrue("document.getElementById('route-filters').open"));
    await ev("document.querySelector('[data-route-filter=margin]').click(); true");
    check(`${sLang} · «Transbordos con margen» se enciende y cuenta en el botón`, (await ev("document.querySelector('[data-route-filter=margin]').getAttribute('aria-checked')")) === 'true' && (await text('#route-filters-count')) === '1');
    await ev("document.querySelector('[data-route-filter=margin]').click(); document.getElementById('route-networks-open').click(); true");
    check(`${sLang} · Redes: tus redes y las demás, sin Villavesas`, await waitTrue(shown('#route-networks-yours .route-check')) && (await ev("![...document.querySelectorAll('.route-check-name')].some((n) => /Villaves/.test(n.textContent))")));
    await ev("document.querySelector('#route-networks-others .route-check').click(); true");
    check(`${sLang} · quitar una red la marca y lo cuenta`, (await ev("document.querySelector('#route-networks-others .route-check').getAttribute('aria-checked')")) === 'false' && (await text('#route-filters-count')) === '1');
    await ev("document.getElementById('route-networks-all').click(); document.getElementById('route-networks-done').click(); document.getElementById('route-filters-done').click(); true");
    check(`${sLang} · «Ver las opciones» cierra la hoja`, await waitTrue("!document.getElementById('route-filters').open"));
    await waitOptionsSettled();

    await ev("document.querySelector('.route-option.is-open .route-choose').click(); true");
    check(`${sLang} · «${sChoose}» abre la pantalla del viaje`, await waitTrue(`${shown('#route-trip .route-hero')} && document.getElementById('route-trip-title').textContent.startsWith(${JSON.stringify(sTrip)})`));
    check(`${sLang} · el viaje: salida, llegada, reparto y cada vehículo con su red`, await ev("!!document.querySelector('.route-hero-big') && /\\d\\d:\\d\\d/.test(document.querySelector('.route-hero-clock').textContent) && document.querySelectorAll('.route-split-part').length > 1 && document.querySelectorAll('.route-ride').length > 0"));
    if (sLang === 'es') {
        // Sin el mapa base (lo hace el despliegue: .github/scripts/mapa-base.sh), el fondo son las teselas de OSM.
        const bBasemap = (await fetch(BASE + '/map-data/bizkaia.pmtiles', { method: 'HEAD' }).catch(() => null))?.ok;
        let sTiles = "document.querySelectorAll('.route-trip-map canvas.leaflet-tile').length > 0 && !document.querySelector('.route-trip-map img.leaflet-tile')";
        let sBase = 'el mapa base propio (PMTiles)';
        if (!bBasemap) {
            sTiles = "!!document.querySelector('.route-trip-map img.leaflet-tile')";
            sBase = 'las teselas de OSM (no hay PMTiles)';
        }
        check(`mapa del viaje: con el viaje dibujado y de fondo ${sBase}`,
            await waitTrue(`!!document.querySelector('.route-trip-map .leaflet-overlay-pane path') && ${sTiles}`, PLANNER_MS));
        check('mapa del viaje: atribución «© OpenStreetMap»', /© OpenStreetMap/.test(await text('.route-trip-map .leaflet-control-attribution')));
        check('mapa del viaje: entre el resumen y los tramos', await ev("(() => { const m = document.querySelector('.route-trip-map'); return m.previousElementSibling.classList.contains('route-hero') || m.previousElementSibling.classList.contains('route-notice'); })()"));
        check('mapa del viaje: fuera de Bizkaia, las teselas de siempre', await ev(`(async () => {
            const { addBasemap } = await import('/js/route/basemap.js');
            const box = document.createElement('div');
            box.className = 'route-trip-map';
            document.body.append(box);
            const bounds = L.latLngBounds([[40.41, -3.71], [40.42, -3.70]]);
            const map = L.map(box).fitBounds(bounds);
            await addBasemap(L, map, bounds);
            const bRaster = Object.values(map._layers).some((layer) => layer instanceof L.TileLayer && !!layer._url);
            map.remove();
            box.remove();
            return bRaster;
        })()`));
    }
    check(`${sLang} · «Empezar» lleva a la parada donde subes`, /^\/stops\/.+\?red=/.test(await ev("document.querySelector('.route-start-button').getAttribute('href')")));
    check(`${sLang} · el viaje queda en la dirección`, /[?&]viaje=/.test(await ev('location.search')));
    await ev('history.back(); true');
    check(`${sLang} · «atrás» vuelve a las opciones`, await waitTrue(`document.getElementById('route-trip').hidden && ${shown('#route-options .route-option')}`));
}

// ===== Directo simulado =====
await send('Fetch.enable', { patterns: [{ urlPattern: '*/api/planner/live*' }] });
const LIVE_SEARCH = '/planificador?de=parada&de_red=metro&de_id=20&de_nombre=Gobela&a_texto=Gernika';
const LABELS = /^(En directo · (\d+ min de retraso|\d+ min adelantado|a su hora|cancelado)|Según horario)$/;
const labels = "[...document.querySelectorAll('#route-options .route-step-live')].map((n) => n.textContent)";
const waitUntil = async (test, ms) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if (test()) return true;
        await sleep(250);
    }
    return false;
};
// Busca con el directo simulado en ese modo: true si se ha pedido el directo.
const liveSearch = async (sMode) => {
    live.mode = sMode;
    const iBefore = live.count;
    await go(LIVE_SEARCH);
    await waitTrue(optionsReady, PLANNER_MS);
    return waitUntil(() => live.count > iBefore, 15000);
};
await ev("localStorage.setItem('bide_lang', 'es'); true");
if (!(await liveSearch('mixed'))) {
    results.push('SALTA directo: ninguna opción sale en las próximas 2 h, no se pide el directo');
} else {
    check('directo · las opciones piden el directo de sus vehículos y lo enseñan', await waitTrue(`${labels}.some((s) => s === 'En directo · 4 min de retraso')`, 15000));
    check('directo · lo que no trae dato va «Según horario» y no hay otras etiquetas', await ev(`${labels}.every((s) => ${LABELS}.test(s)) && ${labels}.some((s) => s === 'Según horario')`));
    await ev("document.getElementById('route-filters-open').click(); document.querySelector('[data-route-filter=liveOnly]').click(); document.getElementById('route-filters-done').click(); true");
    await sleep(500);
    await waitTrue(optionsReady, PLANNER_MS);
    await sleep(1500);
    const bNone = await ev("[...document.querySelectorAll('.route-notice-title')].some((n) => n.textContent === 'Ahora ninguna va entera en directo')");
    check('directo · «Solo en directo» deja las que van enteras en directo o lo dice', bNone || (await ev(`${labels}.every((s) => s.startsWith('En directo'))`)));
    await ev("document.getElementById('route-filters-open').click(); document.querySelector('[data-route-filter=liveOnly]').click(); document.getElementById('route-filters-done').click(); true");
    await sleep(500);
    await waitTrue(optionsReady, PLANNER_MS);

    // Con el viaje elegido, un retraso de 25 min en el metro hace perder el transbordo: se dice y se da el plan B.
    const iMetroFirst = await ev("[...document.querySelectorAll('.route-option')].findIndex((o) => { const s = [...o.querySelectorAll('.route-step[data-mark=ride] .route-step-sub')].map((n) => n.textContent); return s.length > 1 && /^Metro/.test(s[0]) && !/^Metro/.test(s[1]); })");
    if (iMetroFirst < 0) {
        results.push('SALTA directo: ninguna opción cambia del metro a otra red');
    } else {
        live.mode = 'late';
        await ev(`document.querySelectorAll('.route-option .route-choose')[${iMetroFirst}].click(); true`);
        check('directo · con el retraso se pierde el transbordo y lo dice con el plan B', await waitTrue("[...document.querySelectorAll('#route-trip .route-notice-title')].some((n) => n.textContent === 'Con el retraso de ahora pierdes un transbordo') && /Coges el de las/.test(document.querySelector('#route-trip .route-notice').textContent)", 20000));
        check('directo · el vehículo retrasado lo dice en el viaje', await ev("[...document.querySelectorAll('.route-ride-badge')].some((n) => n.textContent === 'En directo · 25 min de retraso')"));
        await ev('history.back(); true');
        await waitTrue("document.getElementById('route-trip').hidden");
    }

    // En segundo plano no se pide nada; al volver, enseguida. Antes, que acabe lo que se pidió con la pestaña a la vista
    // (con el servidor local, la respuesta y la búsqueda que la sigue pueden tardar unos segundos).
    live.mode = 'mixed';
    await waitQuiet();
    await ev("Object.defineProperty(document, 'hidden', { configurable: true, get: () => window.__hidden === true }); window.__hidden = true; document.dispatchEvent(new Event('visibilitychange')); true");
    const iHidden = live.count;
    await sleep(32000);
    check('directo · en segundo plano no se refresca', live.count === iHidden, `${live.count - iHidden} peticiones`);
    await ev("window.__hidden = false; document.dispatchEvent(new Event('visibilitychange')); true");
    check('directo · al volver a la pestaña se refresca', await waitUntil(() => live.count > iHidden, 8000));

    // Servicios mínimos hoy en las redes que no son el metro: se avisa, lo que va con su horario «puede no pasar» y van
    // primero las opciones que no dependen solo de su horario.
    check('servicios mínimos · se pide el directo', await liveSearch('reduced'));
    check('servicios mínimos · aviso encima de las opciones', await waitTrue("[...document.querySelectorAll('#route-notices .route-notice[data-kind=care] .route-notice-title')].some((n) => /^Hoy hay servicios mínimos en /.test(n.textContent))", 15000));
    const aMarks = await ev("[...document.querySelectorAll('#route-options .route-option')].map((o) => [...o.querySelectorAll('.route-tag[data-kind=care]')].some((n) => n.textContent === 'Puede no pasar'))");
    check('servicios mínimos · las que dependen del horario de esa red llevan «Puede no pasar» y van detrás', Array.isArray(aMarks) && aMarks.indexOf(true) !== -1 && aMarks.slice(aMarks.indexOf(true)).every(Boolean), JSON.stringify(aMarks));
    check('servicios mínimos · su tramo con horario va «Según horario · puede no pasar»', await ev(`${labels}.some((s) => s === 'Según horario · puede no pasar')`));

    // Si el directo falla, todo sigue con el horario, sin avisos de error.
    check('directo · si falla, se pide igual y las opciones salen', await liveSearch('fail'));
    await sleep(1500);
    check('directo · si falla, todo va «Según horario» y sin avisos de error', await ev(`${labels}.length > 0 && ${labels}.every((s) => s === 'Según horario') && !document.querySelector('#route-notices .route-notice[data-kind=serious]')`));
}
await send('Fetch.disable');

// «Cómo llegar aquí» o «Salir desde aquí» (js/planner-link.js) y la dirección vieja /planificador llevan al inicio,
// que rellena la tarjeta y busca.
await go('/planificador?de=parada&de_red=metro&de_id=20&de_nombre=Gobela&a_texto=Gernika');
check('/planificador lleva al inicio con su consulta y busca', (await ev('location.pathname')) === '/' && await waitTrue(optionsReady, PLANNER_MS));

// Compartir las opciones copia un enlace (#v1.…) que se abre en el inicio con ellas.
await ev("navigator.clipboard.writeText = (t) => { window.__copied = t; return Promise.resolve(); }; navigator.share = undefined; document.getElementById('route-share').click(); true");
check('compartir copia el enlace', await waitTrue("typeof window.__copied === 'string' && window.__copied.includes('/#v1.')"));
const sCopied = await ev('window.__copied');
await send('Page.navigate', { url: sCopied });
await sleep(2500);
check('el enlace recibido enseña sus opciones', await waitTrue(`${shown('#route-options .route-option')} && document.getElementById('route-filters-open').hidden`, PLANNER_MS));

const bad = results.filter(r => r.startsWith('MAL')).length;
const unique = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO planificador del inicio: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${unique.length} excepciones`);
for (const p of unique.slice(0, 10)) console.log(' -', p);
ws.close();
let exitCode = 0;
if (bad || unique.length) exitCode = 1;
shutdown(exitCode);
