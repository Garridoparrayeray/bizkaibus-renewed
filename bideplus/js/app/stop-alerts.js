// Las incidencias que afectan a la parada abierta, en un aviso dentro de su tarjeta: el primero entero y un botón que
// abre todas. Según la red, las de toda la red (Metro, Euskotren…), las de las líneas de la parada (Bizkaibus) o las
// de la estación y sus líneas (Renfe).
import { FEATURES, state } from './core.js';
import { openSideMenu } from './side-menu.js';

const REFRESH_MS = 2 * 60 * 1000;
let lastStopId = null;
let lastFetchAt = 0;

async function stopAlerts(stop) {
    if (FEATURES.alerts === 'stops') return (await Api.alerts(null, stop.id)).alerts;
    const alerts = (await Api.alerts()).alerts;
    if (FEATURES.alerts !== 'lines') return alerts;
    const lineIds = new Set((stop.lines || []).map((line) => String(line.id)));
    return alerts.filter((alert) => (alert.lineRefs || []).some((ref) => lineIds.has(String(ref))));
}

// Bizkaibus titula todas «Incidencia» y lo que pasa va en la descripción: con un título así de corto, la descripción.
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

// Se piden al abrir la parada y, con ella abierta, como mucho cada dos minutos.
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
    for (const button of document.querySelectorAll('.stop-alerts')) button.addEventListener('click', openSideMenu);
    // Al cambiar de idioma se vuelve a pedir, para el texto del botón (y las de Renfe, que vienen traducidas).
    window.addEventListener('bide:lang', () => {
        lastFetchAt = 0;
        refreshStopAlerts();
    });
}
