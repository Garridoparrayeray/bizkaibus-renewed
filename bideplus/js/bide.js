(() => {
    const root = document.documentElement;
    if (!root.classList.contains('bide')) return;

    const APPS = {
        bus: { name: 'Bizkaibus+', storageKey: 'bizkaibus_favorites' },
        metro: { name: 'Metro+', storageKey: 'metrobilbao_favorites' },
        euskotren: { name: 'Euskotren+', storageKey: 'euskotren_favorites' },
        renfe: { name: 'Renfe Cercanías+', storageKey: 'renfe_favorites' },
        'tranvia-bilbao': { name: 'Tranvía Bilbao+', storageKey: 'tranviabilbao_favorites' },
        'tranvia-vitoria': { name: 'Tranvía Vitoria+', storageKey: 'tranviavitoria_favorites' },
    };
    const VIEWS = ['favoritos', 'avisos', 'info'];
    const INSTALL_DISMISS_KEY = 'bide_install_dismissed';
    const INSTALL_DISMISS_MS = 30 * 24 * 60 * 60 * 1000;
    const desktop = window.matchMedia('(min-width: 1024px)');
    const isMenu = !window.__bbNetwork;
    const labelCache = new Map();
    let currentView = null;

    function translate(key, fallback) {
        const text = I18n.t(key);
        return text === key ? fallback : text;
    }

    function createNode(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;
        return node;
    }

    function readFavorites(app) {
        try {
            const favorites = JSON.parse(localStorage.getItem(APPS[app].storageKey) || '[]');
            return Array.isArray(favorites) ? favorites : [];
        } catch (e) {
            return [];
        }
    }

    function appQuery(app) {
        return app === 'bus' ? '' : '?red=' + app;
    }

    function groupTitle(app, tag) {
        const title = createNode(tag, 'bide-group');
        title.append(createNode('span', 'bide-swatch bide-swatch--' + app), document.createTextNode(APPS[app].name));
        return title;
    }

    function setActiveTab(view) {
        document.querySelectorAll('[data-bide-tab]').forEach((tab) => {
            const name = tab.getAttribute('data-bide-tab');
            if (view ? name === view : name === 'apps') tab.setAttribute('aria-current', 'page');
            else tab.removeAttribute('aria-current');
        });
    }

    function showView(view, addToHistory) {
        const wasOpen = !!currentView;
        document.querySelectorAll('dialog[open]').forEach((dialog) => dialog.close());
        document.querySelectorAll('[data-bide-view]').forEach((section) => {
            section.hidden = section.getAttribute('data-bide-view') !== view;
        });
        currentView = view;
        root.classList.toggle('bide-view-open', !!view);
        setActiveTab(view);
        if (view === 'favoritos') renderFavorites();
        if (view === 'avisos') renderAlerts();
        if (addToHistory && view && wasOpen) history.replaceState({ ...history.state, bideView: view }, '', '#' + view);
        else if (addToHistory && view) history.pushState({ ...history.state, bideView: view }, '', '#' + view);
        window.scrollTo(0, 0);
        if (view) {
            const heading = document.querySelector(`[data-bide-view="${view}"] h2`);
            heading.setAttribute('tabindex', '-1');
            heading.focus({ preventScroll: true });
        }
    }

    function closeView() {
        if (!currentView) return;
        if (history.state && history.state.bideView) history.back();
        else showView(null, false);
    }

    function selectTab(tab) {
        if (tab === 'apps') {
            if (currentView) closeView();
            else if (!isMenu) window.location.href = '/';
            return;
        }
        if (currentView === tab) closeView();
        else showView(tab, true);
    }

    async function favoriteLabel(app, favorite) {
        const cacheKey = `${app}:${favorite.type}:${favorite.refId}`;
        if (labelCache.has(cacheKey)) return labelCache.get(cacheKey);
        const path = favorite.type === 'stop' ? '/api/stops/' : '/api/lines/';
        let label = { title: '#' + favorite.refId, area: '' };
        try {
            // Como en js/api.js: los identificadores de Euskotren llevan «:» y la API los espera sin codificar.
            const response = await fetch(path + encodeURIComponent(favorite.refId).replace(/%3A/gi, ':') + appQuery(app));
            if (response.ok) {
                const data = await response.json();
                if (favorite.type === 'stop') label = { title: data.name, area: data.area || '' };
                else label = { title: `${data.code} ${data.name}`, area: '' };
            }
        } catch (e) {
            return label;
        }
        labelCache.set(cacheKey, label);
        return label;
    }

    function favoriteItem(app, favorite, compact) {
        const item = createNode('li');
        const link = createNode('a', 'bide-fav');
        link.href = (favorite.type === 'stop' ? '/stops/' : '/lines/') + encodeURIComponent(favorite.refId) + appQuery(app);
        const text = createNode('span', 'bide-fav-text');
        const title = createNode('strong', null, '…');
        const type = favorite.type === 'stop' ? translate('bide.fav.stop', 'Parada') : translate('bide.fav.line', 'Línea');
        const detail = createNode('span', null, compact ? `${APPS[app].name} · ${type}` : type);
        text.append(title, detail);
        link.append(createNode('span', 'bide-swatch bide-swatch--' + app), text);
        item.append(link);
        favoriteLabel(app, favorite).then((label) => {
            title.textContent = label.title;
            if (!compact && label.area) detail.textContent = `${type} · ${label.area}`;
        });
        return item;
    }

    function renderFavorites() {
        document.querySelectorAll('[data-bide-favorites]').forEach((list) => {
            const compact = list.getAttribute('data-bide-favorites') === 'compact';
            let total = 0;
            list.replaceChildren();
            for (const app of Object.keys(APPS)) {
                const favorites = readFavorites(app);
                if (!favorites.length) continue;
                total += favorites.length;
                if (!compact) list.append(groupTitle(app, 'li'));
                favorites.forEach((favorite) => list.append(favoriteItem(app, favorite, compact)));
            }
            if (compact && !total) list.append(createNode('li', 'bide-empty', translate('app.favorites.empty', 'Busca una parada o línea y guárdala para verla aquí.')));
            const empty = list.parentElement.querySelector('[data-bide-favorites-empty]');
            if (empty) empty.hidden = total > 0;
        });
    }

    function alertUrls(app, favorites) {
        const lines = favorites.filter((f) => f.type === 'line').map((f) => encodeURIComponent(f.refId));
        const stops = favorites.filter((f) => f.type === 'stop').map((f) => encodeURIComponent(f.refId));
        if (app === 'bus') return lines.map((id) => `/api/alerts?line=${id}`);
        if (app === 'renfe') return [...stops.map((id) => `/api/alerts?red=renfe&stop=${id}`), ...lines.map((id) => `/api/alerts?red=renfe&line=${id}`)];
        return favorites.length ? [`/api/alerts?red=${app}`] : [];
    }

    async function fetchAlerts(app) {
        const seen = new Set();
        const alerts = [];
        for (const url of alertUrls(app, readFavorites(app))) {
            try {
                const response = await fetch(url);
                if (!response.ok) continue;
                for (const alert of (await response.json()).alerts || []) {
                    const title = alert.summary || alert.title || '';
                    const key = `${title}|${alert.description || ''}`;
                    if (!title || seen.has(key)) continue;
                    seen.add(key);
                    alerts.push({ title, description: alert.description || '' });
                }
            } catch (e) {
                continue;
            }
        }
        return alerts;
    }

    async function renderAlerts() {
        const list = document.querySelector('[data-bide-alerts]');
        const toggle = document.querySelector('.alerts-toggle');
        const toggleSlot = document.querySelector('.bide-alerts-toggle');
        if (toggle && !toggleSlot.contains(toggle)) {
            toggleSlot.append(toggle);
            const note = document.getElementById('alerts-note');
            if (note) toggleSlot.append(note);
        }
        list.replaceChildren(createNode('p', 'bide-note', translate('app.loadingIncidents', 'Cargando incidencias…')));
        const groups = [];
        for (const app of Object.keys(APPS)) {
            const alerts = await fetchAlerts(app);
            if (alerts.length) groups.push({ app, alerts });
        }
        list.replaceChildren();
        if (!groups.length) {
            list.append(createNode('p', 'bide-empty', translate('bide.alerts.none', 'No hay avisos activos en tus favoritos.')));
            return;
        }
        for (const { app, alerts } of groups) {
            list.append(groupTitle(app, 'p'));
            for (const alert of alerts) {
                const card = createNode('article', 'bide-card bide-alert');
                card.append(createNode('strong', null, alert.title));
                if (alert.description) card.append(createNode('p', null, alert.description));
                list.append(card);
            }
        }
    }

    function syncLangSwitch() {
        document.querySelectorAll('[data-bide-lang]').forEach((button) => {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-bide-lang') === I18n.getLang()));
        });
    }

    function selectLang(lang) {
        if (lang === I18n.getLang()) return;
        document.getElementById('lang-toggle').click();
        syncLangSwitch();
        if (currentView === 'favoritos') renderFavorites();
    }

    function setUpInstallBanner() {
        const banner = document.querySelector('.bide-install');
        if (!banner) return;
        const hint = banner.querySelector('.bide-install-hint');
        const accept = banner.querySelector('.bide-install-accept');
        let installPrompt = null;

        function dismissedRecently() {
            try {
                return Date.now() - Number(localStorage.getItem(INSTALL_DISMISS_KEY) || 0) < INSTALL_DISMISS_MS;
            } catch (e) {
                return false;
            }
        }

        function show(isIos) {
            if (root.classList.contains('is-standalone') || desktop.matches || dismissedRecently()) return;
            const key = isIos ? 'bide.install.ios' : 'bide.install.text';
            hint.setAttribute('data-i18n', key);
            hint.textContent = translate(key, hint.textContent);
            accept.hidden = isIos;
            banner.hidden = false;
        }

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            installPrompt = e;
            show(false);
        });
        window.addEventListener('appinstalled', () => {
            banner.hidden = true;
        });
        accept.addEventListener('click', async () => {
            if (!installPrompt) return;
            installPrompt.prompt();
            await installPrompt.userChoice.catch(() => null);
            installPrompt = null;
            banner.hidden = true;
        });
        banner.querySelector('.bide-install-dismiss').addEventListener('click', () => {
            banner.hidden = true;
            try {
                localStorage.setItem(INSTALL_DISMISS_KEY, String(Date.now()));
            } catch (e) {
                return;
            }
        });

        const userAgent = navigator.userAgent || '';
        const isIos = /iPhone|iPad|iPod/.test(userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        if (isIos && /Safari/.test(userAgent) && !/CriOS|FxiOS|EdgiOS/.test(userAgent)) show(true);
    }

    document.addEventListener('click', (e) => {
        const tab = e.target.closest('[data-bide-tab]');
        const lang = e.target.closest('[data-bide-lang]');
        if (tab) selectTab(tab.getAttribute('data-bide-tab'));
        else if (lang) selectLang(lang.getAttribute('data-bide-lang'));
        else if (e.target.closest('[data-bide-close]')) closeView();
        else if (e.target.closest('[data-bide-legal]')) document.getElementById('legal-panel').showModal();
    });

    const closedByHistory = new Set();

    function watchDialog(dialog) {
        new MutationObserver(() => {
            const inHistory = history.state && history.state.bideDialog === dialog.id;
            if (dialog.open && !inHistory) history.pushState({ ...history.state, bideDialog: dialog.id }, '', location.href);
            else if (!dialog.open && closedByHistory.has(dialog)) closedByHistory.delete(dialog);
            else if (!dialog.open && inHistory) history.back();
        }).observe(dialog, { attributes: true, attributeFilter: ['open'] });
    }

    window.addEventListener('popstate', (e) => {
        const openDialog = document.querySelector('dialog[open]');
        if (openDialog && !(e.state && e.state.bideDialog === openDialog.id)) {
            closedByHistory.add(openDialog);
            openDialog.close();
        }
        const view = e.state && e.state.bideView;
        const nextView = VIEWS.includes(view) ? view : null;
        if (nextView !== currentView) showView(nextView, false);
    });

    document.querySelectorAll('dialog[id]').forEach(watchDialog);

    const appsToggle = document.querySelector('.bide-rail-apps');
    if (appsToggle) {
        appsToggle.addEventListener('click', () => {
            const open = appsToggle.getAttribute('aria-expanded') !== 'true';
            appsToggle.setAttribute('aria-expanded', String(open));
            appsToggle.nextElementSibling.hidden = !open;
        });
    }

    if (isMenu) {
        const legalPanel = document.getElementById('legal-panel');
        document.getElementById('legal-close').addEventListener('click', () => legalPanel.close());
        legalPanel.addEventListener('click', (e) => {
            if (e.target === legalPanel) legalPanel.close();
        });
    }

    new MutationObserver(syncLangSwitch).observe(root, { attributes: true, attributeFilter: ['lang'] });
    window.addEventListener('storage', renderFavorites);
    window.addEventListener('bide:favorites', renderFavorites);
    syncLangSwitch();
    setUpInstallBanner();
    renderFavorites();

    const initialView = location.hash.slice(1);
    if (VIEWS.includes(initialView)) {
        history.replaceState({ ...history.state, bideView: initialView }, '', location.href);
        showView(initialView, false);
    } else {
        setActiveTab(null);
    }
})();
