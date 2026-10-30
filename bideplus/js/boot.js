// Tema, red y recursos de la app. Va en <head> y antes de pintar: usa document.write a propósito.
(function () {
    var params = new URLSearchParams(location.search);
    // El tema lo decide theme.js; aquí solo se limpia ?tema= de la URL.
    var isMiamor = window.__bbTheme === 'miamor';
    if (params.has('tema')) {
        params.delete('tema');
        var qs = params.toString();
        var search = '';
        if (qs) search = '?' + qs;
        history.replaceState(null, '', location.pathname + search + location.hash);
    }

    var redParam = params.get('red');
    window.__bbNetwork = BideNetworks.DEFAULT;
    if (BideNetworks.exists(redParam)) window.__bbNetwork = redParam;
    var network = BideNetworks.get(window.__bbNetwork);
    var isDefault = window.__bbNetwork === BideNetworks.DEFAULT;

    var title      = network.texts.es.name;
    var manifest   = 'manifest-bide.json';
    var themeColor = network.themeColor;
    var touchIcon  = network.icons + '/apple-touch-icon.png';
    var icon       = network.icons + '/icon-192.png';
    var stylesheets = '<link rel="stylesheet" href="style-app.css">';

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
