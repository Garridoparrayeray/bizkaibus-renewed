// Tema, red y recursos de la app. Va en <head> y antes de pintar: usa document.write a propósito.
(function () {
    var params = new URLSearchParams(location.search);
    var qTema = params.get('tema');
    // bideplusmiamor.vercel.app es un dominio dedicado, sin toggle ni
    // forma de salir del tema: siempre "mi amor" en ese dominio.
    var isMiamorDomain = location.hostname === 'bideplusmiamor.vercel.app';
    var isMiamor = isMiamorDomain || qTema === 'miamor';
    if (qTema === 'miamor' || qTema === 'pro') {
        params.delete('tema');
        var qs = params.toString();
        history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
    }
    window.__bbTheme = isMiamor ? 'miamor' : 'pro';

    var redParam = params.get('red');
    window.__bbNetwork = 'bus';
    if (redParam === 'metro' || redParam === 'euskotren' || redParam === 'tranvia-bilbao' || redParam === 'tranvia-vitoria' || redParam === 'renfe') {
        window.__bbNetwork = redParam;
    }
    var isMetro          = window.__bbNetwork === 'metro';
    var isEuskoTren      = window.__bbNetwork === 'euskotren';
    var isTranviaBilbao  = window.__bbNetwork === 'tranvia-bilbao';
    var isTranviaVitoria = window.__bbNetwork === 'tranvia-vitoria';
    var isRenfe          = window.__bbNetwork === 'renfe';

    var title      = 'BizkaiBus+';
    var manifest   = isMiamor ? 'manifest-miamor.json' : 'manifest-bide.json';
    var themeColor = isMiamor ? '#db2777' : '#01573C';
    var touchIcon  = isMiamor ? 'icons/apple-touch-icon.png' : 'icons-pro/apple-touch-icon.png';
    var icon       = isMiamor ? 'icons/icon-192.png' : 'icons-pro/icon-192.png';
    var stylesheet = isMiamor ? 'style.css' : 'style-app.css';

    if (isMetro) {
        title = 'Metro+';
        if (isMiamor) {
            themeColor = '#db2777';
        } else {
                        touchIcon  = 'icons-metro/apple-touch-icon.png';
            icon       = 'icons-metro/icon-192.png';
            themeColor = '#C8102E';
        }
    } else if (isEuskoTren) {
        title      = 'Euskotren+';
                touchIcon  = 'icons-euskotren/apple-touch-icon.png';
        icon       = 'icons-euskotren/icon-192.png';
        themeColor = '#003F8C';
    } else if (isTranviaBilbao) {
        title      = 'Tranvía Bilbao+';
                touchIcon  = 'icons-tranvia-bilbao/apple-touch-icon.png';
        icon       = 'icons-tranvia-bilbao/icon-192.png';
        themeColor = '#4EB848';
    } else if (isTranviaVitoria) {
        title      = 'Tranvía Vitoria+';
                touchIcon  = 'icons-tranvia-vitoria/apple-touch-icon.png';
        icon       = 'icons-tranvia-vitoria/icon-192.png';
        themeColor = '#60AE27';
    } else if (isRenfe) {
        title      = 'Renfe Cercanías+';
                touchIcon  = 'icons-renfe/apple-touch-icon.png';
        icon       = 'icons-renfe/icon-192.png';
        themeColor = '#74349A';
    }

    if (isMiamor && !isMetro && !isEuskoTren && !isTranviaBilbao && !isTranviaVitoria && !isRenfe) {
        title += ' | Para el amor de mi vida';
    }
    // El título descriptivo de cada página lo pone el servidor (SEO); aquí solo se cambia en el tema «mi amor».
    if (isMiamor) {
        document.title = title;
    }

    if (isMetro)          document.documentElement.classList.add('is-metro');
    if (isEuskoTren)      document.documentElement.classList.add('is-euskotren');
    if (isTranviaBilbao)  document.documentElement.classList.add('is-tranvia-bilbao');
    if (isTranviaVitoria) document.documentElement.classList.add('is-tranvia-vitoria');
    if (isRenfe)          document.documentElement.classList.add('is-renfe');

    document.write(
        '<link rel="manifest" href="' + manifest + '">' +
        '<meta name="theme-color" content="' + themeColor + '">' +
        '<link rel="apple-touch-icon" href="' + touchIcon + '">' +
        '<link rel="icon" href="' + icon + '">' +
        '<link rel="stylesheet" href="' + stylesheet + '">'
    );
})();
