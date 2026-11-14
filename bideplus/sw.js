importScripts('/js/networks.js', '/js/alerts-store.js');

const CACHE_VERSION = 'v57';
// Huella de los ficheros de la app: la pone el despliegue a producción (scripts/stamp-sw.php) y cambia en cuanto cambia
// cualquiera de ellos, así que el móvil baja la versión nueva entera. Vacía en local y en las vistas previas.
const SHELL_HASH = '';
let shellCacheName = 'bizkaibus-shell-' + CACHE_VERSION;
if (SHELL_HASH) shellCacheName += '-' + SHELL_HASH;
const CACHE_NAME = shellCacheName;
const API_CACHE_NAME = 'bizkaibus-api-' + CACHE_VERSION;
// Los horarios sin conexión los guarda la página (js/offline.js) y no dependen de la versión: no se borran al actualizar.
const OFFLINE_CACHE_NAME = 'bide-offline';
// Los datos del planificador (horarios, calles y lugares) cambian cada día con los mismos nombres: los últimos que
// llegaron, para usarlo sin conexión. Tampoco dependen de la versión.
const PLANNER_DATA_CACHE_NAME = 'bide-planner-data';
const PLANNER_DATA = /^\/planificador\/build\/data\//;
// El código del planificador (pantallas, envoltorio y WebAssembly) entra en la huella (scripts/stamp-sw.php), como el
// de la app; se guarda la primera vez que se abre, no al instalar. Su página es una sola, sin mirar la consulta.
const PLANNER_CODE = /^\/planificador\/(pantallas|js|dist)\//;
const PLANNER_PAGE = /^\/planificador\/?$/;
// Con wifi lento, esperar a la red por la hoja de estilos o el script de la página nueva deja la pantalla congelada: el navegador no pinta la
// página nueva hasta tenerlos, y mientras muestra el último fotograma de la anterior (con el «+» de carga parado).
const SLOW_NETWORK_MS = 1500;
// El icono pequeño de la barra de estado (Android usa solo la silueta: tiene que ser blanco sobre transparente).
const BADGE_ICON = '/icons-bide-rojo/badge-96.png';
// Lo que nunca sale de la caché.
const LIVE_API = /\/(departures|live|vehicles|alerts|nearby|notice|app\/version)(\/|\?|$)/;
const SHELL_FILES = [
    '/',
    '/css/style-networks.css',
    '/css/style-miamor.css',
    '/css/style-app.css',
    '/css/style-screen.css',
    '/fonts/fonts.css',
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
    '/js/app/share.js',
    '/js/app/favorites.js',
    '/js/app/side-menu.js',
    '/js/app/stop-alerts.js',
    '/js/app/alerts.js',
    '/js/app/arrival-alerts.js',
    '/js/networks.js',
    '/js/alerts-store.js',
    '/js/linkify.js',
    '/js/notice.js',
    '/js/push.js',
    '/js/incident-alerts.js',
    '/js/arrival-alerts-store.js',
    '/js/settings.js',
    '/js/location-pref.js',
    '/js/new-badge.js',
    '/js/app-update.js',
    '/js/menu.js',
    '/js/menu-view.js',
    '/js/menu-favorites.js',
    '/js/menu-planner.js',
    '/js/planner-link.js',
    '/js/i18n.js',
    '/css/style-menu.css',
    '/css/style-planner.css',
    '/css/style-splash.css',
    '/js/splash.js',
    '/js/theme.js',
    '/js/boot.js',
    '/js/header-brand.js',
    '/js/sw-register.js',
    '/js/menu-lang.js',
    '/js/bide-mode.js',
    '/js/notification-open.js',
    '/js/bide.js',
    '/css/style-bide.css',
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
const SHELL_PATHS = new Set(SHELL_FILES.filter((file) => file !== '/'));

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
            Promise.all(keys.filter((key) => ![CACHE_NAME, API_CACHE_NAME, OFFLINE_CACHE_NAME, PLANNER_DATA_CACHE_NAME].includes(key)).map((key) => caches.delete(key)))
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
    if (PLANNER_DATA.test(url.pathname)) {
        event.respondWith(plannerData(event, url));
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
                        if (serverFailed(response)) return caches.match(event.request).then((hit) => hit || response);
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
    // Los ficheros de la app con huella no se vuelven a pedir: si cambia alguno, cambia la huella y llega la versión nueva entera.
    if (SHELL_HASH && !url.search && (SHELL_PATHS.has(url.pathname) || PLANNER_CODE.test(url.pathname))) {
        event.respondWith(cacheFirst(event.request));
        return;
    }
    // Hojas de estilo, scripts, tipografías e imágenes: salen de la caché al instante y se actualizan en segundo plano.
    event.respondWith(staleWhileRevalidate(event));
});

