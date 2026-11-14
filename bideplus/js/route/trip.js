// La pantalla del viaje elegido: cuándo salir y a qué hora llegas, el reparto del tiempo, cada tramo (a pie, en
// vehículo con el color de su red, transbordo con su margen y qué hacer si pierdes el enlace) y «Empezar».
import * as engine from '/planificador/pantallas/engine.js';
import { transfersOf } from '/planificador/pantallas/journey.js';
import { nowHere, ridesOf } from '/planificador/pantallas/query.js';
import { SECONDS_PER_MINUTE, clock, dayNote, distance, duration, minutesOf, spareText } from '/planificador/pantallas/texts.js';
import { inkOn } from '/planificador/pantallas/dom.js';
import { byId, colorOf, countText, el, namesOf, ridesIn, svgIcon } from './common.js';
import { liveOf } from './options.js';

// «Sal en N min» solo si es hoy y falta poco; si no, la hora de salida.
const SOON_SECONDS = 90 * SECONDS_PER_MINUTE;
const trip = { app: null, chosen: null, render: 0, opener: null };

const leaveNode = (chosen) => {
    const box = el('div', 'route-hero-leave');
    const here = nowHere();
    const iLeft = chosen.option.depart - here.seconds;
    const big = el('span', 'route-hero-big');
    if (chosen.day === here.day && iLeft >= 0 && iLeft <= SOON_SECONDS) {
        box.append(el('span', 'route-hero-label', I18n.t('route.trip.leaveIn')));
        big.append(el('span', '', String(minutesOf(iLeft))), el('span', 'route-hero-unit', 'min'));
    } else {
        box.append(el('span', 'route-hero-label', I18n.t('route.trip.leaveAt')));
        big.append(el('span', '', clock(chosen.option.depart)));
    }
    box.append(big);
    return box;
};

// La barra del reparto: a pie, cada vehículo con el color de su red y lo que se espera entre medias.
const splitBar = (app, option) => {
    const bar = el('div', 'route-split');
    const aSpoken = [];
    let iEnd = option.depart;
    option.legs.forEach((leg) => {
        if (leg.depart > iEnd) {
            const wait = el('span', 'route-split-part');
            wait.dataset.kind = 'wait';
            wait.style.flexGrow = String(leg.depart - iEnd);
            bar.append(wait);
            aSpoken.push(I18n.t('route.trip.splitWait', { time: duration(leg.depart - iEnd) }));
        }
        const part = el('span', 'route-split-part');
        part.dataset.kind = leg.kind;
        part.style.flexGrow = String(Math.max(1, leg.arrive - leg.depart));
        if (leg.kind === 'ride') {
            part.style.background = colorOf(app.networkOf(leg.network));
            aSpoken.push(I18n.t('route.trip.splitRide', { time: duration(leg.arrive - leg.depart), network: app.networkName(leg.network) }));
        } else {
            aSpoken.push(I18n.t('route.trip.splitWalk', { time: duration(leg.arrive - leg.depart) }));
        }
        bar.append(part);
        iEnd = Math.max(iEnd, leg.arrive);
    });
    bar.setAttribute('role', 'img');
    bar.setAttribute('aria-label', I18n.t('route.trip.split', { parts: aSpoken.join(', ') }));
    return bar;
};

const stat = (sValue, sLabel) => {
    const box = el('div', 'route-stat');
    box.append(el('strong', '', sValue), el('span', '', sLabel));
    return box;
};

const hero = (app, chosen) => {
    const option = chosen.option;
    const box = el('div', 'route-hero');
    const top = el('div', 'route-hero-top');
    const arrive = el('div', 'route-hero-arrive');
    arrive.append(el('span', 'route-hero-label', I18n.t('route.trip.arriveAt')), el('span', 'route-hero-clock', clock(option.arrive) + dayNote(option.arrive)));
    top.append(leaveNode(chosen), arrive);
    const stats = el('div', 'route-stats');
    let sTransfers = I18n.t('route.trip.transfers');
    if (Math.max(0, option.rides - 1) === 1) {
        sTransfers = I18n.t('route.trip.transfer');
    }
    stats.append(stat(duration(option.arrive - option.depart), I18n.t('route.trip.total')),
        stat(String(Math.max(0, option.rides - 1)), sTransfers), stat(distance(option.walk_meters), I18n.t('route.trip.onFoot')),
        stat(I18n.t('route.cost.unknown'), I18n.t('route.trip.cost')));
    box.append(top, splitBar(app, option), stats);
    return box;
};

const row = (sIcon, sTitle, sText) => {
    const item = el('li', 'route-row');
    const text = el('span', 'route-row-text');
    text.append(el('strong', '', sTitle), el('span', '', sText));
    item.append(svgIcon(sIcon, 'route-row-icon'), text);
    return item;
};

// La tarjeta de un vehículo, con el color y el icono de su red como las fichas de «Tus redes».
const rideCard = (app, leg) => {
    const network = app.networkOf(leg.network);
    const card = el('li', 'route-ride');
    const head = el('div', 'route-ride-head');
    const live = liveOf(leg);
    const badge = el('span', 'route-ride-badge', live.text);
    const facts = [I18n.t('route.trip.arrives', { time: clock(leg.arrive) }), countText('stops', leg.stops)];
    if (live.live) {
        badge.classList.add('is-live');
    }
    card.style.setProperty('--ride', colorOf(network));
    card.style.setProperty('--ride-ink', inkOn(colorOf(network)));
    if (BideNetworks.exists(leg.network)) {
        const mark = el('img', 'route-ride-mark');
        mark.src = '/' + BideNetworks.all[leg.network].icons + '/icon-192.png';
        mark.alt = '';
        card.append(mark);
    }
    head.append(el('strong', '', app.networkName(leg.network) + ' · ' + leg.line.code), badge);
    card.append(head);
    if (String(leg.headsign || '').trim().length > 2) {
        card.append(el('span', 'route-ride-towards', I18n.t('route.trip.towards', { headsign: leg.headsign })));
    }
    card.append(el('span', 'route-ride-time', clock(leg.depart)), el('strong', 'route-ride-stops', leg.from.name + ' → ' + leg.to.name),
        el('span', 'route-ride-facts', facts.join(' · ')));
    return card;
};

