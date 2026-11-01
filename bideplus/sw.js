importScripts('/js/networks.js', '/js/alerts-store.js');

const CACHE_VERSION = 'v50';
const CACHE_NAME = 'bizkaibus-shell-' + CACHE_VERSION;
const API_CACHE_NAME = 'bizkaibus-api-' + CACHE_VERSION;
// Los horarios sin conexión los guarda la página (js/offline.js) y no dependen de la versión: no se borran al actualizar.
const OFFLINE_CACHE_NAME = 'bide-offline';
// Con wifi lento, esperar a la red por la hoja de estilos o el script de la página nueva deja la pantalla congelada: el navegador no pinta la
// página nueva hasta tenerlos, y mientras muestra el último fotograma de la anterior (con el «+» de carga parado).
const SLOW_NETWORK_MS = 1500;
// El icono pequeño de la barra de estado (Android usa solo la silueta: tiene que ser blanco sobre transparente).
const BADGE_ICON = '/icons-bide-rojo/badge-96.png';
const LIVE_API = /\/(departures|live|vehicles|alerts|nearby)(\/|\?|$)/;
const SHELL_FILES = [
    '/',
    '/style-networks.css',
    '/style-miamor.css',
    '/style-app.css',
    '/style-screen.css',
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
    '/js/offline.js',
    '/js/bide-info.js',
    '/js/screen.js',
    '/js/app/main.js',
    '/js/app/core.js',
    '/js/app/ui.js',
    '/js/app/navigation.js',
    '/js/app/search.js',
    '/js/app/stop.js',
    '/js/app/timetable.js',
    '/js/app/line-map.js',
    '/js/app/schedule-modal.js',
    '/js/app/schedule-periods.js',
    '/js/app/vehicle-modal.js',
    '/js/app/favorites.js',
    '/js/app/side-menu.js',
    '/js/app/alerts.js',
    '/js/app/arrival-alerts.js',
    '/js/networks.js',
    '/js/alerts-store.js',
    '/js/push.js',
    '/js/incident-alerts.js',
    '/js/arrival-alerts-store.js',
    '/js/settings.js',
    '/js/menu.js',
    '/js/menu-view.js',
    '/js/i18n.js',
    '/style-menu.css',
    '/style-splash.css',
    '/js/splash.js',
    '/js/theme.js',
    '/js/boot.js',
    '/js/header-brand.js',
    '/js/sw-register.js',
    '/js/menu-lang.js',
    '/js/bide-mode.js',
    '/js/notification-open.js',
    '/js/bide.js',
    '/style-bide.css',
    '/manifest-bide.json',
    '/manifest-miamor.json',
    '/miamor.html',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons-bide-rojo/icon-192.png',
    '/icons-bide-rojo/icon-512.png',
    '/icons-bide-rojo/badge-96.png',
    '/icons-bide-rojo/bide-wordmark-mayusculas.svg',
    // Los iconos de cada red salen de su configuración (js/networks.js): una red nueva no hay que añadirla aquí.
    ...BideNetworks.slugs.flatMap((slug) => ['/' + BideNetworks.get(slug).icons + '/icon-192.png', '/' + BideNetworks.get(slug).icons + '/icon-512.png']),
];

// La app entera, recién bajada (sin la caché HTTP). Falla si se corta la conexión: así no se guarda a medias.
function downloadShell() {
    return Promise.all(SHELL_FILES.map((file) => fetch(new Request(file, { cache: 'reload' })).then((response) => [file, response])));
}

function storeShell(files) {
    return caches.open(CACHE_NAME).then((cache) => Promise.all(files.filter(([, response]) => response.ok).map(([file, response]) => cache.put(file, response))));
}

self.addEventListener('install', (event) => {
    event.waitUntil(downloadShell().then(storeShell));
    self.skipWaiting();
});

// Ajustes → «Vaciar caché y actualizar»: se baja la app entera y solo si llega todo se cambia por lo guardado, para no
// quedarse sin nada sin conexión. Los horarios sin conexión no se tocan, y el service worker sigue registrado, así que
// las notificaciones siguen llegando. Responde por el puerto que manda la página.
self.addEventListener('message', (event) => {
    const reply = event.ports[0];
    if (!event.data || event.data.type !== 'refresh-cache' || !reply) return;
    event.waitUntil(downloadShell()
        .then((files) => Promise.all([caches.delete(CACHE_NAME), caches.delete(API_CACHE_NAME)]).then(() => storeShell(files)))
        .then(() => reply.postMessage({ ok: true }), () => reply.postMessage({ ok: false })));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => ![CACHE_NAME, API_CACHE_NAME, OFFLINE_CACHE_NAME].includes(key)).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }
    if (url.pathname.startsWith('/offline-data/')) {
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
                caches.match(request).then((hit) => hit || appPageFallback(request)).then((hit) => settle(hit || Response.error()));
            });
    });
}

