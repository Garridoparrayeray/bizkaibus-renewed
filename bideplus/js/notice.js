// El aviso general (huelga, obras, cortes): un cartel arriba que publica el dueño desde GitHub y caduca a la hora que
// dice. Se pide al abrir la app y al volver a ella; cada persona lo cierra una vez y no vuelve hasta que cambie.
const BideNotice = (() => {
    const CLOSED_KEY = 'bide-notice-closed';
    const CHECK_EVERY_MS = 5 * 60 * 1000;
    const TICK_MS = 60 * 1000;
    // El mismo ancho de ordenador que usa style-bide.css: allí la página es una rejilla y el cartel va encima de ella.
    const DESKTOP = window.matchMedia('(min-width: 1024px)');
    let current = null;
    let lastCheck = 0;

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

    function isShown(notice) {
        return notice !== null && notice.expiresAt * 1000 > Date.now() && closedId() !== notice.id;
    }

    function build(notice) {
        const lang = I18n.getLang();
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
        BideLinks.append(text, notice.text[lang] || notice.text.es);
        body.append(title, text);
        if (notice.url) {
            const more = document.createElement('a');
            more.className = 'bide-notice-link';
            more.href = notice.url;
            more.target = '_blank';
            more.rel = 'noopener noreferrer';
            more.textContent = I18n.t('bide.notice.more');
            body.append(more);
        }

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

    load();
    setInterval(tick, TICK_MS);
    document.addEventListener('visibilitychange', tick);
    window.addEventListener('bide:lang', render);
    DESKTOP.addEventListener('change', render);

    return { render };
})();
