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

// Donde el navegador no guarda el permiso y pregunta en cada petición (la app instalada en iPhone), la última posición
// vale unos minutos, también al cambiar de red. Se guarda en la sesión, que se borra al cerrar la app.
const POSITION_REUSE_MS = 5 * 60 * 1000;
const POSITION_KEY = 'bide-last-position';

function savedPosition() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(POSITION_KEY));
        if (saved && Date.now() - saved.at < POSITION_REUSE_MS) return saved;
    } catch (e) { /* sin almacenamiento: se pide otra vez */ }
    return null;
}

async function permissionState() {
    try {
        return (await navigator.permissions.query({ name: 'geolocation' })).state;
    } catch (e) {
        return 'unknown';
    }
}

export async function currentPosition() {
    const state = await permissionState();
    const saved = savedPosition();
    if (saved && state !== 'granted' && state !== 'denied') {
        return { coords: { latitude: saved.lat, longitude: saved.lon } };
    }
    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition((position) => {
            try {
                sessionStorage.setItem(POSITION_KEY, JSON.stringify({ lat: position.coords.latitude, lon: position.coords.longitude, at: Date.now() }));
            } catch (e) { /* sin almacenamiento: vale igual esta vez */ }
            resolve(position);
        }, reject, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
    });
}
