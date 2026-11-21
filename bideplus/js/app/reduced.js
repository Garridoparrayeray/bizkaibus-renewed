// Servicio reducido de la red (huelga, servicios mínimos; /api/reduced) debajo de la búsqueda, que se ve en el inicio y
// con la parada abierta: hoy o el próximo día con él. Lo último que se vio hoy se pinta al arrancar, para que el aviso
// no empuje la página al llegar; con él, lo que va solo con su horario hoy se marca como «puede no pasar».
import { NETWORK_SLUG, hereNow } from './core.js';
import { setReducedToday } from './ui.js';

const STORAGE_KEY = `bide_reduced_${NETWORK_SLUG}`;
const REFRESH_MS = 5 * 60 * 1000;
let lastReduced = null;

const todayIso = () => hereNow().date;

function noticeText(reduced) {
    let kind = 'reduced';
    if (reduced.cause === 'strike') kind = 'strike';
    let text = '';
    if (reduced.today) text = I18n.t(`app.reduced.${kind}Today`);
    const upcoming = (reduced.days || []).find((day) => day > todayIso());
    if (!reduced.today && upcoming) {
        const date = new Date(`${upcoming}T12:00:00`).toLocaleDateString(I18n.getLang(), { weekday: 'long', day: 'numeric', month: 'long' });
        text = I18n.t(`app.reduced.${kind}Day`, { date });
    }
    if (text && Array.isArray(reduced.lineCodes)) text += ` ${I18n.t('app.reduced.lines', { lines: reduced.lineCodes.join(', ') })}`;
    return text;
}

function render(reduced) {
    lastReduced = reduced;
    let text = '';
    if (reduced) text = noticeText(reduced);
    const notice = document.getElementById('reduced-notice');
    notice.textContent = text;
    notice.hidden = text === '';
    setReducedToday(reduced);
    window.dispatchEvent(new CustomEvent('bide:reduced'));
}

async function refresh() {
    let reduced = null;
    try {
        reduced = await Api.reduced();
    } catch (e) {
        return;
    }
    // La copia que guardó el service worker (sin conexión) puede ser de otro día: «hoy» se mira en sus días.
    if (reduced.offline) {
        render({ ...reduced, today: (reduced.days || []).includes(todayIso()) });
        return;
    }
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ date: todayIso(), reduced }));
    } catch (e) { /* sin almacenamiento */ }
    render(reduced);
}

export function setUpReduced() {
    try {
        const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        if (saved && saved.date === todayIso()) render(saved.reduced);
    } catch (e) { /* sin almacenamiento */ }
    refresh();
    setInterval(() => {
        if (!document.hidden) refresh();
    }, REFRESH_MS);
    window.addEventListener('bide:lang', () => render(lastReduced));
}
