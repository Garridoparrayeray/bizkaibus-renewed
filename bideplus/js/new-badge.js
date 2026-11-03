// «Nuevo» en las funciones recién salidas: un botón o una tarjeta con data-new-since="AAAA-MM-DD" lleva la etiqueta los
// días de new_days (api/Config/site.php, que llega en <html data-new-days>). La pinta el CSS (.is-new::after) con el
// texto de data-new-label: así no la borra quien cambie el texto del botón, y cambia con el idioma.
(function () {
    const DAY_MS = 24 * 60 * 60 * 1000;

    function days() {
        return Number(document.documentElement.dataset.newDays) || 30;
    }

    function isNew(since) {
        const start = Date.parse(`${since}T00:00:00`);
        if (Number.isNaN(start)) return false;
        return Date.now() < start + days() * DAY_MS;
    }

    function mark() {
        const label = I18n.t('bide.new');
        for (const element of document.querySelectorAll('[data-new-since]')) {
            const fresh = isNew(element.dataset.newSince);
            element.classList.toggle('is-new', fresh);
            if (fresh) element.dataset.newLabel = label;
        }
    }

    document.addEventListener('DOMContentLoaded', mark);
    window.addEventListener('bide:lang', mark);
})();
