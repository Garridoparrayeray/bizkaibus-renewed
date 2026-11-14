// «Cómo llegar», debajo de la tarjeta del inicio: hasta cinco opciones como tarjetas que se despliegan con su línea de
// tiempo (horas, paradas, directo o horario, coste) y «Elegir este viaje»; encima, los avisos de la búsqueda.
import { sumOf, transfersOf } from '/planificador/pantallas/journey.js';
import { momentOf, nowHere, ridesOf } from '/planificador/pantallas/query.js';
import { MIN_DELAY_SECONDS, clock, dayNote, dayShift, distance, duration, spareText, transfers } from '/planificador/pantallas/texts.js';
import { t } from '/planificador/pantallas/lang.js';
import { byId, colorOf, countText, el, linePill, namesOf, optionTags, ridesIn, svgIcon } from './common.js';
import { liveEntry } from './live.js';

const MAX_OPTIONS = 5;
const MIN_HEADSIGN = 3;
const PAST_TOLERANCE_SECONDS = 60;
// Cómo se quita cada filtro que el planificador tuvo que quitar para encontrar viajes, y sus textos (route.dropped.*).
const DROPPED = {
    transfer_margin: { clear: { margin: 0 }, key: 'margin' },
    accessible: { clear: { accessible: false }, key: 'accessible' },
    live_only: { clear: { liveOnly: false }, key: 'live' },
    networks: { clear: { networksOff: [] }, key: 'networks' },
};

export const showSearching = (app, bOn) => {
    byId('route').hidden = false;
    byId('route-searching').hidden = !bOn;
    if (bOn) {
        byId('route-options').replaceChildren();
        byId('route-notices').replaceChildren();
        byId('route-summary').textContent = '';
        byId('route-share').hidden = true;
    }
};

// Un aviso: título, explicación y botones ({ text, run }). sKind: care, serious o info.
const notice = (sKind, sTitle, sText, aActions) => {
    const box = el('div', 'route-notice');
    box.dataset.kind = sKind;
    box.append(el('strong', 'route-notice-title', sTitle));
    if (sText) {
        box.append(el('span', 'route-notice-text', sText));
    }
    aActions.forEach((action) => {
        const press = el('button', 'route-notice-button', action.text);
        press.type = 'button';
        press.addEventListener('click', action.run);
        box.append(press);
    });
    return box;
};

export const renderProblem = (app, sTitle, sText, aActions) => {
    showSearching(app, false);
    byId('route-options').replaceChildren();
    byId('route-notices').replaceChildren(notice('serious', sTitle, sText, aActions));
    byId('route-summary').textContent = sTitle;
    byId('route-share').hidden = true;
};

// ===== Línea de tiempo de una opción =====

const step = (sTime, sMark, sColor, aLines, bLast) => {
    const row = el('li', 'route-step');
    const rail = el('span', 'route-step-rail');
    const text = el('div', 'route-step-text');
    row.dataset.mark = sMark;
    rail.append(el('span', 'route-step-dot'));
    if (!bLast) {
        rail.append(el('span', 'route-step-line'));
    }
    if (sColor) {
        row.style.setProperty('--step', sColor);
    }
    aLines.forEach((line) => text.append(el('span', line[0], line[1])));
    row.append(el('time', 'route-step-time', sTime), rail, text);
    return row;
};

const headsignOf = (leg) => {
    const sHeadsign = String(leg.headsign || '').trim();
    if (sHeadsign.length < MIN_HEADSIGN || /^\d+$/.test(sHeadsign)) {
        return '';
    }
    return sHeadsign;
};

// «Metro+ L1, dirección Etxebarri · 13 paradas · 19 min».
export const rideText = (app, leg) => {
    let sLine = app.networkName(leg.network) + ' ' + leg.line.code;
    if (headsignOf(leg) !== '') {
        sLine = I18n.t('route.ride.towards', { line: sLine, headsign: headsignOf(leg) });
    }
    return [sLine, countText('stops', leg.stops), duration(leg.arrive - leg.depart)].join(' · ');
};

