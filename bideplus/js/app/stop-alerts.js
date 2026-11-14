import { FEATURES, state } from './core.js';
import { openIncidents } from './side-menu.js';
import { setReducedToday } from './ui.js';

const REFRESH_MS = 2 * 60 * 1000;
let lastStopId = null;
let lastFetchAt = 0;
let lastReduced = null;

async function stopAlerts(stop) {
    if (FEATURES.alerts === 'stops') return withReduced(await Api.alerts(null, stop.id)).alerts;
    const alerts = withReduced(await Api.alerts()).alerts;
    if (FEATURES.alerts !== 'lines') return alerts;
    const lineIds = new Set((stop.lines || []).map((line) => String(line.id)));
    return alerts.filter((alert) => (alert.lineRefs || []).some((ref) => lineIds.has(String(ref))));
}

// Servicio reducido de la red (huelga, servicios mínimos) debajo de la búsqueda, que se ve en el inicio y con la parada
// abierta: hoy o el próximo día con él.
function renderReduced(reduced) {
    lastReduced = reduced;
    const today = Boolean(reduced && reduced.today);
    const upcoming = ((reduced && reduced.days) || [])[0];
    let text = '';
    if (today) text = I18n.t('app.reduced.today');
    if (!today && upcoming) {
        const date = new Date(`${upcoming}T12:00:00`).toLocaleDateString(I18n.getLang(), { weekday: 'long', day: 'numeric', month: 'long' });
        text = I18n.t('app.reduced.day', { date });
    }
    const notice = document.getElementById('reduced-notice');
    notice.textContent = text;
    notice.hidden = text === '';
    setReducedToday(today);
    window.dispatchEvent(new CustomEvent('bide:reduced'));
}

function withReduced(response) {
    renderReduced(response.reducedService);
    return response;
}

function alertText(alert) {
    const summary = alert.summary || alert.title || '';
    if (alert.description && summary.length < 25) return alert.description;
    return summary;
}

function render(alerts) {
    for (const button of document.querySelectorAll('.stop-alerts')) {
        button.replaceChildren();
        button.hidden = alerts.length === 0;
        if (alerts.length === 0) continue;
        const summary = document.createElement('span');
        summary.className = 'stop-alerts-summary';
        summary.textContent = alertText(alerts[0]);
        const action = document.createElement('span');
        action.className = 'stop-alerts-action';
        action.textContent = I18n.t('app.incidents.view');
        if (alerts.length > 1) action.textContent = I18n.t('app.stopAlerts.more', { n: alerts.length });
        button.append(summary, action);
    }
}

export async function refreshStopAlerts() {
    const stop = state.currentStop;
    if (!stop || !FEATURES.alerts) {
        render([]);
        return;
    }
    const stopId = String(stop.id);
    if (stopId === lastStopId && Date.now() - lastFetchAt < REFRESH_MS) return;
    if (stopId !== lastStopId) render([]);
    lastStopId = stopId;
    lastFetchAt = Date.now();
    let alerts = [];
    try {
        alerts = await stopAlerts(stop);
    } catch (e) {
        alerts = [];
    }
    if (state.currentStop && String(state.currentStop.id) === stopId) render(alerts);
}

export function setUpStopAlerts() {
    for (const button of document.querySelectorAll('.stop-alerts')) button.addEventListener('click', openIncidents);
    Api.alerts().then(withReduced).catch(() => null);
    window.addEventListener('bide:lang', () => {
        renderReduced(lastReduced);
        lastFetchAt = 0;
        refreshStopAlerts();
    });
}
