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

const flags = ['--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profileDir}`, 'about:blank'];
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
    return r.exceptionDetails ? 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0] : (r.result && r.result.value);
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
const check = (name, ok, extra = '') => results.push(`${ok ? 'OK ' : 'MAL'} ${name}${extra ? ' — ' + extra : ''}`);
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
check('navegador · menú: sin barra de pestañas', (await ev(shown('#bide-tabbar'))) === false);
check('navegador · menú: con pie', await ev(shown('main > footer')));
check('navegador · menú: selectores de idioma y vista', (await ev(shown('.bide-lang'))) && (await ev(shown('.view-toggle'))));
check('navegador · menú: ES marcado', (await ev("document.querySelector('[data-bide-lang=es]').getAttribute('aria-pressed')")) === 'true');
check('navegador · menú: aviso «Instala Bide+» en iPhone', await waitTrue(shown('#bide-install'), 4000));
check('navegador · menú: sin desbordar a lo ancho', await ev(noOverflow));
await ev("document.getElementById('bide-install-dismiss').click()");
await go('/');
check('navegador · el aviso cerrado no vuelve', (await ev(shown('#bide-install'))) === false);
await ev("localStorage.removeItem('bide_install_dismissed'); true");

await go('/?red=bus');
check('navegador · app: botón para volver a Bide+', await ev(shown('.bide-home')));
check('navegador · app: se mantienen ☰ y pie', (await ev(shown('#menu-open'))) && (await ev(shown('#dev-footer'))));
check('navegador · app: el pie enlaza a Bide+ y a las 6 apps', (await ev("document.querySelectorAll('#dev-footer .bide-apps-links a').length")) === 7);
check('navegador · app: el aviso legal sigue abriéndose desde el pie', (await ev("document.getElementById('legal-open').click(); document.getElementById('legal-panel').open")) === true);
await ev("document.getElementById('legal-panel').close(); true");
check('navegador · app: sin desbordar a lo ancho', await ev(noOverflow));
await send('Emulation.setUserAgentOverride', { userAgent: '' });

// ===== App instalada =====
await setStandalone(true);
await go('/');
check('instalada · menú: barra con 4 pestañas', (await ev(shown('#bide-tabbar'))) && (await ev("document.querySelectorAll('#bide-tabbar .bide-tab').length")) === 4);
check('instalada · menú: «Apps» marcada', (await ev("document.querySelector('#bide-tabbar [data-bide-tab=apps]').getAttribute('aria-current')")) === 'page');
check('instalada · menú: sin pie ni aviso de instalación', (await ev(shown('main > footer'))) === false && (await ev(shown('#bide-install'))) === false);

await ev("document.querySelector('#bide-tabbar [data-bide-tab=favoritos]').click(); true");
check('instalada · Favoritos: se abre como página', await waitTrue(shown('#bide-view-favoritos')));
check('instalada · Favoritos: oculta las fichas de apps', (await ev(shown('#tr'))) === false);
check('instalada · Favoritos: agrupa Bizkaibus+ y Metro+', (await ev("[...document.querySelectorAll('#bide-view-favoritos .bide-group')].map(n => n.textContent).join('|')")) === 'Bizkaibus+|Metro+');
check('instalada · Favoritos: 3 favoritos con su nombre', await waitTrue("document.querySelectorAll('#bide-view-favoritos .bide-fav').length === 3 && document.querySelector('#bide-view-favoritos .bide-fav strong').textContent.includes('MOYUA')"));
check('instalada · Favoritos: enlaza a la parada en su app', (await ev("document.querySelectorAll('#bide-view-favoritos .bide-fav')[2].getAttribute('href')")) === '/stops/7?red=metro');
check('instalada · Favoritos: se puede volver con «atrás»', (await ev("location.hash")) === '#favoritos');
await ev('history.back(); true');
check('instalada · «atrás» cierra la página', await waitTrue(`${shown('#tr')} && location.hash === ''`));
check('instalada · Favoritos: sin desbordar a lo ancho', await ev(noOverflow));

await ev("document.querySelector('#bide-tabbar [data-bide-tab=info]').click(); true");
check('instalada · Info: se abre como página', await waitTrue(shown('#bide-view-info')));
await ev("document.querySelector('#bide-view-info [data-bide-legal]').click(); true");
check('instalada · Info: abre el aviso legal', (await ev("document.getElementById('legal-panel').open")) === true);
await ev("document.getElementById('legal-close').click(); true");
check('instalada · Info: el aviso legal se cierra', (await ev("document.getElementById('legal-panel').open")) === false);

