import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

// Bide+ como una sola app: navegador del móvil, app instalada (pestañas), ordenador (columna lateral),
// páginas de Favoritos, Avisos e Info, selector de idioma, aviso de instalación y tema «mi amor» intacto.

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9376);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-shell-'));
const sleep = ms => new Promise(r => setTimeout(r, ms));
const IOS_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

// En castellano: la app sigue el idioma del navegador y las pruebas comprueban los textos en castellano.
const flags = ['--headless=new', '--disable-gpu', '--lang=es-ES', '--accept-lang=es-ES,es', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profileDir}`, 'about:blank'];
if (process.env.CI) flags.unshift('--no-sandbox');
const browser = spawn(CHROME, flags, { stdio: 'ignore' });
const shutdown = code => { try { browser.kill(); } catch (e) { } process.exit(code); };

let targets;
for (let i = 0; i < 40 && !targets; i++) {
    await sleep(500);
    try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); } catch (e) { }
}
if (!targets) { console.log('MAL no se pudo conectar con el navegador'); shutdown(1); }

const ws = new WebSocket(targets.find(x => x.type === 'page').webSocketDebuggerUrl);
await new Promise(r => ws.onopen = r);
let id = 0;
const pending = new Map();
const problems = [];
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) return 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0];
    return r.result && r.result.value;
};
const waitTrue = async (expr, ms = 10000) => {
    const end = Date.now() + ms;
    while (Date.now() < end) {
        if ((await ev(expr)) === true) return true;
        await sleep(250);
    }
    return false;
};
const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};
const go = async (url, wait = 2500) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };
const shown = sel => `(() => { const n = document.querySelector(${JSON.stringify(sel)}); return !!n && getComputedStyle(n).display !== 'none' && n.getBoundingClientRect().height > 0; })()`;
const noOverflow = "document.documentElement.scrollWidth <= window.innerWidth";

const phone = () => send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
const desktop = () => send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
let standaloneScript = null;
const setStandalone = async on => {
    if (standaloneScript) { await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: standaloneScript }); standaloneScript = null; }
    if (on) standaloneScript = (await send('Page.addScriptToEvaluateOnNewDocument', { source: 'window.__bbForceStandalone = true;' })).identifier;
};

await send('Runtime.enable'); await send('Page.enable');
await phone();

// Favoritos de dos apps distintas para las páginas que reúnen todas las apps.
await go('/');
await ev(`localStorage.clear();
    localStorage.setItem('bizkaibus_favorites', JSON.stringify([{ type: 'stop', refId: '4255' }, { type: 'line', refId: '3527' }]));
    localStorage.setItem('metrobilbao_favorites', JSON.stringify([{ type: 'stop', refId: '7' }]));
    localStorage.setItem('bide_lang', 'es'); true`);

// ===== Navegador del móvil =====
await send('Emulation.setUserAgentOverride', { userAgent: IOS_UA });
await go('/');
check('navegador · menú: sin barra de pestañas', (await ev(shown('.bide-tabbar'))) === false);
check('navegador · menú: con pie', await ev(shown('main > footer')));
check('navegador · menú: selectores de idioma y vista', (await ev(shown('.bide-lang'))) && (await ev(shown('.view-toggle'))));
check('navegador · menú: ES marcado', (await ev("document.querySelector('[data-bide-lang=es]').getAttribute('aria-pressed')")) === 'true');
check('navegador · menú: aviso «Instala Bide+» en iPhone', await waitTrue(shown('.bide-install'), 4000));
check('navegador · menú: sin desbordar a lo ancho', await ev(noOverflow));
await ev("document.querySelector('.bide-install-dismiss').click()");
await go('/');
check('navegador · el aviso cerrado no vuelve', (await ev(shown('.bide-install'))) === false);
await ev("localStorage.removeItem('bide_install_dismissed'); true");

await go('/?red=bus');
check('navegador · app: el menú ☰ lleva a todas las apps', (await ev("document.querySelector('#side-menu .bide-menu-apps').getAttribute('href')")) === '/');
check('navegador · app: se mantienen ☰ y pie', (await ev(shown('#menu-open'))) && (await ev(shown('#app-footer'))));
check('navegador · app: el pie enlaza a todas las apps y al código', (await ev("document.querySelectorAll('#app-footer .bide-apps-links a').length === BideNetworks.slugs.length")) && (await ev("!!document.querySelector('#app-footer a[href*=\"bizkaibus-renewed\"]')")));
check('navegador · app: el aviso legal sigue abriéndose desde el pie', (await ev("document.getElementById('legal-open').click(); document.getElementById('legal-panel').open")) === true);
await ev("document.getElementById('legal-panel').close(); true");
check('navegador · app: sin desbordar a lo ancho', await ev(noOverflow));
await send('Emulation.setUserAgentOverride', { userAgent: '' });

// ===== App instalada =====
await setStandalone(true);
await go('/');
check('instalada · menú: barra con 5 pestañas', (await ev(shown('.bide-tabbar'))) && (await ev("document.querySelectorAll('.bide-tabbar .bide-tab').length")) === 5);
check('instalada · menú: «Apps» marcada', (await ev("document.querySelector('.bide-tabbar [data-bide-tab=apps]').getAttribute('aria-current')")) === 'page');
check('instalada · menú: sin pie ni aviso de instalación', (await ev(shown('main > footer'))) === false && (await ev(shown('.bide-install'))) === false);

await ev("document.querySelector('.bide-tabbar [data-bide-tab=favoritos]').click(); true");
check('instalada · Favoritos: se abre como página', await waitTrue(shown('[data-bide-view=favoritos]')));
check('instalada · Favoritos: oculta las fichas de apps', (await ev(shown('#tr'))) === false);
check('instalada · Favoritos: agrupa Bizkaibus+ y Metro+', (await ev("[...document.querySelectorAll('[data-bide-view=favoritos] .bide-group')].map(n => n.textContent).join('|')")) === 'Bizkaibus+|Metro+');
check('instalada · Favoritos: 3 favoritos con su nombre', await waitTrue("document.querySelectorAll('[data-bide-view=favoritos] .bide-fav').length === 3 && document.querySelector('[data-bide-view=favoritos] .bide-fav strong').textContent.includes('MOYUA')"));
check('instalada · Favoritos: enlaza a la parada en su app', (await ev("document.querySelectorAll('[data-bide-view=favoritos] .bide-fav')[2].getAttribute('href')")) === '/stops/7?red=metro');
check('instalada · Favoritos: se puede volver con «atrás»', (await ev("location.hash")) === '#favoritos');
await ev('history.back(); true');
check('instalada · «atrás» cierra la página', await waitTrue(`${shown('#tr')} && location.hash === ''`));
check('instalada · Favoritos: sin desbordar a lo ancho', await ev(noOverflow));

await ev("document.querySelector('.bide-tabbar [data-bide-tab=info]').click(); true");
check('instalada · Info: se abre como página', await waitTrue(shown('[data-bide-view=info]')));
check('instalada · Info: novedades plegadas, solo la última', await ev("(() => { const c = document.querySelector('[data-bide-view=info] .bide-changelog'); const d = c && c.querySelector('details'); return !!d && !d.open && c.querySelectorAll(':scope > .bide-changelog-entry').length === 1; })()"));
await ev("document.querySelector('[data-bide-view=info] .bide-changelog summary').click(); true");
check('instalada · Info: «ver todas» despliega el historial completo', await waitTrue("document.querySelector('[data-bide-view=info] .bide-changelog details').open && [...document.querySelectorAll('[data-bide-view=info] .bide-changelog-entry')].filter(e => e.getBoundingClientRect().height > 0).length === 31"));
check('instalada · Info: novedades sin desbordar a lo ancho', await ev(noOverflow));
check('instalada · Info: preguntas frecuentes plegadas', await ev("(() => { const items = document.querySelectorAll('[data-bide-view=info] .bide-faq-item'); return items.length >= 6 && [...items].every(i => !i.open); })()"));
await ev("(() => { const t = document.querySelector('[data-feedback-text]'); t.value = 'Prueba'; t.dispatchEvent(new Event('input')); document.querySelector('[data-feedback-type=error]').click(); return true; })()");
check('instalada · Info: sugerencias cuenta los caracteres', await ev("document.querySelector('[data-feedback-count]').textContent === '6 / 1500'"));
check('instalada · Info: el tipo elegido queda marcado', await ev("document.querySelector('[data-feedback-type=error]').getAttribute('aria-pressed') === 'true' && document.querySelector('[data-feedback-type=suggestion]').getAttribute('aria-pressed') === 'false'"));
await ev("document.querySelector('[data-bide-view=info] .bide-changelog summary').click(); true");
await ev("document.querySelector('[data-bide-view=info] [data-bide-legal]').click(); true");
check('instalada · Info: abre el aviso legal', (await ev("document.getElementById('legal-panel').open")) === true);
check('instalada · menú: el aviso legal tiene su ✕ visible', await ev("(() => { const r = document.getElementById('legal-close').getBoundingClientRect(); const svg = document.querySelector('#legal-close svg').getBoundingClientRect(); return r.width >= 40 && r.height >= 40 && svg.width >= 16; })()"));
await ev("document.getElementById('legal-close').click(); true");
check('instalada · Info: el aviso legal se cierra', (await ev("document.getElementById('legal-panel').open")) === false);

await ev("document.querySelector('[data-bide-lang=eu]').click(); true");
check('idioma · EU cambia la página y las pestañas', await waitTrue("document.documentElement.lang === 'eu' && document.querySelector('[data-bide-tab=favoritos] span').textContent === 'Gogokoak'"));
check('idioma · EU traduce las novedades', await waitTrue("document.querySelector('.bide-changelog h3').textContent === 'Berritasunak'"));
check('idioma · EU traduce las preguntas frecuentes y las sugerencias', await ev("document.querySelector('.bide-faq h3').textContent === 'Ohiko galderak' && document.querySelector('.bide-feedback h3').textContent === 'Iradokizunak eta akatsak'"));
check('idioma · EU queda marcado', (await ev("document.querySelector('[data-bide-lang=eu]').getAttribute('aria-pressed')")) === 'true');
await ev("document.querySelector('[data-bide-lang=en]').click(); true");
check('idioma · EN cambia la página y las pestañas', await waitTrue("document.documentElement.lang === 'en' && document.querySelector('[data-bide-tab=favoritos] span').textContent === 'Favourites'"));
check('idioma · EN traduce las novedades, las preguntas y las sugerencias', await ev("document.querySelector('.bide-changelog h3').textContent === \"What's new\" && document.querySelector('.bide-faq h3').textContent === 'Frequently asked questions' && document.querySelector('.bide-feedback h3').textContent === 'Suggestions and bugs'"));
check('idioma · EN queda marcado', (await ev("document.querySelector('[data-bide-lang=en]').getAttribute('aria-pressed')")) === 'true');
await ev("document.querySelector('[data-bide-lang=es]').click(); true");
check('idioma · vuelve a castellano', await waitTrue("document.documentElement.lang === 'es'"));

// ===== Ajustes =====
await ev(`(() => { localStorage.setItem('bide-arrival-alerts', JSON.stringify([{ id: '0123456789abcdef01234567', tripKey: 'x', stopId: '4255', minutes: 5, endpoint: 'https://fcm.googleapis.com/fcm/send/x', created: Date.now(), lineCode: 'A3514', headsign: 'GERNIKA', stopName: 'Termibus' }])); return true; })()`);
await ev("document.querySelector('.bide-tabbar [data-bide-tab=ajustes]').click(); true");
check('instalada · Ajustes: se abre como página', await waitTrue(shown('[data-bide-view=ajustes]')));
check('instalada · Ajustes: idioma, notificaciones, sin conexión y datos', await ev("['.bide-lang', '#alerts-toggle', '[data-settings-permission]', '.bide-offline', '[data-settings-delete]'].every((s) => !!document.querySelector('[data-bide-view=ajustes] ' + s))"));
check('instalada · Ajustes: horarios sin conexión, una fila por red', await ev("document.querySelectorAll('[data-bide-view=ajustes] .bide-offline-item input[type=checkbox]').length === BideNetworks.slugs.length"));
check('instalada · Ajustes: el estado del permiso, con su texto', await ev("(() => { const p = document.querySelector('[data-settings-permission]').textContent; return p.length > 10 && p.indexOf('bide.') === -1; })()"));
check('instalada · Ajustes: el aviso de llegada activo, con línea y parada', await waitTrue("(document.querySelector('[data-settings-arrivals]').textContent || '').includes('A3514 → GERNIKA') && document.querySelector('[data-settings-arrivals-empty]').hidden"));
check('instalada · Ajustes: uno de una vez no dice «todos los días»', await ev("!(document.querySelector('[data-settings-arrivals]').textContent || '').includes('todos los días')"));
await ev("document.querySelector('[data-settings-arrivals] button').click(); true");
check('instalada · Ajustes: cancelar el aviso lo quita', await waitTrue("document.querySelectorAll('[data-settings-arrivals] li').length === 0 && !document.querySelector('[data-settings-arrivals-empty]').hidden && JSON.parse(localStorage.getItem('bide-arrival-alerts')).length === 0"));
await ev(`(() => { localStorage.setItem('bide-arrival-alerts', JSON.stringify([{ id: '0123456789abcdef01234568', tripKey: 'x', stopId: '4255', minutes: 5, repeat: true, endpoint: 'https://fcm.googleapis.com/fcm/send/x', created: Date.now() - 86400000, lineCode: 'A3514', headsign: 'GERNIKA', stopName: 'Termibus' }])); window.dispatchEvent(new CustomEvent('bide:arrival-alerts')); return true; })()`);
check('instalada · Ajustes: uno de todos los días lo dice y no caduca a las 3 horas', await waitTrue("(document.querySelector('[data-settings-arrivals]').textContent || '').includes('5 min antes · todos los días')"));
await ev("localStorage.removeItem('bide-arrival-alerts'); window.dispatchEvent(new CustomEvent('bide:arrival-alerts')); true");
await ev("document.querySelector('[data-bide-view=ajustes] [data-bide-lang=en]').click(); true");
check('instalada · Ajustes: el idioma se cambia desde aquí', await waitTrue("document.documentElement.lang === 'en' && document.querySelector('[data-bide-view=ajustes] h2').textContent === 'Settings'"));
await ev("document.querySelector('[data-bide-view=ajustes] [data-bide-lang=es]').click(); true");
check('instalada · Ajustes: sin desbordar a lo ancho', await ev(noOverflow));
check('instalada · Ajustes: ubicación, la caché y la versión', await ev("['[data-settings-location]', '[data-settings-cache]', '[data-settings-version]'].every((s) => !!document.querySelector('[data-bide-view=ajustes] ' + s))"));
check('instalada · Ajustes: la versión es la de la última novedad', await ev("(() => { const n = document.querySelector('.bide-changelog-entry strong').getAttribute('data-i18n').split('.')[2]; return document.querySelector('[data-settings-version]').textContent.startsWith(n + ' · '); })()"));
check('instalada · Ajustes: la ubicación, aún sin pedir y con su botón', await waitTrue("document.querySelector('[data-settings-location]').getAttribute('data-i18n') === 'bide.settings.locationPrompt' && !document.querySelector('[data-settings-location-ask]').hidden"));
await send('Browser.grantPermissions', { permissions: ['geolocation'], origin: BASE });
check('instalada · Ajustes: al darla, «Permitida» sin recargar', await waitTrue("document.querySelector('[data-settings-location]').getAttribute('data-i18n') === 'bide.settings.locationGranted' && document.querySelector('[data-settings-location-ask]').hidden"));
await send('Browser.resetPermissions', {});

// Vaciar la caché: la app se vuelve a bajar entera y se recarga; los horarios sin conexión y el service worker se quedan.
await waitTrue('!!navigator.serviceWorker.controller');
await ev(`(async () => {
    const shell = (await caches.keys()).find((k) => k.startsWith('bizkaibus-shell-'));
    await (await caches.open(shell)).put('/__viejo', new Response('viejo'));
    await (await caches.open('bide-offline')).put('/__prueba-sin-conexion', new Response('ok'));
    window.__antesDeVaciar = true;
    return true;
})()`);
await ev("document.querySelector('[data-settings-cache]').click(); true");
check('caché · se vacía, se vuelve a bajar la app y recarga', await waitTrue(`(async () => {
    if (window.__antesDeVaciar || document.readyState !== 'complete') return false;
    const shell = (await caches.keys()).find((k) => k.startsWith('bizkaibus-shell-'));
    if (!shell) return false;
    const cache = await caches.open(shell);
    return !(await cache.match('/__viejo')) && !!(await cache.match('/js/settings.js'));
})()`, 30000));
check('caché · los horarios sin conexión y el service worker se quedan', await ev("(async () => !!(await (await caches.open('bide-offline')).match('/__prueba-sin-conexion')) && !!(await navigator.serviceWorker.getRegistration()))()"));
await ev("caches.open('bide-offline').then((c) => c.delete('/__prueba-sin-conexion')).then(() => true)");
await ev("document.querySelector('.bide-tabbar [data-bide-tab=avisos]').click(); true");
check('instalada · Avisos: enlaza con Ajustes', await waitTrue("!!document.querySelector('[data-bide-view=avisos] [data-bide-tab=ajustes]')"));
await ev("document.querySelector('[data-bide-view=avisos] [data-bide-tab=ajustes]').click(); true");
check('instalada · Avisos: el enlace abre Ajustes', await waitTrue(shown('[data-bide-view=ajustes]')));
await ev("document.querySelector('.bide-tabbar [data-bide-tab=apps]').click(); true");

await go('/?red=bus');
check('instalada · app: barra de pestañas', await ev(shown('.bide-tabbar')));
check('instalada · app: sin ☰ ni pie', (await ev(shown('#menu-open'))) === false && (await ev(shown('#app-footer'))) === false);
check('instalada · app: se mantiene «Cerca de mí»', await ev(shown('#nearby-btn')));
await ev("document.querySelector('.bide-tabbar [data-bide-tab=ajustes]').click(); true");
check('instalada · app: Ajustes lleva el interruptor de notificaciones', await waitTrue(`${shown('[data-bide-view=ajustes]')} && !!document.querySelector('[data-bide-view=ajustes] #alerts-toggle')`));
await ev("document.querySelector('.bide-tabbar [data-bide-tab=apps]').click(); true");
check('instalada · «Apps» cierra la página abierta', await waitTrue(shown('.layout')));
await ev("document.querySelector('.bide-tabbar [data-bide-tab=apps]').click(); true");
check('instalada · «Apps» vuelve al menú de Bide+', await waitTrue("location.pathname === '/' && location.search === '' && !!document.getElementById('tr')"));

const back = async (wait = 1500) => { await ev('history.back(); true'); await sleep(wait); };
const openStop = async () => {
    await ev(`(() => { const i = document.getElementById('search-input'); i.value = 'moyua'; i.dispatchEvent(new Event('input', { bubbles: true })); document.getElementById('search-form').requestSubmit(); return true; })()`);
    await waitTrue("!!document.querySelector('#search-results .pill')");
    await ev("document.querySelector('#search-results .pill').click(); true");
    return waitTrue("location.pathname.startsWith('/stops/') && !document.getElementById('live-card').hidden");
};
await go('/?red=bus');
check('atrás · se abre una parada', await openStop());
await waitTrue("!!document.querySelector('#live-more-list button')", 15000);
await ev("document.querySelector('#live-timetable-link:not([hidden]), #live-more-list button').click(); true");
check('atrás · se abre una línea desde la parada', await waitTrue("location.pathname.startsWith('/lines/') && !document.getElementById('timetable-section').hidden"));
await back();
check('atrás · desde la línea vuelve a la parada y cierra el horario', await waitTrue("location.pathname.startsWith('/stops/') && !document.getElementById('live-card').hidden && document.getElementById('timetable-section').hidden"));
await back();
check('atrás · desde la parada vuelve al inicio de la app', await waitTrue("location.pathname === '/' && location.search === '?red=bus' && document.getElementById('live-card').hidden"));
check('atrás · se abre otra vez la parada', await openStop());
await ev("document.getElementById('live-close').click(); true");
check('atrás · el ✕ de la parada vuelve al inicio de la app', await waitTrue("location.search === '?red=bus' && document.getElementById('live-card').hidden"));
await back(2500);
check('atrás · tras el ✕, atrás sale de la app (sin pasos repetidos)', await waitTrue("location.pathname === '/' && location.search === ''"));
await go('/?red=bus');
await ev("document.querySelector('.bide-tabbar [data-bide-tab=favoritos]').click(); true");
await ev("document.querySelector('.bide-tabbar [data-bide-tab=avisos]').click(); true");
await back();
check('atrás · desde Avisos vuelve a la app sin pasar por Favoritos', await waitTrue("!document.documentElement.classList.contains('bide-view-open') && location.hash === ''"));
await ev("document.querySelector('.bide-tabbar [data-bide-tab=info]').click(); true");
await ev("document.querySelector('[data-bide-view=info] [data-bide-legal]').click(); true");
check('atrás · se abre el aviso legal sobre Info', await waitTrue("document.getElementById('legal-panel').open"));
await back();
check('atrás · cierra el aviso legal y sigue en Info', await waitTrue("!document.getElementById('legal-panel').open && document.documentElement.classList.contains('bide-view-open')"));
await back();
check('atrás · después cierra Info', await waitTrue("!document.documentElement.classList.contains('bide-view-open')"));
await sleep(500);
await ev("document.querySelector('.bide-tabbar [data-bide-tab=info]').click(); true");
check('instalada · la pestaña activa rellena todo su hueco con el color de la app', await waitTrue("(() => { const t = document.querySelector('.bide-tabbar [data-bide-tab=info]'); if (!t) return false; const c = getComputedStyle(t); return c.backgroundColor !== 'rgba(0, 0, 0, 0)' && c.color === 'rgb(255, 255, 255)' && c.borderRadius === '0px' && t.getBoundingClientRect().height >= 57.5; })()"));
await setStandalone(false);

// ===== Ordenador =====
await desktop();
await go('/stops/4255');
check('ordenador · app: columna de Bide+', await ev(shown('.bide-rail')));
check('ordenador · buscador y «Cerca de mí» justo bajo la cabecera y alineados', await ev("(() => { const h = document.querySelector('.app-container > header').getBoundingClientRect(); const s = document.querySelector('#search-form').getBoundingClientRect(); const n = document.querySelector('.nearby-btn').getBoundingClientRect(); return s.top - h.bottom < 24 && Math.abs(s.top - n.top) < 2 && Math.abs(s.height - n.height) < 2; })()"));
check('ordenador · «Todas las apps» empieza plegado', (await ev(shown('.bide-rail-applist'))) === false);
await ev("document.querySelector('.bide-rail-apps').click(); true");
check('ordenador · al pulsarlo muestra todas las apps', (await ev(shown('.bide-rail-applist'))) && (await ev("document.querySelectorAll('.bide-rail-applist a').length === BideNetworks.slugs.length")));
check('ordenador · cada app con su logo', await waitTrue("[...document.querySelectorAll('.bide-rail-applist .bide-app-logo')].filter(img => img.complete && img.naturalWidth > 0).length === BideNetworks.slugs.length"));
check('ordenador · marca la app actual', (await ev("document.querySelector('.bide-rail-applist [aria-current=page]').textContent")) === 'Bizkaibus+');
check('ordenador · favoritos de todas las apps en la columna', await waitTrue("document.querySelectorAll('.bide-rail .bide-fav').length === 3"));
check('ordenador · selector ES | EU en la banda, sin ☰', (await ev(shown('.app-container > header .bide-lang'))) && (await ev(shown('#menu-open'))) === false);
check('ordenador · pie con las apps', (await ev(shown('#app-footer'))) && (await ev(shown('#app-footer .bide-apps-links'))));
check('ordenador · sin línea abierta, explica el hueco del detalle', await ev(shown('#detail-empty')));
await ev("document.querySelector('.bide-rail [data-bide-tab=info]').click(); true");
check('ordenador · Info se abre en el contenido', (await waitTrue(shown('[data-bide-view=info]'))) && (await ev(shown('.layout'))) === false && (await ev(shown('.bide-rail'))));
check('ordenador · sin desbordar a lo ancho', await ev(noOverflow));
await go('/');
check('ordenador · menú: columna de Bide+ sin título en la barra', (await ev(shown('.bide-rail'))) && (await ev(shown('.menu-brand'))) === false);
check('ordenador · menú: idioma a la izquierda y vista a la derecha, en una fila', await ev("(() => { const l = document.querySelector('.bide-lang').getBoundingClientRect(); const v = document.querySelector('.view-toggle').getBoundingClientRect(); return Math.abs(l.top - v.top) < 4 && l.right < v.left && v.right > window.innerWidth * 0.8; })()"));

// ===== Mapa de la red =====
await go('/');
check('menú: enlace al mapa de la red', await ev("!!document.querySelector('a.menu-map-link[href=\"/mapa\"]')"));
await go('/mapa');
check('mapa: carga vacio por defecto', await waitTrue("!!window.BideMap && window.BideMap.loaded().length === 0"));
check('mapa: un filtro por red', (await ev("document.querySelectorAll('[data-map-network]').length")) === (await ev("fetch('/map-data/index.json').then(r => r.json()).then(d => d.networks.length)")));
check('mapa: Metro separado en L1 y L2', await ev("fetch('/map-data/metro.json').then(r => r.json()).then(d => d.lines.map(l => l.code).join(',') === 'L1,L2')"));
await ev("window.BideMap.selectLine('metro', 'L1')");
check('mapa: el esquema de la L1 lista sus paradas', await waitTrue("!document.getElementById('map-panel').hidden && document.querySelectorAll('.map-schematic li').length > 20"));
check('mapa: la L1 muestra transbordos (Abando)', await ev("[...document.querySelectorAll('.map-schematic li')].some(li => /Abando/.test(li.textContent) && li.querySelectorAll('.map-chip').length > 0)"));
await ev("document.getElementById('map-panel-close').click()");
check('mapa: el panel se cierra', await ev("document.getElementById('map-panel').hidden"));
check('mapa: el selector tiene las líneas de todas las redes', await ev("document.querySelectorAll('#map-line optgroup').length === document.querySelectorAll('[data-map-network]').length && document.querySelectorAll('#map-line option').length > 100"));
await ev("(() => { const s = document.getElementById('map-line'); s.value = 'metro|L2'; s.dispatchEvent(new Event('change')); })()");
check('mapa: elegir una línea en el selector abre su esquema con minutos entre paradas', await waitTrue("!document.getElementById('map-panel').hidden && /Kabiezes/.test(document.getElementById('map-panel-body').textContent) && document.querySelectorAll('.map-schematic .map-min').length > 10"));
await ev("document.getElementById('map-panel-close').click()");
check('mapa: el buscador filtra lineas', await ev("(() => { const inp = document.getElementById('map-search-input'); inp.value = 'L2'; inp.dispatchEvent(new Event('input')); return document.querySelectorAll('.map-search-item').length > 0; })()"));
await ev("document.querySelector('.map-search-item').click()");
check('mapa: elegir una linea en el buscador abre su esquema', await waitTrue("!document.getElementById('map-panel').hidden && /Kabiezes/.test(document.getElementById('map-panel-body').textContent)"));
check('mapa: la línea elegida dibuja un punto en cada parada', await ev("document.querySelectorAll('.map-schematic li').length") > 10);
await ev("document.getElementById('map-panel-close').click()");
check('mapa: el buscador tiene su texto, no la clave', await ev("document.getElementById('map-search-input').placeholder.indexOf('map.') === -1"));
await ev("window.BideMap.search('abando')");
check('mapa: el buscador encuentra estaciones de redes ocultas', await waitTrue("[...document.querySelectorAll('.map-search-item.is-stop')].some(b => /Metro Bilbao/.test(b.textContent))"));
await ev("window.BideMap.search('')");
await ev("(() => { const b = document.querySelector('[data-map-network=renfe]'); b.click(); b.click(); return true; })()");
await sleep(1500);
check('mapa: dos toques seguidos en un filtro lo dejan apagado', await ev("document.querySelector('[data-map-network=renfe]').getAttribute('aria-pressed') === 'false'"));
await ev("document.querySelector('[data-map-network=bus]').click()");
check('mapa: Bizkaibus se carga al activarlo', await waitTrue("window.BideMap.loaded().includes('bus')"));
check('mapa: las líneas no son de un color tan claro que se pierda en el mapa', await ev("fetch('/map-data/bus.json').then(r => r.json()).then(d => d.lines.every(l => { const v = parseInt(l.color.slice(1), 16); const lum = 0.2126 * ((v >> 16) & 255) + 0.7152 * ((v >> 8) & 255) + 0.0722 * (v & 255); return lum < 200; }))"));
check('mapa: sin desbordar a lo ancho', await ev(noOverflow));

// ===== Inglés para quien tiene el móvil en inglés =====
await phone();
await go('/?red=bus', 1500);
await ev("localStorage.removeItem('bide_lang'); true");
const englishPhone = await send('Page.addScriptToEvaluateOnNewDocument', { source: "Object.defineProperty(navigator, 'languages', { get: () => ['en-GB', 'en'] });" });
await go('/?red=bus', 3000);
check('idioma · con el móvil en inglés, la app sale en inglés', await waitTrue("document.documentElement.lang === 'en' && document.querySelector('[data-bide-tab=favoritos] span').textContent === 'Favourites'"));
check('idioma · el botón de la cabecera pasa después a castellano', await ev("document.getElementById('lang-toggle').textContent.trim() === 'ES'"));
check('idioma · los textos de la red también salen en inglés', await ev("document.getElementById('app-subtitle').textContent.trim() === 'Bizkaibus timetables and real time'"));
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: englishPhone.identifier });
await ev("localStorage.setItem('bide_lang', 'es'); true");

// ===== Compartir un viaje =====
// El enlace compartido abre la parada con la ficha del viaje, y el botón manda el texto con el enlace.
await phone();
// Paradas con mucho paso, por si a esta hora alguna ya no tiene viajes (de noche queda Metro).
let sharedTrip = null;
let sharedStop = null;
for (const [red, stop] of [['bus', '1137'], ['bus', '356'], ['bus', '4255'], ['metro', '7']]) {
    const data = await (await fetch(`${BASE}/api/stops/${stop}/departures?limit=8&red=${red}`)).json();
    sharedTrip = (data.departures || []).find(d => d.tripKey);
    if (sharedTrip) {
        sharedStop = { red, stop };
        break;
    }
}
if (sharedTrip) {
    await go(`/stops/${sharedStop.stop}?red=${sharedStop.red}&viaje=${encodeURIComponent(sharedTrip.tripKey)}`, 3000);
    check('compartir: el enlace abre la ficha del viaje', await waitTrue("document.getElementById('vehicle-modal').open"));
    check('compartir: la dirección queda sin ?viaje', await ev("!location.search.includes('viaje')"));
    await ev("navigator.share = undefined; navigator.clipboard.writeText = async (text) => { window.__shared = text; }; document.getElementById('modal-share').click(); true");
    check('compartir: el texto lleva la línea y el enlace al viaje', await waitTrue(`(window.__shared || '').includes(${JSON.stringify(sharedTrip.lineCode)}) && (window.__shared || '').includes('/stops/${sharedStop.stop}?') && (window.__shared || '').includes('viaje=')`, 3000));

    // Al tocar una notificación: abre la ficha una vez. Ni al recargar ni si Android vuelve a cargar la dirección con
    // la que se abrió la app sale otra vez.
    const alertUrl = `/stops/${sharedStop.stop}?red=${sharedStop.red}&viaje=${encodeURIComponent(sharedTrip.tripKey)}&aviso=prueba-${Date.now()}`;
    await go(alertUrl, 3000);
    check('notificación: abre la ficha del viaje', await waitTrue("document.getElementById('vehicle-modal').open"));
    check('notificación: la dirección queda sin ?viaje ni ?aviso', await ev("!location.search.includes('viaje') && !location.search.includes('aviso')"), await ev('location.search'));
    await send('Page.reload');
    await sleep(3000);
    check('notificación: al recargar no vuelve a salir la ficha', await ev("!document.getElementById('vehicle-modal').open"));
    await go(alertUrl, 3000);
    check('notificación: si se vuelve a cargar su dirección, se queda en el inicio, sin la parada ni la ficha', await ev("!document.getElementById('vehicle-modal').open && location.pathname === '/' && !location.search.includes('viaje') && document.getElementById('live-card').hidden"), await ev("location.pathname + location.search"));
    await go(`/stops/${sharedStop.stop}?red=${sharedStop.red}&viaje=${encodeURIComponent(sharedTrip.tripKey)}`, 3000);
    check('compartir: el mismo enlace compartido se puede abrir más veces', await waitTrue("document.getElementById('vehicle-modal').open"));
} else {
    results.push('SKIP compartir: ahora no hay viajes en las paradas de prueba');
}

// ===== Avísame cuando llegue =====
// En local no hay almacén ni servicio de push: se simulan el servidor (clave y avisos) y la suscripción del navegador.
if (sharedTrip) {
    const tripUrl = `/stops/${sharedStop.stop}?red=${sharedStop.red}&viaje=${encodeURIComponent(sharedTrip.tripKey)}`;
    await go(tripUrl, 3000);
    await waitTrue("document.getElementById('vehicle-modal').open");
    check('avisos: sin almacén en el servidor, no se ofrecen', await ev("document.getElementById('modal-notify').hidden"));
    const fakePush = await send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
        const realFetch = window.fetch.bind(window);
        window.__alertCalls = [];
        const json = (body) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
        window.fetch = async (url, init = {}) => {
            const path = String(url);
            if (path.includes('/api/push/key')) return json({ publicKey: 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', minutes: [2, 5, 10] });
            if (path.includes('/cancel')) { window.__alertCalls.push({ path, body: init.body }); return json({ status: 'cancelled' }); }
            if (path.includes('/api/push/arrival')) { window.__alertCalls.push({ path, body: init.body }); return json({ status: 'created', id: '0123456789abcdef01234567' }); }
            return realFetch(url, init);
        };
        Object.defineProperty(window, 'Notification', { value: { permission: 'default', requestPermission: async () => 'granted' }, configurable: true });
        const subscription = { endpoint: 'https://fcm.googleapis.com/fcm/send/prueba', options: { applicationServerKey: null }, toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'x', auth: 'y' } }; }, unsubscribe: async () => true };
        const registration = { pushManager: { getSubscription: async () => null, subscribe: async () => subscription } };
        Object.defineProperty(navigator.serviceWorker, 'ready', { get: () => Promise.resolve(registration), configurable: true });
    })();` });
    await ev("localStorage.removeItem('bide-arrival-alerts'); true");
    await go(tripUrl, 3000);
    await waitTrue("document.getElementById('vehicle-modal').open");
    check('avisos: la ficha ofrece 2, 5 y 10 min', await waitTrue("!document.getElementById('modal-notify').hidden && document.querySelectorAll('[data-notify-minutes]').length === 3"));
    await ev("document.querySelector('[data-notify-minutes=\"5\"]').click(); true");
    check('avisos: al elegir 5 min queda activo', await waitTrue("document.getElementById('modal-notify-status').textContent.includes('5 min') && !document.getElementById('modal-notify-cancel').hidden && document.getElementById('modal-notify-options').hidden"));
    check('avisos: pide al servidor el viaje, la parada y los minutos', await ev(`(() => { const body = JSON.parse((window.__alertCalls[0] || {}).body || '{}'); return body.minutes === 5 && body.repeat === false && body.tripKey === ${JSON.stringify(sharedTrip.tripKey)} && String(body.stopId) === ${JSON.stringify(sharedStop.stop)} && body.subscription.endpoint.startsWith('https://fcm.googleapis.com/'); })()`));
    check('avisos: por defecto avisa una vez y se quita', await ev("document.getElementById('modal-notify-status').textContent.includes('una vez') && document.getElementById('modal-notify-repeat').hidden"));
    await ev("document.getElementById('modal-notify-cancel').click(); true");
    check('avisos: se puede cancelar', await waitTrue("window.__alertCalls.some((call) => call.path.includes('/cancel')) && !document.getElementById('modal-notify-options').hidden"));
    check('avisos: se ofrece «Avisarme todos los días», sin marcar', await ev("!document.getElementById('modal-notify-repeat').hidden && document.getElementById('modal-notify-repeat-input').checked === false"));
    await ev("document.getElementById('modal-notify-repeat-input').click(); document.querySelector('[data-notify-minutes=\"10\"]').click(); true");
    check('avisos: todos los días se pide al servidor y se explica', await waitTrue("window.__alertCalls.filter((call) => !call.path.includes('/cancel')).length === 2 && JSON.parse(window.__alertCalls.filter((call) => !call.path.includes('/cancel'))[1].body).repeat === true && document.getElementById('modal-notify-status').textContent.includes('todos los días')"), await ev("document.getElementById('modal-notify-status').textContent"));
    check('avisos: todos los días queda guardado como tal', await ev("JSON.parse(localStorage.getItem('bide-arrival-alerts') || '[]').some((alert) => alert.repeat === true && alert.minutes === 10)"));
    await ev("document.getElementById('modal-notify-cancel').click(); true");
    await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: fakePush.identifier });
    await ev("localStorage.removeItem('bide-arrival-alerts'); true");
} else {
    results.push('SKIP avisos: ahora no hay viajes en las paradas de prueba');
}

