import { readFileSync } from 'node:fs';
import { launchBrowser } from './browser.mjs';

// El planificador del inicio, en castellano, euskera y francés (sin ningún texto en castellano): escribir origen y destino en la tarjeta «¿A dónde vas?» con
// las sugerencias debajo, buscar, las opciones debajo de la tarjeta (desplegar, etiquetas, línea de tiempo), los
// filtros y las redes, la pantalla del viaje elegido, «atrás», «Cómo llegar aquí» desde una parada y un enlace
// compartido. Con el directo simulado (/api/planner/live, interceptado): las etiquetas «En directo · N min de retraso» y
// «Según horario», «Solo en directo», un directo que falla, el transbordo perdido, que se para en segundo plano, un
// día con servicios mínimos (aviso, «puede no pasar» y primero lo que no depende solo del horario), Renfe Cercanías
// y un refuerzo (un metro que el horario no tiene) que sale primero, marcado «Refuerzo · en directo».
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
// error; «renfe» retrasa 3 min los trenes de Renfe y apunta sus tramos. Cuenta las peticiones.
const live = { mode: 'mixed', count: 0, renfeLegs: [], extra: null };
const madridDay = () => Math.floor(Date.parse(new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date()) + 'T00:00:00Z') / 86400000);
const liveBody = (sUrl) => {
    const aLegs = (new URL(sUrl).searchParams.get('legs') || '').split(',').filter(Boolean).map((sLeg) => sLeg.split('|').map(decodeURIComponent));
    let iDelay = 240;
    if (live.mode === 'late') iDelay = 1500;
    let sNetwork = 'metro';
    if (live.mode === 'renfe') {
        sNetwork = 'renfe';
        iDelay = 180;
        live.renfeLegs.push(...aLegs.filter((a) => a[0] === 'renfe'));
    }
    const updates = aLegs.filter((a) => a[0] === sNetwork).map((a) => ({ network: a[0], trip: a[4], day: madridDay(), delay: iDelay, cancelled: false }));
    let extras = [];
    if (live.mode === 'extra' && live.extra) extras = [live.extra];
    return JSON.stringify({ day: madridDay(), generated: Math.floor(Date.now() / 1000), updates, extras });
};
// /api/reduced: sin nada, salvo en «reduced», que da servicios mínimos hoy en las redes que no son el metro (y tarda
// un poco, para ver que las opciones salen antes y no se mueven al llegar).
const reducedBody = (sUrl) => {
    const sNetwork = new URL(sUrl).searchParams.get('red');
    const reduced = { today: false, days: [], sources: [], alert: null, cause: null, lines: null };
    if (live.mode === 'reduced' && sNetwork !== 'metro') {
        Object.assign(reduced, { today: true, days: [new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' }).format(new Date())], sources: ['alerts'], cause: 'strike' });
    }
    return JSON.stringify(reduced);
};
const REDUCED_DELAY_MS = 2500;
const OPTION_ORDER = "[...document.querySelectorAll('#route-options .route-option')].map((o) => o.dataset.rides).join('/')";
const fulfilLive = (params) => {
    let iCode = 200;
    let sBody;
    let iWait = 0;
    if (params.request.url.includes('/api/reduced')) {
        sBody = reducedBody(params.request.url);
        if (live.mode === 'reduced') iWait = REDUCED_DELAY_MS;
    } else {
        live.count += 1;
        sBody = liveBody(params.request.url);
        if (live.mode === 'fail') {
            iCode = 500;
            sBody = '{"error":"Internal error"}';
        }
    }
    setTimeout(async () => {
        if (iWait > 0) live.orderBefore = await ev(OPTION_ORDER);
        send('Fetch.fulfillRequest', {
            requestId: params.requestId, responseCode: iCode, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }],
            body: Buffer.from(sBody).toString('base64'),
        });
    }, iWait);
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

// Los textos en castellano que no se escriben igual en francés (de la app y de las pantallas del planificador), para
// ver que en francés no queda ninguno. Sin huecos ({n}) y de más de 6 letras: «Metro», «Bus»… se escriben igual.
const tableOf = (sCode, sStart, sEnd) => {
    const sBlock = sCode.slice(sCode.indexOf(sStart), sCode.indexOf(sEnd, sCode.indexOf(sStart) + 1));
    return Object.fromEntries([...sBlock.matchAll(/^\s*'((?:route|planner)\.[^']+)': '((?:[^'\\]|\\.)*)',$/gm)].map((m) => [m[1], m[2]]));
};
const sAppTexts = readFileSync(new URL('../js/i18n.js', import.meta.url), 'utf8');
const sPlannerEs = await (await fetch(BASE + '/planificador/pantallas/lang-es.js')).text();
const sPlannerFr = await (await fetch(BASE + '/planificador/pantallas/lang-fr.js').catch(() => null))?.text() || '';
const SPANISH = Object.assign(tableOf(sAppTexts, '        es: {', '        eu: {'), tableOf(sPlannerEs, 'export const', '};'));
const FRENCH = Object.assign(tableOf(sAppTexts, '        fr: {', '\n    };'), tableOf(sPlannerFr, 'export const', '};'));
const SPANISH_ONLY = Object.keys(SPANISH).map((sKey) => [sKey, SPANISH[sKey]])
    .filter(([sKey, sText]) => sText !== FRENCH[sKey] && !/[{}]/.test(sText) && sText.length > 6);
const spanishIn = (sText) => SPANISH_ONLY.filter(([, sSpanish]) => sText.includes(sSpanish)).map(([sKey]) => sKey);
const PLANNER_TEXT = "[...document.querySelectorAll('.planner, #route')].map((n) => n.innerText + ' ' + [...n.querySelectorAll('[placeholder], [aria-label], [title]')].map((e) => [e.getAttribute('placeholder'), e.getAttribute('aria-label'), e.getAttribute('title')].join(' ')).join(' ')).join(' ')";

// «Sal en N min» sigue el reloj aunque el directo no cambie y, pasada la salida, deja de contar. Con el viaje abierto, el
// reloj de la página se adelanta (window.__shiftClock): primero a unos 10 min de la salida, luego 2 min más y luego
// hasta después de salir. Al acabar, el reloj de verdad.
const LEAVE = "document.querySelector('#route-trip .route-hero-leave .route-hero-label').textContent + ' ' + document.querySelector('#route-trip .route-hero-big').textContent";
const leaveIn = async () => {
    const sLeave = await ev(LEAVE);
    if (typeof sLeave !== 'string' || !sLeave.startsWith('Sal en ')) return null;
    return parseInt(sLeave.slice('Sal en '.length), 10);
};
const checkLeaveTicks = async () => {
    await ev(`(() => {
        const Real = Date;
        let shift = 0;
        class Shifted extends Real {
            constructor(...args) {
                if (args.length === 0) {
                    super(Real.now() + shift);
                } else {
                    super(...args);
                }
            }
            static now() {
                return Real.now() + shift;
            }
        }
        window.__RealDate = Real;
        window.Date = Shifted;
        window.__shiftClock = (ms) => {
            shift += ms;
        };
        return true;
    })()`);
    // Lo que falta para salir: los minutos de «Sal en» o, con «Sal a las HH:MM», hasta esa hora de hoy o de mañana.
    await waitTrue("!!document.querySelector('#route-trip .route-hero-leave .route-hero-big')");
    let iLeft = await leaveIn();
    const aAt = String(await ev(LEAVE)).match(/(\d\d):(\d\d)$/);
    if (iLeft === null && !aAt) {
        check('es · «Sal en N min» sigue el reloj aunque no cambie el directo', false, `sin la hora de salida: ${await ev(LEAVE)}`);
        await ev('window.Date = window.__RealDate; true');
        return;
    }
    if (iLeft === null) {
        const [iHour, iMinute] = aAt.slice(1).map(Number);
        const iNow = await ev("(async () => (await import('/planificador/pantallas/query.js')).nowHere().seconds)()");
        iLeft = (((iHour * 60 + iMinute) - Math.floor(iNow / 60)) % 1440 + 1440) % 1440;
    }
    await ev(`window.__shiftClock(${(iLeft - 10) * 60000}); true`);
    // Hasta que se repinta con el reloj nuevo: si ya decía «Sal en N», el texto de antes también empieza así.
    const bTen = await waitTrue(`(() => { const s = ${LEAVE}; const n = parseInt(s.slice('Sal en '.length), 10); return s.startsWith('Sal en ') && n >= 9 && n <= 11; })()`, 20000);
    const iTen = await leaveIn();
    await ev('window.__shiftClock(2 * 60000); true');
    const bTicks = await waitTrue(`(() => { const n = parseInt((${LEAVE}).slice('Sal en '.length), 10); return n <= ${iTen} - 2 && n >= ${iTen} - 3; })()`, 20000);
    check('es · «Sal en N min» sigue el reloj aunque no cambie el directo', bTen && iTen >= 9 && iTen <= 11 && bTicks, `${iTen} → ${await ev(LEAVE)}`);
    await ev(`window.__shiftClock(${(iTen + 1) * 60000}); true`);
    check('es · pasada la salida ya no dice «Sal en»', await waitTrue(`(${LEAVE}).startsWith('Sal a las ')`, 20000), await ev(LEAVE));
    await ev('window.Date = window.__RealDate; true');
};

for (const [sLang, sTitle, sChoose, sTrip] of [['es', 'Cómo llegar', 'Elegir este viaje', 'Tu viaje a'], ['eu', 'Nola iritsi', 'Aukeratu bidaia hau', 'Zure bidaia'], ['fr', 'Itinéraire', 'Choisir ce trajet', 'Votre trajet vers']]) {
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
    if (sLang === 'fr') {
        const aTrip = spanishIn(await ev(PLANNER_TEXT));
        check('fr · el viaje y su mapa, sin textos en castellano', FRENCH['planner.trip.timeline'] !== undefined && aTrip.length === 0, aTrip.join(', '));
    }
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
        // En oscuro, los controles de Leaflet siguen los colores del mapa (--map-*), no el blanco de leaflet.css.
        const sControls = "(() => { const box = document.querySelector('.route-trip-map'); const css = (n) => getComputedStyle(n); const zoom = css(box.querySelector('.leaflet-control-zoom-in')); const credit = css(box.querySelector('.leaflet-control-attribution')); const token = css(box).getPropertyValue('--map-control').trim(); return [zoom.backgroundColor, zoom.color, credit.backgroundColor, token]; })()";
        await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'dark' }] });
        await sleep(500);
        const aDark = await ev(sControls);
        await send('Emulation.setEmulatedMedia', { features: [] });
        check('mapa del viaje: en oscuro, «+/−» y la atribución con los colores del mapa', Array.isArray(aDark) && aDark[3] === '#262d29'
            && aDark[0] === 'rgb(38, 45, 41)' && aDark[1] !== 'rgb(0, 0, 0)' && aDark[2] !== 'rgba(255, 255, 255, 0.8)', JSON.stringify(aDark));
        if (bBasemap) {
            check('mapa del viaje: los nombres, encima del recorrido y debajo de las paradas', await ev("(() => { const z = (s) => Number(getComputedStyle(document.querySelector('.route-trip-map .leaflet-' + s + '-pane')).zIndex); return z('overlay') < z('routeLabels') && z('routeLabels') < z('marker') && !!document.querySelector('.route-trip-map .leaflet-routeLabels-pane canvas'); })()"));
        }
        await checkLeaveTicks();
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
    if (sLang === 'fr') {
        await ev("document.getElementById('route-filters-open').click(); true");
        await waitTrue("document.getElementById('route-filters').open");
        const aOptions = spanishIn(await ev(PLANNER_TEXT + " + ' ' + document.getElementById('route-filters').innerText"));
        await ev("document.getElementById('route-filters-done').click(); true");
        check('fr · la tarjeta, las opciones y los filtros, sin textos en castellano', aOptions.length === 0, aOptions.join(', '));
    }
}

