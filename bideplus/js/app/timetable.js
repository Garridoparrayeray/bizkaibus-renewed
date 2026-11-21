// Horario de una línea con los filtros de fecha y hora.
import { FEATURES, el, hereNow, networkQuery, savedSchedule, state, syncPageContent } from './core.js';
import { toggleFavorite, updateFavoriteButton } from './favorites.js';
import { loadLineMap } from './line-map.js';
import { statusLabel } from './ui.js';
import { closeFromButton, closeLiveCard, closeTimetableSection, updateTitle } from './navigation.js';
import { openVehicleModal } from './vehicle-modal.js';
import { forgetStopRequests } from './stop.js';

// La línea: con la red guardada en el móvil, de ahí, sin pedirla al servidor; si no, de la API.
async function lineInfo(lineId) {
    const saved = await savedSchedule();
    if (saved) {
        const line = BideOffline.line(saved, lineId);
        if (line) return line;
    }
    return Api.line(lineId);
}

export async function selectLine(lineId, scopeToCurrentStop = false) {
    let line;
    try {
        line = await lineInfo(lineId);
    } catch (e) {
        return;
    }
    // Una parada que aún no ha llegado tampoco se abre ya encima de la línea.
    if (!scopeToCurrentStop) forgetStopRequests();
    if (!scopeToCurrentStop && state.currentStop) {
        closeLiveCard();
    }
    state.currentLine = { id: line.id, code: line.code, name: line.name };
    state.timetableStopId = null;
    syncPageContent('line', line.id);
    updateTitle();
    if (scopeToCurrentStop) state.timetableStopId = state.currentStop?.id ?? null;
    updateFavoriteButton(el.timetableFavorite, 'line', line.id);
    el.timetableSection.hidden = false;
    el.timetableLine.textContent = `${line.code} · ${line.name}`;
    el.timetableSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

    if (!window.location.pathname.startsWith('/lines/' + lineId)) {
        if (history.state && history.state.view === 'line') {
            history.replaceState({ view: 'line', id: lineId, fromApp: true }, '', '/lines/' + lineId + networkQuery);
        } else {
            history.pushState({ view: 'line', id: lineId, fromApp: true }, '', '/lines/' + lineId + networkQuery);
        }
    } else if (!history.state || !history.state.view) {
        history.replaceState({ view: 'line', id: lineId, fromApp: true }, '', window.location.href);
    }

    followClock();
    await loadTimetable();
    if (FEATURES.line_map) {
        await loadLineMap(line.id);
    }
}

let timetableRequestId = 0;

export async function loadTimetable() {
    if (!state.currentLine) return;
    const requestId = ++timetableRequestId;
    let data;
    try {
        data = await Api.timetable(state.currentLine.id, {
            date: el.filterDate.value,
            hourFrom: el.filterHourFrom.value,
            hourTo: el.filterHourTo.value,
            stopId: state.timetableStopId,
        });
    } catch (e) {
        data = null;
    }
    // Sin conexión, el horario guardado en el móvil, también en vez de la copia que guardó el service worker (offline),
    // que lleva los estados y retrasos de cuando se pidió. Sin horario guardado, esa copia sin lo del directo.
    if (!data || data.offline) {
        const saved = await offlineTimetable();
        if (saved) data = saved;
        else if (data) data = withoutLive(data);
    }
    if (!data || requestId !== timetableRequestId) return;
    state.lastTimetableEntries = data.entries;
    renderTimetableRows(data.entries);
    const timetableNote = document.getElementById('timetable-note');
    const fmtDate = (iso) => { const [y, m, d] = iso.split('-'); return `${d}/${m}/${y}`; };
    const rangeNote = document.getElementById('timetable-range');
    if (data.publishedFrom && data.publishedUntil) {
        rangeNote.textContent = I18n.t('app.timetable.range', { from: fmtDate(data.publishedFrom), to: fmtDate(data.publishedUntil) });
        rangeNote.hidden = false;
    } else {
        rangeNote.hidden = true;
    }
    const savedOnPhone = data.offline && data.generated;
    timetableNote.hidden = !(data.beyondPublished || data.beforePublished || savedOnPhone);
    if (savedOnPhone) {
        timetableNote.textContent = I18n.t('app.timetable.offline', { date: fmtDate(data.generated) });
    } else if (data.beyondPublished) {
        timetableNote.textContent = I18n.t('app.timetable.beyond', { date: fmtDate(data.publishedUntil) });
    } else if (data.beforePublished) {
        timetableNote.textContent = I18n.t('app.timetable.before', { date: fmtDate(data.publishedFrom) });
    }
    state.schedulePublished = data.scheduleSourcePublished;
    renderSchedulePublished();
}