// «En directo · 3 min de retraso», «En directo · a su hora» o, sin dato del directo para ese vehículo, «Según
// horario». El retraso es el que ha aplicado el planificador, el mismo de las horas que se ven.
export const liveOf = (leg, iDay) => {
    const entry = liveEntry(leg, iDay);
    if (!entry) {
        return { live: false, text: I18n.t('route.live.scheduled') };
    }
    if (entry.cancelled) {
        return { live: true, text: I18n.t('route.live.cancelled') };
    }
    if (leg.delay >= MIN_DELAY_SECONDS) {
        return { live: true, text: I18n.t('route.live.delay', { n: duration(leg.delay) }) };
    }
    if (leg.delay <= -MIN_DELAY_SECONDS) {
        return { live: true, text: I18n.t('route.live.early', { n: duration(-leg.delay) }) };
    }
    return { live: true, text: I18n.t('route.live.onTime') };
};

// Si todos sus vehículos van con dato del directo (y ninguno cancelado): lo que deja «Solo en directo».
const allLive = (option, iDay) => ridesIn(option.legs).every((leg) => {
    const entry = liveEntry(leg, iDay);
    return entry !== null && !entry.cancelled;
});

// El hueco de un transbordo: lo que se anda (si se anda) y el margen que queda.
export const transferText = (transfer, sStop) => {
    const sSpare = spareText(Math.max(0, transfer.spare));
    if (transfer.walk <= 0) {
        return I18n.t('route.transfer.same', { spare: sSpare });
    }
    return I18n.t('route.transfer.walk', { walk: duration(transfer.walk), stop: sStop, spare: sSpare });
};

const walkLine = (leg) => I18n.t('route.walk.short', { time: duration(leg.arrive - leg.depart), distance: distance(leg.meters) });

const timeline = (app, option, query, iDay) => {
    const list = el('ol', 'route-steps');
    const names = namesOf(option.legs, query);
    const aTransfers = transfersOf(option.legs);
    const aRides = ridesIn(option.legs);
    const first = option.legs[0];
    const last = option.legs[option.legs.length - 1];
    if (first.kind === 'walk') {
        list.append(step(clock(first.depart), 'walk', '', [['route-step-title', names.from], ['route-step-sub', walkLine(first)]]));
    }
    aRides.forEach((leg, i) => {
        if (i > 0) {
            const previous = aRides[i - 1];
            list.append(step(clock(previous.arrive), 'transfer', '', [['route-step-title', previous.to.name],
                ['route-step-sub', transferText(aTransfers[i - 1], leg.from.name)]]));
        }
        const live = liveOf(leg, iDay);
        let sLiveClass = 'route-step-live';
        if (live.live) {
            sLiveClass += ' is-live';
        }
        list.append(step(clock(leg.depart), 'ride', colorOf(app.networkOf(leg.network)), [['route-step-title', leg.from.name],
            ['route-step-sub', rideText(app, leg)], [sLiveClass, live.text]]));
    });
    if (last.kind === 'walk' && aRides.length > 0) {
        const ride = aRides[aRides.length - 1];
        list.append(step(clock(ride.arrive), 'walk', '', [['route-step-title', ride.to.name], ['route-step-sub', walkLine(last)]]));
    }
    list.append(step(clock(option.arrive) + dayNote(option.arrive), 'end', '', [['route-step-title', names.to]], true));
    return list;
};

const costRow = () => {
    const row = el('div', 'route-cost');
    row.append(el('span', '', I18n.t('route.cost.total')), el('strong', '', I18n.t('route.cost.unknown')));
    return row;
};

// ===== Tarjeta de una opción =====

const timesText = (option) => {
    let sTimes = clock(option.depart) + ' → ' + clock(option.arrive);
    const iShift = dayShift(option.arrive) - dayShift(option.depart);
    if (iShift > 0) {
        sTimes += ' (+' + iShift + ')';
    }
    return sTimes + ' · ' + duration(option.arrive - option.depart);
};

const factsNode = (app, option) => {
    const facts = el('span', 'route-option-facts');
    if (option.rides === 0) {
        facts.append(t('planner.card.walkAll', { time: duration(sumOf(option.legs, 'walk')), distance: distance(option.walk_meters) }));
        return facts;
    }
    ridesIn(option.legs).forEach((leg) => facts.append(linePill(leg.line.code, app.networkOf(leg.network))));
    const aFacts = [transfers(option.rides)];
    if (option.walk_meters > 0) {
        aFacts.push(I18n.t('route.card.walk', { distance: distance(option.walk_meters) }));
    }
    facts.append(aFacts.join(' · '));
    return facts;
};

