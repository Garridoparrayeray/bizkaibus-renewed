import { spawn } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const PORT = Number(process.env.CDP_PORT || 9362);
const CHROME = process.env.CHROME_PATH || 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const profileDir = mkdtempSync(join(tmpdir(), 'bide-tests-'));
const sleep = ms => new Promise(r => setTimeout(r, ms));

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
let where = '';
ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') problems.push(`[${where}] EXCEPCION ${m.params.exceptionDetails.exception?.description?.split('\n')[0] || m.params.exceptionDetails.text}`);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') problems.push(`[${where}] console.error ${(m.params.args[0]?.value || m.params.args[0]?.description || '').toString().slice(0, 140)}`);
    if (m.method === 'Network.responseReceived') {
        const { status, url } = m.params.response;
        if (status >= 400 && !/favicon|_vercel/.test(url)) problems.push(`[${where}] HTTP ${status} ${url.replace(BASE, '')}`);
    }
    if (m.method === 'Network.loadingFailed' && m.params.errorText !== 'net::ERR_ABORTED' && !/_vercel|unpkg|googleapis|gstatic|tile\.openstreetmap|cartocdn/.test(m.params.errorText)) {
        problems.push(`[${where}] carga fallida ${m.params.errorText}`);
    }
};
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.exceptionDetails ? 'EXC:' + (r.exceptionDetails.exception?.description || '').split('\n')[0] : (r.result && r.result.value);
};
await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');

const results = [];
const check = (name, ok, extra = '') => {
    const line = `${ok ? 'OK ' : 'MAL'} ${name}${extra ? ' — ' + extra : ''}`;
    results.push(line);
    if (process.env.VERBOSE) console.log(line);
};
const skip = (name, why) => results.push(`SKIP ${name} — ${why}`);
const go = async (url, wait = 2800) => { await send('Page.navigate', { url: BASE + url }); await sleep(wait); };
const search = async (q, wait = 3200) => {
    await ev(`(()=>{const i=document.getElementById('search-input');i.value='${q}';i.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('search-form').requestSubmit();})()`);
    await sleep(wait);
};

const apps = [
    { key: 'bus', url: '/?red=bus', q: 'moyua', panel: 'live-card' },
    { key: 'metro', url: '/?red=metro', q: 'abando', panel: 'platform-panel' },
    { key: 'euskotren', url: '/?red=euskotren', q: 'amara', panel: 'platform-panel' },
];

