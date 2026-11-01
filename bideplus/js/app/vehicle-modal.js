// Ficha de un viaje: paradas, posición del vehículo, retraso e incidencias.
import { FEATURES, HAS_PLATFORMS, NETWORK_SLUG, el, onReturn, state } from './core.js';
import { selectStop } from './stop.js';
import { statusLabel } from './ui.js';
import { showArrivalAlerts } from './arrival-alerts.js';

/*
 * El recorrido abierto sigue el reloj: se vuelve a pedir cada 15 s (el ritmo del feed) y, entre peticiones, los
 * minutos y la parada actual se recalculan con el reloj del dispositivo a partir de la hora absoluta de cada
 * parada (etaAt). Una parada prevista hace más de 45 s ya se da por pasada, como en las salidas.
 */
const MODAL_REFRESH_MS = 15000;
const MODAL_TICK_MS = 10000;
const MODAL_PASSED_AFTER_MS = 45000;
export const DEPARTED_GRACE_MS = 45 * 1000;
export const modalState = { tripKey: null, data: null, refreshTimer: null, tickTimer: null, signature: '' };
// Lo que se comparte del viaje abierto: su clave y sus datos (los de la ficha en directo o los del horario).
const shareState = { tripKey: null, data: null };
const SHARE_DONE_MS = 2500;

function freshModalStops(data) {
    const now = Date.now();
    const clockDriven = data.status === 'live';
    let currentSet = false;
    return data.stops.map((stop) => {
        const out = { ...stop };
        if (clockDriven && Number.isFinite(stop.etaAt)) {
            out.etaMinutes = Math.round((stop.etaAt * 1000 - now) / 60000);
            if (stop.etaAt * 1000 < now - MODAL_PASSED_AFTER_MS) out.isPast = true;
        }
        if (clockDriven) {
            out.isCurrent = !out.isPast && !currentSet;
            if (out.isCurrent) currentSet = true;
        }
        return out;
    });
}

export function renderModalStops(data) {
    const stops = freshModalStops(data);
    const signature = stops.map((s) => {
        let flags = '';
        if (s.isPast) flags += 'p';
        if (s.isCurrent) flags += 'c';
        return `${s.stopId}:${flags}:${s.etaMinutes}`;
    }).join('|');
    if (signature === modalState.signature && el.modalStops.children.length > 0) return;
    modalState.signature = signature;

    const scrollTop = el.modal.scrollTop;
    el.modalStops.innerHTML = '';
    for (const stop of stops) {
        const li = document.createElement('li');
        const classes = [];
        if (stop.isCurrent) classes.push('stop-current');
        if (data.feedTimes && stop.isPast) classes.push('stop-past');
        li.className = classes.join(' ');
        let label = `${stop.scheduledTime} · ${stop.name}`;
        if (data.feedTimes && stop.etaMinutes !== null && stop.etaMinutes !== undefined && !stop.isPast) {
            label += ` · ${Math.max(stop.etaMinutes, 0)} min`;
        }
        li.textContent = label;
        li.classList.add('stop-clickable');
        li.addEventListener('click', () => {
            el.modal.close();
            selectStop(stop.stopId);
        });
        el.modalStops.appendChild(li);
    }
    el.modal.scrollTop = scrollTop;
}

export function renderModalHeader(data) {
    const { text } = statusLabel(data.status, data.delayMinutes);
    el.modalBadge.textContent = text;
    el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
    el.modalHeadsign.textContent = I18n.t('app.direction', { headsign: data.headsign });
    if (data.vehicleRef) {
        el.modalVehicle.textContent = I18n.t('app.liveTracking', { ref: data.vehicleRef });
    } else if (data.status === 'finished') {
        el.modalVehicle.textContent = I18n.t('app.tripFinished');
    } else if (data.status === 'live') {
        el.modalVehicle.textContent = I18n.t('app.liveTrackingFeed');
    } else if (data.status === 'estimated') {
        el.modalVehicle.textContent = I18n.t('app.estimatedNoGps');
    } else {
        el.modalVehicle.textContent = I18n.t('app.noLiveTracking');
    }
}

function stopModalFollow() {
    if (modalState.refreshTimer) clearInterval(modalState.refreshTimer);
    if (modalState.tickTimer) clearInterval(modalState.tickTimer);
    modalState.refreshTimer = null;
    modalState.tickTimer = null;
    modalState.tripKey = null;
    modalState.data = null;
}

async function refreshVehicleModal() {
    const tripKey = modalState.tripKey;
    if (!tripKey || !el.modal.open) return;
    let data;
    try {
        data = await Api.vehicle(tripKey, state.currentStop?.id);
    } catch (e) {
        return;
    }
    if (modalState.tripKey !== tripKey || !el.modal.open) return;
    modalState.data = data;
    shareState.data = data;
    renderModalHeader(data);
    renderModalStops(data);
}

function tickVehicleModal() {
    if (!modalState.data || !el.modal.open) return;
    renderModalStops(modalState.data);
}

