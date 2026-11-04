const AlertsStore = (() => {
    const DB_NAME = 'bide-alerts';
    const STORE = 'kv';
    const NETWORKS = BideNetworks.slugs;
    const MAX_SEEN = 300;
    const MAX_NOTIFICATIONS = 3;
    const appLabel = (network) => BideNetworks.name(network);
    const appIcon = (network) => `/${BideNetworks.get(network).icons}/icon-192.png`;
    // El de la barra de estado de Android: la silueta de Bide+ en blanco sobre transparente.
    const BADGE_ICON = '/icons-bide-rojo/badge-96.png';
    // El service worker no tiene el diccionario de la app.
    const MORE_TEXTS = {
        es: { title: 'Más incidencias en tus favoritos', body: '{n} avisos más. Ábrelos en la app.' },
        eu: { title: 'Intzidentzia gehiago zure gogokoetan', body: 'Beste {n} abisu. Ireki aplikazioan.' },
        en: { title: 'More disruptions on your favourites', body: '{n} more alerts. Open them in the app.' },
    };

    function openDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, 1);
            request.onupgradeneeded = () => request.result.createObjectStore(STORE);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function get(key) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const request = db.transaction(STORE).objectStore(STORE).get(key);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function set(key, value) {
        const db = await openDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE, 'readwrite');
            tx.objectStore(STORE).put(value, key);
            tx.oncomplete = () => resolve();
            tx.onerror = () => reject(tx.error);
        });
    }

    function hash(text) {
        let value = 5381;
        for (let i = 0; i < text.length; i++) value = ((value << 5) + value + text.charCodeAt(i)) | 0;
        return (value >>> 0).toString(36);
    }

    async function setFavorites(network, lineIds, stopIds = []) {
        const favorites = (await get('favorites')) || {};
        favorites[network] = { lines: lineIds.map(String), stops: stopIds.map(String) };
        await set('favorites', favorites);
    }

    function favoritesOf(favorites, network) {
        const entry = favorites[network];
        if (Array.isArray(entry)) return { lines: entry, stops: [] };
        if (entry && typeof entry === 'object') return { lines: entry.lines || [], stops: entry.stops || [] };
        return { lines: [], stops: [] };
    }

    function sourcesFor(network, favorite) {
        const mode = BideNetworks.get(network).features.alerts;
        const base = `/api/alerts?red=${encodeURIComponent(network)}`;
        if (mode === 'lines') {
            return favorite.lines.map((id) => ({ id, url: `${base}&line=${encodeURIComponent(id)}` }));
        }
        if (mode === 'stops') {
            return [
                ...favorite.stops.map((id) => ({ id: `stop:${id}`, url: `${base}&stop=${encodeURIComponent(id)}` })),
                ...favorite.lines.map((id) => ({ id: `line:${id}`, url: `${base}&line=${encodeURIComponent(id)}` })),
            ];
        }
        return [{ id: '*', url: base }];
    }

    // Petición directa y no por js/api.js: este archivo también lo usa el service worker, donde no hay página.
    async function fetchAlerts(network, source) {
        const response = await fetch(source.url);
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        return (data.alerts || []).map((alert) => {
            const title = alert.summary || alert.title || 'Incidencia';
            const body = (alert.description || '').trim();
            return { network, title, body, key: `${network}:${hash(`${title}|${body}`)}` };
        });
    }

    async function checkAlerts() {
        const favorites = (await get('favorites')) || {};
        const seen = (await get('seen')) || {};
        const baselined = (await get('baselined')) || {};
        const fresh = [];

        for (const network of NETWORKS) {
            const favorite = favoritesOf(favorites, network);
            if (favorite.lines.length + favorite.stops.length === 0) continue;
            for (const source of sourcesFor(network, favorite)) {
                let alerts;
                try {
                    alerts = await fetchAlerts(network, source);
                } catch (e) {
                    continue;
                }
                const baselineKey = `${network}:${source.id}`;
                for (const alert of alerts) {
                    if (seen[alert.key]) continue;
                    seen[alert.key] = Date.now();
                    if (baselined[baselineKey] && !fresh.some((item) => item.key === alert.key)) fresh.push(alert);
                }
                baselined[baselineKey] = true;
            }
        }

        const keys = Object.keys(seen);
        if (keys.length > MAX_SEEN) {
            keys.sort((a, b) => seen[a] - seen[b]).slice(0, keys.length - MAX_SEEN).forEach((key) => delete seen[key]);
        }
        await set('seen', seen);
        await set('baselined', baselined);
        return fresh;
    }

    async function resetBaseline() {
        await set('baselined', {});
        await set('seen', {});
    }

    function openUrl(network, seed) {
        return `/?red=${network}&aviso=${Date.now().toString(36)}-${hash(seed)}#avisos`;
    }

    async function notify(registration, alerts) {
        for (const alert of alerts.slice(0, MAX_NOTIFICATIONS)) {
            await registration.showNotification(`${appLabel(alert.network)} · ${alert.title}`, {
                body: alert.body.slice(0, 180),
                icon: appIcon(alert.network),
                badge: BADGE_ICON,
                tag: alert.key,
                data: { url: openUrl(alert.network, alert.key) },
            });
        }
        if (alerts.length > MAX_NOTIFICATIONS) {
            const texts = MORE_TEXTS[(await get('lang').catch(() => null)) || 'es'] || MORE_TEXTS.es;
            await registration.showNotification(texts.title, {
                body: texts.body.replace('{n}', String(alerts.length - MAX_NOTIFICATIONS)),
                icon: appIcon(alerts[0].network),
                badge: BADGE_ICON,
                tag: 'bide-more-alerts',
                data: { url: openUrl(alerts[0].network, 'bide-more-alerts') },
            });
        }
    }

    // Una notificación se abre una sola vez aunque el sistema vuelva a cargar su dirección.
    const OPENED_KEY = 'bide-opened-alerts';
    const OPENED_KEEP_MS = 7 * 24 * 3600 * 1000;

    function firstOpening(token) {
        let opened = {};
        try {
            opened = JSON.parse(localStorage.getItem(OPENED_KEY) || '{}') || {};
        } catch (e) {
            opened = {};
        }
        if (opened[token]) return false;
        for (const [key, at] of Object.entries(opened)) {
            if (Date.now() - at > OPENED_KEEP_MS) delete opened[key];
        }
        opened[token] = Date.now();
        try {
            localStorage.setItem(OPENED_KEY, JSON.stringify(opened));
        } catch (e) {
            // Sin almacenamiento se abre igual.
        }
        return true;
    }

    return { get, set, setFavorites, checkAlerts, resetBaseline, notify, firstOpening };
})();
