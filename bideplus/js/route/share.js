// Compartir las opciones o el viaje: el planificador escribe el enlace (#v1.…, que el navegador no manda al servidor)
// y se abre el menú de compartir del sistema; si no lo hay, se copia el enlace. Lo que pasa se dice en un aviso.
import * as share from '/planificador/pantallas/share.js';
import { byId } from './common.js';

const TOAST_MS = 4000;
let iToastTimer = 0;

const toast = (sText) => {
    const box = byId('route-toast');
    box.textContent = sText;
    box.hidden = false;
    clearTimeout(iToastTimer);
    iToastTimer = setTimeout(() => {
        box.hidden = true;
    }, TOAST_MS);
};

const copy = async (sUrl) => {
    try {
        await navigator.clipboard.writeText(sUrl);
        return true;
    } catch (error) {
        return false;
    }
};

export const shareLink = async (iDay, aJourneys, query, request, sTitle) => {
    let sUrl = '';
    try {
        sUrl = await share.encode(iDay, aJourneys, query, request);
    } catch (error) {
        toast(I18n.t('route.share.failed'));
        return;
    }
    if (navigator.share) {
        try {
            await navigator.share({ title: sTitle, url: sUrl });
            return;
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
        }
    }
    if (await copy(sUrl)) {
        toast(I18n.t('route.share.copied'));
        return;
    }
    toast(I18n.t('route.share.manual', { url: sUrl }));
};
