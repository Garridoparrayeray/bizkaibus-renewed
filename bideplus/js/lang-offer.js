// Si el móvil está en otro idioma de Bide+ y nadie ha elegido uno, un aviso lo ofrece en ese idioma, como primera opción.
// La página no se traduce sola: la dirección castellana sigue en castellano (y con su título) para los buscadores.
(() => {
    const offered = I18n.browserLang();
    if (I18n.hasChoice() || offered === I18n.getLang()) return;
    // Se crea al vuelo cuando la página ya ha cargado y el navegador está libre: no compite con lo que se pinta.
    const SHOW_DELAY_MS = 2000;
    const show = () => setTimeout(() => {
        if ('requestIdleCallback' in window) requestIdleCallback(build);
        else build();
    }, SHOW_DELAY_MS);
    if (document.readyState === 'complete') show();
    else window.addEventListener('load', show, { once: true });

    function build() {
        if (I18n.hasChoice() || offered === I18n.getLang()) return;

        const offer = document.createElement('div');
        offer.className = 'bide-lang-offer';
        offer.setAttribute('role', 'status');
        offer.lang = offered;
        const text = document.createElement('p');
        text.className = 'bide-lang-offer-text';
        text.textContent = I18n.t('bide.langOffer.text', null, offered);
        const actions = document.createElement('div');
        actions.className = 'bide-lang-offer-actions';
        const change = document.createElement('button');
        change.type = 'button';
        change.className = 'bide-lang-offer-switch';
        change.textContent = I18n.t('bide.langOffer.switch', null, offered);
        change.addEventListener('click', () => {
            offer.remove();
            I18n.setLang(offered);
        });
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'bide-lang-offer-close';
        close.textContent = I18n.t('bide.langOffer.close', null, offered);
        // Cerrarlo es quedarse con el idioma de la página: no vuelve a salir.
        close.addEventListener('click', () => {
            offer.remove();
            I18n.setLang(I18n.getLang());
        });
        actions.append(change, close);
        offer.append(text, actions);
        document.body.appendChild(offer);
        change.focus({ preventScroll: true });
    }
})();
