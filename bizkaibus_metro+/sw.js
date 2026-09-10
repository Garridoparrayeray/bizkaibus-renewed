const CACHE_VERSION = 'v3';
const CACHE_NAME = 'bizkaibus-shell-' + CACHE_VERSION;
const SHELL_FILES = [
    '/',
    '/style.css',
    '/style-pro.css',
    '/style-metro.css',
    '/js/api.js',
    '/js/app.js',
    '/manifest.json',
    '/manifest-miamor.json',
    '/manifest-metro.json',
    '/miamor.html',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons-pro/icon-192.png',
    '/icons-pro/icon-512.png',
    '/icons-metro/icon-192.png',
    '/icons-metro/icon-512.png',
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
