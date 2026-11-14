// El directo del planificador del inicio: pide a /api/planner/live el retraso de los vehículos de las opciones que se
// ven y del viaje elegido, se lo pasa al núcleo (operación live) y avisa para repetir la búsqueda. Se refresca cada
// 30 s con la pestaña a la vista y se para en segundo plano. Si falla, todo sigue con el horario.
import * as engine from '/planificador/pantallas/engine.js';
import { nowHere } from '/planificador/pantallas/query.js';
import { ridesIn } from './common.js';

const LIVE_URL = '/api/planner/live';
const REFRESH_MS = 30000;
const TIMEOUT_MS = 8000;
// Un dato que no se renueva en dos vueltas deja de valer: el vehículo vuelve a su horario.
const STALE_MS = 2 * REFRESH_MS;
// Los tramos que caben en una petición (los que acepta la API) y hasta cuándo se pregunta: más allá no hay directo.
const MAX_LEGS = 40;
const SECONDS_PER_DAY = 86400;
const AHEAD_SECONDS = 2 * 3600;
const LEG_SEPARATOR = ',';
const PART_SEPARATOR = '|';

const live = { entries: new Map(), asked: new Map(), sent: '', running: null, timer: 0, last: 0, sources: null, changed: null };

const keyOf = (sNetwork, sTrip, iDay) => sNetwork + PART_SEPARATOR + sTrip + PART_SEPARATOR + iDay;

// Lo que dice el directo del vehículo de un tramo ({ delay, cancelled }), o null si va con su horario.
export const liveEntry = (leg, iDay) => {
    const entry = live.entries.get(keyOf(leg.network, leg.trip, iDay + leg.day_shift));
    if (!entry || Date.now() - entry.at > STALE_MS) {
        return null;
    }
    return entry.update;
};

// Los tramos de hoy que salen pronto o van ya en marcha, uno por vehículo, con su parada, línea y hora sin retraso.
const wantedLegs = () => {
    const here = nowHere();
    const wanted = new Map();
    live.sources().forEach((source) => {
        const iOffset = (source.day - here.day) * SECONDS_PER_DAY;
        ridesIn(source.legs).forEach((leg) => {
            const bToday = source.day + leg.day_shift === here.day;
            const bSoon = leg.depart + iOffset <= here.seconds + AHEAD_SECONDS && leg.arrive + iOffset >= here.seconds;
            const sKey = keyOf(leg.network, leg.trip, here.day);
            if (bToday && bSoon && !wanted.has(sKey)) {
                wanted.set(sKey, { key: sKey, network: leg.network, stop: leg.from.id, line: leg.line.code, depart: leg.scheduled_depart,
                    trip: leg.trip });
            }
        });
    });
    return Array.from(wanted.values()).slice(0, MAX_LEGS);
};

// Si hay tramos por los que aún no se ha preguntado (o hace mucho): pasa al repetir la búsqueda con el directo.
export const unaskedLegs = () => wantedLegs().some((leg) => !live.asked.has(leg.key) || Date.now() - live.asked.get(leg.key) > REFRESH_MS);

const legsParam = (aLegs) => aLegs.map((leg) => [leg.network, leg.stop, leg.line, leg.depart, leg.trip].map(encodeURIComponent).join(PART_SEPARATOR))
    .join(LEG_SEPARATOR);

const download = async (aLegs) => {
    const abort = new AbortController();
    const iTimer = setTimeout(() => abort.abort(), TIMEOUT_MS);
    try {
        const response = await fetch(LIVE_URL + '?legs=' + encodeURIComponent(legsParam(aLegs)), { signal: abort.signal });
        if (!response.ok) {
            return null;
        }
        const answer = await response.json();
        if (!Array.isArray(answer.updates) || answer.day !== nowHere().day) {
            return null;
        }
        return answer;
    } catch (error) {
        return null;
    } finally {
        clearTimeout(iTimer);
    }
};

// Lo preguntado sin dato vuelve a su horario; lo que llega se apunta con su hora.
const remember = (aLegs, answer) => {
    const iNow = Date.now();
    aLegs.forEach((leg) => {
        live.asked.set(leg.key, iNow);
        live.entries.delete(leg.key);
    });
    answer.updates.forEach((update) => {
        live.entries.set(keyOf(update.network, update.trip, answer.day), { at: iNow, update: Object.assign({ day: answer.day }, update) });
    });
};

// Manda al núcleo lo que vale ahora. true si ha cambiado algo de lo que había.
const apply = async () => {
    const iNow = Date.now();
    live.entries.forEach((entry, sKey) => {
        if (iNow - entry.at > STALE_MS) {
            live.entries.delete(sKey);
        }
    });
    const aUpdates = Array.from(live.entries.values()).map((entry) => entry.update);
    const sSent = JSON.stringify(aUpdates.map((update) => [update.network, update.trip, update.day, update.delay, update.cancelled]).sort());
    if (sSent === live.sent) {
        return false;
    }
    live.sent = sSent;
    await engine.setLive(nowHere().day, aUpdates).catch(() => null);
    return true;
};

const refresh = async () => {
    const aLegs = wantedLegs();
    if (aLegs.length > 0) {
        const answer = await download(aLegs);
        if (answer) {
            remember(aLegs, answer);
        }
    }
    live.last = Date.now();
    return apply();
};

// Pide el directo de lo que se ve y lo aplica: true si ha cambiado. Si ya hay una petición en marcha, es esa.
export const refreshLive = () => {
    if (!live.running) {
        live.running = refresh().finally(() => {
            live.running = null;
        });
    }
    return live.running;
};

const tick = async () => {
    clearTimeout(live.timer);
    if (document.hidden) {
        return;
    }
    if (await refreshLive()) {
        await live.changed();
    }
    scheduleLive();
};

// Vuelve a empezar la cuenta de 30 s (después de una búsqueda o de abrir un viaje).
export const scheduleLive = () => {
    clearTimeout(live.timer);
    if (!document.hidden) {
        live.timer = setTimeout(tick, REFRESH_MS);
    }
};

// sources() da lo que se ve ([{ legs, day }]); changed() repite la búsqueda y repinta cuando cambia el directo.
export const initLive = (sources, changed) => {
    Object.assign(live, { sources, changed });
    document.addEventListener('visibilitychange', () => {
        clearTimeout(live.timer);
        if (document.hidden || live.last === 0) {
            return;
        }
        if (Date.now() - live.last >= REFRESH_MS) {
            tick();
            return;
        }
        live.timer = setTimeout(tick, REFRESH_MS - (Date.now() - live.last));
    });
};
