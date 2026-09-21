const CACHE_VERSION = 'v5';
const CACHE_NAME = 'bizkaibus-shell-' + CACHE_VERSION;
const API_CACHE_NAME = 'bizkaibus-api-' + CACHE_VERSION;
const LIVE_API = /\/(departures|live|vehicles|alerts)(\/|\?|$)/;
const SHELL_FILES = [
    '/',
    '/style.css',
    '/style-app.css',
    '/js/api.js',
    '/js/app.js',
    '/js/menu.js',
    '/js/menu-view.js',
    '/style-menu.css',
    '/style-splash.css',
    '/js/splash.js',
    '/manifest.json',
    '/manifest-miamor.json',
    '/manifest-metro.json',
    '/manifest-euskotren.json',
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