const spokenText = (app, option, iPosition) => {
    const aParts = [I18n.t('route.card.option', { n: iPosition }), timesText(option)];
    ridesIn(option.legs).forEach((leg) => aParts.push(app.networkName(leg.network) + ' ' + leg.line.code));
    aParts.push(transfers(option.rides));
    return aParts.join('. ');
};

// En el ordenador la opción abierta se ve al lado de la lista: solo una a la vez.
const SIDE_BY_SIDE = '(min-width: 1024px)';

const toggle = (item, bOpen) => {
    if (bOpen && window.matchMedia(SIDE_BY_SIDE).matches) {
        item.parentElement.querySelectorAll('.route-option.is-open').forEach((other) => {
            if (other !== item) {
                toggle(other, false);
            }
        });
    }
    item.classList.toggle('is-open', bOpen);
    item.querySelector('.route-option-head').setAttribute('aria-expanded', String(bOpen));
    item.querySelector('.route-option-body').hidden = !bOpen;
};

const optionCard = (app, option, query, iDay, iPosition) => {
    const item = el('li', 'route-option');
    const head = el('button', 'route-option-head');
    const text = el('span', 'route-option-text');
    const body = el('div', 'route-option-body');
    const choose = el('button', 'route-choose', I18n.t('route.choose'));
    head.type = 'button';
    body.id = 'route-option-' + iPosition;
    head.setAttribute('aria-controls', body.id);
    text.append(optionTags(option, app), el('strong', 'route-option-times', timesText(option)), factsNode(app, option));
    text.setAttribute('aria-label', spokenText(app, option, iPosition));
    head.append(text, svgIcon('chevron', 'route-chevron'));
    head.addEventListener('click', () => toggle(item, !item.classList.contains('is-open') || window.matchMedia(SIDE_BY_SIDE).matches));
    choose.type = 'button';
    choose.addEventListener('click', () => app.chooseOption(option));
    body.append(timeline(app, option, query, iDay), costRow(), choose);
    item.dataset.rides = JSON.stringify(ridesOf(option));
    item.append(head, body);
    toggle(item, false);
    return item;
};

// Al repintar con el directo siguen abiertas las mismas opciones (por sus vehículos) y el foco vuelve a la suya.
const openRides = (list) => {
    const head = document.activeElement && document.activeElement.closest('.route-option-head');
    const aOpen = [...list.querySelectorAll('.route-option.is-open')].map((item) => item.dataset.rides);
    let sFocus = '';
    if (head && list.contains(head)) {
        sFocus = head.parentElement.dataset.rides;
    }
    return { open: aOpen, focus: sFocus };
};

const renderOptions = (app, aOptions, query, iDay, bKeep) => {
    const list = byId('route-options');
    let kept = { open: [], focus: '' };
    if (bKeep) {
        kept = openRides(list);
    }
    list.replaceChildren(...aOptions.slice(0, MAX_OPTIONS).map((option, i) => optionCard(app, option, query, iDay, i + 1)));
    const aItems = [...list.children];
    const aReopen = aItems.filter((item) => kept.open.includes(item.dataset.rides));
    if (aReopen.length === 0 && list.firstElementChild) {
        aReopen.push(list.firstElementChild);
    }
    aReopen.forEach((item) => toggle(item, true));
    const focused = aItems.find((item) => kept.focus !== '' && item.dataset.rides === kept.focus);
    if (focused) {
        focused.querySelector('.route-option-head').focus({ preventScroll: true });
    }
    byId('route-share').hidden = aOptions.every((option) => option.rides === 0);
};

// ===== Avisos de una búsqueda =====

// Un filtro que no deja viajes: lo dice, cuándo sale el primero que sí lo cumple y deja quitarlo.
const droppedNotice = (app, sFilter) => {
    const dropped = DROPPED[sFilter];
    let sText = I18n.t('route.dropped.' + dropped.key + 'Others');
    if (app.firstOk && app.firstOk.none) {
        sText = t('planner.firstOk.none') + ' ' + sText;
    } else if (app.firstOk) {
        sText = I18n.t('route.dropped.first', { time: clock(app.firstOk.depart) }) + ' ' + sText;
    }
    return notice('info', I18n.t('route.dropped.' + dropped.key, { n: app.filters.margin }), sText,
        [{ text: I18n.t('route.dropped.clear'), run: () => app.changeFilters(dropped.clear, true) }]);
};

