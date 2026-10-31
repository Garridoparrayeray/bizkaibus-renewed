// Ajustes de Bide+, en el menú de inicio y en cada app: idioma, notificaciones (incidencias de los favoritos y avisos
// de llegada activos), horarios sin conexión y borrar los datos del móvil. Todo se guarda al momento, sin recargar:
// las peticiones al servidor van por js/api.js.
import { cancelArrivalAlert, readArrivalAlerts } from './arrival-alerts-store.js';
import { disableLineAlerts, setUpIncidentAlerts } from './incident-alerts.js';
import { isIos } from './push.js';

const view = document.querySelector('[data-bide-view="ajustes"]');
const CONFIRM_MS = 5000;

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

function renderPermission() {
    setText(view.querySelector('[data-settings-permission]'), permissionKey());
}

// Los avisos creados antes de guardar la línea y la parada solo tienen los minutos.
function arrivalTitle(alert) {
    if (alert.lineCode && alert.headsign) return `${alert.lineCode} → ${alert.headsign}`;
    if (alert.lineCode) return alert.lineCode;
    return I18n.t('app.notify.title');
}

function arrivalDetail(alert) {
    const before = I18n.t('bide.settings.before', { min: alert.minutes });
    if (alert.stopName) return `${alert.stopName} · ${before}`;
    return before;
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

function render() {
    renderPermission();
    renderArrivals();
}

// Lo guardado en el servidor para las notificaciones se borra primero; si falla (sin conexión), no se toca nada más.
async function forgetNotifications() {
    for (const alert of readArrivalAlerts()) await cancelArrivalAlert(alert);
    if ((await AlertsStore.get('enabled')) === true) await disableLineAlerts();
    if (!('serviceWorker' in navigator)) return;
    const registration = await navigator.serviceWorker.getRegistration();
    if (!registration || !('pushManager' in registration)) return;
    const subscription = await registration.pushManager.getSubscription();
    if (subscription) await subscription.unsubscribe();
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
    setUpDelete();
    render();
    // Al abrir Ajustes, el permiso puede haber cambiado en los ajustes del navegador.
    new MutationObserver(() => {
        if (!view.hidden) render();
    }).observe(view, { attributes: true, attributeFilter: ['hidden'] });
    window.addEventListener('bide:incident-alerts', renderPermission);
    window.addEventListener('bide:arrival-alerts', renderArrivals);
    window.addEventListener('bide:lang', renderArrivals);
}
