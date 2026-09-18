importScripts('/js/alerts-store.js');

const CACHE_VERSION = 'v3';
const CACHE_NAME = 'gasolinera-shell-' + CACHE_VERSION;
const SHELL_FILES = [
    '/',
    '/style.css',
    '/js/api.js',
    '/js/alerts-store.js',
    '/js/app.js',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_FILES))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (url.pathname.startsWith('/api/')) {
        return;
    }
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});

function formatPrice(value) {
    return value.toFixed(3).replace('.', ',');
}

async function notifyPriceDrops() {
    if (!(await AlertsStore.get('enabled'))) return;
    const drops = await AlertsStore.checkPrices();
    for (const drop of drops) {
        const lines = drop.fuelDrops.slice(0, 3).map((f) =>
            (AlertsStore.FUEL_LABELS[f.slug] || f.slug) + ': ' + formatPrice(f.from) + ' → ' + formatPrice(f.to) + ' €'
        );
        await self.registration.showNotification(drop.favorite.rotulo + ' ha bajado de precio', {
            body: lines.join('\n'),
            icon: '/icons/icon-192.png',
            tag: 'price-' + drop.favorite.ideess,
            data: { ideess: drop.favorite.ideess },
        });
    }
}

self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'price-drop-check') {
        event.waitUntil(notifyPriceDrops());
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = '/?station=' + encodeURIComponent(event.notification.data.ideess);
    event.waitUntil((async () => {
        const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if ('navigate' in client) {
                await client.navigate(url);
                return client.focus();
            }
        }
        return clients.openWindow(url);
    })());
});
