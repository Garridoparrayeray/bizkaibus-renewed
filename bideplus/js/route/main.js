// El planificador en el inicio: la tarjeta «¿A dónde vas?» busca y las opciones salen debajo, en la misma pantalla;
// al elegir una se abre la pantalla del viaje. El cálculo es el del planificador (planificador/pantallas: trabajador,
// núcleo en WebAssembly y buscador de lugares), que arranca al tocar la tarjeta para no cargarlo en cada visita.
import * as engine from '/planificador/pantallas/engine.js';
import { openLink } from '/planificador/pantallas/link.js';
import { locate } from '/planificador/pantallas/location.js';
import { addRecent, readFilters, readHome, readLines, writeFilters } from '/planificador/pantallas/prefs.js';
import { cleanPlace, nowHere, planRequest, readSearch, ridesOf, samePlace, sameRides, savedHub, siteOf, writeSearch }
    from '/planificador/pantallas/query.js';
import { stopDetail } from '/planificador/pantallas/texts.js';
import { t } from '/planificador/pantallas/lang.js';
import { defaultOrigin, fillWhen, initCard, readWhen } from './card.js';
import { byId } from './common.js';
import { initFilters, refreshFilters } from './filters.js';
import { renderProblem, renderReceived, renderResults, sharedJourneys, showSearching } from './options.js';
import { shareLink } from './share.js';
import { closeTrip, initTrip, openTrip } from './trip.js';

const POSITION_MAX_AGE_MS = 60000;
const LINK_HASH = /^v\d+\./;

const app = {
    started: false, manifest: null, networks: new Map(), slugs: [], stopKeys: new Set(), hubs: null,
    filters: readFilters(), home: [], homeSaved: false, lines: readLines(), position: null,
    query: null, request: null, answer: null, answerKey: '', firstOk: null, received: null, fields: null,
};
let markReady = () => {};
let markFailed = () => {};
const ready = new Promise((resolve, reject) => {
    markReady = resolve;
    markFailed = reject;
});
ready.catch(() => null);

app.networkOf = (sSlug) => app.networks.get(sSlug) || null;

// El nombre de una red en el idioma de la app (net.<red>.name) o, si la app no la tiene, el del planificador.
app.networkName = (sSlug) => {
    if (BideNetworks.exists(sSlug)) {
        return BideNetworks.name(sSlug);
    }
    const network = app.networks.get(sSlug);
    if (network) {
        return network.name;
    }
    return String(sSlug);
};

// Lo dice a los lectores de pantalla aunque se repita el mismo texto.
let iAnnounceTimer = 0;
app.announce = (sText) => {
    const announcer = byId('route-announcer');
    announcer.textContent = '';
    clearTimeout(iAnnounceTimer);
    iAnnounceTimer = setTimeout(() => {
        announcer.textContent = sText;
    }, 60);
};

// ===== Arranque del planificador =====

const onReady = (message) => {
    app.manifest = message.manifest;
    message.manifest.networks.forEach((network) => app.networks.set(network.slug, network));
    app.slugs = message.manifest.networks.map((network) => network.slug);
    const home = readHome(app.slugs);
    Object.assign(app, { home: home || [], homeSaved: home !== null });
    app.filters.networksOff = app.filters.networksOff.filter((sSlug) => app.slugs.includes(sSlug));
    if (app.filters.networksOff.length >= app.slugs.length) {
        app.filters.networksOff = [];
    }
    app.stopKeys = new Set(message.stops.map((stop) => stop.network + '|' + stop.id));
    engine.indexStops(message.stops, message.manifest.networks.map((network) => ({ slug: network.slug, kind: network.kind })));
    refreshFilters(app);
    markReady();
};

const onPlaces = (message) => {
    if (Array.isArray(message.hubs)) {
        app.hubs = new Map(message.hubs.map((hub) => [hub.lat + '|' + hub.lon, { network: hub.network, stop: hub.stop }]));
    }
    app.fields.from.refresh();
    app.fields.to.refresh();
};

const onMore = (message) => {
    if (message.refresh) {
        app.fields.from.refresh();
        app.fields.to.refresh();
    }
};

