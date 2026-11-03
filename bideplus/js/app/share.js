// Compartir un enlace: con la hoja de compartir del móvil o, si no la hay, copiándolo. Se comparte la parada (el enlace
// abre sus próximas salidas en directo) desde su ficha y desde la del viaje, y el viaje en curso (vehicle-modal.js).
import { el, networkQuery, state } from './core.js';

const COPIED_MS = 2500;

export async function shareLink(url, text, button) {
    if (navigator.share) {
        try {
            await navigator.share({ title: 'Bide+', text, url });
            return;
        } catch (e) {
            if (e.name === 'AbortError') return;
        }
    }
    try {
        await navigator.clipboard.writeText(text + ' ' + url);
        showCopied(button);
    } catch (e) {
        window.prompt(I18n.t('app.share.prompt'), url);
    }
}

// «Enlace copiado» un momento: en el texto del botón o, si es un icono, en su color y su título.
function showCopied(button) {
    const key = button.getAttribute('data-i18n');
    button.classList.add('is-copied');
    button.title = I18n.t('app.share.copied');
    if (key) button.textContent = I18n.t('app.share.copied');
    setTimeout(() => {
        button.classList.remove('is-copied');
        button.title = I18n.t('app.share.stopButton');
        if (key) button.textContent = I18n.t(key);
    }, COPIED_MS);
}

export function shareStop(button) {
    if (!state.currentStop) return;
    const url = location.origin + '/stops/' + encodeURIComponent(state.currentStop.id) + networkQuery;
    shareLink(url, I18n.t('app.share.stop', { stop: state.currentStop.name }), button);
}

export function setUpStopShare() {
    for (const button of [el.liveShare, el.platformShare, el.modalShareStop]) {
        if (button) button.addEventListener('click', () => shareStop(button));
    }
}