// Datos del planificador: de la red y, sin conexión (o con el servidor caído), los últimos guardados. Se guardan por
// su ruta, sin la consulta (la versión de las teselas), y solo si han cambiado: no se reescriben megas en cada visita.
function plannerData(event, url) {
    const key = new Request(url.origin + url.pathname);
    const saved = () => caches.open(PLANNER_DATA_CACHE_NAME).then((cache) => cache.match(key));
    return fetch(event.request)
        .then((response) => {
            if (response.ok) event.waitUntil(keepPlannerData(key, response.clone()));
            if (serverFailed(response)) return saved().then((hit) => hit || response);
            return response;
        })
        .catch(() => saved().then((hit) => hit || Response.error()));
}

function versionOf(response) {
    return response.headers.get('ETag') || response.headers.get('Last-Modified') || '';
}

async function keepPlannerData(key, response) {
    const cache = await caches.open(PLANNER_DATA_CACHE_NAME);
    const saved = await cache.match(key);
    if (saved && versionOf(response) !== '' && versionOf(saved) === versionOf(response)) return;
    await cache.put(key, response);
}

// La copia de una página: la del planificador es una para todas sus consultas (las pantallas leen la dirección).
function pageKey(request) {
    const url = new URL(request.url);
    if (PLANNER_PAGE.test(url.pathname)) return new Request(url.origin + '/planificador');
    return request;
}

// Servidor caído, saturado o sin cuota: mejor lo guardado que una página de error.
function serverFailed(response) {
    return response.status >= 500 || response.status === 402 || response.status === 429;
}

function saveInCache(request, response) {
    if (!response.ok) return Promise.resolve();
    return caches.open(CACHE_NAME).then((cache) => cache.put(request, response));
}

function cacheFirst(request) {
    return caches.match(request).then((cached) => {
        if (cached) return cached;
        return fetch(request).then((response) => {
            saveInCache(request, response.clone());
            return response;
        });
    });
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

// Una página de una versión que este service worker aún no ha bajado: con sus ficheros guardados saldría descuadrada
// y con textos sin traducir. Se enseña la guardada hasta que llega la versión nueva, que recarga sola (sw-register.js).
function fromOtherVersion(response) {
    const shell = response.headers.get('X-Bide-Shell');
    return SHELL_HASH !== '' && !!shell && shell !== SHELL_HASH;
}

// Páginas: la red manda, pero si tarda más de `timeoutMs` y hay copia, se usa la copia y la red la renueva.
function networkFirst(request, timeoutMs) {
    const key = pageKey(request);
    return new Promise((resolve) => {
        let settled = false;
        const settle = (response) => {
            if (!settled) {
                settled = true;
                resolve(response);
            }
        };
        const timer = setTimeout(() => {
            caches.match(key).then((hit) => {
                if (hit) settle(hit);
            });
        }, timeoutMs);
        const savedPage = () => caches.match(key).then((hit) => hit || appPageFallback(request));
        fetch(request)
            .then((response) => {
                clearTimeout(timer);
                if (fromOtherVersion(response)) {
                    self.registration.update().catch(() => {});
                    caches.match(key).then((hit) => settle(hit || response));
                    return;
                }
                if (serverFailed(response)) {
                    savedPage().then((hit) => settle(hit || response));
                    return;
                }
                saveInCache(key, response.clone());
                settle(response);
            })
            .catch(() => {
                clearTimeout(timer);
                savedPage().then((hit) => settle(hit || Response.error()));
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

// Avisos de llegada de una vez que ya avisaron, para que la app los quite aunque estuviera cerrada.
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
        // Sin IndexedDB se quita igual al pasar su hora.
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