const onFailed = (failure) => {
    markFailed(new Error(failure.message));
    renderProblem(app, I18n.t('route.failed'), t('planner.failure.detail', { detail: failure.message.replace(/[.\s]+$/, '') }), []);
};

app.wake = () => {
    if (app.started) {
        return;
    }
    app.started = true;
    engine.start({ ready: onReady, failed: onFailed, places: onPlaces, more: onMore });
};

// ===== Búsqueda =====

// La dirección de una consulta, sin perder el idioma elegido en ella (?lang=eu).
const urlOf = (sSearch) => {
    const params = new URLSearchParams(sSearch);
    const sLang = new URLSearchParams(location.search).get('lang');
    if (sLang) {
        params.set('lang', sLang);
    }
    if (params.toString() === '') {
        return location.pathname;
    }
    return location.pathname + '?' + params.toString();
};

const settings = () => {
    let home = null;
    if (app.homeSaved && app.home.length > 0) {
        home = app.home;
    }
    return { filters: app.filters, home, lines: app.lines, slugs: app.slugs };
};

// La posición del aparato para «Tu ubicación», válida un minuto. Si falla, lanza el error con su explicación.
const currentPosition = async () => {
    if (app.position && Date.now() - app.position.at < POSITION_MAX_AGE_MS) {
        return app.position;
    }
    const point = await locate();
    app.position = { lat: point.lat, lon: point.lon, at: Date.now() };
    return app.position;
};

// Un pueblo se busca desde su parada principal, si está en los horarios cargados.
const hubOfPlace = (place) => {
    if (place.kind !== 'point') {
        return null;
    }
    let hub = savedHub(place);
    if (app.hubs) {
        hub = app.hubs.get(place.lat + '|' + place.lon) || null;
    }
    if (hub && app.stopKeys.has(hub.network + '|' + hub.stop)) {
        return hub;
    }
    return null;
};

const siteOfPlace = async (place) => {
    if (place.kind === 'location') {
        const position = await currentPosition();
        return { lat: position.lat, lon: position.lon };
    }
    return siteOf(place, hubOfPlace(place));
};

const dayLoaded = (iDay) => iDay >= app.manifest.base_day && iDay < app.manifest.base_day + app.manifest.days;

const placeOfSite = (site) => {
    if (site.network) {
        return { kind: 'stop', network: site.network, stop: site.stop };
    }
    return { kind: 'point', lat: site.lat, lon: site.lon };
};

// La petición «plan» de la consulta de ahora, o null si no se puede (y lo dice).
const requestFor = async () => {
    let from = null;
    let to = null;
    try {
        from = await siteOfPlace(app.query.from);
        to = await siteOfPlace(app.query.to);
    } catch (error) {
        renderProblem(app, t('planner.problem.noLocation'), error.message, []);
        return null;
    }
    engine.prepareWalk([from, to]).catch(() => null);
    const request = planRequest(app.query, from, to, settings());
    if (!dayLoaded(request.day)) {
        renderProblem(app, t('planner.problem.noDay'), I18n.t('route.noDay'), []);
        return null;
    }
    if (samePlace(placeOfSite(from), placeOfSite(to))) {
        renderProblem(app, t('planner.samePlace.title'), t('planner.samePlace.text'), []);
        return null;
    }
    return request;
};

// El primer viaje que cumple los filtros que hubo que quitar, en las 12 horas siguientes (operación range).
const FIRST_OK_WINDOW = 43200;
const findFirstOk = async (request, sKey) => {
    const range = {
        op: 'range', from: request.from, to: request.to, day: request.day, time: request.time, window: FIRST_OK_WINDOW,
        accessible: request.accessible, live_only: request.live_only, transfer_margin: request.transfer_margin,
    };
    if (request.networks) {
        range.networks = request.networks;
    }
    const answer = await engine.call(range, 'first-ok').catch(() => null);
    if (!answer || answer.status !== 'ok' || app.answerKey !== sKey) {
        return;
    }
    app.firstOk = { none: answer.options.length === 0, depart: 0 };
    if (answer.options.length > 0) {
        app.firstOk.depart = Math.min(...answer.options.map((option) => option.depart));
    }
    renderResults(app);
};

