// Los «Avísame cuando llegue» creados desde este móvil, para enseñarlos activos y poder cancelarlos: desde la ficha del
// viaje (js/app/arrival-alerts.js) y desde Ajustes (js/settings.js).
// - Uno de una vez se quita en cuanto avisa: el service worker apunta su id al recibir la notificación, aunque la app
//   esté cerrada, y si hay una ventana abierta se lo dice al momento (sw.js). Si nunca llega a avisar (el viaje se
//   cancela), se quita media hora después de su llegada prevista y, como mucho, a las 3 horas, como en el servidor.
// - Uno de todos los días se queda hasta que se cancela.
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

// Cancela en el servidor y lo quita de aquí. Si el servidor ya lo había mandado o borrado, también vale.
export async function cancelArrivalAlert(alert) {
    try {
        await Api.cancelArrivalAlert(alert.id, alert.endpoint);
    } catch (e) {
        // Se quita igual: el servidor lo borra solo al mandarlo o al caducar.
    }
    writeArrivalAlerts(readArrivalAlerts().filter((item) => item.id !== alert.id));
}

// Quita los de una vez que ya avisaron. Los de todos los días no se tocan aunque su id aparezca.
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
        // Sin IndexedDB se quitan igual al pasar su hora.
    }
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        const data = event.data;
        if (data && data.type === 'bide:arrival-fired' && typeof data.id === 'string') dropFired([data.id]);
    });
}
dropFiredWhileClosed();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') dropFiredWhileClosed();
});