// Sin conexión, una parada o línea que no se había abierto antes se sirve con cualquier página guardada de su app: la
// página mira la dirección y, con el horario guardado, pinta la parada.
async function appPageFallback(request) {
    const url = new URL(request.url);
    if (!/^\/(stops|lines)\//.test(url.pathname)) return undefined;
    const red = url.searchParams.get('red') || BideNetworks.DEFAULT;
    const cache = await caches.open(CACHE_NAME);
    for (const key of await cache.keys()) {
        const cached = new URL(key.url);
        let cachedRed = cached.searchParams.get('red');
        if (!cachedRed) cachedRed = BideNetworks.DEFAULT;
        const isAppPage = cached.pathname === '/' || /^\/(stops|lines)\//.test(cached.pathname);
        if (isAppPage && cachedRed === red && (cached.pathname !== '/' || cached.searchParams.has('red'))) return cache.match(key);
    }
    return undefined;
}

// Solo sin push: con push, las incidencias las manda el servidor (js/app/alerts.js).
async function notifyNewAlerts() {
    if (!(await AlertsStore.get('enabled')) || (await AlertsStore.get('mode')) === 'push') return;
    const fresh = await AlertsStore.checkAlerts();
    if (fresh.length > 0) await AlertsStore.notify(self.registration, fresh);
}

self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'line-alerts-check') {
        event.waitUntil(notifyNewAlerts());
    }
});

// Notificaciones del servidor (avisos de llegada e incidencias): llegan con el texto ya listo. Solo se aceptan
// direcciones e iconos de la propia app.
function ownPath(value, fallback) {
    if (typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')) return value;
    return fallback;
}

// Un aviso de llegada de una vez ya ha avisado: se apunta (para la app cerrada) y se dice a las ventanas abiertas, y la
// app lo quita de su lista (js/arrival-alerts-store.js). Lo apuntado se olvida al día.
const FIRED_KEY = 'arrivalFired';
const FIRED_KEEP_MS = 24 * 3600 * 1000;

async function rememberFiredAlert(id) {
    try {
        const fired = (await AlertsStore.get(FIRED_KEY)) || {};
        for (const [key, at] of Object.entries(fired)) {
            if (Date.now() - at > FIRED_KEEP_MS) delete fired[key];
        }
        fired[id] = Date.now();
        await AlertsStore.set(FIRED_KEY, fired);
    } catch (e) {
        // Sin IndexedDB la app lo quita igual al pasar su hora.
    }
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    windows.forEach((client) => client.postMessage({ type: 'bide:arrival-fired', id }));
}

// Siempre se enseña algo, aunque el mensaje llegue roto: en iPhone, un push sin notificación hace que el sistema
// acabe quitando el permiso.
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data.json();
    } catch (e) {
        data = {};
    }
    const work = [self.registration.showNotification(data.title || 'Bide+', {
        body: data.body || '',
        icon: ownPath(data.icon, '/icons-bide-rojo/icon-192.png'),
        badge: BADGE_ICON,
        tag: data.tag || 'bide-arrival',
        renotify: true,
        vibrate: [200, 100, 200],
        data: { url: ownPath(data.url, '/') },
    })];
    if (typeof data.alertId === 'string' && data.repeat !== true) work.push(rememberFiredAlert(data.alertId));
    event.waitUntil(Promise.all(work));
});

// Al tocar una notificación se abre su dirección: en la ventana de la app que ya haya o, si no hay, en una nueva. En
// iPhone, con la app cerrada, el sistema la abre por el inicio para atender el toque y esa ventana aún no se deja
// llevar a otra dirección («navigate failed»): entonces se le pide a la página que vaya ella (js/notification-open.js).
async function openFromNotification(url) {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    if (windows.length === 0) {
        await clients.openWindow(url);
        return;
    }
    const client = windows[0];
    try {
        await client.focus();
    } catch (e) {
        // Sin permiso para traerla al frente se sigue igual: lo importante es la dirección.
    }
    try {
        await client.navigate(url);
    } catch (e) {
        client.postMessage({ type: 'bide:open', url });
    }
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    let url = '/';
    if (event.notification.data) url = ownPath(event.notification.data.url, '/');
    event.waitUntil(openFromNotification(url));
});
