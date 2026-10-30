// Horario de una línea con los filtros de fecha y hora.
import { FEATURES, NETWORK, el, networkQuery, state } from './core.js';
import { toggleFavorite, updateFavoriteButton } from './favorites.js';
import { loadLineMap, startLineMapRefresh } from './line-map.js';
import { statusLabel } from './ui.js';
import { closeFromButton, closeLiveCard, closeTimetableSection } from './navigation.js';
import { openVehicleModal } from './vehicle-modal.js';

export async function selectLine(lineId, scopeToCurrentStop = false) {
    let line;
    try {
        line = await Api.line(lineId);
    } catch (e) {
        return;
    }
    if (!scopeToCurrentStop && state.currentStop) {
        closeLiveCard();
    }
    state.currentLine = { id: line.id, code: line.code, name: line.name };
    state.timetableStopId = null;
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

    await loadTimetable();
    if (FEATURES.line_map) {
        await loadLineMap(line.id);
        startLineMapRefresh(line.id);
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
        return;
    }
    if (requestId !== timetableRequestId) return;
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
    timetableNote.hidden = !(data.beyondPublished || data.beforePublished);
    if (data.beyondPublished) {
        timetableNote.textContent = I18n.t('app.timetable.beyond', { date: fmtDate(data.publishedUntil) });
    } else if (data.beforePublished) {
        timetableNote.textContent = I18n.t('app.timetable.before', { date: fmtDate(data.publishedFrom) });
    }
    const sourceLabel = NETWORK.attribution;
    el.attribution.textContent = `${sourceLabel} · Horario base publicado: ${data.scheduleSourcePublished}`;
}

export function renderTimetableRows(entries) {
    el.timetableBody.innerHTML = '';
    el.timetableEmpty.hidden = entries.length > 0;

    for (const entry of entries) {
        const { text, className } = statusLabel(entry.status, entry.delayMinutes);
        const tr = document.createElement('tr');
        if (entry.status === 'departed' || entry.status === 'likely_departed') tr.className = 'is-past';

        const departureCell = document.createElement('td');
        departureCell.textContent = entry.departure;

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

export function setUpTimetable() {
    el.timetableFavorite.addEventListener('click', () => toggleFavorite('line', state.currentLine?.id, el.timetableFavorite));
    el.timetableClose.addEventListener('click', () => closeFromButton('line', closeTimetableSection));

    el.filterDate.addEventListener('change', loadTimetable);
    el.filterHourFrom.addEventListener('change', loadTimetable);
    el.filterHourTo.addEventListener('change', loadTimetable);

    const now = new Date();
    const pad2 = (n) => String(n).padStart(2, '0');
    el.filterDate.value = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
    el.filterHourFrom.value = `${pad2(now.getHours())}:00`;
    el.filterHourTo.value = `${pad2((now.getHours() + 2) % 24)}:00`;
}