// ===== Avisos de incidencias por push =====
// Al activarlos se dan al servidor la suscripción, los favoritos de todas las apps y el idioma; al quitarlos, se borran.
const fakeIncidentPush = await send('Page.addScriptToEvaluateOnNewDocument', { source: `(() => {
    const realFetch = window.fetch.bind(window);
    window.__pushCalls = [];
    const json = (body) => new Response(JSON.stringify(body), { headers: { 'Content-Type': 'application/json' } });
    window.fetch = async (url, init = {}) => {
        const path = String(url);
        if (path.includes('/api/push/key')) return json({ publicKey: 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', minutes: [2, 5, 10] });
        if (path.includes('/api/push/incidents')) { window.__pushCalls.push({ path, body: JSON.parse(init.body || '{}') }); if (path.includes('/remove')) return json({ status: 'removed' }); return json({ status: 'saved' }); }
        return realFetch(url, init);
    };
    Object.defineProperty(window, 'Notification', { value: { permission: 'granted', requestPermission: async () => 'granted' }, configurable: true });
    const subscription = { endpoint: 'https://fcm.googleapis.com/fcm/send/incidencias', options: { applicationServerKey: null }, toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'x', auth: 'y' } }; }, unsubscribe: async () => true };
    const registration = { pushManager: { getSubscription: async () => subscription, subscribe: async () => subscription } };
    Object.defineProperty(navigator.serviceWorker, 'ready', { get: () => Promise.resolve(registration), configurable: true });
})();` });
await go('/?red=bus', 2500);
const savedFavorites = await ev("JSON.stringify([localStorage.getItem('bizkaibus_favorites'), localStorage.getItem('metrobilbao_favorites')])");
await ev(`(async () => { localStorage.setItem('bizkaibus_favorites', JSON.stringify([{ type: 'line', refId: '3513' }])); localStorage.setItem('metrobilbao_favorites', JSON.stringify([{ type: 'stop', refId: '7' }])); localStorage.removeItem('bide-incident-push'); await AlertsStore.set('enabled', false); await AlertsStore.set('mode', null); return true; })()`);
await go('/?red=bus', 2500);
await ev("document.getElementById('alerts-toggle').click(); true");
check('incidencias: al activarlas quedan con push y lo dice', await waitTrue("document.getElementById('alerts-toggle').checked && document.getElementById('alerts-note').textContent === I18n.t('app.alerts.push')"), await ev("document.getElementById('alerts-note').textContent"));
check('incidencias: el servidor recibe los favoritos de todas las apps y el idioma', await ev(`(() => { const call = window.__pushCalls.find((c) => !c.path.includes('/remove')); if (!call) return false; const f = call.body.favorites; return f.bus.lines[0] === '3513' && f.metro.stops[0] === '7' && call.body.lang === 'es' && call.body.subscription.endpoint.endsWith('/incidencias'); })()`), await ev('JSON.stringify(window.__pushCalls)'));
check('incidencias: con push, la app no mira por su cuenta', (await ev("AlertsStore.get('mode')")) === 'push');
await ev("document.getElementById('alerts-toggle').click(); true");
check('incidencias: al quitarlas se borran del servidor', await waitTrue("!document.getElementById('alerts-toggle').checked && window.__pushCalls.some((c) => c.path.includes('/remove') && c.body.endpoint.endsWith('/incidencias'))"));
// Quien ya los tenía activados sin push pasa a push al abrir la app, sin volver a pedir permiso.
await ev(`(async () => { await AlertsStore.set('enabled', true); await AlertsStore.set('mode', 'local'); localStorage.removeItem('bide-incident-push'); return true; })()`);
await go('/?red=bus', 2500);
check('incidencias: quien las tenía sin push pasa a push solo', await waitTrue("window.__pushCalls.some((c) => !c.path.includes('/remove')) && document.getElementById('alerts-toggle').checked") && (await ev("AlertsStore.get('mode')")) === 'push');
await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: fakeIncidentPush.identifier });
await ev(`(async () => { const [bus, metro] = ${savedFavorites}; const restore = (key, value) => { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value); }; restore('bizkaibus_favorites', bus); restore('metrobilbao_favorites', metro); localStorage.removeItem('bide-incident-push'); await AlertsStore.set('enabled', false); await AlertsStore.set('mode', null); return true; })()`);

