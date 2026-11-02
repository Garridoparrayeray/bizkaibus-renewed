// Una parada: sus salidas en directo (tarjeta o andenes), refresco periódico y horario guardado sin conexión.
import { FEATURES, HAS_PLATFORMS, NETWORK, NETWORK_SLUG, VEHICLE_PLURAL, el, networkQuery, state } from './core.js';
import { favoriteKey, toggleFavorite, updateFavoriteButton } from './favorites.js';
import { escapeHtml, liveBadgeText, setDelaySub, statusLabel } from './ui.js';
import { closeFromButton, closeLiveCard, closeTimetableSection } from './navigation.js';
import { loadTimetable, selectLine } from './timetable.js';
import { DEPARTED_GRACE_MS, openVehicleModal } from './vehicle-modal.js';

const DEPARTURES_REFRESH_MS = 25000;
const DEPARTURES_TICK_MS = 10000;

// Sin conexión, la parada y sus salidas salen del horario guardado en el móvil (Info → Horarios sin conexión).
async function offlineSchedule() {
    if (typeof BideOffline === 'undefined') return null;
    return BideOffline.load(NETWORK_SLUG).catch(() => null);
}

// Las salidas que se ven, ¿salen del horario guardado (sin conexión)? Solo entonces se puede marcar si un bus pasó.
let offlineView = null;

async function refreshOfflineDepartures() {
    const saved = await offlineSchedule();
    if (!saved || !state.currentStop) return;
    const data = BideOffline.departures(saved, state.currentStop.id, new Date(), 20);
    state.lastDepartures = data;
    renderDepartures(data);
}

// «Ya pasó» o, en los que salen como probablemente pasados, «no ha pasado». Sin conexión, sin directo que lo diga.
function offlineMarkButton(departure, button = document.createElement('button')) {
    button.type = 'button';
    button.className = 'offline-mark';
    let kind = 'passed';
    if (departure.status === 'likely_departed') kind = 'coming';
    button.textContent = I18n.t('app.mark.' + kind);
    button.setAttribute('aria-label', `${I18n.t('app.mark.' + kind)}: ${departure.lineCode} ${departure.headsign} ${departure.scheduledTime}`);
    button.onclick = (event) => {
        event.stopPropagation();
        BideOffline.mark(departure, kind);
        refreshOfflineDepartures();
    };
    return button;
}

function renderMarksUndo(button, data) {
    const hidden = (data && data.hiddenByMarks) || [];
    const trips = new Set(hidden.map((h) => h.serviceDay + '|' + h.tripKey));
    button.hidden = !offlineView || trips.size === 0;
    if (button.hidden) return;
    button.textContent = I18n.t('app.mark.undo', { n: trips.size });
    button.onclick = () => {
        BideOffline.unmark(hidden);
        refreshOfflineDepartures();
    };
}

function showOfflineSchedule(isOffline) {
    const message = el.netBanner.querySelector('[data-i18n]');
    let key = 'app.offline.banner';
    if (isOffline) key = 'app.offline.saved';
    message.dataset.i18n = key;
    message.textContent = I18n.t(key);
    if (isOffline) el.netBanner.hidden = false;
}

export async function selectStop(stopId) {
    let stop;
    try {
        stop = await Api.stop(stopId);
    } catch (e) {
        const saved = await offlineSchedule();
        if (saved) stop = BideOffline.stop(saved, stopId);
        if (!stop) return;
    }
    state.currentStop = { id: stop.id, name: stop.name, lines: stop.lines || [] };
    el.liveEmpty.hidden = true;
    const screenUrl = '/pantalla/' + encodeURIComponent(stop.id) + networkQuery;
    if (HAS_PLATFORMS) {
        updateFavoriteButton(el.platformFavorite, 'stop', stop.id);
        el.platformScreen.href = screenUrl;
        el.platformPanelStop.textContent = stop.name;
        el.platformPanel.hidden = false;
    } else {
        updateFavoriteButton(el.liveFavorite, 'stop', stop.id);
        el.liveScreen.href = screenUrl;
        el.liveCard.hidden = false;
    }
    if (state.currentLine && state.timetableStopId === null) {
        closeTimetableSection();
    }
    if (!window.location.pathname.startsWith('/stops/' + stopId)) {
        if (history.state && (history.state.view === 'stop' || history.state.view === 'line')) {
            history.replaceState({ view: 'stop', id: stopId, fromApp: true }, '', '/stops/' + stopId + networkQuery);
        } else {
            history.pushState({ view: 'stop', id: stopId, fromApp: true }, '', '/stops/' + stopId + networkQuery);
        }
    } else if (!history.state || !history.state.view) {
        history.replaceState({ view: 'stop', id: stopId, fromApp: true }, '', window.location.href);
    }

    await loadDepartures();
    startDeparturesRefresh();
}

