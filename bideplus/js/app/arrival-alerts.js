// «Avísame cuando llegue»: desde la ficha de un viaje abierta en una parada, el móvil avisa cuando faltan 2, 5 o 10
// minutos, aunque la app esté cerrada (notificaciones push; en iPhone, con Bide+ en la pantalla de inicio). El
// servidor guarda el aviso y lo manda en el momento justo (api/Services/ArrivalAlerts.php).
import { el, state } from './core.js';

const STORAGE_KEY = 'bide-arrival-alerts';
const KEEP_MS = 3 * 3600 * 1000;
let keyRequest = null;
let current = { tripKey: null, stopId: null };

function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function isIos() {
    return /iPhone|iPad|iPod/.test(navigator.userAgent);
}

// La clave del servidor se pide una vez. Sin ella (sin almacén), no se ofrece el aviso.
function serverKey() {
    if (!keyRequest) keyRequest = Api.arrivalAlertsKey().catch(() => null);
    return keyRequest;
}

// Avisos creados desde este móvil, para enseñar «aviso activo» y poder cancelarlo. Caducan como en el servidor.
function readAlerts() {
    try {
        const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        return list.filter((alert) => Date.now() - alert.created < KEEP_MS);
    } catch (e) {
        return [];
    }
}

function writeAlerts(list) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
        // Sin almacenamiento el aviso llega igual; solo no se podrá cancelar desde aquí.
    }
}

function activeAlert() {
    return readAlerts().find((alert) => alert.tripKey === current.tripKey && String(alert.stopId) === String(current.stopId));
}

function setStatus(key, vars) {
    el.modalNotifyStatus.textContent = I18n.t(key, vars);
}

function renderState() {
    const active = activeAlert();
    el.modalNotifyOptions.hidden = Boolean(active);
    el.modalNotifyCancel.hidden = !active;
    if (active) setStatus('app.notify.set', { min: active.minutes });
}

// Se enseña en la ficha de un viaje abierta desde una parada, si el servidor y el navegador lo permiten.
export async function showArrivalAlerts(tripKey) {
    current = { tripKey, stopId: state.currentStop?.id ?? null };
    el.modalNotify.hidden = true;
    el.modalNotifyStatus.textContent = '';
    if (current.stopId === null) return;
    const key = await serverKey();
    if (!key || current.tripKey !== tripKey) return;
    if (!pushSupported()) {
        // En iPhone, Safari solo permite notificaciones a la app añadida a la pantalla de inicio.
        if (!isIos()) return;
        el.modalNotify.hidden = false;
        el.modalNotifyOptions.hidden = true;
        el.modalNotifyCancel.hidden = true;
        setStatus('app.notify.ios');
        return;
    }
    el.modalNotify.hidden = false;
    renderState();
}

function keyBytes(base64Url) {
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64Url.length % 4)) % 4);
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

function sameKey(buffer, bytes) {
    if (!buffer) return false;
    const stored = new Uint8Array(buffer);
    return stored.length === bytes.length && stored.every((value, index) => value === bytes[index]);
}

// La suscripción de este navegador; si se hizo con otra clave del servidor, se rehace.
async function pushSubscription(publicKey) {
    const registration = await navigator.serviceWorker.ready;
    const bytes = keyBytes(publicKey);
    const existing = await registration.pushManager.getSubscription();
    if (existing && sameKey(existing.options.applicationServerKey, bytes)) return existing;
    if (existing) await existing.unsubscribe();
    return registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes });
}

async function createAlert(minutes) {
    const key = await serverKey();
    if (!key) return;
    setStatus('app.notify.saving');
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        setStatus('app.notify.denied');
        return;
    }
    const target = { ...current };
    let result;
    let subscription;
    try {
        subscription = (await pushSubscription(key.publicKey)).toJSON();
        result = await Api.createArrivalAlert({ subscription, stopId: target.stopId, tripKey: target.tripKey, minutes, lang: I18n.getLang() });
    } catch (e) {
        setStatus('app.notify.error');
        return;
    }
    if (result.status === 'created') {
        writeAlerts([...readAlerts(), { id: result.id, tripKey: target.tripKey, stopId: target.stopId, minutes, endpoint: subscription.endpoint, created: Date.now() }]);
        if (target.tripKey === current.tripKey) renderState();
    } else if (result.status === 'now') {
        setStatus('app.notify.now');
    } else if (result.status === 'gone') {
        setStatus('app.notify.gone');
    } else {
        setStatus('app.notify.error');
    }
}

async function cancelAlert() {
    const active = activeAlert();
    if (!active) return;
    try {
        await Api.cancelArrivalAlert(active.id, active.endpoint);
    } catch (e) {
        // Si el servidor ya lo había mandado o borrado, también vale: se quita de aquí.
    }
    writeAlerts(readAlerts().filter((alert) => alert.id !== active.id));
    renderState();
    setStatus('app.notify.cancelled');
}

export function setUpArrivalAlerts() {
    el.modalNotifyOptions.querySelectorAll('[data-notify-minutes]').forEach((button) => {
        button.addEventListener('click', () => createAlert(Number(button.dataset.notifyMinutes)));
    });
    el.modalNotifyCancel.addEventListener('click', cancelAlert);
}
