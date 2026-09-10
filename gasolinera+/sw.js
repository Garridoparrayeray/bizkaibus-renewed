// El sufijo de versión fuerza a que activate() borre cualquier caché
// anterior. Subir este número cada vez que cambie SHELL_FILES o el propio
// HTML/JS del shell de forma significativa.
const CACHE_VERSION = 'v1';
const CACHE_NAME = 'gasolinera-shell-' + CACHE_VERSION;
const SHELL_FILES = [
    '/',
    '/style.css',
    '/js/api.js',
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

    // Los precios cambian a diario: la API nunca debe servirse desde caché
    // habiendo red disponible.
    if (url.pathname.startsWith('/api/')) {
        return;
    }
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Network-first, cache como fallback offline.
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
