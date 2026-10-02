// Va en <head>, antes de pintar. Marca la página como Bide+ (salvo el tema «mi amor») y si está instalada.
// Las pruebas fuerzan el modo instalado con window.__bbForceStandalone.
(function () {
    var root = document.documentElement;
    if (window.__bbTheme !== 'miamor') {
        root.classList.add('bide');
    }
    var standalone = window.__bbForceStandalone === true || navigator.standalone === true ||
        (window.matchMedia && (matchMedia('(display-mode: standalone)').matches || matchMedia('(display-mode: fullscreen)').matches));
    if (standalone) {
        root.classList.add('is-standalone');
    }
})();
