// «No usar mi ubicación», de Ajustes: con ella puesta, «Cerca de mí» no pide la posición. Una web no puede quitarse
// el permiso del navegador; esto es lo que sí puede hacer Bide+.
const LOCATION_OFF_KEY = 'bide_location_off';

export function isLocationOff() {
    try {
        return localStorage.getItem(LOCATION_OFF_KEY) === '1';
    } catch (e) {
        return false;
    }
}

export function setLocationOff(off) {
    try {
        if (off) {
            localStorage.setItem(LOCATION_OFF_KEY, '1');
            return;
        }
        localStorage.removeItem(LOCATION_OFF_KEY);
    } catch (e) {
        // Sin almacenamiento (navegación privada estricta), no hay nada que recordar.
    }
}

// Donde el navegador no guarda el permiso y pregunta en cada petición (la app instalada en iPhone), se pide una sola
// vez y se sigue recibiendo mientras la página está abierta; la última posición vale unos minutos al cambiar de red.
// Se guarda en la sesión, que se borra al cerrar la app, y nunca sale del móvil.
const POSITION_REUSE_MS = 5 * 60 * 1000;
const POSITION_KEY = 'bide-last-position';
const POSITION_OPTIONS = { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 };
let watchId = null;

function savedPosition() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(POSITION_KEY));
        if (saved && Date.now() - saved.at < POSITION_REUSE_MS) return { coords: { latitude: saved.lat, longitude: saved.lon } };
    } catch (e) { /* sin almacenamiento: se pide otra vez */ }
    return null;
}

function savePosition(position) {
    try {
        sessionStorage.setItem(POSITION_KEY, JSON.stringify({ lat: position.coords.latitude, lon: position.coords.longitude, at: Date.now() }));
    } catch (e) { /* sin almacenamiento: vale igual esta vez */ }
}

async function permissionState() {
    try {
        return (await navigator.permissions.query({ name: 'geolocation' })).state;
    } catch (e) {
        return 'unknown';
    }
}

function stopWatching() {
    if (watchId === null) return;
    navigator.geolocation.clearWatch(watchId);
    watchId = null;
}

function watchPosition() {
    return new Promise((resolve, reject) => {
        let settled = false;
        watchId = navigator.geolocation.watchPosition((position) => {
            savePosition(position);
            if (settled) return;
            settled = true;
            resolve(position);
        }, (error) => {
            if (settled) return;
            settled = true;
            stopWatching();
            reject(error);
        }, POSITION_OPTIONS);
        window.addEventListener('pagehide', stopWatching, { once: true });
    });
}

export async function currentPosition() {
    const state = await permissionState();
    if (state === 'granted' || state === 'denied') {
        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition((position) => {
                savePosition(position);
                resolve(position);
            }, reject, POSITION_OPTIONS);
        });
    }
    const saved = savedPosition();
    if (saved) return saved;
    stopWatching();
    return watchPosition();
}