// ===== Pantalla de una parada =====
await go('/stops/4255', 3000);
check('pantalla: la tarjeta de la parada enlaza a su pantalla', await waitTrue("(document.getElementById('live-screen').getAttribute('href') || '') === '/pantalla/4255'"));
await go('/pantalla/7?red=metro', 3000);
check('pantalla: sale el nombre de la estación', await ev("document.querySelector('.screen-stop').textContent.trim() === 'Abando'"));
check('pantalla: hay salidas o el aviso de que no quedan', await waitTrue("document.querySelectorAll('.screen-row').length > 0 || !document.querySelector('[data-screen-empty]').hidden"));
check('pantalla: el reloj está en marcha', await ev("/^\\d{2}:\\d{2}$/.test(document.querySelector('[data-screen-clock]').textContent)"));
check('pantalla: sin desbordes en el móvil', await ev(noOverflow));
check('pantalla: no se indexa', await ev("document.querySelector('meta[name=robots]').content === 'noindex'"));
check('pantalla: una parada que no existe da 404', (await fetch(`${BASE}/pantalla/no-existe`)).status === 404);

// ===== Tema «mi amor»: la misma app que la normal, en rosa =====
await phone();
await go('/?red=bus&tema=miamor');
check('«mi amor»: usa el formato de Bide+', await ev("document.documentElement.classList.contains('bide') && document.documentElement.classList.contains('is-miamor')"));
check('«mi amor»: sus colores, también en otras redes', await ev("getComputedStyle(document.documentElement).getPropertyValue('--primary').trim().toLowerCase() === '#db2777'"));
check('«mi amor»: su subtítulo', await ev("document.getElementById('app-subtitle').textContent === 'Para el amor de mi vida'"));
await go('/?red=metro&tema=miamor');
check('«mi amor»: rosa también en Metro', await ev("getComputedStyle(document.documentElement).getPropertyValue('--primary').trim().toLowerCase() === '#db2777'"));
check('«mi amor»: la cabecera es la de la red abierta, no Bizkaibus', await ev("document.getElementById('app-title').textContent.trim().startsWith('METRO')"));
await go('/');
check('«mi amor»: sigue en el menú de inicio', await ev("document.documentElement.classList.contains('is-miamor') && location.pathname === '/'"));
await go('/mapa');
check('«mi amor»: sigue en el mapa de la red', await ev("document.documentElement.classList.contains('is-miamor')"));
await go('/?red=bus&tema=pro');
check('«mi amor»: ?tema=pro vuelve al tema normal', await ev("!document.documentElement.classList.contains('is-miamor')"));

