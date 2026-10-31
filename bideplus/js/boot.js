// Tema, red y recursos de la app. Va en <head> y antes de pintar: usa document.write a propósito.
(function () {
    const params = new URLSearchParams(location.search);
    // El tema lo decide theme.js; aquí solo se limpia ?tema= de la URL.
    const isMiamor = window.__bbTheme === 'miamor';
    if (params.has('tema')) {
        params.delete('tema');
        const qs = params.toString();
        let search = '';
        if (qs) search = '?' + qs;
        history.replaceState(null, '', location.pathname + search + location.hash);
    }

    const redParam = params.get('red');
    window.__bbNetwork = BideNetworks.DEFAULT;
    if (BideNetworks.exists(redParam)) window.__bbNetwork = redParam;
    const network = BideNetworks.get(window.__bbNetwork);
    const isDefault = window.__bbNetwork === BideNetworks.DEFAULT;

    let title = network.texts.es.name;
    let manifest = 'manifest-bide.json';
    let themeColor = network.themeColor;
    let touchIcon = network.icons + '/apple-touch-icon.png';
    let icon = network.icons + '/icon-192.png';
    const stylesheets = '<link rel="stylesheet" href="style-app.css">';

    if (isMiamor) {
        manifest = 'manifest-miamor.json';
        themeColor = '#db2777';
        if (isDefault) {
            touchIcon = 'icons/apple-touch-icon.png';
            icon = 'icons/icon-192.png';
            title += ' | Para el amor de mi vida';
        }
    }
    // El título descriptivo de cada página lo pone el servidor (SEO); aquí solo se cambia en el tema «mi amor».
    if (isMiamor) {
        document.title = title;
    }

    if (!isDefault) document.documentElement.classList.add('is-' + window.__bbNetwork);

    document.write(
        '<link rel="manifest" href="' + manifest + '">' +
        '<meta name="theme-color" content="' + themeColor + '">' +
        '<link rel="apple-touch-icon" href="' + touchIcon + '">' +
        '<link rel="icon" href="' + icon + '">' +
        stylesheets
    );
})();
