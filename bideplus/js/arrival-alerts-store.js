// Los «Avísame cuando llegue» de este móvil. Uno de una vez se quita al avisar o, si nunca avisa, al pasar su hora.
const STORAGE_KEY = 'bide-arrival-alerts';
const KEEP_MS = 3 * 3600 * 1000;
const AFTER_ARRIVAL_MS = 30 * 60 * 1000;
const FIRED_KEY = 'arrivalFired';

function stillActive(alert) {
    if (alert.repeat === true) return true;
    if (Date.now() - alert.created >= KEEP_MS) return false;
    if (typeof alert.expectedAt === 'number' && Date.now() > alert.expectedAt + AFTER_ARRIVAL_MS) return false;
    return true;
}

export function readArrivalAlerts() {
    try {
        const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        return list.filter(stillActive);
    } catch (e) {
        return [];
    }
}

export function writeArrivalAlerts(list) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
        // Sin almacenamiento el aviso llega igual; solo no se podrá cancelar desde aquí.
    }
    window.dispatchEvent(new CustomEvent('bide:arrival-alerts'));
}

// Las cancelaciones que el servidor aún no sabe (se cancelaron sin conexión): [{ id, endpoint }].
const CANCELS_KEY = 'bide-arrival-cancels';

function readPendingCancels() {
    try {
        return JSON.parse(localStorage.getItem(CANCELS_KEY) || '[]') || [];
    } catch (e) {
        return [];
    }
}

function writePendingCancels(list) {
    try {
        localStorage.setItem(CANCELS_KEY, JSON.stringify(list));
    } catch (e) {
        // Sin almacenamiento no se puede reintentar: el servidor lo borra al caducar.
    }
}

// true si el servidor ya no lo tiene: lo cancela ahora o ya no estaba (404: ya avisó, caducó o se canceló antes).
async function cancelOnServer(alert) {
    try {
        await Api.cancelArrivalAlert(alert.id, alert.endpoint);
        return true;
    } catch (e) {
        return e.status === 404 || e.status === 410;
    }
}

/*
 * Lo cancela en el servidor y lo quita de aquí. Sin conexión (o con el servidor fallando) queda apuntado y se manda en
 * cuanto se pueda: si no, uno de todos los días seguiría llegando y ya no se podría cancelar. false si queda pendiente.
 */
export async function cancelArrivalAlert(alert) {
    const done = await cancelOnServer(alert);
    if (!done) {
        const others = readPendingCancels().filter((item) => item.id !== alert.id);
        writePendingCancels([...others, { id: alert.id, endpoint: alert.endpoint }]);
    }
    writeArrivalAlerts(readArrivalAlerts().filter((item) => item.id !== alert.id));
    return done;
}

let sendingCancels = null;

// Las cancelaciones pendientes se mandan al abrir la app, al volver a ella y al recuperar la conexión.
function sendPendingCancels() {
    if (sendingCancels || readPendingCancels().length === 0) return;
    sendingCancels = (async () => {
        for (const item of readPendingCancels()) {
            if (await cancelOnServer(item)) writePendingCancels(readPendingCancels().filter((other) => other.id !== item.id));
        }
    })().finally(() => {
        sendingCancels = null;
    });
}

function dropFired(ids) {
    const list = readArrivalAlerts();
    const kept = list.filter((alert) => alert.repeat === true || !ids.includes(alert.id));
    if (kept.length !== list.length) writeArrivalAlerts(kept);
}

async function dropFiredWhileClosed() {
    if (typeof AlertsStore === 'undefined') return;
    try {
        dropFired(Object.keys((await AlertsStore.get(FIRED_KEY)) || {}));
    } catch (e) {
        // Sin IndexedDB se quitan al pasar su hora.
    }
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        const data = event.data;
        if (data && data.type === 'bide:arrival-fired' && typeof data.id === 'string') dropFired([data.id]);
    });
}
dropFiredWhileClosed();
sendPendingCancels();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    dropFiredWhileClosed();
    sendPendingCancels();
});
window.addEventListener('online', sendPendingCancels);
