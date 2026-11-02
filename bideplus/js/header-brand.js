// Cabecera según la red y el idioma: título, ciudad, subtítulo y enlaces del selector. Se ejecuta en cuanto existe la cabecera.
(function () {
    const net = window.__bbNetwork;
    const isMiamorActive = window.__bbTheme === 'miamor';

    if (isMiamorActive && net === BideNetworks.DEFAULT) {
        const subtitle = document.getElementById('app-subtitle');
        subtitle.removeAttribute('data-i18n');
        subtitle.textContent = 'Para el amor de mi vida';
    }

    const titleEl = document.getElementById('app-title');
    const cityEl = document.getElementById('app-title-city');
    const cityKey = 'net.' + net + '.tile_city';
    const seoTitle = document.title;

    // Los tranvías llevan el título y la ciudad de su ficha; la pestaña, el nombre de la app. En el idioma elegido.
    function translateHeader() {
        if (cityEl.textContent && I18n.t(cityKey) !== cityKey) {
            titleEl.firstChild.textContent = I18n.t('net.' + net + '.tile_title').toUpperCase();
            cityEl.textContent = I18n.t(cityKey).toUpperCase();
        }
        if (isMiamorActive) return;
        document.title = seoTitle;
        if (I18n.getLang() !== 'es') document.title = I18n.t('net.' + net + '.name') + ' · ' + I18n.t('net.' + net + '.switcher');
    }

    // Que la ciudad termine justo bajo la última letra del título, sin contar el "+": se mide el ancho
    // real en vez de adivinarlo, porque el contenedor del título es más ancho que el texto (para poder recortar con "…").
    function fitCity() {
        if (!cityEl.textContent) return;
        const titleWidth = titleEl.getBoundingClientRect().width;
        const markWidth = document.getElementById('app-title-mark').getBoundingClientRect().width;
        cityEl.style.width = Math.max(0, titleWidth - markWidth) + 'px';
    }

    translateHeader();
    fitCity();
    window.addEventListener('bide:lang', function () {
        translateHeader();
        fitCity();
    });

    if (isMiamorActive) {
        document.querySelectorAll('#network-switcher .net-card:not(#net-card-menu)').forEach(function (card) {
            card.href += '&tema=miamor';
        });
    }
})();