// ===== Directo simulado =====
// /api/reduced pasa por el service worker (caché de la API): sin saltárselo, el navegador no deja interceptarlo.
await send('Network.enable');
await send('Network.setBypassServiceWorker', { bypass: true });
await send('Fetch.enable', { patterns: [{ urlPattern: '*/api/planner/live*' }, { urlPattern: '*/api/reduced*' }] });
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
    check('directo · una opción «Con N de retraso» lleva algún tramo con retraso (no todo «Según horario»)', await ev(`[...document.querySelectorAll('#route-options .route-option')].every((card) => !/^Con .* de retraso$/.test(card.querySelector('.route-tags').textContent.trim()) || [...card.querySelectorAll('.route-step-live')].some((n) => /de retraso$/.test(n.textContent)))`));
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

    // Servicios mínimos hoy en las redes que no son el metro: las opciones salen sin esperarlo y, al llegar, se avisa,
    // lo que va con su horario «puede no pasar» y las opciones no cambian de orden.
    live.mode = 'reduced';
    await go(LIVE_SEARCH);
    check('servicios mínimos · las opciones salen sin esperar al servicio reducido', await waitTrue(optionsReady, PLANNER_MS));
    check('servicios mínimos · aviso encima de las opciones', await waitTrue("[...document.querySelectorAll('#route-notices .route-notice[data-kind=care] .route-notice-title')].some((n) => /^Hoy hay servicios mínimos en /.test(n.textContent))", 15000));
    check('servicios mínimos · las que dependen del horario de esa red llevan «Puede no pasar»', await ev("[...document.querySelectorAll('#route-options .route-tag[data-kind=care]')].some((n) => n.textContent === 'Puede no pasar')"));
    await sleep(500);
    check('servicios mínimos · las opciones no cambian de orden al llegar', typeof live.orderBefore === 'string' && live.orderBefore !== '' && (await ev(OPTION_ORDER)) === live.orderBefore);
    check('servicios mínimos · su tramo con horario va «Según horario · puede no pasar»', await ev(`${labels}.some((s) => s === 'Según horario · puede no pasar')`));

    // Renfe Cercanías (Desertu-Barakaldo → Abando): los tramos de Renfe piden su parada de la base, el código de la
    // línea (C1, C2) y la hora del horario; con el retraso simulado, «En directo · 3 min de retraso».
    live.mode = 'renfe';
    live.renfeLegs = [];
    const iRenfeBefore = live.count;
    await go('/planificador?de=parada&de_red=renfe&de_id=13400&de_nombre=Desertu-Barakaldo&a=parada&a_red=renfe&a_id=13200&a_nombre=Abando');
    await waitTrue(optionsReady, PLANNER_MS);
    if (!(await waitUntil(() => live.count > iRenfeBefore, 15000)) || live.renfeLegs.length === 0) {
        results.push('SALTA directo de Renfe: ningún tren sale en las próximas 2 h');
    } else {
        check('directo de Renfe · cada tramo con su parada, su línea y su hora', live.renfeLegs.every((a) => a[1] === '13400' && /^C\d$/.test(a[2]) && /^\d+$/.test(a[3]) && a[4] !== ''), JSON.stringify(live.renfeLegs.slice(0, 3)));
        check('directo de Renfe · las opciones lo enseñan', await waitTrue(`${labels}.some((s) => s === 'En directo · 3 min de retraso')`, 15000));
    }

    // Un refuerzo: un metro de Gobela a San Mamés que el horario no tiene, que sale 1 min después que el primero que da
    // el núcleo y llega 2 min antes. Sale primero, marcado, y el viaje elegido lo lleva.
    const first = await ev(`(async () => {
        const engine = await import('/planificador/pantallas/engine.js');
        const here = (await import('/planificador/pantallas/query.js')).nowHere();
        const answer = await engine.call({ op: 'plan', day: here.day, time: here.seconds, from: { network: 'metro', stop: '20' },
            to: { network: 'metro', stop: '10' } }, 'prueba');
        const ride = ((answer.options || [])[0] || { legs: [] }).legs.find((leg) => leg.kind === 'ride');
        if (!ride || ride.depart > here.seconds + 3600) return null;
        return { line: ride.line.id, depart: ride.depart, arrive: ride.arrive };
    })()`);
    if (!first) {
        results.push('SALTA refuerzo: no hay metro de Gobela a San Mamés en la próxima hora');
    } else {
        live.extra = { network: 'metro', line: first.line, trip: 'rt:prueba-refuerzo', headsign: 'San Mamés',
            stops: [{ stop: '20', arrive: first.depart + 60, depart: first.depart + 60 }, { stop: '10', arrive: first.arrive - 120, depart: first.arrive - 120 }] };
        live.mode = 'extra';
        await go('/planificador?de=parada&de_red=metro&de_id=20&de_nombre=Gobela&a=parada&a_red=metro&a_id=10&a_nombre=San%20Mam%C3%A9s');
        await waitTrue(optionsReady, PLANNER_MS);
        const sFirstLabel = "(document.querySelector('#route-options .route-option .route-step-live') || {}).textContent";
        check('refuerzo · sale primero y marcado «Refuerzo · en directo»', await waitTrue(`${sFirstLabel} === 'Refuerzo · en directo'`, 20000), await ev(labels));
        await ev("document.querySelector('.route-option .route-choose').click(); true");
        check('refuerzo · el viaje elegido lo lleva, marcado', await waitTrue("[...document.querySelectorAll('.route-ride-badge')].some((n) => n.textContent === 'Refuerzo · en directo')", 20000));
        // En el refresco siguiente su hora se mueve 1 min y medio (como la ETA del feed): sigue siendo el mismo viaje.
        live.extra.stops = live.extra.stops.map((stop) => ({ ...stop, arrive: stop.arrive + 90, depart: stop.depart + 90 }));
        const iAsked = live.count;
        const bRefreshed = await waitUntil(() => live.count > iAsked, 45000);
        await sleep(3000);
        check('refuerzo · con su hora movida en el refresco el viaje elegido sigue en él', bRefreshed && await waitTrue("[...document.querySelectorAll('.route-ride-badge')].some((n) => n.textContent === 'Refuerzo · en directo')", 10000));
        await ev('history.back(); true');
        await waitTrue("document.getElementById('route-trip').hidden");
        live.mode = 'mixed';
        live.extra = null;
    }

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

