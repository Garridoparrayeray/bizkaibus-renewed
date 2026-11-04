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
