importScripts('/js/networks.js', '/js/alerts-store.js');

const CACHE_VERSION = 'v21';
const CACHE_NAME = 'bizkaibus-shell-' + CACHE_VERSION;
const API_CACHE_NAME = 'bizkaibus-api-' + CACHE_VERSION;
// Con wifi lento, esperar a la red por la hoja de estilos o el script de la página nueva deja la pantalla congelada: el navegador no pinta la
// página nueva hasta tenerlos, y mientras muestra el último fotograma de la anterior (con el «+» de carga parado).
const SLOW_NETWORK_MS = 1500;
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
    '/js/networks.js',
    '/js/alerts-store.js',
    '/js/menu.js',
    '/js/menu-view.js',
    '/js/i18n.js',
    '/style-menu.css',
    '/style-splash.css',
    '/js/splash.js',
    '/js/boot.js',
    '/js/header-brand.js',
    '/js/sw-register.js',
    '/js/menu-lang.js',
    '/js/bide-mode.js',
    '/js/bide.js',
    '/style-bide.css',
    '/manifest.json',
    '/manifest-miamor.json',
    '/manifest-metro.json',
    '/manifest-euskotren.json',
    '/manifest-tranvia-bilbao.json',
    '/manifest-tranvia-vitoria.json',
    '/manifest-renfe.json',
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
    '/icons-renfe/icon-192.png',
    '/icons-renfe/icon-512.png',
    '/icons-lurraldebus/icon-192.png',
    '/icons-lurraldebus/icon-512.png',
    '/icons-bide-rojo/icon-192.png',
    '/icons-bide-rojo/icon-512.png',
    '/icons-bide-rojo/bide-wordmark-mayusculas.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => Promise.all(SHELL_FILES.map((file) => fetch(new Request(file, { cache: 'reload' })).then((response) => {
            if (response.ok) return cache.put(file, response);
            return undefined;
        }))))
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

    if (event.request.mode === 'navigate') {
        event.respondWith(networkFirst(event.request, SLOW_NETWORK_MS));
        return;
    }
    // Hojas de estilo, scripts, tipografías e imágenes: salen de la caché al instante y se actualizan en segundo plano.
    event.respondWith(staleWhileRevalidate(event));
});

function saveInCache(request, response) {
    if (!response.ok) return Promise.resolve();
    return caches.open(CACHE_NAME).then((cache) => cache.put(request, response));
}

function staleWhileRevalidate(event) {
    const update = fetch(event.request).then((response) => {
        event.waitUntil(saveInCache(event.request, response.clone()));
        return response;
    });
    return caches.match(event.request).then((cached) => {
        if (cached) {
            update.catch(() => {});
            return cached;
        }
        return update.catch(() => caches.match(event.request).then((hit) => hit || Response.error()));
    });
}

// Páginas: la red manda, pero si tarda más de `timeoutMs` y hay copia, se usa la copia y la red la renueva.
function networkFirst(request, timeoutMs) {
    return new Promise((resolve) => {
        let settled = false;
        const settle = (response) => {
            if (!settled) {
                settled = true;
                resolve(response);
            }
        };
        const timer = setTimeout(() => {
            caches.match(request).then((hit) => {
                if (hit) settle(hit);
            });
        }, timeoutMs);
        fetch(request)
            .then((response) => {
                clearTimeout(timer);
                saveInCache(request, response.clone());
                settle(response);
            })
            .catch(() => {
                clearTimeout(timer);
                caches.match(request).then((hit) => settle(hit || Response.error()));
            });
    });
}

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