await ev("document.querySelector('[data-bide-lang=eu]').click(); true");
check('idioma · EU cambia la página y las pestañas', await waitTrue("document.documentElement.lang === 'eu' && document.querySelector('[data-bide-tab=favoritos] span').textContent === 'Gogokoak'"));
check('idioma · EU queda marcado', (await ev("document.querySelector('[data-bide-lang=eu]').getAttribute('aria-pressed')")) === 'true');
await ev("document.querySelector('[data-bide-lang=es]').click(); true");
check('idioma · vuelve a castellano', await waitTrue("document.documentElement.lang === 'es'"));

await go('/?red=bus');
check('instalada · app: barra de pestañas y botón para volver', (await ev(shown('#bide-tabbar'))) && (await ev(shown('.bide-home'))));
check('instalada · app: sin ☰ ni pie', (await ev(shown('#menu-open'))) === false && (await ev(shown('#dev-footer'))) === false);
check('instalada · app: se mantiene «Cerca de mí»', await ev(shown('#nearby-btn')));
await ev("document.querySelector('#bide-tabbar [data-bide-tab=avisos]').click(); true");
check('instalada · Avisos: lleva el interruptor de notificaciones', await waitTrue("!!document.querySelector('#bide-view-avisos .alerts-toggle')"));
await ev("document.querySelector('#bide-tabbar [data-bide-tab=apps]').click(); true");
check('instalada · «Apps» cierra la página abierta', await waitTrue(shown('.layout')));
await ev("document.querySelector('#bide-tabbar [data-bide-tab=apps]').click(); true");
check('instalada · «Apps» vuelve al menú de Bide+', await waitTrue("location.pathname === '/' && location.search === '' && !!document.getElementById('tr')"));
await setStandalone(false);

// ===== Ordenador =====
await desktop();
await go('/stops/4255');
check('ordenador · app: columna de Bide+', await ev(shown('#bide-rail')));
check('ordenador · «Todas las apps» empieza plegado', (await ev(shown('#bide-rail-apps'))) === false);
await ev("document.querySelector('.bide-rail-apps').click(); true");
check('ordenador · al pulsarlo muestra las 6 apps', (await ev(shown('#bide-rail-apps'))) && (await ev("document.querySelectorAll('#bide-rail-apps a').length")) === 6);
check('ordenador · marca la app actual', (await ev("document.querySelector('#bide-rail-apps [aria-current=page]').textContent")) === 'Bizkaibus+');
check('ordenador · favoritos de todas las apps en la columna', await waitTrue("document.querySelectorAll('#bide-rail .bide-fav').length === 3"));
check('ordenador · selector ES | EU en la banda, sin ☰', (await ev(shown('.app-container > header .bide-lang'))) && (await ev(shown('#menu-open'))) === false);
check('ordenador · pie con las apps', (await ev(shown('#dev-footer'))) && (await ev(shown('#dev-footer .bide-apps-links'))));
check('ordenador · sin línea abierta, explica el hueco del detalle', await ev(shown('#detail-empty')));
await ev("document.querySelector('#bide-rail [data-bide-tab=info]').click(); true");
check('ordenador · Info se abre en el contenido', (await waitTrue(shown('#bide-view-info'))) && (await ev(shown('.layout'))) === false && (await ev(shown('#bide-rail'))));
check('ordenador · sin desbordar a lo ancho', await ev(noOverflow));
await go('/');
check('ordenador · menú: columna de Bide+ y título', (await ev(shown('#bide-rail'))) && (await ev(shown('.bide-page-title'))));

// ===== Tema «mi amor»: sin cambios =====
await phone();
await go('/?red=bus&tema=miamor');
check('«mi amor»: no usa la navegación de Bide+', (await ev("document.documentElement.classList.contains('bide')")) === false && (await ev(shown('#bide-tabbar'))) === false && (await ev(shown('#bide-rail'))) === false);

const bad = results.filter(r => r.startsWith('MAL')).length;
const unique = [...new Set(problems)];
console.log(results.join('\n'));
console.log(`\nRESULTADO bide: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${unique.length} excepciones`);
for (const p of unique.slice(0, 10)) console.log(' -', p);
ws.close();
shutdown(bad || unique.length ? 1 : 0);