// «Accesible» con el metro: su ficha dice que todo es accesible (el GTFS no trae el dato), así que hay opciones
// accesibles, marcadas «Accesible según el operador», y ningún aviso de que no consta. Luego se apaga.
const ACCESSIBLE_TAGS = "[...document.querySelectorAll('#route-options .route-option')].map((o) => [...o.querySelectorAll('.route-tag')].map((n) => n.textContent))";
const NOTICES = "[...document.querySelectorAll('#route-notices .route-notice')].map((n) => n.textContent).join(' | ')";
await go('/planificador?de=parada&de_red=metro&de_id=20&de_nombre=Gobela&a=parada&a_red=metro&a_id=10&a_nombre=San%20Mam%C3%A9s');
await waitTrue(optionsReady, PLANNER_MS);
await ev("document.getElementById('route-filters-open').click(); true");
await waitTrue("document.getElementById('route-filters').open");
await ev("document.querySelector('[data-route-filter=accessible]').click(); document.getElementById('route-filters-done').click(); true");
await waitOptionsSettled();
const aAccessibleTags = await ev(ACCESSIBLE_TAGS);
check('accesible con el metro · todas las opciones lo son y las del metro dicen «Accesible según el operador»',
    aAccessibleTags.length > 0 && aAccessibleTags.every((a) => a.some((s) => s.startsWith('Accesible')))
    && aAccessibleTags.some((a) => a.includes('Accesible según el operador')), JSON.stringify(aAccessibleTags));
check('accesible con el metro · sin el aviso de que no consta', !/no consta|que consten|Sin constar/.test(await ev(NOTICES)),
    await ev(NOTICES));
await ev("document.getElementById('route-filters-open').click(); true");
await waitTrue("document.getElementById('route-filters').open");
await ev("document.querySelector('[data-route-filter=accessible]').click(); document.getElementById('route-filters-done').click(); true");
await waitOptionsSettled();

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
