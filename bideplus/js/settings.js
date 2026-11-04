// Ajustes de Bide+, en el inicio y en cada app. Todo se guarda al momento, sin recargar.
import { cancelArrivalAlert, readArrivalAlerts } from './arrival-alerts-store.js';
import { disableLineAlerts, setUpIncidentAlerts } from './incident-alerts.js';
import { isIos } from './push.js';
import { forgetUpdates, setUpAppUpdate } from './app-update.js';
import { isLocationOff, setLocationOff } from './location-pref.js';

const view = document.querySelector('[data-bide-view="ajustes"]');
const CONFIRM_MS = 5000;
const CACHE_TIMEOUT_MS = 30000;
const LOCATION_TIMEOUT_MS = 10000;
const LOCATION_KEYS = {
    granted: 'bide.settings.locationGranted',
    denied: 'bide.settings.locationDenied',
    prompt: 'bide.settings.locationPrompt',
    unknown: 'bide.settings.locationUnknown',
    unsupported: 'bide.settings.locationUnsupported',
};
let locationWatched = false;

function setText(node, key, vars) {
    node.setAttribute('data-i18n', key);
    node.textContent = I18n.t(key, vars);
}

function permissionKey() {
    if (!('Notification' in window)) {
        // En iPhone, Safari solo permite notificaciones a la app añadida a la pantalla de inicio.
        if (isIos()) return 'app.notify.ios';
        return 'app.alerts.unsupported';
    }
    if (Notification.permission === 'granted') return 'bide.settings.allowed';
    if (Notification.permission === 'denied') return 'app.alerts.denied';
    return 'bide.settings.notAsked';
}

// La suscripción a las notificaciones de este navegador, o null.
async function pushSubscription() {
    if (!('serviceWorker' in navigator)) return null;
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration || !('pushManager' in registration)) return null;
    return registration.pushManager.getSubscription();
}

// Con el permiso dado, el botón de desactivar sale mientras Bide+ tenga algo que mandar.
async function renderPermission() {
    let key = permissionKey();
    let active = false;
    if (key === 'bide.settings.allowed') {
        const subscribed = (await pushSubscription().catch(() => null)) !== null;
        active = subscribed || (await AlertsStore.get('enabled')) === true;
        if (!active) key = 'bide.settings.allowedIdle';
    }
    setText(view.querySelector('[data-settings-permission]'), key);
    view.querySelector('[data-settings-notify-off]').hidden = !active;
}

// Los avisos creados antes de guardar la línea y la parada solo tienen los minutos.
function arrivalTitle(alert) {
    if (alert.lineCode && alert.headsign) return `${alert.lineCode} → ${alert.headsign}`;
    if (alert.lineCode) return alert.lineCode;
    return I18n.t('app.notify.title');
}

function arrivalDetail(alert) {
    let when = I18n.t('bide.settings.before', { min: alert.minutes });
    if (alert.repeat === true) when += ` · ${I18n.t('bide.settings.daily')}`;
    if (alert.stopName) return `${alert.stopName} · ${when}`;
    return when;
}

function arrivalItem(alert) {
    const item = document.createElement('li');
    item.className = 'bide-settings-arrival';
    const text = document.createElement('span');
    const title = document.createElement('strong');
    title.textContent = arrivalTitle(alert);
    const detail = document.createElement('span');
    detail.textContent = arrivalDetail(alert);
    text.append(title, detail);
    const cancel = document.createElement('button');
    cancel.type = 'button';
    cancel.className = 'bide-button is-secondary';
    setText(cancel, 'bide.settings.cancel');
    cancel.addEventListener('click', () => {
        cancel.disabled = true;
        cancelArrivalAlert(alert);
    });
    item.append(text, cancel);
    return item;
}

function renderArrivals() {
    const alerts = readArrivalAlerts();
    view.querySelector('[data-settings-arrivals]').replaceChildren(...alerts.map(arrivalItem));
    view.querySelector('[data-settings-arrivals-empty]').hidden = alerts.length > 0;
}

// ===== Ubicación («Cerca de mí») =====

// El estado del permiso. Los navegadores sin la API de permisos (Safari antiguo) no lo dicen: «unknown».
async function locationState() {
    if (!('geolocation' in navigator)) return 'unsupported';
    if (!navigator.permissions || !navigator.permissions.query) return 'unknown';
    try {
        const status = await navigator.permissions.query({ name: 'geolocation' });
        // Si se cambia en los ajustes del navegador con la página abierta, se ve al momento.
        if (!locationWatched) {
            locationWatched = true;
            status.addEventListener('change', () => renderLocation());
        }
        return status.state;
    } catch (e) {
        return 'unknown';
    }
}

// Apagada en Ajustes, se ofrece volver a usarla; si no, permitirla o dejar de usarla, según el permiso.
function showLocation(state) {
    const ask = view.querySelector('[data-settings-location-ask]');
    const off = view.querySelector('[data-settings-location-off]');
    view.querySelector('[data-settings-location-ios]').hidden = !isIos() || isLocationOff() || state === 'unsupported';
    if (isLocationOff()) {
        setText(view.querySelector('[data-settings-location]'), 'bide.settings.locationOff');
        setText(ask, 'bide.settings.locationOn');
        ask.hidden = false;
        off.hidden = true;
        return;
    }
    let key = LOCATION_KEYS[state];
    if (!key) key = LOCATION_KEYS.unknown;
    setText(view.querySelector('[data-settings-location]'), key);
    setText(ask, 'bide.settings.locationAsk');
    ask.hidden = state !== 'prompt' && state !== 'unknown';
    off.hidden = state === 'denied' || state === 'unsupported';
}

async function renderLocation() {
    showLocation(await locationState());
}