// Busca la consulta de ahora y pinta las opciones debajo de la tarjeta. Si otra búsqueda la adelanta, no pinta nada.
const runSearch = async () => {
    const sKey = writeSearch(app.query, null);
    Object.assign(app, { answer: null, answerKey: sKey, firstOk: null, received: null });
    showSearching(app, true);
    try {
        await ready;
    } catch (error) {
        showSearching(app, false);
        return null;
    }
    const request = await requestFor();
    if (request === null || app.answerKey !== sKey) {
        return null;
    }
    let answer = null;
    try {
        answer = await engine.call(request, 'plan');
    } catch (error) {
        renderProblem(app, t('planner.problem.failed'), error.message, [{ text: t('planner.problem.retry'), run: runSearch }]);
        return null;
    }
    if (answer === null || app.answerKey !== sKey) {
        return null;
    }
    if (answer.status !== 'ok') {
        renderProblem(app, t('planner.problem.refused'), '', []);
        return null;
    }
    Object.assign(app, { request, answer });
    renderResults(app);
    if (answer.dropped_filters.length > 0) {
        findFirstOk(request, sKey);
    }
    return answer;
};
app.rerun = () => {
    if (app.query) {
        runSearch();
    }
};

// Pide la ubicación antes de buscar, para explicar en la tarjeta si falla.
const positionOk = async (from, to) => {
    let sField = '';
    if (from.kind === 'location') {
        sField = 'from';
    } else if (to.kind === 'location') {
        sField = 'to';
    }
    if (sField === '') {
        return true;
    }
    try {
        await currentPosition();
        return true;
    } catch (error) {
        app.fields[sField].showError(error.message);
        app.fields[sField].focus();
        return false;
    }
};

// Desde la tarjeta: resuelve origen y destino (la primera sugerencia si no se ha elegido) y busca.
app.search = async () => {
    app.wake();
    const when = readWhen();
    if (when === null) {
        return;
    }
    const from = await app.fields.from.resolve();
    if (!from) {
        app.fields.from.focus();
        return;
    }
    const to = await app.fields.to.resolve();
    if (!to) {
        app.fields.to.focus();
        return;
    }
    if (samePlace(from, to)) {
        app.fields.to.showError(t('planner.home.samePlace'));
        app.fields.to.focus();
        return;
    }
    if (!(await positionOk(from, to))) {
        return;
    }
    app.query = { from, to, when: when.when, day: when.day, time: when.time, sort: 'best' };
    addRecent(from, to);
    history.replaceState(history.state, '', urlOf(writeSearch(app.query, null)));
    byId('route').hidden = false;
    byId('route').scrollIntoView({ block: 'start', behavior: 'smooth' });
    return runSearch();
};

// Cambia los filtros guardados (nunca todas las redes fuera) y vuelve a buscar.
app.changeFilters = (change, bRerun) => {
    if (change.networksOff && change.networksOff.length >= app.slugs.length) {
        return;
    }
    Object.assign(app.filters, change);
    writeFilters(app.filters);
    refreshFilters(app);
    if (bRerun) {
        app.rerun();
    }
};

app.searchAt = (iTime) => {
    Object.assign(app.query, { when: 'depart', day: app.request.day, time: iTime });
    fillWhen(app.query);
    history.replaceState(history.state, '', urlOf(writeSearch(app.query, null)));
    runSearch();
};

app.searchNow = () => {
    app.query.when = 'now';
    fillWhen(app.query);
    history.replaceState(history.state, '', urlOf(writeSearch(app.query, null)));
    runSearch();
};

// ===== Viaje elegido =====

// La dirección del viaje: la consulta y su lista de vehículos; el de un enlace recibido sigue en el enlace.
const tripUrl = (option, iDay) => {
    if (app.received) {
        return urlOf('?viaje=' + encodeURIComponent(JSON.stringify(ridesOf(option)))) + location.hash;
    }
    return urlOf(writeSearch(app.query, { rides: ridesOf(option), day: iDay }));
};

