// El sufijo de versión fuerza a que activate() borre cualquier caché anterior
// (ver más abajo) — sin él, un dispositivo que instaló el SW antes de añadir
// Metro+ puede quedarse sirviendo / o js/app.js viejos desde caché si la red
// falla o tarda, aunque el fetch de abajo sea network-first: la navegación
// inicial de una PWA instalada no siempre pasa por ese camino en todos los
// navegadores. Subir este número cada vez que cambien SHELL_FILES o el
// propio HTML/JS del shell de forma significativa.
// '/index.html' quitado de la lista: el shell ahora es api/shell.php,
// servido solo a través del rewrite '/' (no es una ruta pública propia en
// Vercel) — cachearlo por su nombre de fichero real daría 404 y rompería
// cache.addAll() entero, que falla si un solo fetch de la lista falla.
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

    // Never cache the API — real-time data must always hit the network.
    if (url.pathname.startsWith('/api/')) {
        return;
    }
    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Network-first, cache as offline fallback.
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
