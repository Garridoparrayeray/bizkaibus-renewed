// Aviso de versión nueva: «1» en Ajustes con sus novedades y, con permiso, notificación del servidor.
import { pushSupported, pushSubscription, serverKey } from './push.js';

const MAX_VERSION = 999;
const CHECK_EVERY_MS = 30 * 60 * 1000;
const SUBSCRIBED_KEY = 'bide-update-subscribed';
const SUBSCRIBE_EVERY_MS = 24 * 3600 * 1000;

let lastCheck = 0;
let updateReady = false;

// La versión de este código: la última novedad con título en el diccionario.
export function shippedVersion() {
    for (let number = MAX_VERSION; number > 0; number--) {
        const key = `bide.changelog.${number}.title`;
        if (I18n.t(key) !== key) return number;
    }
    return 0;
}

function badge() {
    const node = document.createElement('span');
    node.className = 'bide-update-badge';
    node.textContent = '1';
    node.setAttribute('role', 'img');
    node.setAttribute('aria-label', I18n.t('bide.update.badge'));
    return node;
}

function renderBadges() {
    document.querySelectorAll('[data-bide-tab="ajustes"]').forEach((tab) => {
        tab.querySelectorAll('.bide-update-badge').forEach((current) => current.remove());
        if (updateReady) tab.append(badge());
    });
}

function noteItem(note) {
    const item = document.createElement('li');
    const title = document.createElement('strong');
    title.textContent = note.title;
    const text = document.createElement('p');
    text.textContent = note.text;
    item.append(title, text);
    return item;
}

function renderSettings(view, notes) {
    const box = view.querySelector('[data-settings-update]');
    box.hidden = !updateReady;
    view.querySelector('[data-settings-update-badge]').hidden = !updateReady;
    view.querySelector('[data-settings-update-notes]').replaceChildren(...notes.map(noteItem));
}

function showUpdate(view) {
    if (!updateReady || view.hidden) return;
    requestAnimationFrame(() => view.querySelector('.bide-settings-app').scrollIntoView({ block: 'start' }));
}

async function check(view, force) {
    if (!navigator.onLine || (!force && Date.now() - lastCheck < CHECK_EVERY_MS)) return;
    lastCheck = Date.now();
    const shipped = shippedVersion();
    let server;
    try {
        server = await Api.appVersion(shipped, I18n.getLang());
    } catch (e) {
        return;
    }
    updateReady = Number(server.version) > shipped;
    let notes = [];
    if (updateReady && Array.isArray(server.notes)) notes = server.notes;
    renderBadges();
    renderSettings(view, notes);
    showUpdate(view);
}

function readSubscribed() {
    try {
        return JSON.parse(localStorage.getItem(SUBSCRIBED_KEY) || '{}') || {};
    } catch (e) {
        return {};
    }
}

async function subscribeToUpdates() {
    if (!pushSupported() || Notification.permission !== 'granted') return;
    const key = await serverKey();
    if (!key) return;
    const subscription = await pushSubscription(key.publicKey);
    const lang = I18n.getLang();
    const mark = `${subscription.endpoint}|${lang}`;
    const saved = readSubscribed();
    if (saved.mark === mark && Date.now() - saved.at < SUBSCRIBE_EVERY_MS) return;
    await Api.saveUpdateAlerts(subscription, lang);
    try {
        localStorage.setItem(SUBSCRIBED_KEY, JSON.stringify({ mark, at: Date.now() }));
    } catch (e) {
        // Sin almacenamiento se vuelve a apuntar la próxima vez.
    }
}

export async function forgetUpdates(endpoint) {
    await Api.removeUpdateAlerts(endpoint);
}

export function setUpAppUpdate(view) {
    check(view, true);
    subscribeToUpdates().catch(() => null);
    new MutationObserver(() => showUpdate(view)).observe(view, { attributes: true, attributeFilter: ['hidden'] });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') check(view, false);
    });
    window.addEventListener('bide:lang', () => check(view, true));
}
