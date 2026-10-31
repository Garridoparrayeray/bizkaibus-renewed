// «Avísame cuando llegue»: desde la ficha de un viaje abierta en una parada, el móvil avisa cuando faltan 2, 5 o 10
// minutos, aunque la app esté cerrada (notificaciones push; en iPhone, con Bide+ en la pantalla de inicio). El
// servidor guarda el aviso y lo manda en el momento justo (api/Services/ArrivalAlerts.php).
import { el, state } from './core.js';
import { cancelArrivalAlert, readArrivalAlerts, writeArrivalAlerts } from '../arrival-alerts-store.js';
import { isIos, pushSubscription, pushSupported, serverKey } from '../push.js';

let current = { tripKey: null, stopId: null };

function activeAlert() {
    return readArrivalAlerts().find((alert) => alert.tripKey === current.tripKey && String(alert.stopId) === String(current.stopId));
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
        subscription = await pushSubscription(key.publicKey);
        result = await Api.createArrivalAlert({ subscription, stopId: target.stopId, tripKey: target.tripKey, minutes, lang: I18n.getLang() });
    } catch (e) {
        setStatus('app.notify.error');
        return;
    }
    if (result.status === 'created') {
        // La línea, el destino y la parada, para enseñarlo en Ajustes.
        const alert = { id: result.id, tripKey: target.tripKey, stopId: target.stopId, minutes, endpoint: subscription.endpoint, created: Date.now() };
        writeArrivalAlerts([...readArrivalAlerts(), { ...alert, lineCode: result.lineCode, headsign: result.headsign, stopName: result.stopName }]);
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
    await cancelArrivalAlert(active);
    renderState();
    setStatus('app.notify.cancelled');
}

export function setUpArrivalAlerts() {
    el.modalNotifyOptions.querySelectorAll('[data-notify-minutes]').forEach((button) => {
        button.addEventListener('click', () => createAlert(Number(button.dataset.notifyMinutes)));
    });
    el.modalNotifyCancel.addEventListener('click', cancelAlert);
    // Si se cancela desde Ajustes con la ficha abierta, la ficha vuelve a ofrecer el aviso.
    window.addEventListener('bide:arrival-alerts', () => {
        if (el.modalNotify.hidden) return;
        if (!activeAlert()) el.modalNotifyStatus.textContent = '';
        renderState();
    });
}