function startDeparturesRefresh() {
    stopDeparturesRefresh();
    state.departuresRefreshTimer = setInterval(loadDepartures, DEPARTURES_REFRESH_MS);
    state.departuresTickTimer = setInterval(tickDepartures, DEPARTURES_TICK_MS);
}

export function stopDeparturesRefresh() {
    if (state.departuresRefreshTimer) clearInterval(state.departuresRefreshTimer);
    state.departuresRefreshTimer = null;
    if (state.departuresTickTimer) clearInterval(state.departuresTickTimer);
    state.departuresTickTimer = null;
}

/*
 * Los minutos se calculan con el reloj del dispositivo a partir de la hora absoluta de llegada (etaAt), no con los
 * que calculó el servidor al responder: así una respuesta con unos minutos de antigüedad (caché, pestaña dormida,
 * sin conexión) no muestra un «0 min» que ya pasó.
 */
function freshDepartures(departures) {
    const now = Date.now();
    // Un bus que pasó hace más de 45 s deja de salir: antes seguía 2 min como «0 min» con el bus ya lejos.
    return (departures || [])
        .filter((d) => {
            if (Number.isFinite(d.etaAt)) return d.etaAt * 1000 >= now - DEPARTED_GRACE_MS;
            return d.etaMinutes >= -2;
        })
        .map((d) => {
            if (!Number.isFinite(d.etaAt)) return d;
            return { ...d, etaMinutes: Math.max(0, Math.round((d.etaAt * 1000 - now) / 60000)) };
        });
}

function departuresSignature(departures) {
    return departures.map((d) => `${d.tripKey}:${d.etaMinutes}`).join('|');
}

export function renderDepartures(data) {
    const fresh = { ...data, departures: freshDepartures(data.departures) };
    state.departuresSignature = departuresSignature(fresh.departures);
    offlineView = null;
    if (data.offline === true) offlineView = data;
    if (HAS_PLATFORMS) {
        renderPlatformPanel(fresh);
        renderMarksUndo(el.platformMarksUndo, offlineView);
    } else {
        renderLiveCard(fresh.departures);
        renderMarksUndo(el.liveMarksUndo, offlineView);
    }
}

export async function loadDepartures() {
    if (!state.currentStop) return;
    let data;
    try {
        data = await Api.stopDepartures(state.currentStop.id, 20);
    } catch (e) {
        const saved = await offlineSchedule();
        if (saved) data = BideOffline.departures(saved, state.currentStop.id, new Date(), 20);
        if (!data) {
            state.lastDepartures = null;
            renderDepartures({ departures: [], platforms: [] });
            return;
        }
    }
    showOfflineSchedule(data.offline === true);
    state.lastDepartures = data;
    renderDepartures(data);
}

function tickDepartures() {
    if (!state.currentStop || !state.lastDepartures) return;
    // Solo se repinta si cambia algún minuto, para no mover la lista que el usuario esté desplazando.
    if (departuresSignature(freshDepartures(state.lastDepartures.departures)) !== state.departuresSignature) {
        renderDepartures(state.lastDepartures);
    }
}

function renderStopLines(lines) {
    const single = lines.length === 1;
    el.liveTimetableLink.hidden = lines.length > 1;
    el.liveTimetableLink.disabled = !single;
    el.liveTimetableLink.dataset.lineId = '';
    if (single) el.liveTimetableLink.dataset.lineId = lines[0].id;
    if (lines.length < 2) return;

    for (const line of lines) {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.innerHTML = `<strong>${escapeHtml(line.code)}</strong><span>${escapeHtml(line.name)}</span>`;
        button.addEventListener('click', () => selectLine(line.id, true));
        li.appendChild(button);
        el.liveMoreList.appendChild(li);
    }
}