const tooFar = (app, answer) => {
    let sTitle = t('planner.tooFar.to');
    if (answer.far_end === 'from') {
        sTitle = t('planner.tooFar.from');
    }
    if (!answer.nearest) {
        return notice('serious', sTitle, t('planner.tooFar.other'), []);
    }
    const sNearest = answer.nearest.name + ' (' + app.networkName(answer.nearest.network) + ')';
    return notice('serious', sTitle, t('planner.tooFar.nearest', { stop: sNearest, distance: distance(answer.nearest_meters) }), []);
};

const notInTime = (app, answer) => {
    let sText = t('planner.notInTime.none');
    if (answer.options.length > 0) {
        const iEarliest = Math.min(...answer.options.map((option) => option.arrive));
        sText = t('planner.notInTime.earliest', { time: clock(iEarliest) + dayNote(iEarliest) });
    }
    return notice('serious', t('planner.notInTime.title', { time: clock(app.request.time) }), sText,
        [{ text: t('planner.notInTime.now'), run: app.searchNow }]);
};

const noService = (app, answer) => {
    const option = answer.options[0];
    if (!option) {
        return notice('serious', t('planner.noService.title'), t('planner.noService.other'), []);
    }
    return notice('serious', t('planner.noService.title'), t('planner.noService.departs', { time: clock(option.depart) }),
        [{ text: t('planner.noService.search', { time: clock(option.depart) }), run: () => app.searchAt(option.depart) }]);
};

const noConnection = (app) => {
    const aActions = [];
    if (app.filters.networksOff.length > 0) {
        aActions.push({ text: t('planner.noConnection.all'), run: () => app.changeFilters({ networksOff: [] }, true) });
    }
    return notice('serious', t('planner.noConnection.title'), t('planner.noConnection.text'), aActions);
};

const DIAGNOSES = { too_far: tooFar, not_in_time: notInTime, no_service_in_window: noService, no_connection: noConnection };

// «Salir a» una hora que ya ha pasado: las opciones ya han salido.
const pastNotice = (app) => {
    const here = nowHere();
    const moment = momentOf(app.query);
    const bPast = moment.day < here.day || (moment.day === here.day && moment.time < here.seconds - PAST_TOLERANCE_SECONDS);
    if (app.query.when !== 'depart' || !bPast) {
        return [];
    }
    return [notice('care', t('planner.past.title'), t('planner.past.time', { time: clock(moment.time), now: clock(here.seconds) }),
        [{ text: t('planner.past.now'), run: app.searchNow }])];
};

// Con «Accesible»: si lo son todas, cuáles o ninguna.
const accessibleNotice = (app, answer, aOptions) => {
    if (!app.filters.accessible || aOptions.length === 0 || answer.dropped_filters.includes('accessible')) {
        return [];
    }
    const aYes = aOptions.map((option, i) => [option.accessible === true, i + 1]).filter((pair) => pair[0]).map((pair) => pair[1]);
    let sText = I18n.t('route.accessible.some', { list: aYes.join(', ') });
    if (aYes.length === aOptions.length) {
        sText = I18n.t('route.accessible.all');
    } else if (aYes.length === 0) {
        sText = I18n.t('route.accessible.none');
    }
    return [notice('info', sText, '', [])];
};

// La parada más cercana queda lejos pero se llega andando.
const farNotices = (answer) => {
    const aNotices = [];
    const first = answer.options[0];
    if (answer.far_from && first && first.legs[0].kind === 'walk' && first.legs[0].to) {
        aNotices.push(notice('info', t('planner.far.from', { distance: distance(first.legs[0].meters) }),
            t('planner.far.fromText', { stop: first.legs[0].to.name }), []));
    }
    return aNotices;
};

