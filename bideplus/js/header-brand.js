// Subtítulo, ancho de la ciudad bajo el título y enlaces del selector según la red. Se ejecuta en cuanto existe la cabecera.
(function () {
    const net = window.__bbNetwork;
    const isMiamorActive = window.__bbTheme === 'miamor';

    if (isMiamorActive && net === BideNetworks.DEFAULT) {
        const subtitle = document.getElementById('app-subtitle');
        subtitle.removeAttribute('data-i18n');
        subtitle.textContent = 'Para el amor de mi vida';
    }

    const cityEl = document.getElementById('app-title-city');
    if (cityEl.textContent) {
        // Que la ciudad termine justo bajo la última letra del título, sin contar el "+": se mide el ancho
        // real en vez de adivinarlo, porque el contenedor del título es más ancho que el texto (para poder recortar con "…").
        const titleWidth = document.getElementById('app-title').getBoundingClientRect().width;
        const markWidth = document.getElementById('app-title-mark').getBoundingClientRect().width;
        cityEl.style.width = Math.max(0, titleWidth - markWidth) + 'px';
    }

    if (isMiamorActive) {
        document.querySelectorAll('#network-switcher .net-card:not(#net-card-menu)').forEach(function (card) {
            card.href += '&tema=miamor';
        });
    }
})();