app.chooseOption = (option) => {
    let iDay = nowHere().day;
    let query = null;
    let request = null;
    if (app.answer && !app.received) {
        iDay = app.answer.day;
        query = app.query;
        request = app.request;
    } else if (app.received) {
        iDay = app.received.day;
    }
    history.pushState({ routeTrip: true }, '', tripUrl(option, iDay));
    openTrip(app, { option, day: iDay, query, request });
};

app.share = (iDay, aJourneys, query, request, sTitleKey) => shareLink(iDay, aJourneys, query, request, I18n.t(sTitleKey));

// «Buscar desde donde estoy», desde un enlace recibido: de «Tu ubicación» a la última parada del viaje.
app.searchFromHere = (sNetwork, stop) => {
    const sDetail = stopDetail(app.networkOf(sNetwork), app.networkName(sNetwork));
    app.fields.from.set(defaultOrigin());
    app.fields.to.set(cleanPlace({ kind: 'stop', network: sNetwork, stop: stop.id, name: stop.name, detail: sDetail }));
    history.replaceState(history.state, '', urlOf(''));
    app.search();
};

// ===== Lo que trae la dirección =====

const placeStart = (field, place, sText, fallback) => {
    if (place && (place.kind !== 'stop' || app.stopKeys.has(place.network + '|' + place.stop))) {
        let sDetail = place.detail;
        if (place.kind === 'stop' && !sDetail) {
            sDetail = stopDetail(app.networkOf(place.network), app.networkName(place.network));
        }
        field.set(cleanPlace(Object.assign({}, place, { detail: sDetail })));
        return true;
    }
    let sTyped = sText;
    if (place) {
        sTyped = place.name;
    }
    if (sTyped !== '') {
        field.type(sTyped);
        return true;
    }
    field.set(fallback);
    return fallback !== null;
};

const openReceived = async (sLink) => {
    byId('route').hidden = false;
    showSearching(app, true);
    try {
        await ready;
    } catch (error) {
        return;
    }
    const answer = await openLink(sLink).catch(() => null);
    app.received = { link: sLink, answer, day: (answer && answer.day) || 0 };
    renderReceived(app);
};

// «Cómo llegar aquí», «Salir desde aquí» y los enlaces del planificador: rellenan la tarjeta y, si está todo, buscan.
const restore = async () => {
    const sHash = decodeURIComponent(location.hash.slice(1));
    const parsed = readSearch(location.search);
    if (LINK_HASH.test(sHash)) {
        app.wake();
        await openReceived(sHash);
        return;
    }
    if (!parsed.query && !parsed.start) {
        return;
    }
    app.wake();
    await ready.catch(() => null);
    if (parsed.query) {
        placeStart(app.fields.from, parsed.query.from, '', null);
        placeStart(app.fields.to, parsed.query.to, '', null);
        fillWhen(parsed.query);
        await app.search();
        reopenTrip(parsed.chosen);
        return;
    }
    const bFrom = placeStart(app.fields.from, parsed.start.from, parsed.start.fromText, defaultOrigin());
    const bTo = placeStart(app.fields.to, parsed.start.to, parsed.start.toText, null);
    fillWhen(parsed.start);
    history.replaceState(history.state, '', urlOf(''));
    if (bFrom && bTo) {
        app.search();
    } else {
        byId('to-input').focus();
    }
};

// Al recargar con un viaje en la dirección, se abre en cuanto llegan las opciones.
const reopenTrip = async (chosen) => {
    if (!chosen) {
        return;
    }
    const option = (app.answer && app.answer.options.find((other) => sameRides(ridesOf(other), chosen.rides))) || null;
    if (option) {
        const sTrip = location.href;
        history.replaceState(history.state, '', urlOf(writeSearch(app.query, null)));
        history.pushState({ routeTrip: true }, '', sTrip);
        openTrip(app, { option, day: chosen.day, query: app.query, request: app.request });
    }
};

window.addEventListener('popstate', (event) => {
    if (!(event.state && event.state.routeTrip)) {
        closeTrip();
    }
});

app.fields = initCard(app);
initFilters(app);
initTrip(app);
byId('route-share').addEventListener('click', () => {
    app.share(app.answer.day, sharedJourneys(app), app.query, app.request, 'route.share.optionsTitle');
});
restore();
