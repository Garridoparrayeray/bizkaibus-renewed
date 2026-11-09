(function () {
    'use strict';

    const KEY = 'bide_fav_networks';
    const DEFAULT_COUNT = 4;
    const section = document.getElementById('tr');
    const favList = document.getElementById('tiles-fav');
    const restList = document.getElementById('tiles-rest');
    const editButton = document.getElementById('nets-edit');
    const allButton = document.getElementById('nets-all');
    const allCount = document.getElementById('nets-all-count');
    const hint = document.getElementById('nets-hint');
    const empty = document.getElementById('nets-empty');
    const items = Array.from(favList.children);
    const order = items.map(slugOf);
    let editing = false;
    let open = false;

    function slugOf(item) {
        return item.querySelector('a.tile').getAttribute('data-app');
    }

    function translate(key, fallback, vars) {
        if (!window.I18n) return fallback;
        return window.I18n.t(key, vars);
    }

    function readFavorites() {
        let saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(KEY));
        } catch (e) {
            saved = null;
        }
        if (!Array.isArray(saved)) return order.slice(0, DEFAULT_COUNT);
        return saved.filter(function (slug) {
            return order.includes(slug);
        });
    }

    function saveFavorites(favorites) {
        try {
            localStorage.setItem(KEY, JSON.stringify(favorites));
        } catch (e) {
            return;
        }
    }

    function render() {
        const favorites = readFavorites();
        const rest = order.filter(function (slug) {
            return !favorites.includes(slug);
        });
        favorites.forEach(function (slug) {
            favList.appendChild(items[order.indexOf(slug)]);
        });
        rest.forEach(function (slug) {
            restList.appendChild(items[order.indexOf(slug)]);
        });
        items.forEach(function (item) {
            const pin = item.querySelector('.tile-pin');
            const isFavorite = favorites.includes(slugOf(item));
            const name = item.querySelector('a.tile').getAttribute('data-name');
            let label = translate('menu.nets.add', 'Añadir {name} a tus redes', { name: name });
            if (isFavorite) label = translate('menu.nets.remove', 'Quitar {name} de tus redes', { name: name });
            pin.hidden = !editing;
            pin.classList.toggle('is-fav', isFavorite);
            pin.setAttribute('aria-label', label);
        });
        const showRest = editing || open;
        empty.hidden = favorites.length > 0;
        allButton.hidden = rest.length === 0;
        allButton.setAttribute('aria-expanded', String(showRest));
        allCount.textContent = translate('menu.nets.more', '{n} más', { n: rest.length });
        restList.hidden = !showRest || rest.length === 0;
        hint.hidden = !editing;
        section.classList.toggle('is-editing', editing);
        editButton.setAttribute('aria-pressed', String(editing));
        let editLabel = translate('menu.nets.edit', 'Editar');
        if (editing) editLabel = translate('menu.nets.done', 'Hecho');
        editButton.textContent = editLabel;
    }

    function toggleFavorite(slug) {
        const favorites = readFavorites();
        const at = favorites.indexOf(slug);
        if (at === -1) {
            favorites.push(slug);
        } else {
            favorites.splice(at, 1);
        }
        saveFavorites(favorites);
        render();
    }

    editButton.addEventListener('click', function () {
        editing = !editing;
        render();
    });
    allButton.addEventListener('click', function () {
        open = !open;
        render();
    });
    section.addEventListener('click', function (event) {
        const pin = event.target.closest('.tile-pin');
        if (pin) {
            toggleFavorite(pin.getAttribute('data-pin'));
            return;
        }
        if (editing && event.target.closest('a.tile')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    window.addEventListener('bide:lang', render);

    render();
})();
