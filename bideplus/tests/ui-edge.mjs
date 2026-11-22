import http from 'node:http';
import { launchBrowser } from './browser.mjs';

// Casos límite de la app de una red: una respuesta que llega tarde al cambiar de parada, la hora de Madrid con el
// móvil en otra zona o pasada la medianoche (ficha del viaje, viaje de mañana, filtro del horario y /pantalla), lo que
// oye un lector de pantalla (minutos, favorito, cerrar y filas del horario), los andenes y «Cerca de mí» en euskera,
// inglés y francés, los nombres del mapa de la red como texto, cancelar un aviso de llegada sin conexión y el service
// worker con el servidor fallando (copias viejas del servicio reducido y del horario, «Vaciar caché y actualizar» y la
// instalación con un 503). Lo del service worker va por un proxy propio (PROXY_PORT) delante de BASE_URL, que hace
// fallar unas rutas o corta la conexión.

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9381);
const PROXY_PORT = Number(process.env.PROXY_PORT || 8217);
const PROXY = `http://localhost:${PROXY_PORT}`;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const { browser, targets } = await launchBrowser(PORT, 'bide-edge-');
const shutdown = (code) => { try { browser.kill(); } catch (e) { } process.exit(code); };
if (!targets) { console.log('MAL no se pudo conectar con el navegador'); shutdown(1); }

const ws = new WebSocket(targets.find((x) => x.type === 'page').webSocketDebuggerUrl);
await new Promise((r) => ws.onopen = r);
let id = 0;
const pending = new Map();
const problems = [];
// Lo que responde la prueba en vez del servidor: { match, delay, fail, body (o una función de la dirección), seen }.
let rules = [];
const answer = async (params) => {
    const requestId = params.requestId;
    const rule = rules.find((r) => r.match.test(params.request.url));
    if (!rule) { send('Fetch.continueRequest', { requestId }); return; }
    if (rule.seen) rule.seen.push(params.request.url);
    if (rule.delay) await sleep(rule.delay);
    if (rule.fail) { send('Fetch.failRequest', { requestId, errorReason: 'InternetDisconnected' }); return; }
    if (rule.body === undefined) { send('Fetch.continueRequest', { requestId }); return; }
    let body = rule.body;
    if (typeof body === 'function') body = await body(params.request.url);
    send('Fetch.fulfillRequest', { requestId, responseCode: 200, responseHeaders: [{ name: 'Content-Type', value: 'application/json' }], body: Buffer.from(JSON.stringify(body)).toString('base64') });
};
ws.onmessage = (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Fetch.requestPaused') { answer(m.params); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
};
const send = (method, params = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async (expr) => {
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
const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};
const go = async (url, wait = 2500) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };

// La hora de Madrid de un instante y el instante de una hora de Madrid de hoy («23:38»).
const madridParts = (ms) => {
    const parts = {};
    new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
        .formatToParts(new Date(ms)).forEach((part) => { parts[part.type] = part.value; });
    return parts;
};
const madridDate = (ms) => { const p = madridParts(ms); return `${p.year}-${p.month}-${p.day}`; };
const madridHm = (ms) => { const p = madridParts(ms); return `${p.hour}:${p.minute}`; };
const madridToday = (hm) => {
    const guess = Date.parse(`${madridDate(Date.now())}T${hm}:00Z`);
    const p = madridParts(guess);
    return guess - (Date.parse(`${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:00Z`) - guess);
};

// El reloj de la página, adelantado o atrasado (los segundos siguen corriendo), para probar la noche sin esperarla.
// window.__shiftClock(ms) lo mueve después sin recargar.
const clockSource = (shift) => `(() => {
    const Real = Date;
    let shift = ${shift};
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
    window.Date = Shifted;
    window.__shiftClock = (ms) => {
        shift += ms;
    };
})();`;
let clockScript = null;
let clockShift = 0;
const setClock = async (at) => {
    if (clockScript) await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: clockScript });
    clockScript = null;
    clockShift = 0;
    if (at === null) return;
    clockShift = at - Date.now();
    clockScript = (await send('Page.addScriptToEvaluateOnNewDocument', { source: clockSource(clockShift) })).identifier;
};
const pageNow = () => Date.now() + clockShift;

