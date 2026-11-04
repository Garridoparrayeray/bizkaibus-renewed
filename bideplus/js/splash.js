(function () {
    'use strict';

    const root = document.documentElement;
    const SLOW_MS = 400;
    const MAX_MS = 8000;
    const SPLASH_KEY = 'bide_splash';
    let showTimer = null;
    let maxTimer = null;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function hide() {
        clearTimeout(showTimer);
        clearTimeout(maxTimer);
        root.classList.remove('is-loading');
    }

    function show() {
        if (reduceMotion) return;
        root.classList.add('is-loading');
        clearTimeout(maxTimer);
        maxTimer = setTimeout(hide, MAX_MS);
    }

    function arm() {
        clearTimeout(showTimer);
        showTimer = setTimeout(show, SLOW_MS);
    }

    function whenEverythingLoaded() {
        const pageLoaded = new Promise(function (resolve) {
            if (document.readyState === 'complete') {
                resolve();
            } else {
                window.addEventListener('load', resolve);
            }
        });
        return pageLoaded.then(function () {
            const pending = [];
            if (document.fonts) pending.push(document.fonts.ready);
            Array.prototype.forEach.call(document.images, function (image) {
                if (image.decode) pending.push(image.decode().catch(function () {}));
            });
            return Promise.all(pending);
        });
    }

    function finish() {
        clearTimeout(showTimer);
        if (!root.classList.contains('is-loading')) return;
        const plus = document.querySelector('.splash .wm-plus');
        if (!plus) {
            hide();
            return;
        }
        plus.addEventListener('animationiteration', hide, { once: true });
        setTimeout(hide, 1600);
    }

    function isFirstVisitOfSession() {
        let first = true;
        try {
            first = sessionStorage.getItem(SPLASH_KEY) !== '1';
            sessionStorage.setItem(SPLASH_KEY, '1');
        } catch (e) {
            first = true;
        }
        return first || /[?&]splash\b/.test(window.location.search);
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        let link = null;
        if (event.target.closest) link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download')) return;
        if (link.target && link.target !== '_self') return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search) return;
        arm();
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) hide();
    });

    window.bideSplash = { arm: arm };

    if (root.getAttribute('data-splash') === 'first' && isFirstVisitOfSession()) {
        show();
    } else {
        arm();
    }
    whenEverythingLoaded().then(finish);
})();