const sizes = [{ n: 'movil', w: 390, h: 844, m: true }, { n: 'pc', w: 1366, h: 800, m: false }];
const sizeFilter = process.env.SIZES ? process.env.SIZES.split(',') : null;
const appFilter = process.env.APPS ? process.env.APPS.split(',') : null;
for (const size of (sizes.filter((x) => !sizeFilter || sizeFilter.includes(x.n)))) {
    await send('Emulation.setDeviceMetricsOverride', { width: size.w, height: size.h, deviceScaleFactor: 1, mobile: size.m });
    for (const a of apps) {
        if (appFilter && !appFilter.includes(a.key)) continue;
        const tag = `${a.key}/${size.n}`;
        where = tag;
        await go(a.url);
        check(`${tag} carga con estilos`, (await ev("getComputedStyle(document.querySelector('header')).position")) === (size.m ? 'sticky' : 'static'));
        check(`${tag} sin scroll horizontal`, (await ev('document.documentElement.scrollWidth - document.documentElement.clientWidth')) <= 0);
        await search(a.q);
        check(`${tag} aparecen resultados de busqueda`, (await ev("document.querySelectorAll('#search-results .pill').length")) > 0);
        await ev("document.querySelector('#search-results .pill').click()");
        await sleep(3500);
        check(`${tag} se abre la ficha de la parada`, (await ev(`!document.getElementById('${a.panel}').hidden`)) === true);
        const favBtn = a.key === 'bus' ? 'live-favorite' : 'platform-favorite';
        await ev(`document.getElementById('${favBtn}').click()`);
        await sleep(600);
        check(`${tag} guardar favorito`, (await ev("document.querySelectorAll('#favorites-list li:not(#favorites-empty)').length")) === 1);
        await ev("document.getElementById('menu-open').click()");
        await sleep(400);
        check(`${tag} menu lateral abre`, (await ev("document.getElementById('side-menu').open")) === true);
        await ev("document.getElementById('menu-close').click()");
        await sleep(300);
        const pick = a.key === 'bus'
            ? '#live-timetable-link:not([hidden]), #live-more-list button'
            : '#platform-timetable-lines .pill, #platform-timetable-link:not([hidden])';
        await ev(`(()=>{const c=document.querySelector('${pick}');if(c)c.click();})()`);
        await sleep(3500);
        check(`${tag} horario completo se abre`, (await ev("!document.getElementById('timetable-section').hidden")) === true);
        await ev("(()=>{document.getElementById('filter-hour-from').value='00:00';document.getElementById('filter-hour-from').dispatchEvent(new Event('change',{bubbles:true}));document.getElementById('filter-hour-to').value='23:59';document.getElementById('filter-hour-to').dispatchEvent(new Event('change',{bubbles:true}));})()");
        await sleep(2500);
        const rows = await ev("document.querySelectorAll('#timetable-body tr').length");
        check(`${tag} tabla con filas de todo el dia`, rows > 0, `${rows} filas`);
        const todayValue = await ev("document.getElementById('filter-date').value");
        const localToday = await ev("(() => { const n = new Date(); const p = (v) => String(v).padStart(2, '0'); return `${n.getFullYear()}-${p(n.getMonth() + 1)}-${p(n.getDate())}`; })()");
        check(`${tag} la fecha por defecto del horario es hoy (hora local)`, todayValue === localToday, `${todayValue} vs ${localToday}`);
        check(`${tag} aviso de fecha sin publicar oculto hoy`, (await ev("document.getElementById('timetable-note').hidden")) === true);
        await ev("(()=>{const d=document.getElementById('filter-date');d.value='2027-06-01';d.dispatchEvent(new Event('change',{bubbles:true}));})()");
        await sleep(2000);
        check(`${tag} aviso de fecha sin publicar visible en el futuro`, (await ev("document.getElementById('timetable-note').hidden")) === false, (await ev("document.getElementById('timetable-note').textContent")).slice(0, 60));
        await ev(`(()=>{const d=document.getElementById('filter-date');d.value='${todayValue}';d.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        await sleep(2000);
        check(`${tag} aviso vuelve a ocultarse al volver a hoy`, (await ev("document.getElementById('timetable-note').hidden")) === true);
        await ev("document.querySelector('#timetable-body tr').click()");
        await sleep(2500);
        check(`${tag} modal del tren abre`, (await ev("document.getElementById('vehicle-modal').open")) === true, await ev("document.getElementById('modal-line').textContent"));
        await ev("document.getElementById('modal-close').click()");
        await sleep(300);
        if (a.key === 'bus') {
            await ev("document.getElementById('schedule-text-toggle').click()");
            await sleep(6000);
            const open = (await ev("document.getElementById('schedule-modal').open")) === true;
            const blocks = await ev("document.querySelectorAll('#schedule-modal-content .schedule-block').length");
            if (open && blocks > 0) check(`${tag} horario oficial en texto`, true, `${blocks} bloques`);
            else skip(`${tag} horario oficial en texto`, 'feed externo sin datos o sin internet');
            await ev("document.getElementById('schedule-modal-close').click()");
        }
        await ev("document.getElementById('legal-open').click()");
        await sleep(300);
        check(`${tag} aviso legal abre`, (await ev("document.getElementById('legal-panel').open")) === true);
        await ev("document.getElementById('legal-close').click()");
        await ev("document.getElementById('home-link').click()");
        await sleep(400);
        const cards = await ev("[...document.querySelectorAll('#network-switcher .net-card:not([hidden])')].length");
        check(`${tag} selector de app muestra 3 destinos`, cards === 3, `${cards}`);
        await ev('localStorage.clear()');
    }
}

where = 'deep';
await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
for (const [url, panel, name] of [
    ['/stops/4255', 'live-card', 'parada bus'],
    ['/stops/19?red=metro', 'platform-panel', 'estacion metro'],
    ['/stops/ES:Euskotren:StopPlace:2581:?red=euskotren', 'platform-panel', 'estacion euskotren'],
    ['/lines/MB?red=metro', 'timetable-section', 'linea metro'],
    ['/lines/3516', 'timetable-section', 'linea bus'],
]) {
    await go(url, 4500);
    check(`enlace directo ${name}`, (await ev(`!document.getElementById('${panel}').hidden`)) === true && (await ev("getComputedStyle(document.querySelector('header')).position")) === 'sticky');
}

where = 'menu';
await go('/', 2500);
check('menu Bide+ carga y tiene 3 fichas', (await ev("document.querySelectorAll('a.tile[data-app]').length")) === 3);
await ev("document.querySelector('[data-view-btn=list]').click()"); await sleep(600);
check('menu vista lista', (await ev("document.documentElement.getAttribute('data-view')")) === 'list');
await ev("document.querySelector('[data-view-btn=tiles]').click()"); await sleep(600);
check('menu vista fichas', (await ev("document.documentElement.getAttribute('data-view')")) === 'tiles');

where = 'miamor';
await go('/?red=metro&tema=miamor', 2800);
check('tema mi amor carga (style.css)', (await ev("[...document.styleSheets].some(s => (s.href||'').endsWith('style.css'))")) === true);
await search('abando');
await ev("document.querySelector('#search-results .pill').click()"); await sleep(3500);
check('tema mi amor abre parada', (await ev("!document.getElementById('platform-panel').hidden")) === true);

where = '404';
for (const [u, nombre] of [['/stops/99999999', 'parada inexistente'], ['/nada-por-aqui', 'ruta desconocida']]) {
    const res = await fetch(BASE + u);
    check(`404 real: ${nombre}`, res.status === 404, `HTTP ${res.status}`);
}

const unique = [...new Set(problems)];
const bad = results.filter(r => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO UI: ${results.filter(r => r.startsWith('OK')).length} OK, ${bad} MAL, ${results.filter(r => r.startsWith('SKIP')).length} SKIP, ${unique.length} problemas de consola/red`);
for (const p of unique.slice(0, 25)) console.log(' -', p);
ws.close();
shutdown(bad || unique.length ? 1 : 0);
