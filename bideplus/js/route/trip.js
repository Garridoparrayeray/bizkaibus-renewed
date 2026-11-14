// La pantalla del viaje elegido: cuándo salir y a qué hora llegas, el reparto del tiempo, cada tramo (a pie, en
// vehículo con el color de su red, transbordo con su margen y qué hacer si pierdes el enlace) y «Empezar».
import * as engine from '/planificador/pantallas/engine.js';
import { transfersOf } from '/planificador/pantallas/journey.js';
import { nowHere, ridesOf, sameRides } from '/planificador/pantallas/query.js';
import { SECONDS_PER_MINUTE, clock, dayNote, distance, duration, minutesOf, spareText } from '/planificador/pantallas/texts.js';
import { inkOn } from '/planificador/pantallas/dom.js';
import { byId, colorOf, countText, el, namesOf, ridesIn, svgIcon } from './common.js';
import { liveOf } from './options.js';

// «Sal en N min» solo si es hoy y falta poco; si no, la hora de salida.
const SOON_SECONDS = 90 * SECONDS_PER_MINUTE;
const trip = { app: null, chosen: null, journey: null, render: 0, opener: null };

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
const rideCard = (app, leg, iDay) => {
    const network = app.networkOf(leg.network);
    const card = el('li', 'route-ride');
    const head = el('div', 'route-ride-head');
    const live = liveOf(leg, iDay);
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
const planBText = (journey, iRide, iEndWalk) => {
    const plan = journey && journey.plan_b[iRide - 1];
    if (!plan) {
        return I18n.t('route.trip.planBNone');
    }
    const next = ridesIn(plan.legs)[iRide];
    return I18n.t('route.trip.planB', { depart: clock(next.depart), arrive: clock(plan.arrive + iEndWalk) });
};

// Lo que se anda al bajar del último vehículo.
const endWalkOf = (option) => {
    const last = option.legs[option.legs.length - 1];
    if (last.kind === 'walk' && option.rides > 0) {
        return last.arrive - last.depart;
    }
    return 0;
};

const walkRow = (leg, sPlace) => row('walk', I18n.t('route.trip.walkTo', { time: clock(leg.depart), place: sPlace }),
    I18n.t('route.walk.short', { time: duration(leg.arrive - leg.depart), distance: distance(leg.meters) }));

const legsList = (app, chosen, option, journey) => {
    const list = el('ol', 'route-legs');
    const names = namesOf(option.legs, chosen.query);
    const aRides = ridesIn(option.legs);
    const aTransfers = transfersOf(option.legs);
    const last = option.legs[option.legs.length - 1];
    const iEndWalk = endWalkOf(option);
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
            let sTitle = I18n.t('route.trip.transferTitle', { time: clock(aRides[i - 1].arrive), spare: spareText(Math.max(0, transfer.spare)) });
            if (transfer.spare < 0) {
                sTitle = I18n.t('route.trip.transferLost', { time: clock(aRides[i - 1].arrive) });
            }
            let sText = I18n.t('route.trip.transferSame', { network: app.networkName(leg.network) });
            if (transfer.walk > 0) {
                sText = I18n.t('route.trip.transferWalk', { walk: duration(transfer.walk), stop: leg.from.name });
            }
            list.append(row('transfer', sTitle, sText));
        }
        list.append(rideCard(app, leg, chosen.day));
        if (i > 0) {
            list.append(row('clock', I18n.t('route.trip.planBTitle'), planBText(journey, i, iEndWalk)));
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
    if (chosen.option.rides < 1) {
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

// La opción con las horas de prepare, que llevan el directo de ahora: sus vehículos y lo que se anda entre ellos, con
// el primer y el último tramo a pie de la opción (prepare no los trae). Sin prepare, la opción tal cual.
const currentOption = (option, journey) => {
    if (!journey) {
        return option;
    }
    const aLegs = journey.journey.legs.slice();
    const first = option.legs[0];
    const last = option.legs[option.legs.length - 1];
    if (first.kind === 'walk') {
        const iWalk = first.arrive - first.depart;
        aLegs.unshift(Object.assign({}, first, { depart: aLegs[0].depart - iWalk, arrive: aLegs[0].depart }));
    }
    if (last.kind === 'walk' && option.legs.length > 1) {
        const iFrom = aLegs[aLegs.length - 1].arrive;
        aLegs.push(Object.assign({}, last, { depart: iFrom, arrive: iFrom + last.arrive - last.depart }));
    }
    return Object.assign({}, option, { legs: aLegs, depart: aLegs[0].depart, arrive: aLegs[aLegs.length - 1].arrive });
};

// Con el retraso de ahora no se llega a un transbordo: el planificador ya monta el viaje con el vehículo siguiente
// (cambia uno que no es el primero) o lo deja sin margen. Se dice arriba, con la hora del siguiente y la de llegada.
const lostNotice = (chosen, option, journey) => {
    const aBefore = ridesOf(chosen.option);
    const aNow = ridesIn(option.legs);
    const iChanged = ridesOf(option).findIndex((ride, i) => !sameRides([ride], [aBefore[i]]));
    const iTight = transfersOf(option.legs).findIndex((transfer) => transfer.spare < 0);
    let sText = '';
    if (iChanged > 0) {
        sText = I18n.t('route.trip.planB', { depart: clock(aNow[iChanged].depart), arrive: clock(option.arrive) });
    } else if (iTight >= 0) {
        sText = planBText(journey, iTight + 1, endWalkOf(option));
    } else {
        return [];
    }
    const box = el('div', 'route-notice');
    box.dataset.kind = 'serious';
    box.setAttribute('role', 'status');
    box.append(el('strong', 'route-notice-title', I18n.t('route.trip.lost')), el('span', 'route-notice-text', sText));
    return [box];
};

const paint = (app, chosen, journey) => {
    const option = currentOption(chosen.option, journey);
    const shown = Object.assign({}, chosen, { option });
    byId('route-trip-body').replaceChildren(hero(app, shown), ...lostNotice(chosen, option, journey), legsList(app, shown, option, journey),
        startLink(option));
};

const repaint = async (iRender) => {
    const journey = await prepared(trip.chosen);
    if (iRender === trip.render && !byId('route-trip').hidden) {
        trip.journey = journey;
        paint(trip.app, trip.chosen, journey);
    }
};

// Con el directo nuevo, si el viaje está abierto, se vuelve a montar con sus horas de ahora.
export const refreshTrip = async () => {
    if (byId('route-trip').hidden || !trip.chosen) {
        return;
    }
    trip.render += 1;
    await repaint(trip.render);
};

// Lo que el directo tiene que mirar del viaje abierto: sus tramos de ahora y su día.
export const tripSource = () => {
    if (byId('route-trip').hidden || !trip.chosen) {
        return [];
    }
    return [{ legs: currentOption(trip.chosen.option, trip.journey).legs, day: trip.chosen.day }];
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
    trip.journey = null;
    await repaint(iRender);
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

