(function () {
    var root = document.documentElement;
    if (window.__bbTheme !== 'miamor') {
        root.classList.add('bide');
    }
    var isStandalone = window.__bbForceStandalone === true || navigator.standalone === true ||
        (window.matchMedia && (matchMedia('(display-mode: standalone)').matches || matchMedia('(display-mode: fullscreen)').matches));
    if (isStandalone) {
        root.classList.add('is-standalone');
    }
})();
