(function () {
    'use strict';

    const KEY = 'bide_fav_networks';
    const DEFAULT_COUNT = 4;
    const section = document.getElementById('tr');
    const favList = document.getElementById('tiles-fav');
    const restList = document.getElementById('tiles-rest');
    const localList = document.getElementById('tiles-local');
    const localButton = document.getElementById('nets-local');
    const localSoon = document.getElementById('nets-local-soon');
    const editButton = document.getElementById('nets-edit');
    const allButton = document.getElementById('nets-all');
    const allCount = document.getElementById('nets-all-count');
    const hint = document.getElementById('nets-hint');
    const empty = document.getElementById('nets-empty');
    const toast = document.getElementById('nets-toast');
    const toastText = document.getElementById('nets-toast-text');
    const toastUndo = document.getElementById('nets-toast-undo');
    const TOAST_MS = 7000;
    let toastTimer = null;
    let undoFavorites = null;
    const items = Array.from(favList.children);
    const order = items.map(slugOf);
    const localSlugs = items.filter(function (item) {
        return item.getAttribute('data-scope') === 'local';
    }).map(slugOf);
    let editing = false;
    let open = false;
    let localOpen = false;

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
        if (!Array.isArray(saved)) {
            return order.filter(function (slug) {
                return !localSlugs.includes(slug);
            }).slice(0, DEFAULT_COUNT);
        }
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
            return !favorites.includes(slug) && !localSlugs.includes(slug);
        });
        const local = localSlugs.filter(function (slug) {
            return !favorites.includes(slug);
        });
        favorites.forEach(function (slug) {
            favList.appendChild(items[order.indexOf(slug)]);
        });
        rest.forEach(function (slug) {
            restList.appendChild(items[order.indexOf(slug)]);
        });
        local.forEach(function (slug) {
            localList.appendChild(items[order.indexOf(slug)]);
        });
        const showLocal = (editing || localOpen) && local.length > 0;
        localButton.disabled = localSlugs.length === 0;
        localSoon.hidden = localSlugs.length > 0;
        localButton.setAttribute('aria-expanded', String(showLocal));
        localList.hidden = !showLocal;
        items.forEach(function (item) {
            const pin = item.querySelector('.tile-pin');
            const isFavorite = favorites.includes(slugOf(item));
            const name = item.querySelector('a.tile').getAttribute('data-name');
            let label = translate('menu.nets.add', 'Añadir {name} a tus redes', { name: name });
            if (isFavorite) label = translate('menu.nets.remove', 'Quitar {name} de tus redes', { name: name });
            let word = translate('menu.nets.addShort', 'Añadir');
            if (isFavorite) word = translate('menu.nets.removeShort', 'Quitar');
            pin.hidden = !editing;
            pin.classList.toggle('is-fav', isFavorite);
            pin.setAttribute('aria-label', label);
            pin.querySelector('.tile-pin-text').textContent = word;
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

    function nameOf(slug) {
        return items[order.indexOf(slug)].querySelector('a.tile').getAttribute('data-name');
    }

    // Lo que se acaba de hacer, dicho con palabras y con vuelta atrás: la ficha cambia de lista y, sin esto, desaparece
    // de donde se tocó sin más.
    function announce(text, previous) {
        undoFavorites = previous;
        toastText.textContent = text;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(hideToast, TOAST_MS);
    }

    function hideToast() {
        toast.hidden = true;
        undoFavorites = null;
    }

    // La pantalla no salta: lo que había bajo el dedo sigue en su sitio aunque la lista de arriba crezca o encoja.
    function keepInPlace(anchor, change) {
        const before = anchor.getBoundingClientRect().top;
        change();
        window.scrollBy(0, anchor.getBoundingClientRect().top - before);
    }

    function toggleFavorite(slug, anchor) {
        const previous = readFavorites();
        const favorites = previous.slice();
        const at = favorites.indexOf(slug);
        let text = translate('menu.nets.added', '{name} añadida a tus redes', { name: nameOf(slug) });
        if (at === -1) {
            favorites.push(slug);
        } else {
            favorites.splice(at, 1);
            text = translate('menu.nets.removed', '{name} quitada de tus redes', { name: nameOf(slug) });
        }
        saveFavorites(favorites);
        keepInPlace(anchor, render);
        announce(text, previous);
    }

    editButton.addEventListener('click', function () {
        editing = !editing;
        render();
    });
    allButton.addEventListener('click', function () {
        open = !open;
        render();
    });
    localButton.addEventListener('click', function () {
        localOpen = !localOpen;
        render();
    });
    section.addEventListener('click', function (event) {
        const pin = event.target.closest('.tile-pin');
        if (pin) {
            toggleFavorite(pin.getAttribute('data-pin'), pin.closest('ul'));
            return;
        }
        if (editing && event.target.closest('a.tile')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    toastUndo.addEventListener('click', function () {
        if (undoFavorites === null) return;
        saveFavorites(undoFavorites);
        hideToast();
        render();
    });
    window.addEventListener('bide:lang', render);

    render();
})();