// Pedir la posición es lo que hace que el navegador pregunte; la posición no se usa ni se manda a ningún sitio.
function setUpLocation() {
    view.querySelector('[data-settings-location-off]').addEventListener('click', () => {
        setLocationOff(true);
        renderLocation();
    });
    view.querySelector('[data-settings-location-ask]').addEventListener('click', async () => {
        setLocationOff(false);
        // Al volver a usarla con el permiso ya dado, no hace falta pedir la posición.
        const state = await locationState();
        if (state === 'granted') {
            showLocation(state);
            return;
        }
        navigator.geolocation.getCurrentPosition(
            () => showLocation('granted'),
            (error) => {
                if (error.code === error.PERMISSION_DENIED) {
                    showLocation('denied');
                    return;
                }
                renderLocation();
            },
            { timeout: LOCATION_TIMEOUT_MS, maximumAge: 10 * 60 * 1000 }
        );
    });
}

// ===== La app: vaciar la caché =====

// Le pide algo al service worker y espera su respuesta por un canal propio (o se cansa a los 30 segundos).
function askWorker(worker, message) {
    return new Promise((resolve, reject) => {
        const channel = new MessageChannel();
        const timer = setTimeout(() => reject(new Error('sin respuesta')), CACHE_TIMEOUT_MS);
        channel.port1.onmessage = (event) => {
            clearTimeout(timer);
            if (event.data && event.data.ok) {
                resolve();
                return;
            }
            reject(new Error('no se pudo descargar'));
        };
        worker.postMessage(message, [channel.port2]);
    });
}

// Primero se busca una versión nueva del service worker; luego el que está activo vuelve a bajar la app entera (sw.js).
// Sin service worker no hay caché de la app que vaciar: basta con recargar.
async function refreshCache() {
    if (!('serviceWorker' in navigator)) return;
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration || !registration.active) return;
    await registration.update().catch(() => null);
    await askWorker(registration.active, { type: 'refresh-cache' });
}

function setUpCache() {
    const button = view.querySelector('[data-settings-cache]');
    const status = view.querySelector('[data-settings-cache-status]');
    button.addEventListener('click', async () => {
        if (!navigator.onLine) {
            setText(status, 'bide.settings.cacheOffline');
            return;
        }
        button.disabled = true;
        setText(status, 'bide.settings.cacheWorking');
        try {
            await refreshCache();
        } catch (e) {
            setText(status, 'bide.settings.cacheError');
            button.disabled = false;
            return;
        }
        setText(status, 'bide.settings.cacheDone');
        setTimeout(() => location.reload(), 800);
    });
}

function render() {
    renderPermission();
    renderArrivals();
    renderLocation();
}

// Lo guardado en el servidor para las notificaciones se borra primero; si falla (sin conexión), no se toca nada más.
async function forgetNotifications() {
    for (const alert of readArrivalAlerts()) await cancelArrivalAlert(alert);
    if ((await AlertsStore.get('enabled')) === true) await disableLineAlerts();
    const subscription = await pushSubscription();
    if (!subscription) return;
    await forgetUpdates(subscription.endpoint);
    await subscription.unsubscribe();
}

// Desactivar: se cancela todo lo que Bide+ tiene apuntado para este móvil. El permiso del navegador sigue dado; quitarlo
// solo se puede desde sus ajustes, y el texto lo explica.
function setUpNotifyOff() {
    const button = view.querySelector('[data-settings-notify-off]');
    const status = view.querySelector('[data-settings-notify-status]');
    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            await forgetNotifications();
            document.getElementById('alerts-toggle').checked = false;
            setText(status, 'bide.settings.notifyOffDone');
        } catch (e) {
            setText(status, 'bide.settings.notifyOffError');
        }
        button.disabled = false;
        renderArrivals();
        renderPermission();
    });
}

async function forgetDevice() {
    for (const network of Object.keys(BideOffline.saved())) await BideOffline.remove(network);
    await AlertsStore.set('favorites', {});
    await AlertsStore.set('mode', null);
    await AlertsStore.resetBaseline();
    localStorage.clear();
    sessionStorage.clear();
}

// Dos pulsaciones: la primera pide confirmación y, si no llega la segunda en 5 segundos, se olvida.
function setUpDelete() {
    const button = view.querySelector('[data-settings-delete]');
    const status = view.querySelector('[data-settings-delete-status]');
    let confirmTimer = null;

    button.addEventListener('click', async () => {
        if (!confirmTimer) {
            setText(button, 'bide.settings.deleteConfirm');
            confirmTimer = setTimeout(() => {
                confirmTimer = null;
                setText(button, 'bide.settings.delete');
            }, CONFIRM_MS);
            return;
        }
        clearTimeout(confirmTimer);
        confirmTimer = null;
        button.disabled = true;
        try {
            await forgetNotifications();
            await forgetDevice();
        } catch (e) {
            setText(status, 'bide.settings.deleteError');
            setText(button, 'bide.settings.delete');
            button.disabled = false;
            return;
        }
        setText(status, 'bide.settings.deleted');
        setTimeout(() => location.reload(), 800);
    });
}

if (view) {
    setUpIncidentAlerts();
    setUpLocation();
    setUpNotifyOff();
    setUpCache();
    setUpDelete();
    setUpAppUpdate(view);
    render();
    // Al abrir Ajustes, los permisos pueden haber cambiado en los ajustes del navegador.
    new MutationObserver(() => {
        if (!view.hidden) render();
    }).observe(view, { attributes: true, attributeFilter: ['hidden'] });
    window.addEventListener('bide:incident-alerts', renderPermission);
    window.addEventListener('bide:arrival-alerts', renderArrivals);
    window.addEventListener('bide:lang', renderArrivals);
}
