// Ficha de un viaje: paradas, posición del vehículo, retraso e incidencias.
import { FEATURES, NETWORK_SLUG, appHref, el, hereNow, onReturn, openOnClick, savedSchedule, state, whenVisible } from './core.js';
import { selectStop } from './stop.js';
import { statusLabel } from './ui.js';
import { showArrivalAlerts } from './arrival-alerts.js';
import { shareLink } from './share.js';

/*
 * El recorrido abierto sigue el reloj: se vuelve a pedir cada 15 s (el ritmo del feed) y, entre peticiones, los
 * minutos y la parada actual se recalculan con el reloj del dispositivo a partir de la hora absoluta de cada
 * parada (etaAt). Una parada prevista hace más de 45 s ya se da por pasada, como en las salidas.
 */
const MODAL_REFRESH_MS = 15000;
const MODAL_TICK_MS = 10000;
const MODAL_PASSED_AFTER_MS = 45000;
export const DEPARTED_GRACE_MS = 45 * 1000;
const MINUTES_PER_DAY = 1440;
// Un viaje de ayer que pasa de las 24:00 se sigue tomando por el de ayer hasta este rato después de su llegada.
const LATE_TRIP_GRACE_MINUTES = 15;
// scheduleOnly: el viaje abierto es el de mañana o el del día de servicio siguiente (solo su horario).
export const modalState = { tripKey: null, data: null, refreshTimer: null, tickTimer: null, signature: '', scheduleOnly: false };
// Lo que se comparte del viaje abierto: su clave y sus datos (los de la ficha en directo o los del horario).
// La hora del texto de compartir, en el idioma de la app y en la zona de los horarios aunque el móvil esté en otra.
const SHARE_TIME_OPTIONS = { timeZone: BideNetworks.TIME_ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
const shareState = { tripKey: null, data: null };

// Sin directo (horario oficial), las que ya pasaron según su hora (etaAt, con su día) van en gris: un búho sin GPS
// compartido de noche dice al menos por dónde debería ir. Sin minutos ni parada actual: no se sabe dónde está.
function byTimetable(data) {
    return data.status === 'scheduled' && !data.feedTimes && !modalState.scheduleOnly;
}

function freshModalStops(data) {
    const now = Date.now();
    const clockDriven = data.status === 'live';
    const timetable = byTimetable(data);
    let currentSet = false;
    return data.stops.map((stop) => {
        const out = { ...stop };
        if (clockDriven && Number.isFinite(stop.etaAt)) {
            out.etaMinutes = Math.round((stop.etaAt * 1000 - now) / 60000);
            if (stop.etaAt * 1000 < now - MODAL_PASSED_AFTER_MS) out.isPast = true;
        }
        if (timetable && Number.isFinite(stop.etaAt)) out.isPast = stop.etaAt * 1000 < now - MODAL_PASSED_AFTER_MS;
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

    // Pasadas en gris y minutos a las siguientes: con las horas del feed o con el vehículo seguido en directo.
    const tracked = data.feedTimes || data.status === 'live';
    const scrollTop = el.modal.scrollTop;
    el.modalStops.innerHTML = '';
    for (const stop of stops) {
        const li = document.createElement('li');
        const classes = [];
        if (stop.isCurrent) classes.push('stop-current');
        if (stop.isPast && (tracked || byTimetable(data))) classes.push('stop-past');
        li.className = classes.join(' ');
        let label = `${stop.scheduledTime} · ${stop.name}`;
        if (tracked && stop.etaMinutes !== null && stop.etaMinutes !== undefined && !stop.isPast) {
            label += ` · ${Math.max(stop.etaMinutes, 0)} min`;
        }
        li.appendChild(stopLink(stop, label));
        li.classList.add('stop-clickable');
        el.modalStops.appendChild(li);
    }
    el.modal.scrollTop = scrollTop;
}

// Al abrir la ficha, la lista baja hasta la parada actual (por donde va el vehículo o la de la consulta) o, sin ella,
// hasta la primera que queda; los refrescos ya no la mueven.
function showCurrentStop() {
    let current = el.modalStops.querySelector('.stop-current');
    if (!current) current = el.modalStops.querySelector('li.stop-past + li:not(.stop-past)');
    current?.scrollIntoView({ block: 'center' });
}

// Cada parada del recorrido es un enlace a la suya: se abre también con el teclado.
function stopLink(stop, label) {
    const link = document.createElement('a');
    link.href = appHref('stops', stop.stopId);
    link.textContent = label;
    openOnClick(link, () => {
        el.modal.close();
        selectStop(stop.stopId);
    });
    return link;
}

export function renderModalHeader(data) {
    const { text } = statusLabel(data.status, data.delayMinutes, { reason: data.reason });
    el.modalBadge.textContent = text;
    if (data.tomorrow) el.modalBadge.textContent = I18n.t('app.badge.tomorrow');
    el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
    el.modalHeadsign.textContent = I18n.t('app.direction', { headsign: data.headsign });
    if (data.vehicleRef) {
        el.modalVehicle.textContent = I18n.t('app.liveTracking', { ref: data.vehicleRef });
    } else if (data.tomorrow) {
        el.modalVehicle.textContent = I18n.t('app.tripTomorrow');
    } else if (data.status === 'finished') {
        el.modalVehicle.textContent = I18n.t('app.tripFinished');
    } else if (data.status === 'live') {
        el.modalVehicle.textContent = I18n.t('app.liveTrackingFeed', { source: I18n.t('net.' + NETWORK_SLUG + '.liveSource') });
    } else if (data.status === 'estimated' && data.reason === 'previous_trip') {
        el.modalVehicle.textContent = I18n.t('app.previousTripLate');
    } else if (data.status === 'estimated') {
        el.modalVehicle.textContent = I18n.t('app.estimatedNoGps');
    } else if (data.awaitingVehicle) {
        el.modalVehicle.textContent = I18n.t('app.awaitingVehicle');
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
    modalState.scheduleOnly = false;
}

// El viaje de mañana o del día siguiente no se vuelve a pedir (tampoco al volver a la app): la clave no lleva fecha y la
// API daría el de hoy, que ya ha terminado.
async function refreshVehicleModal() {
    const tripKey = modalState.tripKey;
    if (!tripKey || !el.modal.open || modalState.scheduleOnly) return;
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

/*
 * Un viaje de mañana (hoy ya no sale ninguno más de la parada) o del día de servicio siguiente que sale en unas horas
 * (nextDay): su horario, sin nada del de hoy con esa clave, que ya terminó. El de mañana lo dice («MAÑANA»); el otro
 * sale como horario oficial, que es lo que es.
 */
function scheduleOnly(data, tomorrow) {
    const stops = data.stops.map((stop) => ({ ...stop, isPast: false, isCurrent: false, etaMinutes: null, etaAt: null }));
    return { ...data, stops, tomorrow, status: 'scheduled', vehicleRef: null, awaitingVehicle: false, delayMinutes: null, reason: null };
}

// La clave de un tren que el horario no tiene (refuerzo) empieza así: tiene ficha, pero no avisos ni enlace.
const EXTRA_TRIP_PREFIX = 'extra-';

export async function openVehicleModal(tripKey, { tomorrow = false, nextDay = false } = {}) {
    if (!tripKey) return;
    if (FEATURES.trip_detail === 'stops') {
        await openTripStopsModal(tripKey, tomorrow);
        return;
    }

    let data;
    try {
        data = await Api.vehicle(tripKey, state.currentStop?.id);
    } catch (e) {
        // Sin el directo (sin conexión, o en estaciones con andenes), las paradas del viaje con su hora.
        await openTripStopsModal(tripKey, tomorrow);
        return;
    }
    const onlySchedule = tomorrow || nextDay;
    if (onlySchedule) data = scheduleOnly(data, tomorrow);

    stopModalFollow();
    modalState.signature = '';
    const isExtra = tripKey.startsWith(EXTRA_TRIP_PREFIX);
    modalState.tripKey = tripKey;
    modalState.data = data;
    modalState.scheduleOnly = onlySchedule;
    shareState.tripKey = tripKey;
    shareState.data = data;
    showShareOptions();
    el.modalShare.hidden = isExtra;
    el.modalVehicle.hidden = false;
    renderModalHeader(data);

    el.modalAlertsToggle.dataset.lineId = tripKey.split('-')[0];
    el.modalAlertsToggle.textContent = I18n.t('app.incidents.view');
    el.modalAlertsToggle.disabled = false;
    el.modalAlertsToggle.hidden = FEATURES.alerts === 'network' || isExtra;
    el.modalAlerts.hidden = true;
    el.modalAlerts.innerHTML = '';

    el.modalStops.innerHTML = '';
    renderModalStops(data);
    if (!isExtra) showArrivalAlerts(tripKey);

    el.modal.showModal();
    showCurrentStop();
    // Su horario no cambia: no hace falta irlo refrescando.
    if (onlySchedule) return;
    modalState.refreshTimer = setInterval(whenVisible(refreshVehicleModal), MODAL_REFRESH_MS);
    modalState.tickTimer = setInterval(tickVehicleModal, MODAL_TICK_MS);
}

async function openTripStopsModal(tripKey, tomorrow = false) {
    let data;
    try {
        data = await Api.tripStops(tripKey, state.currentStop?.id ?? '');
    } catch (e) {
        const saved = await savedSchedule();
        if (saved) data = BideOffline.tripStops(saved, tripKey, state.currentStop?.id ?? '');
    }
    if (!data) return;
    shareState.tripKey = tripKey;
    shareState.data = data;
    showShareOptions();

    el.modalBadge.textContent = I18n.t('app.status.scheduled');
    el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
    el.modalHeadsign.textContent = I18n.t('app.direction', { headsign: data.headsign });
    el.modalVehicle.hidden = true;
    el.modalAlertsToggle.hidden = true;
    el.modalAlerts.hidden = true;
    el.modalAlerts.innerHTML = '';

    const past = pastStops(data.stops, tomorrow, tripKey);
    el.modalStops.innerHTML = '';
    data.stops.forEach((stop, index) => {
        const li = document.createElement('li');
        const classes = ['stop-clickable'];
        if (stop.isTarget) classes.push('stop-current');
        if (past[index]) classes.push('stop-past');
        li.className = classes.join(' ');
        li.appendChild(stopLink(stop, `${stop.scheduledTime} · ${stop.name}`));
        el.modalStops.appendChild(li);
    });
    showArrivalAlerts(tripKey);

    el.modal.showModal();
    showCurrentStop();
}

// Las horas del horario («HH:MM») dan la vuelta a las 24:00: en minutos seguidos desde la primera parada del viaje.
function tripMinutes(stops) {
    let previous = 0;
    let shift = 0;
    return stops.map((stop) => {
        const [hours, minutes] = String(stop.scheduledTime).split(':').map(Number);
        let value = hours * 60 + minutes + shift;
        if (value < previous) {
            shift += MINUTES_PER_DAY;
            value += MINUTES_PER_DAY;
        }
        previous = value;
        return value;
    });
}

// Las paradas por las que el viaje ya ha pasado según su horario, con la hora de Madrid. El viaje es el del día de
// servicio de hoy, salvo uno de ayer que pasa de las 24:00 y aún no ha terminado. La clave lleva su primera salida en
// horas de su día de servicio (25:10 son 90.600 s); con el día «más cerca», uno de dentro de 12 h salía como pasado.
function pastStops(stops, tomorrow, tripKey) {
    if (tomorrow) return stops.map(() => false);
    const minutes = tripMinutes(stops);
    let start = minutes[0];
    const keySeconds = Number(String(tripKey).split('-').pop());
    if (Number.isFinite(keySeconds) && String(tripKey).includes('-')) start = Math.floor(keySeconds / 60);
    const service = minutes.map((value) => start + value - minutes[0]);
    const today = Math.floor(hereNow().seconds / 60);
    let now = today;
    if (today + MINUTES_PER_DAY <= service[service.length - 1] + LATE_TRIP_GRACE_MINUTES) now = today + MINUTES_PER_DAY;
    return service.map((value) => value < now);
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
            BideLinks.append(li, `${alert.summary}: ${alert.description}`);
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
        return new Intl.DateTimeFormat(I18n.getLang(), SHARE_TIME_OPTIONS).format(new Date(stop.etaAt * 1000));
    }
    return stop.scheduledTime || '';
}

function shareText() {
    const data = shareState.data;
    const vars = { line: data.lineCode, headsign: data.headsign, stop: state.currentStop?.name ?? '', time: arrivalTime(data) };
    if (vars.stop && vars.time) return I18n.t('app.share.arrives', vars);
    return I18n.t('app.share.follow', vars);
}

function shareTrip() {
    if (!shareState.tripKey || !shareState.data) return;
    shareLink(shareUrl(), shareText(), el.modalShare);
}

// «Compartir la parada» solo tiene sentido con una parada abierta (la ficha también se abre desde el mapa de la línea).
function showShareOptions() {
    el.modalShare.hidden = false;
    el.modalShareStop.hidden = !state.currentStop;
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
