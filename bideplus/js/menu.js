(function () {
    'use strict';

    var LAST_APP_KEY = 'bide_last_app';
    var VIEW_KEY = 'bide_menu_view';
    var LAUNCH_MS = 380;
    var tiles = document.querySelectorAll('a.tile[data-app]');
    var viewButtons = document.querySelectorAll('[data-view-btn]');
    var main = document.querySelector('main');

    function readStorage(key) {
        try {
            return localStorage.getItem(key);
        } catch (e) {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (e) {
            return;
        }
    }

    function applyView(view) {
        document.documentElement.setAttribute('data-view', view);
        viewButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-view-btn') === view));
        });
    }

    function prefersReducedMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function changeView(view) {
        if (prefersReducedMotion()) {
            applyView(view);
            return;
        }
        if (document.startViewTransition) {
            var transition = document.startViewTransition(function () {
                applyView(view);
            });
            transition.ready.catch(function () {});
            transition.finished.catch(function () {});
            return;
        }
        main.classList.add('is-switching');
        setTimeout(function () {
            applyView(view);
            main.classList.remove('is-switching');
        }, 120);
    }

    var lastApp = readStorage(LAST_APP_KEY);
    tiles.forEach(function (tile) {
        var app = tile.getAttribute('data-app');
        if (app === lastApp) {
            tile.classList.add('is-last');
        }
        tile.addEventListener('click', function (event) {
            writeStorage(LAST_APP_KEY, app);
            var modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
            if (modified || prefersReducedMotion() || tile.classList.contains('is-launching')) return;
            event.preventDefault();
            tile.classList.add('is-launching');
            setTimeout(function () {
                window.location.href = tile.href;
                if (window.bideSplash) window.bideSplash.arm();
            }, LAUNCH_MS);
        });
    });

    applyView(document.documentElement.getAttribute('data-view') || 'tiles');
    viewButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var view = button.getAttribute('data-view-btn');
            changeView(view);
            writeStorage(VIEW_KEY, view);
        });
    });

    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        tiles.forEach(function (tile) {
            tile.classList.remove('is-launching');
        });
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js');
        });
    }
})();