function renderLiveCard(departures) {
    // La tarjeta grande nunca es un bus que probablemente ya pasó (estimación sin datos del bus), salvo que no haya otro.
    const coming = (d) => d.status !== 'likely_departed';
    const favoriteIndex = departures.findIndex((d) => coming(d) && state.favoriteKeys.has(favoriteKey('line', d.lineId)));
    let heroIndex = Math.max(0, departures.findIndex(coming));
    if (favoriteIndex !== -1) heroIndex = favoriteIndex;
    const departure = departures[heroIndex] || null;
    const others = departures.filter((_, i) => i !== heroIndex);
    el.liveMoreList.innerHTML = '';
    el.liveOfflineMarks.hidden = !offlineView;
    el.liveMark.hidden = !offlineView || !departure;
    if (offlineView && departure) offlineMarkButton(departure, el.liveMark);

    if (!departure) {
        el.liveLine.textContent = state.currentStop.name;
        el.liveHeadsign.textContent = I18n.t('app.noUpcomingDepartures');
        el.liveMinutes.textContent = '–';
        setDelaySub(el.liveDelay, null, 0);
        el.liveBadge.textContent = I18n.t('app.noData');
        el.liveStatusText.textContent = '';
        el.liveStatusDot.className = 'status-dot';
        el.liveIncidentsLink.hidden = true;
        el.liveOpenDetail.disabled = true;
        renderStopLines(state.currentStop.lines || []);
        return;
    }

    el.liveTimetableLink.hidden = false;

    const { text, className } = statusLabel(departure.status, departure.delayMinutes);
    el.liveLine.textContent = `${departure.lineCode} · ${departure.headsign}`;
    el.liveHeadsign.textContent = state.currentStop.name;
    el.liveMinutes.textContent = Math.max(departure.etaMinutes, 0);
    setDelaySub(el.liveDelay, departure.status, departure.delayMinutes, departure.scheduledTime);
    el.liveBadge.textContent = liveBadgeText(departure.status, departure.etaMinutes);
    el.liveStatusText.textContent = `${departure.lineCode} · ${departure.scheduledTime}`;
    el.liveStatusDot.className = `status-dot ${className}`;
    el.liveIncidentsLink.hidden = FEATURES.alerts === 'network';
    el.liveOpenDetail.disabled = false;
    el.liveOpenDetail.dataset.tripKey = departure.tripKey;
    el.liveTimetableLink.disabled = false;
    el.liveTimetableLink.dataset.lineId = departure.lineId;

    for (const other of others) {
        const li = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        const when = document.createElement('span');
        when.className = 'live-more-when';
        when.textContent = `${Math.max(other.etaMinutes, 0)} min`;
        // Estimada arriba y, debajo y en gris, la hora oficial.
        if (other.status === 'estimated') {
            when.textContent = `≈ ${when.textContent}`;
            const official = document.createElement('small');
            official.textContent = I18n.t('app.list.official', { time: other.scheduledTime });
            when.appendChild(official);
        }
        if (other.status === 'likely_departed') {
            when.textContent = I18n.t('app.list.likelyDeparted', { time: other.scheduledTime });
            li.className = 'is-likely-past';
        }
        button.innerHTML = `<strong>${escapeHtml(other.lineCode)}</strong><span>${escapeHtml(other.headsign)}</span>`;
        button.appendChild(when);

        button.addEventListener('click', () => openVehicleModal(other.tripKey));

        li.appendChild(button);
        if (offlineView) {
            li.classList.add('has-mark');
            li.appendChild(offlineMarkButton(other));
        }
        el.liveMoreList.appendChild(li);
    }
}

const PLATFORM_DIRECTIONS = [
    { key: 'toward_reference', label: 'Sentido 1' },
    { key: 'away_from_reference', label: 'Sentido 2' },
];

