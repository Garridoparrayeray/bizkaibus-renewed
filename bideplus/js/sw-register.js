if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
    let bbSwRefreshed = false;
    const bbHadController = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!bbHadController || bbSwRefreshed) return;
        bbSwRefreshed = true;
        location.reload();
    });
}