// Sin conexión, el horario del día y la franja elegidos sale del guardado en el móvil.
async function offlineTimetable() {
    const saved = await savedSchedule();
    if (!saved) return null;
    const [year, month, day] = el.filterDate.value.split('-').map(Number);
    return BideOffline.timetable(saved, state.currentLine.id, {
        date: new Date(year, month - 1, day),
        from: hmSeconds(el.filterHourFrom.value || '00:00'),
        to: hmSeconds(el.filterHourTo.value || '23:59'),
        stopId: state.timetableStopId,
        now: new Date(),
    });
}

function withoutLive(data) {
    return { ...data, entries: (data.entries || []).map((entry) => ({ ...entry, status: 'scheduled', delayMinutes: 0, reason: null })) };
}

function hmSeconds(value) {
    const [hours, minutes] = value.split(':').map(Number);
    return hours * 3600 + minutes * 60;
}

export function renderSchedulePublished() {
    if (!state.schedulePublished) return;
    el.schedulePublished.textContent = ' · ' + I18n.t('app.timetable.published', { date: state.schedulePublished });
}

export function renderTimetableRows(entries) {
    el.timetableBody.innerHTML = '';
    el.timetableEmpty.hidden = entries.length > 0;

    for (const entry of entries) {
        const { text, className } = statusLabel(entry.status, entry.delayMinutes, { reason: entry.reason });
        const tr = document.createElement('tr');
        if (entry.status === 'departed' || entry.status === 'likely_departed') tr.className = 'is-past';

        // La hora es un botón: la fila se abre también con el teclado (el clic en cualquier sitio de la fila, como antes).
        const departureCell = document.createElement('td');
        const open = document.createElement('button');
        open.type = 'button';
        open.className = 'timetable-open';
        open.textContent = entry.departure;
        departureCell.appendChild(open);

        const headsignCell = document.createElement('td');
        headsignCell.textContent = entry.headsign;

        const statusCell = document.createElement('td');
        statusCell.textContent = text;
        statusCell.className = className;

        tr.append(departureCell, headsignCell, statusCell);
        tr.addEventListener('click', () => openVehicleModal(entry.tripKey));
        el.timetableBody.appendChild(tr);
    }
}

/*
 * El filtro va con el día y la franja de ahora, con la hora de Madrid: al abrir una línea y al volver a la app, que puede
 * llevar abierta desde ayer. Lo que elige la persona se respeta el resto de ese día (chosenOn).
 */
let chosenOn = null;

// true si ha cambiado.
function followClock() {
    const here = hereNow();
    if (chosenOn === here.date) return false;
    const pad2 = (n) => String(n).padStart(2, '0');
    const from = `${pad2(here.hour)}:00`;
    const to = `${pad2((here.hour + 2) % 24)}:00`;
    if (el.filterDate.value === here.date && el.filterHourFrom.value === from && el.filterHourTo.value === to) return false;
    el.filterDate.value = here.date;
    el.filterHourFrom.value = from;
    el.filterHourTo.value = to;
    return true;
}

function chooseFilter() {
    chosenOn = hereNow().date;
    loadTimetable();
}

export function setUpTimetable() {
    el.timetableFavorite.addEventListener('click', () => toggleFavorite('line', state.currentLine?.id, el.timetableFavorite));
    el.timetableClose.addEventListener('click', () => closeFromButton('line', closeTimetableSection));

    el.filterDate.addEventListener('change', chooseFilter);
    el.filterHourFrom.addEventListener('change', chooseFilter);
    el.filterHourTo.addEventListener('change', chooseFilter);

    followClock();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && followClock() && state.currentLine) loadTimetable();
    });
}
