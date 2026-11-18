// La tarjeta «¿A dónde vas?»: origen y destino se escriben ahí mismo y las sugerencias del buscador salen debajo de
// cada campo (planificador/pantallas/place-field.js); intercambiar, cuándo (ahora, salir a, llegar a), día y hora.
import { createPlaceField } from '/planificador/pantallas/place-field.js';
import { LOCATION, nowHere, secondsOfText } from '/planificador/pantallas/query.js';
import { placeKey, readLocationOff, readRecent } from '/planificador/pantallas/prefs.js';
import { SECONDS_PER_DAY, SECONDS_PER_MINUTE, clock } from '/planificador/pantallas/texts.js';
import { byId, el } from './common.js';

const ROUND_MINUTES = 5;
const DAYS_AHEAD = 6;
const MAX_REMEMBERED = 4;
const form = document.querySelector('[data-planner-card]');
const time = form.querySelector('[data-planner-time]');
const day = form.querySelector('[data-planner-day]');
const hour = form.querySelector('[data-planner-hour]');

// En un campo vacío: «Tu ubicación» (si no está apagada en Ajustes) y los últimos sitios buscados.
const remembered = () => {
    const aPlaces = [];
    readRecent().forEach((route) => {
        [route.to, route.from].forEach((place) => {
            if (place.kind !== 'location' && !aPlaces.some((other) => placeKey(other) === placeKey(place))) {
                aPlaces.push(place);
            }
        });
    });
    const aRemembered = aPlaces.slice(0, MAX_REMEMBERED).map((place) => ({ kind: 'remembered', place }));
    if (readLocationOff()) {
        return aRemembered;
    }
    return [{ kind: 'location' }].concat(aRemembered);
};

// El punto del otro campo, para que salgan antes las sugerencias de cerca.
const nearOf = (field) => {
    const place = field.get();
    if (place && Number.isInteger(place.lat) && Number.isInteger(place.lon)) {
        return { lat: place.lat, lon: place.lon };
    }
    return null;
};

const selectedWhen = () => form.querySelector('input[name="cuando"]:checked').value;

// Con «Salir a» o «Llegar a», el día y la hora; sin hora, la de ahora redondeada a 5 minutos.
const showTime = () => {
    time.hidden = selectedWhen() === 'now';
    if (time.hidden || hour.value !== '') {
        return;
    }
    const iStep = ROUND_MINUTES * SECONDS_PER_MINUTE;
    hour.value = clock(Math.min(Math.ceil(nowHere().seconds / iStep) * iStep, SECONDS_PER_DAY - iStep));
};

const dayName = (iDay, iToday) => {
    if (iDay === iToday) {
        return I18n.t('menu.planner.today');
    }
    if (iDay === iToday + 1) {
        return I18n.t('menu.planner.tomorrow');
    }
    const date = new Date(iDay * SECONDS_PER_DAY * 1000);
    return I18n.t('app.day.' + (((date.getUTCDay() + 6) % 7) + 1)) + ' ' + date.getUTCDate();
};

// Hoy, mañana y los días siguientes («lunes 16»), con el número de día del planificador de valor.
const fillDays = (iExtra) => {
    const iToday = nowHere().day;
    const sChosen = day.value;
    const aDays = [];
    for (let i = 0; i <= DAYS_AHEAD; i++) {
        aDays.push(iToday + i);
    }
    if (Number.isInteger(iExtra) && !aDays.includes(iExtra)) {
        aDays.push(iExtra);
    }
    day.replaceChildren(...aDays.map((iDay) => {
        const option = el('option', '', dayName(iDay, iToday));
        option.value = String(iDay);
        return option;
    }));
    day.value = sChosen;
    if (day.value === '') {
        day.value = String(iToday);
    }
};

// Cuándo se quiere viajar: { when, day, time }, o null si falta la hora.
export const readWhen = () => {
    const sWhen = selectedWhen();
    if (sWhen === 'now') {
        return { when: 'now', day: null, time: null };
    }
    const iTime = secondsOfText(hour.value);
    if (iTime === null) {
        hour.focus();
        return null;
    }
    return { when: sWhen, day: Number(day.value), time: iTime };
};

// Pone en la tarjeta el cuándo de una consulta.
export const fillWhen = (query) => {
    form.querySelector('input[name="cuando"][value="' + query.when + '"]').checked = true;
    if (query.when !== 'now') {
        fillDays(query.day);
        day.value = String(query.day);
        hour.value = clock(query.time);
    }
    showTime();
};

// El origen de partida: «Tu ubicación», salvo que esté apagada en Ajustes.
export const defaultOrigin = () => {
    if (readLocationOff()) {
        return null;
    }
    return LOCATION;
};

const swap = (fields) => {
    const from = fields.from.get();
    const sFrom = byId('from-input').value;
    const to = fields.to.get();
    const sTo = byId('to-input').value;
    fields.from.set(to);
    if (!to) {
        fields.from.type(sTo);
    }
    fields.to.set(from);
    if (!from) {
        fields.to.type(sFrom);
    }
};

// app: { networkOf(red), announce(texto), wake() (arranca el planificador), search() }. Devuelve los dos campos.
export const initCard = (app) => {
    const fields = {};
    const context = (sField, sEmptyKey, sOther) => ({
        emptyKey: sEmptyKey,
        networkOf: (sSlug) => app.networkOf(sSlug),
        near: () => nearOf(fields[sOther]),
        remembered,
        announce: (sText) => app.announce(sText),
        onChange: () => {},
        next: () => {
            if (sField === 'from' && !fields.to.get()) {
                fields.to.focus();
            }
        },
    });
    fields.from = createPlaceField('from', context('from', 'planner.place.emptyFrom', 'to'));
    fields.to = createPlaceField('to', context('to', 'planner.place.emptyTo', 'from'));
    fields.from.set(defaultOrigin());
    fillDays(null);
    showTime();
    form.querySelectorAll('input[name="cuando"]').forEach((radio) => radio.addEventListener('change', showTime));
    form.querySelector('[data-planner-swap]').addEventListener('click', () => swap(fields));
    form.addEventListener('focusin', app.wake);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        app.search();
    });
    window.addEventListener('bide:lang', () => {
        fillDays(null);
        if (fields.from.get() === LOCATION) {
            fields.from.set(LOCATION);
        }
    });
    return fields;
};
