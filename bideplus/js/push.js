// Suscripción a las notificaciones push de Bide+ (llegan con la app cerrada). La usan los avisos de llegada y los de
// incidencias. En iPhone solo funcionan con Bide+ añadida a la pantalla de inicio.
let keyRequest = null;

export function pushSupported() {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export function isIos() {
    return /iPhone|iPad|iPod/.test(navigator.userAgent);
}

// La clave del servidor se pide una vez. Sin ella (sin almacén) no hay notificaciones push.
export function serverKey() {
    if (!keyRequest) {
        keyRequest = Api.pushKey()
            .then((key) => {
                if (!key.publicKey) return null;
                return key;
            })
            .catch(() => null);
    }
    return keyRequest;
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

// La suscripción de este navegador, en el formato que espera el servidor. Si se hizo con otra clave, se rehace.
export async function pushSubscription(publicKey) {
    const registration = await navigator.serviceWorker.ready;
    const bytes = keyBytes(publicKey);
    const existing = await registration.pushManager.getSubscription();
    if (existing && sameKey(existing.options.applicationServerKey, bytes)) return existing.toJSON();
    if (existing) await existing.unsubscribe();
    return (await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: bytes })).toJSON();
}
