// Avisos de incidencias nuevas en los favoritos de todas las apps (notificaciones). El interruptor está en Ajustes, en
// el menú de inicio y en cada app. Con notificaciones push, los manda el servidor cada 5 minutos aunque la app esté
// cerrada (api/Services/IncidentAlerts.php): aquí solo se le dan la suscripción, los favoritos y el idioma. Si el
// navegador no tiene push, la app mira al abrirse y, donde se puede, en segundo plano de vez en cuando
// (js/alerts-store.js).
import { isIos, pushSubscription, pushSupported, serverKey } from './push.js';

let alertsToggle = null;
let alertsNote = null;
const ALERTS_SYNC_TAG = 'line-alerts-check';
const ALERTS_SYNC_INTERVAL_MS = 12 * 60 * 60 * 1000;
const ALERTS_FOREGROUND_COOLDOWN_MS = 5 * 60 * 1000;
// Lo último que se mandó al servidor: solo se vuelve a mandar si cambia o cada semana (el servidor lo guarda 90 días).
const PUSH_SYNC_KEY = 'bide-incident-push';
const PUSH_REFRESH_MS = 7 * 24 * 60 * 60 * 1000;
let lastForegroundAlertCheck = 0;
let pushSyncTimer = null;

// Favoritos de todas las apps (comparten almacenamiento): el servidor avisa de todos, abras la app que abras.
function allFavorites() {
    const favorites = {};
    for (const network of BideNetworks.slugs) {
        let list = [];
        try {
            list = JSON.parse(localStorage.getItem(BideNetworks.get(network).favoritesKey) || '[]');
        } catch (e) {
            list = [];
        }
        if (!Array.isArray(list)) continue;
        const lines = list.filter((favorite) => favorite.type === 'line').map((favorite) => String(favorite.refId));
        const stops = list.filter((favorite) => favorite.type === 'stop').map((favorite) => String(favorite.refId));
        if (lines.length + stops.length > 0) favorites[network] = { lines, stops };
    }
    return favorites;
}

function readPushSync() {
    try {
        return JSON.parse(localStorage.getItem(PUSH_SYNC_KEY) || 'null');
    } catch (e) {
        return null;
    }
}

function writePushSync(value) {
    try {
        if (value === null) localStorage.removeItem(PUSH_SYNC_KEY);
        else localStorage.setItem(PUSH_SYNC_KEY, JSON.stringify(value));
    } catch (e) {
        // Sin almacenamiento se vuelve a mandar la próxima vez: no pasa nada.
    }
}

// Da al servidor la suscripción, los favoritos y el idioma. Devuelve false si no hay push en el servidor.
async function syncPush(force = false) {
    const key = await serverKey();
    if (!key) return false;
    const settings = { subscription: await pushSubscription(key.publicKey), favorites: allFavorites(), lang: I18n.getLang() };
    const signature = JSON.stringify(settings);
    const last = readPushSync();
    if (!force && last && last.signature === signature && Date.now() - last.at < PUSH_REFRESH_MS) return true;
    await Api.saveIncidentAlerts(settings);
    writePushSync({ signature, at: Date.now() });
    return true;
}

async function usingPush() {
    return (await AlertsStore.get('enabled')) === true && (await AlertsStore.get('mode')) === 'push';
}

// Al cambiar los favoritos o el idioma. Se agrupan los cambios seguidos en un solo envío.
function schedulePushSync() {
    clearTimeout(pushSyncTimer);
    pushSyncTimer = setTimeout(async () => {
        if (await usingPush()) syncPush().catch(() => {});
    }, 1500);
}

// Al cambiar los favoritos. En una app, además, se guardan los de su red para la comprobación sin push.
export function syncAlertFavorites() {
    schedulePushSync();
    const network = window.__bbNetwork;
    if (!network) return Promise.resolve();
    const favorites = allFavorites()[network] || { lines: [], stops: [] };
    return AlertsStore.setFavorites(network, favorites.lines, favorites.stops).catch(() => {});
}

// La nota lleva su clave de traducción: si cambia el idioma, se traduce sola.
function setAlertsNote(key) {
    if (!alertsNote) return;
    if (key === '') {
        alertsNote.removeAttribute('data-i18n');
        alertsNote.textContent = '';
    } else {
        alertsNote.setAttribute('data-i18n', key);
        alertsNote.textContent = I18n.t(key);
    }
    alertsNote.hidden = key === '';
}

async function stopBackgroundChecks() {
    const registration = await navigator.serviceWorker.ready;
    if ('periodicSync' in registration) await registration.periodicSync.unregister(ALERTS_SYNC_TAG).catch(() => {});
}

