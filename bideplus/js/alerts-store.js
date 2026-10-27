const AlertsStore = (() => {
    const DB_NAME = 'bide-alerts';
    const STORE = 'kv';
    const NETWORKS = BideNetworks.slugs;
    const MAX_SEEN = 300;
    const MAX_NOTIFICATIONS = 3;
    const appLabel = (network) => BideNetworks.name(network);
    const appIcon = (network) => `/${BideNetworks.get(network).icons}/icon-192.png`;

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

    async function notify(registration, alerts) {
        for (const alert of alerts.slice(0, MAX_NOTIFICATIONS)) {
            await registration.showNotification(`${appLabel(alert.network)} · ${alert.title}`, {
                body: alert.body.slice(0, 180),
                icon: appIcon(alert.network),
                tag: alert.key,
                data: { url: `/?red=${alert.network}` },
            });
        }
        if (alerts.length > MAX_NOTIFICATIONS) {
            await registration.showNotification('Más incidencias en tus líneas', {
                body: `${alerts.length - MAX_NOTIFICATIONS} avisos más. Ábrelos en la app.`,
                icon: appIcon(alerts[0].network),
                tag: 'bide-more-alerts',
                data: { url: `/?red=${alerts[0].network}` },
            });
        }
    }

    return { get, set, setFavorites, checkAlerts, resetBaseline, notify };
})();