// ===== Ajustes: borrar los datos del móvil (al final: lo borra todo) =====
await setStandalone(false);
await phone();
// En el móvil el idioma de la cabecera es el botón «EU», pero el selector de Ajustes se tiene que ver (antes salía vacío).
await go('/?red=bus');
await ev("document.querySelector('[data-bide-tab=ajustes]').click(); true");
check('navegador · app: en Ajustes se ve el selector de idioma', await waitTrue("[...document.querySelectorAll('[data-bide-view=ajustes] .bide-lang')].some((e) => e.getClientRects().length > 0)"));
await go('/');
await ev("localStorage.setItem('bizkaibus_favorites', JSON.stringify([{ type: 'stop', refId: '4255' }])); true");
await ev("document.querySelector('.menu-footer [data-bide-tab=ajustes]').click(); true");
check('navegador · Ajustes: se abre desde el pie', await waitTrue(shown('[data-bide-view=ajustes]')));
await ev("document.querySelector('[data-settings-delete]').click(); true");
check('borrar datos: la primera pulsación pide confirmación', await waitTrue("document.querySelector('[data-settings-delete]').getAttribute('data-i18n') === 'bide.settings.deleteConfirm'") && (await ev("localStorage.getItem('bizkaibus_favorites') !== null")));
await ev("document.querySelector('[data-settings-delete]').click(); true");
await sleep(3000);
check('borrar datos: la segunda lo borra y recarga', await waitTrue("document.readyState === 'complete' && localStorage.getItem('bizkaibus_favorites') === null && location.pathname === '/'"));

const bad = results.filter(r => r.startsWith('MAL')).length;
const unique = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO bide: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${unique.length} excepciones`);
for (const p of unique.slice(0, 10)) console.log(' -', p);
ws.close();
let exitCode = 0;
if (bad || unique.length) exitCode = 1;
shutdown(exitCode);
