(function () {
    const root = document.documentElement;
    root.classList.add('bide');
    const isStandalone = window.__bbForceStandalone === true || navigator.standalone === true ||
        (window.matchMedia && (matchMedia('(display-mode: standalone)').matches || matchMedia('(display-mode: fullscreen)').matches));
    if (isStandalone) {
        root.classList.add('is-standalone');
    }
    // iOS, con la app instalada, a veces deja la barra de pestañas donde estaba el borde de la pantalla al cargar (al
    // volver atrás, al reabrir la app o al girar el móvil) y se queda en mitad de la página: se le obliga a recolocarse.
    function repinTabbar() {
        const bar = document.querySelector('.bide-tabbar');
        if (!bar) return;
        bar.style.display = 'none';
        void bar.offsetHeight;
        bar.style.display = '';
    }
    if (isStandalone) {
        window.addEventListener('pageshow', repinTabbar);
        window.addEventListener('orientationchange', repinTabbar);
        window.addEventListener('resize', repinTabbar);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') repinTabbar();
        });
    }
    // iOS no da el mismo margen inferior en todas las páginas (depende de viewport-fit y del estilo de la barra de estado): se marca para dejar siempre una franja mínima bajo la barra de pestañas.
    const ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
        root.classList.add('is-ios');
        // iPhone con indicador de inicio (X en adelante, 812 pt o más de alto): iOS da el margen inferior en unas páginas y 0 en otras, así que la franja bajo la barra se fija en vez de depender de él.
        if (Math.max(screen.width, screen.height) >= 812) {
            root.classList.add('is-ios-notch');
        }
    }
})();