// Salidas de prueba, con la misma forma que /api/stops/{id}/departures.
const departure = (lineCode, headsign, tripKey, minutes, extra = {}) => ({
    lineId: 3136, lineCode, lineName: lineCode, headsign, tripKey, unscheduled: false, scheduledTime: madridHm(pageNow() + minutes * 60000),
    etaMinutes: minutes, etaAt: Math.round(pageNow() / 1000) + minutes * 60, status: 'live', atOrigin: false, tomorrow: false,
    delayMinutes: 0, direction: null, platformId: null, passedTime: null, reason: null, ...extra,
});
const departures = (stop, list) => ({ stop, platforms: [], directionLabels: null, departures: list });
const vehicle = (status) => ({
    lineCode: 'A3451', lineName: 'Bilbao-Mungia', headsign: 'Bilbao', status, vehicleRef: null, awaitingVehicle: false, delayMinutes: null, feedTimes: false,
    stops: [{ stopId: 1, name: 'Primera', scheduledTime: '06:26', etaMinutes: null, etaAt: null, isPast: status === 'finished', isCurrent: false }],
});

await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
// Lo que contesta la prueba no puede pasar por el service worker (no se dejaría interceptar) ni por la caché HTTP.
await send('Network.setBypassServiceWorker', { bypass: true });
await send('Network.setCacheDisabled', { cacheDisabled: true });
await send('Fetch.enable', { patterns: [{ urlPattern: '*/api/*' }, { urlPattern: '*/map-data/*' }] });
await go('/?red=bus');
// Sin elegir nada la app se guarda los horarios de todas las redes: aquí no hacen falta.
await ev("localStorage.setItem('bide_splash_seen', '1'); localStorage.setItem('bide-offline-chosen', '1'); true");

// ===== Una respuesta que llega tarde no pisa la parada que se ve =====
// Elegir una parada es lo mismo desde el buscador, un favorito o «atrás»: aquí, con el historial.
const openStop = (stopId) => ev(`(() => { const state = { view: 'stop', id: ${JSON.stringify(stopId)} }; history.pushState(state, '', '/stops/${stopId}'); dispatchEvent(new PopStateEvent('popstate', { state })); return true; })()`);
const CARD = "({ url: location.pathname, line: document.getElementById('live-line').textContent, stop: document.getElementById('live-headsign').textContent, history: history.length })";
const STOP_A = { id: 356, name: 'La Casilla (Autonomía 45)' };
const STOP_B = { id: 1137, name: 'Catalina Gibaja 40' };
const departuresA = () => departures(STOP_A, [departure('AAAA', 'Destino A', '3136-1-1', 5)]);
const departuresB = () => departures(STOP_B, [departure('BBBB', 'Destino B', '3136-2-2', 7)]);
rules = [
    { match: /\/api\/stops\/356(\?|$)/, delay: 3000 },
    { match: /\/api\/stops\/356\/departures/, body: departuresA },
    { match: /\/api\/stops\/1137\/departures/, body: departuresB },
];
await go('/?red=bus');
await openStop(STOP_A.id);
await sleep(300);
await openStop(STOP_B.id);
await waitTrue("document.getElementById('live-line').textContent === 'BBBB · Destino B'");
let before = await ev(CARD);
await sleep(4000);
let after = await ev(CARD);
check('parada: si la ficha de la anterior llega tarde, no se vuelve a ella ni se toca el historial', after.url === '/stops/1137' && after.line === 'BBBB · Destino B' && after.history === before.history, JSON.stringify(after));

rules = [
    { match: /\/api\/stops\/356\/departures/, delay: 3000, body: departuresA },
    { match: /\/api\/stops\/1137\/departures/, body: departuresB },
];
await go('/?red=bus');
await openStop(STOP_A.id);
await waitTrue("document.title.includes('La Casilla')");
await openStop(STOP_B.id);
await waitTrue("document.getElementById('live-line').textContent === 'BBBB · Destino B'");
await sleep(4000);
after = await ev(CARD);
check('parada: si las salidas de la anterior llegan tarde, no se pintan en la que se ve', after.url === '/stops/1137' && after.line === 'BBBB · Destino B' && after.stop === STOP_B.name, JSON.stringify(after));