// Pasa a push si el navegador y el servidor lo permiten. El permiso ya tiene que estar concedido.
async function enablePush() {
    if (!pushSupported()) return false;
    try {
        if (!(await syncPush(true))) return false;
    } catch (e) {
        return false;
    }
    await AlertsStore.set('mode', 'push');
    await stopBackgroundChecks();
    return true;
}

// Sin push: la app mira al abrirse y, si el navegador deja, en segundo plano cada 12 horas como mucho.
async function enableLocalAlerts() {
    const registration = await navigator.serviceWorker.ready;
    await AlertsStore.set('mode', 'local');
    // La primera consulta solo fija la línea base (lo que ya existe no avisa). Va en segundo plano: depende de las
    // fuentes de los operadores y, si tardan, el interruptor se quedaba bloqueado y sin nota hasta que respondían.
    AlertsStore.checkAlerts().catch(() => {});
    if ('periodicSync' in registration) {
        try {
            await registration.periodicSync.register(ALERTS_SYNC_TAG, { minInterval: ALERTS_SYNC_INTERVAL_MS });
            return 'app.alerts.background';
        } catch (error) {
            return 'app.alerts.foreground';
        }
    }
    return 'app.alerts.foreground';
}

async function enableLineAlerts() {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        // En iPhone, Safari solo permite notificaciones a la app añadida a la pantalla de inicio.
        let note = 'app.alerts.unsupported';
        if (isIos()) note = 'app.notify.ios';
        setAlertsNote(note);
        return false;
    }
    if ((await Notification.requestPermission()) !== 'granted') {
        setAlertsNote('app.alerts.denied');
        return false;
    }
    await syncAlertFavorites();
    await AlertsStore.resetBaseline();
    let note = 'app.alerts.push';
    if (!(await enablePush())) note = await enableLocalAlerts();
    await AlertsStore.set('enabled', true);
    setAlertsNote(note);
    return true;
}

export async function disableLineAlerts() {
    if ((await AlertsStore.get('mode')) === 'push') {
        // Se borra del servidor; la suscripción del navegador se queda para los avisos de llegada.
        const subscription = await (await navigator.serviceWorker.ready).pushManager.getSubscription();
        if (subscription) await Api.removeIncidentAlerts(subscription.endpoint);
        writePushSync(null);
    }
    await AlertsStore.set('enabled', false);
    await stopBackgroundChecks();
    setAlertsNote('');
}

async function runForegroundAlertCheck() {
    if (Date.now() - lastForegroundAlertCheck < ALERTS_FOREGROUND_COOLDOWN_MS) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if ((await AlertsStore.get('enabled')) !== true || (await AlertsStore.get('mode')) === 'push') return;
    lastForegroundAlertCheck = Date.now();
    const fresh = await AlertsStore.checkAlerts();
    if (fresh.length > 0) await AlertsStore.notify(await navigator.serviceWorker.ready, fresh);
}

// Quien ya tenía los avisos activados sin push pasa a push en cuanto se puede, sin volver a pedir permiso.
async function restoreAlerts() {
    await syncAlertFavorites();
    const enabled = (await AlertsStore.get('enabled')) === true;
    alertsToggle.checked = enabled && 'Notification' in window && Notification.permission === 'granted';
    if (!alertsToggle.checked) return;
    if ((await AlertsStore.get('mode')) !== 'push' && (await enablePush())) return;
    runForegroundAlertCheck();
}

function rememberLang() {
    AlertsStore.set('lang', I18n.getLang()).catch(() => {});
}

export function setUpIncidentAlerts() {
    rememberLang();
    window.addEventListener('bide:lang', rememberLang);
    alertsToggle = document.getElementById('alerts-toggle');
    alertsNote = document.getElementById('alerts-note');
    if (!alertsToggle) return;
    alertsToggle.addEventListener('change', async () => {
        const wanted = alertsToggle.checked;
        alertsToggle.disabled = true;
        try {
            if (wanted) {
                alertsToggle.checked = await enableLineAlerts();
            } else {
                await disableLineAlerts();
            }
        } catch (error) {
            alertsToggle.checked = !wanted;
            let note = 'app.alerts.disableError';
            if (wanted) note = 'app.alerts.enableError';
            setAlertsNote(note);
        } finally {
            alertsToggle.disabled = false;
            window.dispatchEvent(new CustomEvent('bide:incident-alerts'));
        }
    });

    restoreAlerts().catch(() => {});
    window.addEventListener('bide:lang', schedulePushSync);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') runForegroundAlertCheck().catch(() => {});
    });
}
