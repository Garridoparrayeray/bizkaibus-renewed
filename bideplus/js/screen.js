// Pantalla de una parada: las próximas salidas en grande, con reloj, que se refrescan solas. No tiene botones: al
// tocarla se pone a pantalla completa, y mientras se ve no deja que la pantalla se apague.
import { statusLabel } from './app/ui.js';

const REFRESH_MS = 25000;
const TICK_MS = 10000;
const CLOCK_MS = 1000;
const MAX_ROWS = 8;
const PAST_MARGIN_MS = 60000;
const HIDDEN_STATUSES = ['departed', 'likely_departed'];

const body = document.body;
const params = new URLSearchParams(location.search);
window.__bbNetwork = BideNetworks.DEFAULT;
if (BideNetworks.exists(params.get('red'))) window.__bbNetwork = params.get('red');
document.documentElement.style.setProperty('--network', BideNetworks.get(window.__bbNetwork).themeColor);

const list = document.querySelector('[data-screen-list]');
const empty = document.querySelector('[data-screen-empty]');
const clock = document.querySelector('[data-screen-clock]');
const status = document.querySelector('[data-screen-status]');
let departures = [];
let platformLabels = new Map();

function createNode(tag, className, text) {
    const node = document.createElement(tag);
    node.className = className;
    if (text != null) node.textContent = text;
    return node;
}

function hourMinute(date) {
    return date.toTimeString().slice(0, 5);
}

// Minutos que faltan: con la hora absoluta (etaAt) se recalculan con el reloj entre refrescos.
function minutesLeft(item) {
    if (Number.isFinite(item.etaAt)) return Math.round((item.etaAt * 1000 - Date.now()) / 60000);
    return item.etaMinutes;
}

function timeText(item) {
    const minutes = minutesLeft(item);
    if (minutes <= 0) return I18n.t('screen.now');
    if (minutes < 60) return minutes + ' min';
    return item.scheduledTime;
}

function detailText(item) {
    const parts = [];
    const label = platformLabels.get(String(item.platformId));
    if (label) parts.push(I18n.t('screen.platform', { label }));
    const state = statusLabel(item.status, item.delayMinutes).text;
    if (state) parts.push(state);
    return parts.join(' · ');
}

function row(item) {
    const li = createNode('li', 'screen-row');
    const destination = createNode('span', 'screen-dest', item.headsign);
    const detail = detailText(item);
    if (detail) destination.appendChild(createNode('small', 'screen-detail', detail));
    const time = createNode('span', 'screen-time', timeText(item));
    if (item.status === 'live') time.classList.add('is-live');
    li.append(createNode('span', 'screen-line', item.lineCode), destination, time);
    return li;
}

function render() {
    const now = Date.now();
    const rows = departures
        .filter((item) => !HIDDEN_STATUSES.includes(item.status))
        .filter((item) => !Number.isFinite(item.etaAt) || item.etaAt * 1000 > now - PAST_MARGIN_MS)
        .slice(0, MAX_ROWS);
    list.replaceChildren(...rows.map(row));
    empty.hidden = rows.length > 0;
}

async function load() {
    try {
        const data = await Api.stopDepartures(body.dataset.stopId, 12);
        departures = data.departures || [];
        platformLabels = new Map((data.platforms || []).map((platform) => [String(platform.id), platform.label]));
        status.textContent = I18n.t('screen.updated', { time: hourMinute(new Date()) });
        body.classList.remove('is-offline');
    } catch (error) {
        status.textContent = I18n.t('screen.offline');
        body.classList.add('is-offline');
    }
    render();
}

function tickClock() {
    clock.textContent = hourMinute(new Date());
}

// Mientras la pantalla está a la vista, que no se apague (no todos los navegadores lo permiten).
async function keepAwake() {
    if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
    try {
        await navigator.wakeLock.request('screen');
    } catch (error) {
        // Sin permiso o sin batería suficiente: la pantalla sigue funcionando, solo que puede apagarse.
    }
}

function goFullscreen() {
    if (document.fullscreenElement || !document.documentElement.requestFullscreen) return;
    document.documentElement.requestFullscreen().catch(() => {});
}

I18n.applyTranslations();
tickClock();
setInterval(tickClock, CLOCK_MS);
body.addEventListener('click', goFullscreen);
if (body.dataset.stopFound === '1') {
    load();
    setInterval(load, REFRESH_MS);
    setInterval(render, TICK_MS);
    keepAwake();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        keepAwake();
        load();
    });
}