// ===== La ficha de un viaje que pasa de las 24:00, a las 23:38 de Madrid =====
// Sin el directo del vehículo, la ficha son las paradas del viaje con su hora (/api/trips; sin conexión, el horario
// guardado). Solo las de antes de ahora son pasadas, también con el móvil en otra zona horaria.
const TRIP_STOPS = {
    lineCode: 'A3136', lineName: 'Bilbao-Barakaldo-Santurtzi', headsign: 'Santurtzi', stops: [
        { stopId: 330, name: 'Antes', scheduledTime: '23:20', isTarget: false },
        { stopId: 361, name: 'Aquí', scheduledTime: '23:45', isTarget: true },
        { stopId: 362, name: 'Antes de las 24:00', scheduledTime: '23:59', isTarget: false },
        { stopId: 363, name: 'Pasada la medianoche', scheduledTime: '00:10', isTarget: false },
        { stopId: 364, name: 'De madrugada', scheduledTime: '00:40', isTarget: false },
    ],
};
for (const zone of ['Europe/Madrid', 'America/New_York']) {
    await send('Emulation.setTimezoneOverride', { timezoneId: zone });
    await setClock(madridToday('23:38'));
    rules = [
        { match: /\/api\/stops\/361\/departures/, body: () => departures({ id: 361, name: 'Santiago - Santa Ana' }, [departure('A3136', 'Santurtzi', '3136-668-84300', 7, { status: 'scheduled' })]) },
        { match: /\/api\/vehicles\//, fail: true },
        { match: /\/api\/trips\//, body: TRIP_STOPS },
    ];
    await go('/stops/361?red=bus');
    await waitTrue("!!document.getElementById('live-open-detail').dataset.tripKey && !document.getElementById('live-open-detail').disabled");
    await ev("document.getElementById('live-open-detail').click(); true");
    await waitTrue("document.getElementById('vehicle-modal').open && document.querySelectorAll('#modal-stops li').length === 5");
    const marks = await ev("[...document.querySelectorAll('#modal-stops li')].map((li) => li.classList.contains('stop-past'))");
    check(`ficha del viaje a las 23:38 (${zone}): pasadas solo las de antes, no las de después de medianoche`, JSON.stringify(marks) === '[true,false,false,false,false]', JSON.stringify(marks));
    if (zone === 'Europe/Madrid') {
        check('ficha del viaje: cada parada es un enlace (se abre con el teclado)', await ev("[...document.querySelectorAll('#modal-stops li')].every((li) => !!li.querySelector('a[href*=\"/stops/\"]'))"));
    }
    await ev("document.getElementById('vehicle-modal').close(); true");
}
await send('Emulation.setTimezoneOverride', { timezoneId: '' });

// El viaje de la ficha es el de su día de servicio (la clave lleva su primera salida): uno de hoy a más de 12 h no sale
// como pasado ni uno de esta mañana como por venir; uno de ayer que pasa de las 24:00, mientras va, como el de ayer.
const farSheets = [];
for (const [at, tripKey, times, expected] of [
    ['06:00', '3136-700-68400', ['19:00', '19:20', '19:40'], '[false,false,false]'],
    ['23:00', '3136-701-21600', ['06:00', '06:20', '06:40'], '[true,true,true]'],
    ['00:20', '3136-702-87000', ['00:10', '00:30', '00:50'], '[true,false,false]'],
]) {
    await setClock(madridToday(at));
    const stops = times.map((time, index) => ({ stopId: 361 + index, name: 'Parada ' + index, scheduledTime: time, isTarget: index === 1 }));
    rules = [
        { match: /\/api\/stops\/361\/departures/, body: () => departures({ id: 361, name: 'Santiago - Santa Ana' }, [departure('A3136', 'Santurtzi', tripKey, 30, { status: 'scheduled' })]) },
        { match: /\/api\/vehicles\//, fail: true },
        { match: /\/api\/trips\//, body: { lineCode: 'A3136', lineName: 'Bilbao-Barakaldo-Santurtzi', headsign: 'Santurtzi', stops } },
    ];
    await go('/stops/361?red=bus');
    await waitTrue("!!document.getElementById('live-open-detail').dataset.tripKey && !document.getElementById('live-open-detail').disabled");
    await ev("document.getElementById('live-open-detail').click(); true");
    await waitTrue("document.getElementById('vehicle-modal').open && document.querySelectorAll('#modal-stops li').length === 3");
    const marks = JSON.stringify(await ev("[...document.querySelectorAll('#modal-stops li')].map((li) => li.classList.contains('stop-past'))"));
    farSheets.push({ at, marks, ok: marks === expected });
    await ev("document.getElementById('vehicle-modal').close(); true");
}
check('ficha del viaje con su día de servicio: el de las 19:00 a las 06:00 por venir, el de las 06:00 a las 23:00 pasado y el de ayer de las 24:10 a las 00:20, en marcha', farSheets.every((sheet) => sheet.ok), JSON.stringify(farSheets));
await setClock(null);

// ===== La ficha de un viaje de mañana sigue siendo de mañana al volver a la app =====
// La API, por la clave del viaje, contesta con el de hoy, que ya terminó.
rules = [
    { match: /\/api\/stops\/1\/departures/, body: () => departures({ id: 1, name: 'Primera' }, [departure('A3451', 'Bilbao', '3451-1-23160', 400, { status: 'scheduled', tomorrow: true, scheduledTime: '06:26' })]) },
    { match: /\/api\/vehicles\//, body: vehicle('finished') },
];
await go('/stops/1?red=bus');
await waitTrue("document.getElementById('live-open-detail').dataset.tomorrow === 'true'");
await ev("document.getElementById('live-open-detail').click(); true");
await waitTrue("document.getElementById('vehicle-modal').open");
const MODAL = "({ badge: document.getElementById('modal-badge').textContent, vehicle: document.getElementById('modal-vehicle').textContent, tomorrow: I18n.t('app.badge.tomorrow') })";
const opened = await ev(MODAL);
await ev("window.dispatchEvent(new Event('online')); true");
await sleep(2500);
const resumed = await ev(MODAL);
check('ficha de un viaje de mañana: al recuperar la conexión sigue siendo la de mañana', opened.badge === opened.tomorrow && resumed.badge === opened.badge && resumed.vehicle === opened.vehicle, JSON.stringify([opened, resumed]));
await ev("document.getElementById('vehicle-modal').close(); true");

// ===== Un viaje del día de servicio siguiente que sale en unos minutos (nextDay, los de las 00:xx) =====
// En la lista va en minutos, no como «mañana»; su ficha es su horario, no el viaje de hoy con esa clave, que ya terminó.
rules = [
    { match: /\/api\/stops\/1\/departures/, body: () => departures({ id: 1, name: 'Primera' }, [
        departure('A3451', 'Bilbao', '3451-1-300', 5, { status: 'scheduled', nextDay: true }),
        departure('A3451', 'Mungia', '3451-2-900', 15, { status: 'scheduled', nextDay: true }),
    ]) },
    { match: /\/api\/vehicles\//, body: vehicle('finished') },
];
await go('/stops/1?red=bus');
await waitTrue("document.getElementById('live-minutes').textContent === '5' && document.querySelectorAll('#live-more-list li').length === 1");
const nextDayList = await ev("({ badge: document.getElementById('live-badge').textContent, other: document.querySelector('#live-more-list .live-more-when').textContent, tomorrow: I18n.t('app.badge.tomorrow') })");
check('nextDay: en la lista sale en minutos, sin «mañana»', nextDayList.badge !== nextDayList.tomorrow && /^15 min/.test(nextDayList.other), JSON.stringify(nextDayList));
const NEXT_DAY_SHEET = "({ badge: document.getElementById('modal-badge').textContent, vehicle: document.getElementById('modal-vehicle').textContent, past: document.querySelectorAll('#modal-stops li.stop-past').length, finished: [I18n.t('app.status.finished'), I18n.t('app.tripFinished')], tomorrow: I18n.t('app.badge.tomorrow') })";
const nextDaySheets = [];
for (const opener of ["document.getElementById('live-open-detail')", "document.querySelector('#live-more-list li button')"]) {
    await ev(`${opener}.click(); true`);
    await waitTrue("document.getElementById('vehicle-modal').open");
    nextDaySheets.push(await ev(NEXT_DAY_SHEET));
    await ev("document.getElementById('vehicle-modal').close(); true");
}
check('nextDay: su ficha es su horario, sin «Finalizado», sin paradas pasadas y sin «mañana»', nextDaySheets.length === 2 && nextDaySheets.every((s) => !s.finished.includes(s.badge) && !s.finished.includes(s.vehicle) && s.past === 0 && s.badge !== s.tomorrow), JSON.stringify(nextDaySheets));

// ===== Lo que oye un lector de pantalla, en inglés =====
rules = [{ match: /\/api\/stops\/356\/departures/, body: () => departures(STOP_A, [departure('A3136', 'Bilbao', '3136-669-1', 20, { delayMinutes: 3 })]) }];
await go('/stops/356?red=bus&lang=en');
await waitTrue("document.getElementById('live-minutes').textContent === '20'");
const A11Y = "(() => { const b = document.getElementById('live-open-detail'); const f = document.getElementById('live-favorite'); return { label: b.getAttribute('aria-label') || '', delay: document.getElementById('live-delay').textContent, favorite: f.getAttribute('aria-label'), pressed: f.getAttribute('aria-pressed'), close: document.getElementById('live-close').getAttribute('aria-label') }; })()";
const a11y = await ev(A11Y);
check('lector de pantalla: el botón grande dice los minutos y el retraso', /20 ?min/.test(a11y.label) && a11y.delay !== '' && a11y.label.includes(a11y.delay) && a11y.label.includes('journey'), JSON.stringify(a11y));
check('lector de pantalla: el favorito y «cerrar», en inglés', a11y.favorite === 'Save stop to favourites' && a11y.close === 'Close', JSON.stringify(a11y));
await ev("document.getElementById('live-favorite').click(); true");
const pressed = await ev(A11Y);
await ev("document.getElementById('live-favorite').click(); true");
const released = await ev(A11Y);
check('lector de pantalla: el favorito dice si está guardado (aria-pressed)', a11y.pressed === 'false' && pressed.pressed === 'true' && released.pressed === 'false', [a11y.pressed, pressed.pressed, released.pressed].join(' → '));
rules = [
    { match: /\/api\/lines\/3136\/timetable/, body: () => ({ line: { id: 3136, code: 'A3136', name: 'Bilbao-Barakaldo-Santurtzi' }, date: madridDate(Date.now()), entries: [{ tripKey: '3451-1-23160', departure: '08:00', headsign: 'Bilbao', status: 'scheduled', delayMinutes: 0, reason: null }] }) },
    { match: /\/api\/vehicles\//, body: vehicle('scheduled') },
];
await go('/lines/3136?red=bus&lang=en');
await waitTrue("document.querySelectorAll('#timetable-body tr').length > 0");
const focused = await ev("(() => { const b = document.querySelector('#timetable-body tr button'); if (!b) return false; b.focus(); return document.activeElement === b; })()");
await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 });
check('horario con el teclado: cada fila lleva un botón y con Intro se abre su viaje', focused === true && await waitTrue("document.getElementById('vehicle-modal').open", 6000));
await ev("document.getElementById('vehicle-modal').close(); true");

// ===== El filtro del horario, con la hora de Madrid y al día =====
rules = [];
const FILTER = "document.getElementById('filter-date').value + ' ' + document.getElementById('filter-hour-from').value + '-' + document.getElementById('filter-hour-to').value";
const slotAt = (ms) => {
    const hour = Number(madridParts(ms).hour);
    return `${madridDate(ms)} ${String(hour).padStart(2, '0')}:00-${String((hour + 2) % 24).padStart(2, '0')}:00`;
};
await send('Emulation.setTimezoneOverride', { timezoneId: 'America/New_York' });
await go('/lines/3136?red=bus');
const inNewYork = await ev(FILTER);
check('horario con el móvil en Nueva York: abre el día y la franja de Madrid', [slotAt(Date.now()), slotAt(Date.now() - 60000)].includes(inNewYork), `${inNewYork} frente a ${slotAt(Date.now())}`);
await send('Emulation.setTimezoneOverride', { timezoneId: 'Europe/Madrid' });
const night = madridToday('23:30');
await setClock(night);
await go('/lines/3136?red=bus');
const atNight = await ev(FILTER);
await ev("window.__shiftClock(8.5 * 3600 * 1000); document.dispatchEvent(new Event('visibilitychange')); true");
const nextMorning = slotAt(night + 8.5 * 3600 * 1000);
const morning = await waitTrue(`(${FILTER}) === ${JSON.stringify(nextMorning)}`, 5000);
check('horario abierto de noche y vuelto a ver por la mañana: pasa al día y la franja de ahora', atNight === slotAt(night) && morning, `${atNight} → ${await ev(FILTER)}`);
await ev("(() => { const from = document.getElementById('filter-hour-from'); from.value = '12:00'; from.dispatchEvent(new Event('change')); window.__shiftClock(3600 * 1000); document.dispatchEvent(new Event('visibilitychange')); return true; })()");
await sleep(800);
check('horario: la franja que elige la persona no se cambia sola', (await ev("document.getElementById('filter-hour-from').value")) === '12:00');
await setClock(null);

// ===== /pantalla en un aparato con la zona en UTC =====
await send('Emulation.setTimezoneOverride', { timezoneId: 'UTC' });
await go('/pantalla/356');
await waitTrue("document.querySelector('[data-screen-status]').textContent.trim() !== ''");
const screen = await ev("({ clock: document.querySelector('[data-screen-clock]').textContent, status: document.querySelector('[data-screen-status]').textContent })");
const screenTimes = [madridHm(Date.now()), madridHm(Date.now() - 60000)];
check('pantalla en UTC: el reloj y «Actualizado a las» van con la hora de Madrid', screenTimes.includes(screen.clock) && screenTimes.some((time) => screen.status.includes(time)), `${JSON.stringify(screen)} frente a ${screenTimes[0]}`);
await send('Emulation.setTimezoneOverride', { timezoneId: '' });

// ===== Andenes sin trenes y «Cerca de mí» sin próxima salida, en euskera, inglés y francés =====
await send('Browser.grantPermissions', { permissions: ['geolocation'], origin: BASE });
await send('Emulation.setGeolocationOverride', { latitude: 43.26, longitude: -2.93, accuracy: 20 });
for (const lang of ['eu', 'en', 'fr']) {
    rules = [
        { match: /\/api\/stops\/7\/departures/, body: { stop: { id: 7, name: 'Abando' }, platforms: [], directionLabels: { toward_reference: 'Etxebarri · Basauri', away_from_reference: 'Plentzia' }, departures: [] } },
        { match: /\/api\/nearby/, body: { stops: [{ id: 7, name: 'Abando', area: 'Bilbao', distanceM: 120, next: null }] } },
    ];
    await go(`/stops/7?red=metro&lang=${lang}`);
    await waitTrue("document.querySelectorAll('#platform-columns .platform-column').length > 0");
    const platform = await ev("({ notice: document.getElementById('platform-notice').textContent, columns: [...document.querySelectorAll('#platform-columns .platform-column-headsign')].map((n) => n.textContent) })");
    check(`${lang}: andenes sin trenes, el aviso y los sentidos sin castellano`, platform.notice !== '' && !/^app\.|No hay|Consulta/.test(platform.notice) && platform.columns.length === 2 && platform.columns.every((c) => !/Hacia|Sentido/.test(c) && !c.startsWith('app.')), JSON.stringify(platform));
    await ev("document.getElementById('nearby-btn').click(); true");
    await waitTrue("!!document.querySelector('#search-results .pill')");
    const nearby = await ev("({ text: document.querySelector('#search-results .pill').textContent, none: I18n.t('app.noUpcomingDepartures') })");
    check(`${lang}: «Cerca de mí» sin próxima salida, sin castellano`, !nearby.text.includes('Sin salidas próximas') && nearby.text.includes(nearby.none), nearby.text);
}

// ===== Los nombres del mapa de la red entran como texto en los tooltips de Leaflet =====
// Se apuntan las capas con tooltip (Leaflet deja window.L al cargar) para abrir el de la línea y el de la parada.
const tooltipsSource = `(() => {
    window.__tooltipLayers = [];
    let leaflet;
    Object.defineProperty(window, 'L', {
        configurable: true,
        get() {
            return leaflet;
        },
        set(value) {
            leaflet = value;
            const bind = value.Layer.prototype.bindTooltip;
            value.Layer.prototype.bindTooltip = function (...args) {
                window.__tooltipLayers.push(this);
                return bind.apply(this, args);
            };
        },
    });
})();`;
const tooltipsScript = (await send('Page.addScriptToEvaluateOnNewDocument', { source: tooltipsSource })).identifier;
rules = [{
    match: /\/map-data\/metro\.json/,
    body: async () => {
        const data = await (await fetch(BASE + '/map-data/metro.json')).json();
        data.lines[0].name = 'Prueba <b id="inyectado">negrita</b>';
        const first = data.lines[0].patterns[0].stops[0];
        data.stops[first][0] = 'Abando <img id="inyectado-parada" src="/icons/icon-192.png">';
        return data;
    },
}];
await go('/mapa', 3000);
await ev("document.querySelector('[data-map-network=\"metro\"]').click(); true");
await waitTrue("window.__tooltipLayers.length > 0", 10000);
const tooltips = await ev(`(() => {
    const found = [];
    for (const layer of window.__tooltipLayers) {
        if (!layer._map) continue;
        layer.openTooltip();
        const node = layer.getTooltip().getElement();
        if (node && /negrita|Abando </.test(node.textContent)) found.push({ text: node.textContent, html: !!node.querySelector('#inyectado, #inyectado-parada') });
        layer.closeTooltip();
    }
    return found;
})()`);
check('mapa de la red: los nombres con marcado salen como texto, no como HTML', Array.isArray(tooltips) && tooltips.length >= 2 && tooltips.every((t) => !t.html && t.text.includes('<')), JSON.stringify(tooltips));
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: tooltipsScript });

// ===== Cancelar un «Avísame cuando llegue» sin conexión =====
// Se quita del móvil y queda pendiente: al volver la conexión se manda al servidor (si no, seguiría llegando).
const ALERT = { id: 'abc123abc123abc123abc123', tripKey: '3136-669-82802', stopId: 356, minutes: 5, repeat: true, endpoint: 'https://fcm.googleapis.com/fcm/send/prueba', created: Date.now(), lineCode: 'A3136', headsign: 'Bilbao', stopName: 'La Casilla' };
const cancels = [];
rules = [{ match: /\/api\/push\/arrival\/[^/]+\/cancel/, fail: true, seen: cancels }];
await go('/?red=bus');
await ev(`localStorage.setItem('bide-arrival-alerts', ${JSON.stringify(JSON.stringify([ALERT]))}); localStorage.removeItem('bide-arrival-cancels'); true`);
await go('/?red=bus#ajustes', 500);
await send('Page.reload');
await sleep(2500);
await waitTrue("!!document.querySelector('[data-settings-arrivals] li button')");
await ev("document.querySelector('[data-settings-arrivals] li button').click(); true");
await sleep(1500);
const offline = await ev("({ list: document.querySelectorAll('[data-settings-arrivals] li').length, pending: localStorage.getItem('bide-arrival-cancels') || '' })");
check('aviso de llegada cancelado sin conexión: se quita del móvil y queda pendiente', cancels.length === 1 && offline.list === 0 && offline.pending.includes(ALERT.id), JSON.stringify(offline));
rules = [{ match: /\/api\/push\/arrival\/[^/]+\/cancel/, body: { status: 'cancelled' }, seen: cancels }];
await ev("window.dispatchEvent(new Event('online')); true");
const sent = await waitTrue("(localStorage.getItem('bide-arrival-cancels') || '[]') === '[]'", 6000);
check('al volver la conexión, la cancelación pendiente se manda al servidor', sent && cancels.length === 2 && cancels[1].includes(ALERT.id), JSON.stringify(cancels));
await ev("localStorage.removeItem('bide-arrival-alerts'); true");

// ===== El service worker con el servidor fallando (por el proxy) =====
await send('Fetch.disable');
await send('Network.setBypassServiceWorker', { bypass: false });
await send('Network.setCacheDisabled', { cacheDisabled: false });
const proxy = { failing: new Set(), down: false };
const upstream = new URL(BASE);
const proxyServer = http.createServer((req, res) => {
    if (proxy.down) { req.socket.destroy(); return; }
    if (proxy.failing.has(new URL(req.url, PROXY).pathname)) {
        res.writeHead(503, { 'Content-Type': 'text/plain' });
        res.end('saturado');
        return;
    }
    const forward = http.request({ host: upstream.hostname, port: upstream.port, method: req.method, path: req.url, headers: { ...req.headers, host: upstream.host } }, (reply) => {
        res.writeHead(reply.statusCode, reply.headers);
        reply.pipe(res);
    });
    forward.on('error', () => { res.writeHead(502); res.end(); });
    req.pipe(forward);
});
await new Promise((resolve) => proxyServer.listen(PROXY_PORT, resolve));
const goProxy = async (url, wait = 2500) => { await send('Page.navigate', { url: PROXY + url }); await sleep(wait); };
await goProxy('/stops/356?red=bus');
await ev("localStorage.setItem('bide_splash_seen', '1'); localStorage.setItem('bide-offline-chosen', '1'); true");
const controlled = await waitTrue('!!navigator.serviceWorker.controller', 30000);
check('service worker: instalado y al mando de la página', controlled);
const putCached = (path, body) => ev(`(async () => {
    const name = (await caches.keys()).find((k) => k.startsWith('bizkaibus-api-'));
    if (!name) return false;
    await (await caches.open(name)).put(location.origin + ${JSON.stringify(path)}, new Response(JSON.stringify(${JSON.stringify(body)}), { headers: { 'Content-Type': 'application/json' } }));
    return true;
})()`);

// La huelga de ayer, en la copia que guardó el service worker ese día (today era cierto entonces).
const strike = { today: true, days: [madridDate(Date.now() - 86400000)], sources: ['alerts'], alert: null, cause: 'strike', lines: null, lineCodes: null };
for (const mode of ['503', 'sin conexión']) {
    await goProxy('/stops/356?red=bus', 4000);
    const put = await putCached('/api/reduced', strike);
    await ev("localStorage.removeItem('bide_reduced_bus'); true");
    if (mode === '503') proxy.failing = new Set(['/api/reduced']);
    if (mode === 'sin conexión') proxy.down = true;
    await send('Network.clearBrowserCache');
    await goProxy('/stops/356?red=bus', 5000);
    const notice = await ev("(() => { const n = document.getElementById('reduced-notice'); if (!n || n.hidden) return ''; return n.textContent; })()");
    check(`service worker (${mode}): la huelga de ayer guardada no sale como de hoy`, put === true && notice === '', notice);
    proxy.failing = new Set();
    proxy.down = false;
}

// El horario de una línea sin conexión: el guardado en el móvil, no la copia de esa consulta con los retrasos de entonces.
await goProxy('/lines/3136?red=bus', 3000);
await waitTrue("typeof BideOffline !== 'undefined'");
const savedSchedule = await ev("BideOffline.save('bus').then(() => true, () => false)");
const day = madridDate(Date.now());
const setSlot = (from, to) => ev(`(() => { document.getElementById('filter-date').value = '${day}'; document.getElementById('filter-hour-from').value = '${from}'; const to = document.getElementById('filter-hour-to'); to.value = '${to}'; to.dispatchEvent(new Event('change')); return true; })()`);
await setSlot('08:00', '10:00');
await waitTrue("document.querySelectorAll('#timetable-body tr').length > 0");
await sleep(1500);
const tampered = await ev(`(async () => {
    const name = (await caches.keys()).find((k) => k.startsWith('bizkaibus-api-'));
    const cache = await caches.open(name);
    const key = (await cache.keys()).find((request) => request.url.includes('/lines/3136/timetable') && request.url.includes('hourFrom=08%3A00'));
    if (!key) return 0;
    const old = await (await cache.match(key)).json();
    for (const entry of old.entries) {
        entry.status = 'live';
        entry.delayMinutes = 4;
    }
    await cache.put(key, new Response(JSON.stringify(old), { headers: { 'Content-Type': 'application/json' } }));
    return old.entries.length;
})()`);
proxy.down = true;
await send('Network.clearBrowserCache');
await setSlot('07:00', '10:00');
await sleep(1500);
await setSlot('08:00', '10:00');
await sleep(3000);
const offlineTimetable = await ev("(() => { const n = document.getElementById('timetable-note'); let note = ''; if (!n.hidden) note = n.textContent; return { note, rows: [...document.querySelectorAll('#timetable-body tr')].slice(0, 4).map((tr) => tr.textContent) }; })()");
check('service worker sin conexión: el horario sale del guardado en el móvil, no de una copia con los retrasos de entonces', savedSchedule && tampered > 0 && offlineTimetable.note !== '' && !offlineTimetable.rows.some((row) => row.includes('+4')), JSON.stringify(offlineTimetable));
proxy.down = false;

// «Vaciar caché y actualizar» con un fichero en 503: no se queda con la app a medias.
await goProxy('/stops/356?red=bus', 3000);
const shellHas = (path) => ev(`(async () => { const name = (await caches.keys()).find((k) => k.startsWith('bizkaibus-shell-')); if (!name) return false; return !!(await (await caches.open(name)).match(${JSON.stringify(path)})); })()`);
const hadIt = await shellHas('/js/offline.js');
proxy.failing = new Set(['/js/offline.js']);
const reply = await ev(`(async () => {
    const registration = await navigator.serviceWorker.getRegistration();
    return new Promise((resolve) => {
        const channel = new MessageChannel();
        channel.port1.onmessage = (event) => resolve(event.data);
        registration.active.postMessage({ type: 'refresh-cache' }, [channel.port2]);
    });
})()`);
const stillHas = await shellHas('/js/offline.js');
check('«Vaciar caché y actualizar» con un fichero en 503: dice que no se pudo y no borra lo guardado', hadIt === true && !!reply && reply.ok === false && stillHas === true, JSON.stringify({ hadIt, reply, stillHas }));

// Instalar con un fichero en 503: no se da por instalada (el navegador lo vuelve a intentar más tarde).
await ev('(async () => { for (const r of await navigator.serviceWorker.getRegistrations()) await r.unregister(); for (const k of await caches.keys()) await caches.delete(k); return true; })()');
await goProxy('/stops/356?red=bus', 8000);
const installed = await ev("(async () => { const r = await navigator.serviceWorker.getRegistration(); return { active: !!(r && r.active), shell: (await caches.keys()).filter((k) => k.startsWith('bizkaibus-shell-')).length }; })()");
check('instalación con un fichero en 503: no se da por instalada ni guarda la app a medias', installed.active === false && installed.shell === 0, JSON.stringify(installed));
proxy.failing = new Set();
proxyServer.closeAllConnections();
proxyServer.close();

const bad = results.filter((r) => r.startsWith('MAL')).length;
const unique = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO casos límite: ${results.filter((r) => r.startsWith('OK')).length} OK, ${bad} MAL, ${unique.length} excepciones`);
for (const p of unique.slice(0, 10)) console.log(' -', p);
ws.close();
let exitCode = 0;
if (bad || unique.length) exitCode = 1;
shutdown(exitCode);
