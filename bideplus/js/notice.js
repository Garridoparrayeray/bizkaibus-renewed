// El aviso general (huelga, obras, cortes): un cartel arriba que publica el dueño desde GitHub y caduca a la hora que
// dice. Se pide al abrir la app y al volver a ella; cada persona lo cierra una vez y no vuelve hasta que cambie.
// Además se queda siempre en Avisos (la pestaña de Bide+ y las incidencias de cada app) hasta que caduca, se cierre o no
// el cartel: active() lo da a esas listas.
const BideNotice = (() => {
    const CLOSED_KEY = 'bide-notice-closed';
    const CHECK_EVERY_MS = 5 * 60 * 1000;
    const TICK_MS = 60 * 1000;
    // El mismo ancho de ordenador que usa style-bide.css: allí la página es una rejilla y el cartel va encima de ella.
    const DESKTOP = window.matchMedia('(min-width: 1024px)');
    let current = null;
    let lastCheck = 0;
    let firstLoad = null;

    function closedId() {
        try {
            return localStorage.getItem(CLOSED_KEY);
        } catch (e) {
            return null;
        }
    }

    function remember(id) {
        try {
            localStorage.setItem(CLOSED_KEY, id);
        } catch (e) {
            // Sin almacenamiento se vuelve a ver al abrir la app.
        }
    }

    function isActive(notice) {
        return notice !== null && notice.expiresAt * 1000 > Date.now();
    }

    function isShown(notice) {
        return isActive(notice) && closedId() !== notice.id;
    }

    // El texto en el idioma de la persona; sin traducción, el castellano.
    function textFor(notice) {
        return notice.text[I18n.getLang()] || notice.text.es;
    }

    // «Más información», o null si el aviso no trae enlace.
    function linkFor(notice, className) {
        if (!notice.url) return null;
        const more = document.createElement('a');
        more.className = className;
        more.href = notice.url;
        more.target = '_blank';
        more.rel = 'noopener noreferrer';
        more.textContent = I18n.t('bide.notice.more');
        return more;
    }

    function build(notice) {
        const banner = document.createElement('aside');
        banner.className = 'bide-notice';
        banner.setAttribute('role', 'status');

        const badge = document.createElement('span');
        badge.className = 'bide-notice-badge';
        badge.setAttribute('aria-hidden', 'true');
        badge.textContent = '!';

        const body = document.createElement('div');
        body.className = 'bide-notice-body';
        const title = document.createElement('strong');
        title.className = 'bide-notice-title';
        title.textContent = I18n.t('bide.notice.title');
        const text = document.createElement('p');
        text.className = 'bide-notice-text';
        BideLinks.append(text, textFor(notice));
        body.append(title, text);
        const more = linkFor(notice, 'bide-notice-link');
        if (more) body.append(more);

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'bide-notice-close';
        close.setAttribute('aria-label', I18n.t('bide.notice.close'));
        close.textContent = '×';
        close.addEventListener('click', () => {
            remember(notice.id);
            render();
        });

        banner.append(badge, body, close);
        return banner;
    }

    // Pone, cambia o quita el cartel: debajo de la cabecera en el móvil y, en ordenador, encima de toda la página.
    function render() {
        document.querySelectorAll('.bide-notice').forEach((node) => node.remove());
        const main = document.querySelector('main');
        const header = document.querySelector('main > header');
        if (!isShown(current) || !main || !header) return;
        if (DESKTOP.matches) {
            main.before(build(current));
            return;
        }
        header.after(build(current));
    }

    async function load() {
        lastCheck = Date.now();
        try {
            const response = await fetch('/api/notice');
            if (!response.ok) return;
            current = (await response.json()).notice || null;
        } catch (e) {
            return;
        }
        render();
    }

    function tick() {
        if (document.visibilityState !== 'visible') return;
        if (Date.now() - lastCheck >= CHECK_EVERY_MS) {
            load();
            return;
        }
        if (current !== null && !isShown(current)) render();
    }

    firstLoad = load();
    setInterval(tick, TICK_MS);
    document.addEventListener('visibilitychange', tick);
    window.addEventListener('bide:lang', render);
    DESKTOP.addEventListener('change', render);

    // El aviso que está en vigor, cerrado el cartel o no. Espera a la primera consulta.
    async function active() {
        await firstLoad;
        if (!isActive(current)) return null;
        return current;
    }

    return { active, textFor, linkFor };
})();
