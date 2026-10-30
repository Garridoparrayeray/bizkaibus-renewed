// Avisos de incidencias nuevas en las líneas favoritas (notificaciones).
import { FEATURES, state } from './core.js';
import { readFavorites } from './favorites.js';
import { loadDepartures } from './stop.js';

const alertsToggle = document.getElementById('alerts-toggle');
const alertsNote = document.getElementById('alerts-note');
const ALERTS_SYNC_TAG = 'line-alerts-check';
const ALERTS_SYNC_INTERVAL_MS = 12 * 60 * 60 * 1000;
const ALERTS_FOREGROUND_COOLDOWN_MS = 5 * 60 * 1000;
let lastForegroundAlertCheck = 0;

export function syncAlertFavorites() {
    const favorites = readFavorites();
    const lineIds = favorites.filter((favorite) => favorite.type === 'line').map((favorite) => favorite.refId);
    const stopIds = favorites.filter((favorite) => favorite.type === 'stop').map((favorite) => favorite.refId);
    return AlertsStore.setFavorites(window.__bbNetwork, lineIds, stopIds).catch(() => {});
}

function setAlertsNote(message) {
    alertsNote.textContent = message;
    alertsNote.hidden = message === '';
}

async function enableLineAlerts() {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        setAlertsNote('Tu navegador no admite notificaciones.');
        return false;
    }
    if ((await Notification.requestPermission()) !== 'granted') {
        setAlertsNote('Las notificaciones están bloqueadas. Actívalas en los ajustes del navegador para recibir avisos.');
        return false;
    }
    const registration = await navigator.serviceWorker.ready;
    await syncAlertFavorites();
    await AlertsStore.resetBaseline();
    await AlertsStore.set('enabled', true);
    // La primera consulta solo fija la línea base (lo que ya existe no avisa). Va en segundo plano: depende de las
    // fuentes de los operadores y, si tardan, el interruptor se quedaba bloqueado y sin nota hasta que respondían.
    AlertsStore.checkAlerts().catch(() => {});

    let background = false;
    if ('periodicSync' in registration) {
        try {
            await registration.periodicSync.register(ALERTS_SYNC_TAG, { minInterval: ALERTS_SYNC_INTERVAL_MS });
            background = true;
        } catch (error) {
            background = false;
        }
    }
    if (background) {
        setAlertsNote('Te avisaremos de las incidencias nuevas de tus líneas favoritas, incluso con la app cerrada.');
    } else {
        setAlertsNote('Te avisaremos al abrir la app: tu navegador no permite avisos en segundo plano.');
    }
    return true;
}

async function disableLineAlerts() {
    await AlertsStore.set('enabled', false);
    setAlertsNote('');
    if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.ready;
        if ('periodicSync' in registration) await registration.periodicSync.unregister(ALERTS_SYNC_TAG).catch(() => {});
    }
}

async function runForegroundAlertCheck() {
    if (Date.now() - lastForegroundAlertCheck < ALERTS_FOREGROUND_COOLDOWN_MS) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if ((await AlertsStore.get('enabled')) !== true) return;
    lastForegroundAlertCheck = Date.now();
    const fresh = await AlertsStore.checkAlerts();
    if (fresh.length > 0) await AlertsStore.notify(await navigator.serviceWorker.ready, fresh);
}

function setAlertTexts(heading, toggle) {
    document.querySelector('[data-i18n="app.myLinesIncidents"]').setAttribute('data-i18n', heading);
    document.querySelector('[data-i18n="app.notifyMe"]').setAttribute('data-i18n', toggle);
}

export function setUpAlerts() {
    alertsToggle.addEventListener('change', async () => {
        alertsToggle.disabled = true;
        try {
            if (alertsToggle.checked) {
                alertsToggle.checked = await enableLineAlerts();
            } else {
                await disableLineAlerts();
            }
        } catch (error) {
            alertsToggle.checked = false;
            setAlertsNote('No se pudieron activar los avisos. Inténtalo de nuevo.');
        } finally {
            alertsToggle.disabled = false;
        }
    });

    (async () => {
        await syncAlertFavorites();
        const enabled = (await AlertsStore.get('enabled')) === true;
        alertsToggle.checked = enabled && 'Notification' in window && Notification.permission === 'granted';
        if (alertsToggle.checked) runForegroundAlertCheck();
    })().catch(() => {});

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        runForegroundAlertCheck().catch(() => {});
        // Una pestaña en segundo plano congela el temporizador: al volver, las salidas se actualizan ya.
        if (state.currentStop && state.departuresRefreshTimer) loadDepartures();
    });

    if (FEATURES.alerts === 'stops') {
        setAlertTexts('app.myStopsIncidents', 'app.notifyMeStops');
    } else if (FEATURES.alerts === 'network') {
        setAlertTexts('app.myFavoritesIncidents', 'app.notifyMeFavorites');
    }
}