function renderPlatformColumn(direction, departures) {
    const column = document.createElement('div');
    column.className = 'platform-column';

    if (departures.length === 0) {
        const headsignEl = document.createElement('p');
        headsignEl.className = 'platform-column-headsign';
        headsignEl.textContent = direction.label;
        const empty = document.createElement('p');
        empty.className = 'platform-column-empty';
        empty.textContent = I18n.t('app.noUpcomingDepartures');
        column.append(headsignEl, empty);
        return column;
    }

    const [next, ...rest] = departures;

    const headsignEl = document.createElement('p');
    headsignEl.className = 'platform-column-headsign';
    headsignEl.textContent = `→ ${next.headsign}`;

    const nextButton = document.createElement('button');
    nextButton.type = 'button';
    nextButton.className = 'time-display';
    nextButton.innerHTML = `<span class="platform-column-next"><strong>${Math.max(Number(next.etaMinutes) || 0, 0)}</strong><span>min</span></span><sub class="delay-sub" hidden></sub>`;
    setDelaySub(nextButton.querySelector('.delay-sub'), next.status, next.delayMinutes, next.scheduledTime);
    nextButton.addEventListener('click', () => openVehicleModal(next.tripKey));

    const badge = document.createElement('span');
    badge.className = 'badge';
    badge.textContent = liveBadgeText(next.status, next.etaMinutes);

    const { text, className } = statusLabel(next.status, next.delayMinutes);
    const dot = document.createElement('span');
    dot.className = `status-dot ${className}`;
    const scheduled = document.createElement('p');
    scheduled.className = 'platform-column-scheduled';
    scheduled.append(dot, `${next.scheduledTime} · ${text}`);

    column.append(headsignEl, badge, nextButton, scheduled);
    if (offlineView) column.appendChild(offlineMarkButton(next));

    if (rest.length > 0) {
        const list = document.createElement('ul');
        list.className = 'platform-column-list';
        for (const departure of rest) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<span>${escapeHtml(departure.headsign)}</span><span>${escapeHtml(departure.scheduledTime)} · ${Math.max(departure.etaMinutes, 0)} min</span>`;
            button.addEventListener('click', () => openVehicleModal(departure.tripKey));
            li.appendChild(button);
            if (offlineView) {
                li.classList.add('has-mark');
                li.appendChild(offlineMarkButton(departure));
            }
            list.appendChild(li);
        }
        column.appendChild(list);
    }

    return column;
}

function renderPlatformPanel(data) {
    el.platformColumns.innerHTML = '';
    const departures = data.departures || [];
    const platformNotice = document.getElementById('platform-notice');
    platformNotice.hidden = departures.length > 0;
    platformNotice.textContent = `No hay ${VEHICLE_PLURAL[NETWORK.kind] || 'trenes'} en las próximas 4 horas: puede ser fuera de horario o sin servicio en esta estación. Consulta el horario completo.`;

    if (data.platforms && data.platforms.length) {
        for (const platform of data.platforms) {
            const platformDepartures = departures.filter((d) => d.platformId === platform.id);
            el.platformColumns.appendChild(renderPlatformColumn(platform, platformDepartures));
        }
    } else {
        for (const direction of PLATFORM_DIRECTIONS) {
            const columnDepartures = departures.filter((d) => d.direction === direction.key);
            const termini = data.directionLabels && data.directionLabels[direction.key];
            let label = direction.label;
            if (termini) label = 'Hacia ' + termini;
            el.platformColumns.appendChild(renderPlatformColumn({ ...direction, label }, columnDepartures));
        }
    }
    const distinctLines = [];
    const seenLineIds = new Set();
    for (const departure of departures) {
        if (seenLineIds.has(departure.lineId)) continue;
        seenLineIds.add(departure.lineId);
        distinctLines.push(departure);
    }
    if (distinctLines.length === 0) {
        for (const line of state.currentStop?.lines || []) {
            distinctLines.push({ lineId: line.id, lineCode: line.code });
        }
    }

    if (distinctLines.length > 1) {
        el.platformTimetableLink.hidden = true;
        el.platformTimetableLines.hidden = false;
        el.platformTimetableLines.innerHTML = '';
        for (const line of distinctLines) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pill';
            button.textContent = line.lineCode;
            button.addEventListener('click', () => selectLine(line.lineId, true));
            el.platformTimetableLines.appendChild(button);
        }
    } else {
        el.platformTimetableLink.hidden = false;
        el.platformTimetableLines.hidden = true;
        const lineId = distinctLines[0]?.lineId;
        el.platformTimetableLink.disabled = lineId === undefined;
        el.platformTimetableLink.dataset.lineId = lineId ?? '';
    }
}

export function setUpConnectionBanner() {
    window.addEventListener('bb:api', (event) => {
        el.netBanner.hidden = event.detail.ok;
    });
    window.addEventListener('offline', () => { el.netBanner.hidden = false; });
    window.addEventListener('online', () => { el.netBanner.hidden = true; });
    document.getElementById('net-retry').addEventListener('click', () => {
        if (state.currentStop) loadDepartures();
        if (state.currentLine) loadTimetable();
        if (!state.currentStop && !state.currentLine) el.netBanner.hidden = true;
    });
}

export function setUpStop() {
    el.liveFavorite.addEventListener('click', () => toggleFavorite('stop', state.currentStop?.id, el.liveFavorite));
    el.liveClose.addEventListener('click', () => closeFromButton('stop', closeLiveCard));
    el.platformFavorite.addEventListener('click', () => toggleFavorite('stop', state.currentStop?.id, el.platformFavorite));
    el.platformClose.addEventListener('click', () => closeFromButton('stop', closeLiveCard));
    el.platformTimetableLink.addEventListener('click', () => {
        if (el.platformTimetableLink.dataset.lineId) selectLine(el.platformTimetableLink.dataset.lineId, true);
    });

    el.liveOpenDetail.addEventListener('click', () => openVehicleModal(el.liveOpenDetail.dataset.tripKey));
    el.liveTimetableLink.addEventListener('click', () => {
        if (el.liveTimetableLink.dataset.lineId) selectLine(el.liveTimetableLink.dataset.lineId, true);
    });
}