// «Si pierdes el enlace»: el siguiente que sirve y a qué hora llegas, de la operación prepare del planificador.
const planBRow = (journey, iRide, iEndWalk) => {
    const plan = journey && journey.plan_b[iRide - 1];
    if (!plan) {
        return row('clock', I18n.t('route.trip.planBTitle'), I18n.t('route.trip.planBNone'));
    }
    const next = ridesIn(plan.legs)[iRide];
    const iArrive = plan.arrive + iEndWalk;
    return row('clock', I18n.t('route.trip.planBTitle'), I18n.t('route.trip.planB', { depart: clock(next.depart), arrive: clock(iArrive) }));
};

const walkRow = (leg, sPlace) => row('walk', I18n.t('route.trip.walkTo', { time: clock(leg.depart), place: sPlace }),
    I18n.t('route.walk.short', { time: duration(leg.arrive - leg.depart), distance: distance(leg.meters) }));

const legsList = (app, chosen, journey) => {
    const option = chosen.option;
    const list = el('ol', 'route-legs');
    const names = namesOf(option.legs, chosen.query);
    const aRides = ridesIn(option.legs);
    const aTransfers = transfersOf(option.legs);
    const last = option.legs[option.legs.length - 1];
    let iEndWalk = 0;
    if (last.kind === 'walk' && aRides.length > 0) {
        iEndWalk = last.arrive - last.depart;
    }
    if (option.legs[0].kind === 'walk') {
        let sPlace = names.to;
        if (aRides.length > 0) {
            sPlace = aRides[0].from.name;
        }
        list.append(walkRow(option.legs[0], sPlace));
    }
    aRides.forEach((leg, i) => {
        if (i > 0) {
            const transfer = aTransfers[i - 1];
            const sTitle = I18n.t('route.trip.transferTitle', { time: clock(aRides[i - 1].arrive), spare: spareText(Math.max(0, transfer.spare)) });
            let sText = I18n.t('route.trip.transferSame', { network: app.networkName(leg.network) });
            if (transfer.walk > 0) {
                sText = I18n.t('route.trip.transferWalk', { walk: duration(transfer.walk), stop: leg.from.name });
            }
            list.append(row('transfer', sTitle, sText));
        }
        list.append(rideCard(app, leg));
        if (i > 0) {
            list.append(planBRow(journey, i, iEndWalk));
        }
    });
    if (iEndWalk > 0) {
        list.append(walkRow(Object.assign({}, last, { depart: aRides[aRides.length - 1].arrive }), names.to));
    }
    return list;
};

// «Empezar» abre, de momento, la parada donde subes en la app de su red, con su directo y sus avisos.
const startLink = (option) => {
    const ride = ridesIn(option.legs)[0];
    const box = el('div', 'route-start');
    if (!ride) {
        return box;
    }
    const link = el('a', 'route-start-button', I18n.t('route.trip.start'));
    link.href = '/stops/' + encodeURIComponent(ride.from.id) + '?red=' + encodeURIComponent(ride.network);
    box.append(link, el('p', 'route-start-note', I18n.t('route.trip.startNote', { stop: ride.from.name })));
    return box;
};

const prepared = async (chosen) => {
    if (chosen.option.rides < 2) {
        return null;
    }
    const bAccessible = Boolean(chosen.request && chosen.request.accessible);
    const answer = await engine.call({ op: 'prepare', day: chosen.day, journeys: [ridesOf(chosen.option)], accessible: bAccessible }, 'prepare')
        .catch(() => null);
    if (!answer || answer.status !== 'ok') {
        return null;
    }
    return answer;
};

const paint = (app, chosen, journey) => {
    byId('route-trip-body').replaceChildren(hero(app, chosen), legsList(app, chosen, journey), startLink(chosen.option));
};

export const openTrip = async (app, chosen) => {
    Object.assign(trip, { app, chosen, opener: document.activeElement });
    trip.render += 1;
    const iRender = trip.render;
    const names = namesOf(chosen.option.legs, chosen.query);
    byId('route-trip-title').textContent = I18n.t('route.trip.title', { place: names.to });
    paint(app, chosen, null);
    byId('route-trip').hidden = false;
    document.documentElement.classList.add('route-trip-open');
    window.scrollTo(0, 0);
    byId('route-trip-title').focus({ preventScroll: true });
    const journey = await prepared(chosen);
    if (journey && iRender === trip.render && !byId('route-trip').hidden) {
        paint(app, chosen, journey);
    }
};

export const closeTrip = () => {
    if (byId('route-trip').hidden) {
        return;
    }
    byId('route-trip').hidden = true;
    document.documentElement.classList.remove('route-trip-open');
    if (trip.opener && document.contains(trip.opener)) {
        trip.opener.focus({ preventScroll: false });
    }
};

export const initTrip = (app) => {
    byId('route-trip-back').addEventListener('click', () => history.back());
    byId('route-trip-share').addEventListener('click', () => {
        const chosen = trip.chosen;
        app.share(chosen.day, [ridesOf(chosen.option)], chosen.query, chosen.request, 'route.share.tripTitle');
    });
};

