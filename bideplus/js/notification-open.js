// Al tocar una notificación con la app cerrada, en iPhone el sistema abre la app por el inicio y el service worker no
// puede llevar esa ventana a la parada del aviso: se lo pide a la página (sw.js, openFromNotification) y la página va.
// Va en la cabecera para escuchar desde el principio; el mensaje espera a que la página esté lista.
(function () {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.addEventListener('message', function (event) {
        const data = event.data;
        if (!data || data.type !== 'bide:open' || typeof data.url !== 'string') return;
        // Solo direcciones de la propia app.
        if (data.url.charAt(0) !== '/' || data.url.charAt(1) === '/' || data.url.charAt(1) === '\\') return;
        if (location.pathname + location.search === data.url) return;
        location.assign(data.url);
    });
    navigator.serviceWorker.startMessages();
})();