const noticesOf = (app, answer, aOptions) => {
    const aNotices = [];
    if (answer.diagnosis && DIAGNOSES[answer.diagnosis]) {
        aNotices.push(DIAGNOSES[answer.diagnosis](app, answer));
    }
    answer.dropped_filters.filter((sFilter) => DROPPED[sFilter]).slice(0, 1).forEach((sFilter) => aNotices.push(droppedNotice(app, sFilter)));
    return aNotices.concat(pastNotice(app), accessibleNotice(app, answer, aOptions), farNotices(answer));
};

// La etiqueta en mayúsculas que va encima de las opciones cuando no cumplen un filtro («Con menos margen»).
const droppedLabel = (answer) => {
    const sFilter = answer.dropped_filters.find((sOne) => DROPPED[sOne]);
    if (!sFilter) {
        return [];
    }
    return [el('p', 'route-group', I18n.t('route.dropped.' + DROPPED[sFilter].key + 'Label'))];
};

const summaryOf = (app, iCount) => {
    const sWhen = I18n.t('route.when.' + app.query.when, { time: clock(momentOf(app.query).time) });
    return countText('options', iCount, { when: sWhen });
};

// «Solo en directo»: las opciones con todos sus vehículos en directo. Si no queda ninguna, todas con un aviso.
const liveOnly = (app, answer, aOptions) => {
    if (!app.filters.liveOnly || answer.dropped_filters.includes('live_only')) {
        return { options: aOptions, notices: [] };
    }
    const aLive = aOptions.filter((option) => allLive(option, answer.day));
    if (aLive.length > 0) {
        return { options: aLive, notices: [] };
    }
    return { options: aOptions, notices: [notice('info', I18n.t('route.liveOnly.none'), I18n.t('route.liveOnly.noneText'),
        [{ text: I18n.t('route.dropped.clear'), run: () => app.changeFilters({ liveOnly: false }, true) }])] };
};

// Pinta las opciones de la búsqueda. Con bQuiet (el directo ha cambiado) no se anuncia y siguen abiertas las mismas.
export const renderResults = (app, bQuiet) => {
    const answer = app.answer;
    const shown = liveOnly(app, answer, answer.options.filter((option) => option.legs.length > 0));
    const aOptions = shown.options;
    showSearching(app, false);
    byId('route-filters-open').hidden = false;
    byId('route-notices').replaceChildren(...noticesOf(app, answer, aOptions.slice(0, MAX_OPTIONS)), ...shown.notices,
        ...droppedLabel(answer));
    renderOptions(app, aOptions, app.query, answer.day, Boolean(bQuiet));
    byId('route-summary').textContent = summaryOf(app, Math.min(aOptions.length, MAX_OPTIONS));
    if (!bQuiet) {
        app.announce(byId('route-summary').textContent);
    }
};

// ===== Enlace recibido =====

export const renderReceived = (app) => {
    const answer = app.received.answer;
    showSearching(app, false);
    if (!answer || answer.status !== 'ok') {
        renderProblem(app, t('planner.link.broken'), t('planner.link.brokenText'), []);
        return;
    }
    const aOptions = answer.options.filter((option) => option.legs);
    let sTitle = t('planner.link.titleMany', { n: aOptions.length });
    if (aOptions.length === 1) {
        sTitle = t('planner.link.title');
    }
    const aActions = [];
    const aRides = ridesIn((aOptions[0] && aOptions[0].legs) || []);
    if (aRides.length > 0) {
        const last = aRides[aRides.length - 1];
        aActions.push({ text: t('planner.link.fromHere'), run: () => app.searchFromHere(last.network, last.to) });
    }
    const aNotices = [notice('info', sTitle, I18n.t('route.received.text'), aActions)];
    if (aOptions.length > 0) {
        const names = namesOf(aOptions[0].legs, null);
        aNotices[0].insertBefore(el('span', 'route-notice-text', names.from + ' → ' + names.to), aNotices[0].children[1]);
    }
    byId('route-notices').replaceChildren(...aNotices);
    renderOptions(app, aOptions, null, app.received.day, false);
    byId('route-share').hidden = true;
    byId('route-filters-open').hidden = true;
    byId('route-summary').textContent = sTitle;
};

// Lo que se comparte de las opciones a la vista: sus vehículos, con la consulta.
export const sharedJourneys = (app) => app.answer.options.filter((option) => option.rides > 0).slice(0, MAX_OPTIONS).map(ridesOf);
