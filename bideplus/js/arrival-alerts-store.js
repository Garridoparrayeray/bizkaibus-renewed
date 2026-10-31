// Los «Avísame cuando llegue» creados desde este móvil, para enseñarlos activos y poder cancelarlos: desde la ficha del
// viaje (js/app/arrival-alerts.js) y desde Ajustes (js/settings.js). Caducan a las 3 horas, como en el servidor.
const STORAGE_KEY = 'bide-arrival-alerts';
const KEEP_MS = 3 * 3600 * 1000;

export function readArrivalAlerts() {
    try {
        const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        return list.filter((alert) => Date.now() - alert.created < KEEP_MS);
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
        // Se quita igual: el servidor lo borra solo al mandarlo o a las 3 horas.
    }
    writeArrivalAlerts(readArrivalAlerts().filter((item) => item.id !== alert.id));
}
