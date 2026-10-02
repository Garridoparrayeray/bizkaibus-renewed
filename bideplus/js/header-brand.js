// Título, subtítulo y tarjetas del selector según la red. Se ejecuta en cuanto existe la cabecera.
(function () {
    var net = window.__bbNetwork;
    var isMiamorActive = window.__bbTheme === 'miamor';
    var temaSuffix = isMiamorActive ? '&tema=miamor' : '';

    if (net === 'metro') {
        document.getElementById('app-logomark').classList.add('is-metro');
        document.getElementById('app-title').firstChild.textContent = 'METRO';
        document.getElementById('app-subtitle').textContent = I18n.t('switcher.metro.desc');
    } else if (net === 'euskotren') {
        document.getElementById('app-logomark').classList.add('is-euskotren');
        document.getElementById('app-title').firstChild.textContent = 'EUSKOTREN';
        document.getElementById('app-subtitle').textContent = I18n.t('switcher.euskotren.desc');
    } else if (net === 'tranvia-bilbao') {
        document.getElementById('app-logomark').classList.add('is-tranvia-bilbao');
        document.getElementById('app-title').firstChild.textContent = 'TRANVÍA';
        document.getElementById('app-title-city').textContent = 'BILBAO';
        document.getElementById('app-subtitle').textContent = I18n.t('app.tram.desc');
    } else if (net === 'tranvia-vitoria') {
        document.getElementById('app-logomark').classList.add('is-tranvia-vitoria');
        document.getElementById('app-title').firstChild.textContent = 'TRANVÍA';
        document.getElementById('app-title-city').textContent = 'VITORIA';
        document.getElementById('app-subtitle').textContent = I18n.t('app.tram.desc');
    } else if (net === 'renfe') {
        document.getElementById('app-logomark').classList.add('is-renfe');
        document.getElementById('app-title').firstChild.textContent = 'RENFE';
        document.getElementById('app-subtitle').textContent = I18n.t('switcher.renfeSubtitle');
    } else {
        document.getElementById('app-subtitle').textContent = I18n.t('switcher.bus.desc');
    }
    if (isMiamorActive && net === 'bus') {
        document.getElementById('app-subtitle').textContent = 'Para el amor de mi vida';
    }

    var cityEl = document.getElementById('app-title-city');
    if (cityEl.textContent) {
        // Que "BILBAO"/"VITORIA" termine justo bajo la "A" de
        // "TRANVÍA", sin contar el "+": se mide el ancho real en
        // vez de adivinarlo, porque el contenedor del título es
        // más ancho que el texto (para poder recortar con "…").
        var titleWidth = document.getElementById('app-title').getBoundingClientRect().width;
        var markWidth = document.getElementById('app-title-mark').getBoundingClientRect().width;
        cityEl.style.width = Math.max(0, titleWidth - markWidth) + 'px';
    }

    var currentCardId = 'net-card-bus';
    if (net === 'metro') {
        currentCardId = 'net-card-metro';
    } else if (net === 'euskotren') {
        currentCardId = 'net-card-euskotren';
    } else if (net === 'tranvia-bilbao') {
        currentCardId = 'net-card-tranvia-bilbao';
    } else if (net === 'tranvia-vitoria') {
        currentCardId = 'net-card-tranvia-vitoria';
    } else if (net === 'renfe') {
        currentCardId = 'net-card-renfe';
    }
    var currentCard = document.getElementById(currentCardId);
    if (currentCard) currentCard.hidden = true;

    if (temaSuffix) {
        ['net-card-bus', 'net-card-metro', 'net-card-euskotren', 'net-card-tranvia-bilbao', 'net-card-tranvia-vitoria', 'net-card-renfe'].forEach(function (id) {
            var card = document.getElementById(id);
            if (card && card !== currentCard) card.href += temaSuffix;
        });
    }
})();
