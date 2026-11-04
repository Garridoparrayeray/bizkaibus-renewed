// Menú lateral con incidencias (avisos de Renfe y de las líneas favoritas) y el panel legal.
import { FEATURES, el, state } from './core.js';
import { readFavorites } from './favorites.js';

function alertItem(lines, summary, description) {
    const item = document.createElement('li');
    const parts = [['alert-line', lines], ['alert-summary', summary], ['alert-description', description]];
    for (const [className, text] of parts) {
        if (text === null) continue;
        const span = document.createElement('span');
        span.className = className;
        span.textContent = text;
        item.append(span);
    }
    el.menuAlertsList.appendChild(item);
}

function showEmpty(key) {
    el.menuAlertsEmpty.hidden = false;
    el.menuAlertsEmpty.textContent = I18n.t(key);
}

// Renfe: estaciones y líneas guardadas y la abierta.
async function renderStopAlerts() {
    const favorites = readFavorites();
    const stopIds = new Set(favorites.filter((favorite) => favorite.type === 'stop').map((favorite) => String(favorite.refId)));
    const lineIds = new Set(favorites.filter((favorite) => favorite.type === 'line').map((favorite) => String(favorite.refId)));
    if (state.currentStop) stopIds.add(String(state.currentStop.id));
    if (state.currentLine) lineIds.add(String(state.currentLine.id));

    showEmpty('app.saveStopOrLineToSeeIncidents');
    if (stopIds.size === 0 && lineIds.size === 0) return;

    showEmpty('app.loadingIncidents');
    const fetched = [];
    try {
        for (const stopId of stopIds) fetched.push(...(await Api.alerts(null, stopId)).alerts);
        for (const lineId of lineIds) fetched.push(...(await Api.alerts(lineId)).alerts);
    } catch (e) {
        showEmpty('app.incidentsLoadFailed');
        return;
    }
    const seen = new Set();
    const alerts = fetched.filter((alert) => {
        const key = `${alert.summary}|${alert.description}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
    if (alerts.length === 0) {
        showEmpty('app.noneOfYourStopsHaveIncidents');
        return;
    }
    el.menuAlertsEmpty.hidden = true;
    for (const alert of alerts) alertItem(null, alert.summary, alert.description);
}

// Metro, Euskotren, tranvías y Lurraldebus: toda la red.
async function renderNetworkAlerts() {
    showEmpty('app.loadingIncidents');
    let alerts;
    try {
        alerts = (await Api.alerts()).alerts;
    } catch (e) {
        showEmpty('app.incidentsLoadFailed');
        return;
    }
    if (alerts.length === 0) {
        showEmpty('app.noActiveIncidentsNow');
        return;
    }
    el.menuAlertsEmpty.hidden = true;
    for (const alert of alerts) {
        let description = null;
        if (FEATURES.alerts_detail && alert.description) description = alert.description;
        alertItem(null, alert.summary || alert.title || '', description);
    }
}

// Bizkaibus: líneas guardadas y la abierta.
async function renderLineAlerts() {
    const lineIds = new Set(readFavorites().filter((favorite) => favorite.type === 'line').map((favorite) => String(favorite.refId)));
    if (state.currentLine) lineIds.add(String(state.currentLine.id));
    showEmpty('app.saveLineToSeeIncidents');
    if (lineIds.size === 0) return;

    let alerts;
    try {
        alerts = (await Api.alerts()).alerts;
    } catch (e) {
        showEmpty('app.incidentsLoadFailed');
        return;
    }
    const matching = alerts.filter((alert) => alert.lineRefs.some((ref) => lineIds.has(String(ref))));
    if (matching.length === 0) {
        showEmpty('app.noneOfYourLinesHaveIncidents');
        return;
    }
    el.menuAlertsEmpty.hidden = true;
    const codes = await lineCodes(lineIds);
    for (const alert of matching) {
        const affected = alert.lineRefs.filter((ref) => lineIds.has(String(ref))).map((ref) => codes[ref] || ref);
        alertItem(affected.join(', '), alert.summary, alert.description);
    }
}

async function lineCodes(lineIds) {
    const codes = {};
    for (const lineId of lineIds) {
        try {
            codes[lineId] = (await Api.line(lineId)).code;
        } catch (e) {
            codes[lineId] = `#${lineId}`;
        }
    }
    return codes;
}

async function openSideMenu() {
    el.sideMenu.showModal();
    el.menuAlertsList.innerHTML = '';
    el.menuAlertsEmpty.hidden = false;
    if (FEATURES.alerts === 'stops') {
        await renderStopAlerts();
    } else if (FEATURES.alerts === 'network') {
        await renderNetworkAlerts();
    } else {
        await renderLineAlerts();
    }
}

export function setUpSideMenu() {
    el.menuOpen.addEventListener('click', openSideMenu);
    el.liveIncidentsLink.addEventListener('click', openSideMenu);
    el.menuClose.addEventListener('click', () => el.sideMenu.close());
    el.sideMenu.addEventListener('click', (e) => {
        if (e.target === el.sideMenu) el.sideMenu.close();
    });

    const legalOpen = document.getElementById('legal-open');
    const legalPanel = document.getElementById('legal-panel');
    const legalClose = document.getElementById('legal-close');
    if (legalOpen && legalPanel && legalClose) {
        legalOpen.addEventListener('click', () => legalPanel.showModal());
        legalClose.addEventListener('click', () => legalPanel.close());
        legalPanel.addEventListener('click', (e) => {
            if (e.target === legalPanel) legalPanel.close();
        });
    }
}
