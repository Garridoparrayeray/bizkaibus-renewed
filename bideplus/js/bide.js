// Bide+ como una sola app: pestañas (app instalada), columna lateral (ordenador), páginas de
// Favoritos, Avisos e Info que reúnen todas las apps, selector de idioma y aviso de instalación.
(() => {
    const root = document.documentElement;
    if (!root.classList.contains('bide')) return;

    const APPS = {
        bus: { name: 'Bizkaibus+', key: 'bizkaibus_favorites' },
        metro: { name: 'Metro+', key: 'metrobilbao_favorites' },
        euskotren: { name: 'Euskotren+', key: 'euskotren_favorites' },
        renfe: { name: 'Renfe Cercanías+', key: 'renfe_favorites' },
        'tranvia-bilbao': { name: 'Tranvía Bilbao+', key: 'tranviabilbao_favorites' },
        'tranvia-vitoria': { name: 'Tranvía Vitoria+', key: 'tranviavitoria_favorites' },
    };
    const VIEWS = ['favoritos', 'avisos', 'info'];
    const desktop = window.matchMedia('(min-width: 1024px)');
    const isMenu = !window.__bbNetwork;
    const t = (key, fallback) => {
        const text = window.I18n ? I18n.t(key) : key;
        return text === key ? fallback : text;
    };

    function readJson(key) {
        try {
            const value = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(value) ? value : [];
        } catch (e) {
            return [];
        }
    }

    function suffix(network) {
        return network === 'bus' ? '' : '?red=' + network;
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    // ===== Páginas (Favoritos, Avisos, Info) =====
    let currentView = null;

    function setActiveTab(view) {
        document.querySelectorAll('[data-bide-tab]').forEach((tab) => {
            const key = tab.getAttribute('data-bide-tab');
            const active = view ? key === view : key === 'apps';
            if (active) tab.setAttribute('aria-current', 'page');
            else tab.removeAttribute('aria-current');
        });
    }

    function showView(view, push) {
        document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
        VIEWS.forEach((name) => {
            const section = document.getElementById('bide-view-' + name);
            if (section) section.hidden = name !== view;
        });
        currentView = view;
        root.classList.toggle('bide-view-open', !!view);
        if (view) root.setAttribute('data-bide-view', view);
        else root.removeAttribute('data-bide-view');
        setActiveTab(view);
        if (view === 'favoritos') renderFavorites();
        if (view === 'avisos') renderAlerts();
        if (view === 'info') renderInfo();
        if (push && view) history.pushState({ bideView: view }, '', '#' + view);
        window.scrollTo(0, 0);
        if (view) {
            const heading = document.querySelector('#bide-view-' + view + ' h2');
            if (heading) {
                heading.setAttribute('tabindex', '-1');
                heading.focus({ preventScroll: true });
            }
        }
    }

    function closeView() {
        if (!currentView) return;
        if (history.state && history.state.bideView) history.back();
        else showView(null, false);
    }

    function onTab(key) {
        if (key === 'apps') {
            if (currentView) closeView();
            else if (!isMenu) window.location.href = '/';
            return;
        }
        if (currentView === key) closeView();
        else showView(key, true);
    }

    document.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-bide-tab]');
        if (tab) {
            onTab(tab.getAttribute('data-bide-tab'));
            return;
        }
        if (e.target.closest('[data-bide-close]')) {
            closeView();
            return;
        }
        if (e.target.closest('[data-bide-legal]')) {
            const legal = document.getElementById('legal-panel');
            if (legal) legal.showModal();
        }
    });

    window.addEventListener('popstate', (e) => {
        const view = e.state && e.state.bideView;
        showView(VIEWS.includes(view) ? view : null, false);
    });

    const legal = document.getElementById('legal-panel');
    const legalClose = document.getElementById('legal-close');
    if (legal && legalClose && isMenu) {
        legalClose.addEventListener('click', () => legal.close());
        legal.addEventListener('click', (e) => {
            if (e.target === legal) legal.close();
        });
    }

    // ===== Favoritos de todas las apps =====
    const labelCache = new Map();

    async function favoriteLabel(network, favorite) {
        const cacheKey = network + ':' + favorite.type + ':' + favorite.refId;
        if (labelCache.has(cacheKey)) return labelCache.get(cacheKey);
        const path = favorite.type === 'stop' ? '/api/stops/' : '/api/lines/';
        const query = network === 'bus' ? '' : '?red=' + network;
        let label = { title: '#' + favorite.refId, sub: '' };
        try {
            // Como hace la app (js/api.js): los identificadores de Euskotren llevan «:» y la API los espera tal cual.
            const response = await fetch(path + encodeURIComponent(favorite.refId).replace(/%3A/gi, ':') + query);
            if (response.ok) {
                const data = await response.json();
                if (favorite.type === 'stop') label = { title: data.name, sub: data.area || '' };
                else label = { title: data.code + ' ' + data.name, sub: '' };
            }
        } catch (e) {
            // Sin conexión: se muestra el identificador.
        }
        labelCache.set(cacheKey, label);
        return label;
    }

    function favoriteItem(network, favorite, compact) {
        const li = el('li');
        const link = el('a', 'bide-fav');
        link.href = (favorite.type === 'stop' ? '/stops/' : '/lines/') + encodeURIComponent(favorite.refId) + suffix(network);
        link.append(el('span', 'bide-swatch bide-swatch--' + network));
        const text = el('span', 'bide-fav-text');
        const title = el('strong', null, '…');
        const typeLabel = favorite.type === 'stop' ? t('bide.fav.stop', 'Parada') : t('bide.fav.line', 'Línea');
        const sub = el('span', null, compact ? APPS[network].name + ' · ' + typeLabel : typeLabel);
        text.append(title, sub);
        link.append(text);
        li.append(link);
        favoriteLabel(network, favorite).then((label) => {
            title.textContent = label.title;
            if (!compact && label.sub) sub.textContent = typeLabel + ' · ' + label.sub;
        });
        return li;
    }

    function renderFavorites() {
        document.querySelectorAll('[data-bide-favorites]').forEach((list) => {
            const compact = list.getAttribute('data-bide-favorites') === 'compact';
            list.replaceChildren();
            let total = 0;
            for (const [network, app] of Object.entries(APPS)) {
                const favorites = readJson(app.key);
                if (!favorites.length) continue;
                total += favorites.length;
                if (!compact) {
                    const head = el('li', 'bide-group');
                    head.append(el('span', 'bide-swatch bide-swatch--' + network), document.createTextNode(app.name));
                    list.append(head);
                }
                favorites.forEach((favorite) => list.append(favoriteItem(network, favorite, compact)));
            }
            if (compact && total === 0) list.append(el('li', 'bide-empty', t('app.favorites.empty', 'Busca una parada o línea y guárdala para verla aquí.')));
            const empty = list.parentElement.querySelector('[data-bide-favorites-empty]');
            if (empty) empty.hidden = total > 0;
        });
    }

    // ===== Avisos de los favoritos, en todas las apps =====
    function alertSources(network, favorites) {
        const lines = favorites.filter((f) => f.type === 'line').map((f) => String(f.refId));
        const stops = favorites.filter((f) => f.type === 'stop').map((f) => String(f.refId));
        if (network === 'bus') return lines.map((id) => '/api/alerts?line=' + encodeURIComponent(id));
        if (network === 'renfe') {
            return [
                ...stops.map((id) => '/api/alerts?red=renfe&stop=' + encodeURIComponent(id)),
                ...lines.map((id) => '/api/alerts?red=renfe&line=' + encodeURIComponent(id)),
            ];
        }
        return favorites.length ? ['/api/alerts?red=' + network] : [];
    }

    async function renderAlerts() {
        const container = document.querySelector('[data-bide-alerts]');
        if (!container) return;
        const toggle = document.querySelector('.alerts-toggle');
        const slot = document.querySelector('.bide-alerts-toggle-slot');
        if (toggle && slot && !slot.contains(toggle)) {
            slot.append(toggle);
            const note = document.getElementById('alerts-note');
            if (note) slot.append(note);
        }
        container.replaceChildren(el('p', 'bide-note', t('app.loadingIncidents', 'Cargando incidencias…')));
        const groups = [];
        for (const [network, app] of Object.entries(APPS)) {
            const sources = alertSources(network, readJson(app.key));
            if (!sources.length) continue;
            const seen = new Set();
            const alerts = [];
            for (const url of sources) {
                try {
                    const response = await fetch(url);
                    if (!response.ok) continue;
                    const data = await response.json();
                    for (const alert of data.alerts || []) {
                        const title = alert.summary || alert.title || '';
                        const key = title + '|' + (alert.description || '');
                        if (!title || seen.has(key)) continue;
                        seen.add(key);
                        alerts.push({ title, description: alert.description || '' });
                    }
                } catch (e) {
                    // Sin conexión con esa fuente: se sigue con las demás.
                }
            }
            if (alerts.length) groups.push({ network, app, alerts });
        }
        container.replaceChildren();
        if (!groups.length) {
            container.append(el('p', 'bide-empty', t('bide.alerts.none', 'No hay avisos activos en tus favoritos.')));
            return;
        }
        for (const group of groups) {
            const head = el('p', 'bide-group');
            head.append(el('span', 'bide-swatch bide-swatch--' + group.network), document.createTextNode(group.app.name));
            container.append(head);
            for (const alert of group.alerts) {
                const card = el('article', 'bide-card bide-alert');
                card.append(el('strong', null, alert.title));
                if (alert.description) card.append(el('p', null, alert.description));
                container.append(card);
            }
        }
    }

    function renderInfo() {
        // El texto de Info es fijo; aquí solo se asegura el foco y la traducción.
        if (window.I18n) I18n.applyTranslations(document.getElementById('bide-view-info'));
    }

    // ===== Columna lateral: «Todas las apps» plegable =====
    const appsButton = document.querySelector('.bide-rail-apps');
    const appsList = document.getElementById('bide-rail-apps');
    if (appsButton && appsList) {
        appsButton.addEventListener('click', () => {
            const open = appsButton.getAttribute('aria-expanded') !== 'true';
            appsButton.setAttribute('aria-expanded', String(open));
            appsList.hidden = !open;
        });
    }

    // ===== Idioma: ES | EU =====
    function syncLang() {
        const lang = window.I18n ? I18n.getLang() : 'es';
        document.querySelectorAll('[data-bide-lang]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-bide-lang') === lang));
        });
    }
    document.addEventListener('click', (e) => {
        const button = e.target.closest('[data-bide-lang]');
        if (!button || !window.I18n) return;
        const lang = button.getAttribute('data-bide-lang');
        if (lang === I18n.getLang()) return;
        const legacy = document.getElementById('lang-toggle');
        if (legacy) {
            legacy.click();
        } else {
            I18n.setLang(lang);
            I18n.applyTranslations();
        }
        syncLang();
        if (currentView === 'favoritos') renderFavorites();
    });
    new MutationObserver(syncLang).observe(root, { attributes: true, attributeFilter: ['lang'] });
    syncLang();

    // ===== Aviso de instalación (solo en el navegador del móvil) =====
    const banner = document.getElementById('bide-install');
    if (banner) {
        const DISMISS_KEY = 'bide_install_dismissed';
        let deferredPrompt = null;
        const recentlyDismissed = () => {
            try {
                return Date.now() - Number(localStorage.getItem(DISMISS_KEY) || 0) < 30 * 24 * 60 * 60 * 1000;
            } catch (e) {
                return false;
            }
        };
        const canShow = () => !root.classList.contains('is-standalone') && !desktop.matches && !recentlyDismissed();
        const show = (ios) => {
            if (!canShow()) return;
            const hint = document.getElementById('bide-install-hint');
            hint.setAttribute('data-i18n', ios ? 'bide.install.ios' : 'bide.install.text');
            hint.textContent = t(ios ? 'bide.install.ios' : 'bide.install.text', hint.textContent);
            document.getElementById('bide-install-accept').hidden = ios;
            banner.hidden = false;
        };
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            show(false);
        });
        window.addEventListener('appinstalled', () => {
            banner.hidden = true;
        });
        const ua = navigator.userAgent || '';
        const ios = /iPhone|iPad|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (ios && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS/.test(ua)) show(true);
        document.getElementById('bide-install-accept').addEventListener('click', async () => {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            try {
                await deferredPrompt.userChoice;
            } catch (e) {
                // El navegador puede rechazarlo.
            }
            deferredPrompt = null;
            banner.hidden = true;
        });
        document.getElementById('bide-install-dismiss').addEventListener('click', () => {
            banner.hidden = true;
            try {
                localStorage.setItem(DISMISS_KEY, String(Date.now()));
            } catch (e) {
                // Sin almacenamiento solo se oculta en esta visita.
            }
        });
    }

    // ===== Arranque =====
    renderFavorites();
    window.addEventListener('storage', renderFavorites);
    window.addEventListener('bide:favorites', renderFavorites);
    const initial = location.hash.replace('#', '');
    if (VIEWS.includes(initial)) {
        history.replaceState({ bideView: initial }, '', location.href);
        showView(initial, false);
    } else {
        setActiveTab(null);
    }
})();
