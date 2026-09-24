importScripts('/js/alerts-store.js');

const CACHE_VERSION = 'v8';
const CACHE_NAME = 'bizkaibus-shell-' + CACHE_VERSION;
const API_CACHE_NAME = 'bizkaibus-api-' + CACHE_VERSION;
const LIVE_API = /\/(departures|live|vehicles|alerts|nearby)(\/|\?|$)/;
const SHELL_FILES = [
    '/',
    '/style.css',
    '/style-app.css',
    '/fonts/fonts.css',
    '/fonts/inter-latin.woff2',
    '/fonts/bricolage-grotesque-latin.woff2',
    '/fonts/hanken-grotesk-latin.woff2',
    '/lib/leaflet/leaflet.css',
    '/lib/leaflet/leaflet.js',
    '/lib/leaflet/images/layers.png',
    '/lib/leaflet/images/layers-2x.png',
    '/lib/leaflet/images/marker-icon.png',
    '/lib/leaflet/images/marker-icon-2x.png',
    '/lib/leaflet/images/marker-shadow.png',
    '/js/api.js',
    '/js/app.js',
    '/js/alerts-store.js',
    '/js/menu.js',
    '/js/menu-view.js',
    '/style-menu.css',
    '/style-splash.css',
    '/js/splash.js',
    '/manifest.json',
    '/manifest-miamor.json',
    '/manifest-metro.json',
    '/manifest-euskotren.json',
    '/manifest-tranvia-bilbao.json',
    '/manifest-tranvia-vitoria.json',
    '/manifest-bide.json',
    '/miamor.html',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons-pro/icon-192.png',
    '/icons-pro/icon-512.png',
    '/icons-metro/icon-192.png',
    '/icons-metro/icon-512.png',
    '/icons-euskotren/icon-192.png',
    '/icons-euskotren/icon-512.png',
    '/icons-tranvia-bilbao/icon-192.png',
    '/icons-tranvia-bilbao/icon-512.png',
    '/icons-tranvia-vitoria/icon-192.png',
    '/icons-tranvia-vitoria/icon-512.png',
    '/icons-bide-rojo/icon-192.png',
    '/icons-bide-rojo/icon-512.png',
    '/icons-bide-rojo/bide-wordmark-mayusculas.svg',
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
            Promise.all(keys.filter((key) => key !== CACHE_NAME && key !== API_CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    if (url.pathname.startsWith('/api/')) {
        if (!LIVE_API.test(url.pathname)) {
            event.respondWith(
                fetch(event.request)
                    .then((response) => {
                        if (response.ok) {
                            const clone = response.clone();
                            caches.open(API_CACHE_NAME).then((cache) => cache.put(event.request, clone));
                        }
                        return response;
                    })
                    .catch(() => caches.match(event.request))
            );
        }
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

async function notifyNewAlerts() {
    if (!(await AlertsStore.get('enabled'))) return;
    const fresh = await AlertsStore.checkAlerts();
    if (fresh.length > 0) await AlertsStore.notify(self.registration, fresh);
}

self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'line-alerts-check') {
        event.waitUntil(notifyNewAlerts());
    }
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/';
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
