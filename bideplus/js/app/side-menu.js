// Menú lateral con incidencias (avisos de Renfe y de las líneas favoritas) y el panel legal.
import { FEATURES, el, state } from './core.js';
import { readFavorites } from './favorites.js';

async function renderRenfeAlerts() {
    const favorites = readFavorites();
    const stopIds = new Set(favorites.filter((favorite) => favorite.type === 'stop').map((favorite) => String(favorite.refId)));
    const lineIds = new Set(favorites.filter((favorite) => favorite.type === 'line').map((favorite) => String(favorite.refId)));
    if (state.currentStop) stopIds.add(String(state.currentStop.id));
    if (state.currentLine) lineIds.add(String(state.currentLine.id));

    el.menuAlertsEmpty.textContent = I18n.t('app.saveStopOrLineToSeeIncidents');
    if (stopIds.size === 0 && lineIds.size === 0) return;

    el.menuAlertsEmpty.textContent = I18n.t('app.loadingIncidents');
    const fetched = [];
    try {
        for (const stopId of stopIds) fetched.push(...(await Api.alerts(null, stopId)).alerts);
        for (const lineId of lineIds) fetched.push(...(await Api.alerts(lineId)).alerts);
    } catch (e) {
        el.menuAlertsEmpty.textContent = I18n.t('app.incidentsLoadFailed');
        return;
    }

    const seen = new Set();
    const alerts = fetched.filter((alert) => {
        const key = `${alert.summary}|${alert.description}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
    });
    el.menuAlertsEmpty.hidden = alerts.length > 0;
    if (alerts.length === 0) {
        el.menuAlertsEmpty.textContent = I18n.t('app.noneOfYourStopsHaveIncidents');
        return;
    }
    for (const alert of alerts) {
        const li = document.createElement('li');
        const summarySpan = document.createElement('span');
        summarySpan.className = 'alert-summary';
        summarySpan.textContent = alert.summary;
        const descSpan = document.createElement('span');
        descSpan.className = 'alert-description';
        descSpan.textContent = alert.description;
        li.append(summarySpan, descSpan);
        el.menuAlertsList.appendChild(li);
    }
}

async function openSideMenu() {
    el.sideMenu.showModal();

    el.menuAlertsList.innerHTML = '';
    el.menuAlertsEmpty.hidden = false;

    if (FEATURES.alerts === 'stops') {
        await renderRenfeAlerts();
        return;
    }

    if (FEATURES.alerts === 'network') {
        el.menuAlertsEmpty.textContent = I18n.t('app.loadingIncidents');
        let metroAlerts;
        try {
            metroAlerts = (await Api.alerts()).alerts;
        } catch (e) {
            el.menuAlertsEmpty.textContent = I18n.t('app.incidentsLoadFailed');
            return;
        }
        el.menuAlertsEmpty.hidden = metroAlerts.length > 0;
        if (metroAlerts.length === 0) {
            el.menuAlertsEmpty.textContent = I18n.t('app.noActiveIncidentsNow');
            return;
        }
        for (const alert of metroAlerts) {
            const li = document.createElement('li');
            const summarySpan = document.createElement('span');
            summarySpan.className = 'alert-summary';
            summarySpan.textContent = alert.summary || alert.title || '';
            li.append(summarySpan);
            if (FEATURES.alerts_detail && alert.description) {
                const descSpan = document.createElement('span');
                descSpan.className = 'alert-description';
                descSpan.textContent = alert.description;
                li.append(descSpan);
            }
            el.menuAlertsList.appendChild(li);
        }
        return;
    }

    const favoriteLines = readFavorites().filter((f) => f.type === 'line');
    const relevantLineIds = new Set(favoriteLines.map((f) => String(f.refId)));
    if (state.currentLine) {
        relevantLineIds.add(String(state.currentLine.id));
    }

    el.menuAlertsEmpty.textContent = I18n.t('app.saveLineToSeeIncidents');

    if (relevantLineIds.size === 0) {
        return;
    }

    let allAlerts;
    try {
        allAlerts = (await Api.alerts()).alerts;
    } catch (e) {
        el.menuAlertsEmpty.textContent = I18n.t('app.incidentsLoadFailed');
        return;
    }

    const matching = allAlerts.filter((alert) => alert.lineRefs.some((ref) => relevantLineIds.has(String(ref))));

    el.menuAlertsEmpty.hidden = matching.length > 0;
    if (matching.length === 0) {
        el.menuAlertsEmpty.textContent = I18n.t('app.noneOfYourLinesHaveIncidents');
        return;
    }

    const lineLabels = {};
    for (const lineId of relevantLineIds) {
        try {
            const line = await Api.line(lineId);
            lineLabels[lineId] = line.code;
        } catch (e) {
            lineLabels[lineId] = `#${lineId}`;
        }
    }

    for (const alert of matching) {
        const affectedLabels = alert.lineRefs.filter((ref) => relevantLineIds.has(String(ref))).map((ref) => lineLabels[ref] || ref);
        const li = document.createElement('li');

        const lineSpan = document.createElement('span');
        lineSpan.className = 'alert-line';
        lineSpan.textContent = affectedLabels.join(', ');

        const summarySpan = document.createElement('span');
        summarySpan.className = 'alert-summary';
        summarySpan.textContent = alert.summary;

        const descSpan = document.createElement('span');
        descSpan.className = 'alert-description';
        descSpan.textContent = alert.description;

        li.append(lineSpan, summarySpan, descSpan);
        el.menuAlertsList.appendChild(li);
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