export async function openVehicleModal(tripKey) {
    if (!tripKey) return;
    if (FEATURES.trip_detail === 'stops') {
        await openTripStopsModal(tripKey);
        return;
    }

    let data;
    try {
        data = await Api.vehicle(tripKey, state.currentStop?.id);
    } catch (e) {
        if (HAS_PLATFORMS) {
            await openTripStopsModal(tripKey);
        }
        return;
    }

    stopModalFollow();
    modalState.signature = '';
    modalState.tripKey = tripKey;
    modalState.data = data;
    shareState.tripKey = tripKey;
    shareState.data = data;
    el.modalVehicle.hidden = false;
    renderModalHeader(data);

    el.modalAlertsToggle.dataset.lineId = tripKey.split('-')[0];
    el.modalAlertsToggle.textContent = I18n.t('app.incidents.view');
    el.modalAlertsToggle.disabled = false;
    el.modalAlertsToggle.hidden = FEATURES.alerts === 'network';
    el.modalAlerts.hidden = true;
    el.modalAlerts.innerHTML = '';

    el.modalStops.innerHTML = '';
    renderModalStops(data);
    showArrivalAlerts(tripKey);

    el.modal.showModal();
    modalState.refreshTimer = setInterval(refreshVehicleModal, MODAL_REFRESH_MS);
    modalState.tickTimer = setInterval(tickVehicleModal, MODAL_TICK_MS);
}

async function openTripStopsModal(tripKey) {
    let data;
    try {
        data = await Api.tripStops(tripKey, state.currentStop?.id ?? '');
    } catch (e) {
        return;
    }
    shareState.tripKey = tripKey;
    shareState.data = data;

    el.modalBadge.textContent = I18n.t('app.status.scheduled');
    el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
    el.modalHeadsign.textContent = I18n.t('app.direction', { headsign: data.headsign });
    el.modalVehicle.hidden = true;
    el.modalAlertsToggle.hidden = true;
    el.modalAlerts.hidden = true;
    el.modalAlerts.innerHTML = '';

    const nowHm = new Date().toTimeString().slice(0, 5);
    el.modalStops.innerHTML = '';
    for (const stop of data.stops) {
        const li = document.createElement('li');
        const classes = ['stop-clickable'];
        if (stop.isTarget) classes.push('stop-current');
        if (stop.scheduledTime < nowHm) classes.push('stop-past');
        li.className = classes.join(' ');
        li.textContent = `${stop.scheduledTime} · ${stop.name}`;
        li.addEventListener('click', () => {
            el.modal.close();
            selectStop(stop.stopId);
        });
        el.modalStops.appendChild(li);
    }
    showArrivalAlerts(tripKey);

    el.modal.showModal();
}

async function loadModalAlerts() {
    const lineId = el.modalAlertsToggle.dataset.lineId;
    el.modalAlertsToggle.disabled = true;
    el.modalAlertsToggle.textContent = I18n.t('app.loading');

    let alerts;
    try {
        alerts = (await Api.alerts(lineId)).alerts;
    } catch (e) {
        el.modalAlertsToggle.textContent = I18n.t('app.loadFailed');
        return;
    }

    el.modalAlerts.innerHTML = '';
    if (alerts.length === 0) {
        const li = document.createElement('li');
        li.textContent = I18n.t('app.noActiveIncidents');
        el.modalAlerts.appendChild(li);
    } else {
        for (const alert of alerts) {
            const li = document.createElement('li');
            li.textContent = `${alert.summary}: ${alert.description}`;
            el.modalAlerts.appendChild(li);
        }
    }
    el.modalAlerts.hidden = false;
    el.modalAlertsToggle.hidden = true;
}

// Compartir el viaje: el enlace abre la misma parada con la ficha del viaje en directo («?viaje=»).
function shareUrl() {
    const params = new URLSearchParams();
    if (NETWORK_SLUG !== BideNetworks.DEFAULT) params.set('red', NETWORK_SLUG);
    params.set('viaje', shareState.tripKey);
    let path = '/';
    if (state.currentStop) path = '/stops/' + encodeURIComponent(state.currentStop.id);
    return location.origin + path + '?' + params.toString();
}

// La hora a la que el viaje pasa por la parada abierta: la prevista en directo o, si no, la del horario.
function arrivalTime(data) {
    if (!state.currentStop) return '';
    const stop = data.stops.find((s) => String(s.stopId) === String(state.currentStop.id));
    if (!stop) return '';
    if (Number.isFinite(stop.etaAt) && (data.status === 'live' || data.status === 'estimated')) {
        return new Date(stop.etaAt * 1000).toTimeString().slice(0, 5);
    }
    return stop.scheduledTime || '';
}

function shareText() {
    const data = shareState.data;
    const vars = { line: data.lineCode, headsign: data.headsign, stop: state.currentStop?.name ?? '', time: arrivalTime(data) };
    if (vars.stop && vars.time) return I18n.t('app.share.arrives', vars);
    return I18n.t('app.share.follow', vars);
}

function showShareDone() {
    el.modalShare.textContent = I18n.t('app.share.copied');
    setTimeout(() => { el.modalShare.textContent = I18n.t('app.share.button'); }, SHARE_DONE_MS);
}

async function shareTrip() {
    if (!shareState.tripKey || !shareState.data) return;
    const url = shareUrl();
    const text = shareText();
    if (navigator.share) {
        try {
            await navigator.share({ title: 'Bide+', text, url });
            return;
        } catch (e) {
            if (e.name === 'AbortError') return;
        }
    }
    try {
        await navigator.clipboard.writeText(text + ' ' + url);
        showShareDone();
    } catch (e) {
        window.prompt(I18n.t('app.share.prompt'), url);
    }
}

export function setUpVehicleModal() {
    el.modalClose.addEventListener('click', () => el.modal.close());
    el.modalShare.addEventListener('click', shareTrip);
    el.modal.addEventListener('close', stopModalFollow);
    onReturn(refreshVehicleModal);
    el.modalAlertsToggle.addEventListener('click', loadModalAlerts);
    el.modal.addEventListener('click', (e) => {
        if (e.target === el.modal) el.modal.close();
    });
}
