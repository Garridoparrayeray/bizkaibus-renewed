// Info y el aviso legal de las páginas de línea, parada y red: llegan en <template id="bide-deferred"> (bideDeferred(),
// bide-components.php) y entran en la página la primera vez que se abren. Al entrar se traducen y se avisa con
// «bide:deferred» a quien se engancha a ellos (bide.js, bide-info.js, new-badge.js).
const BideDeferred = (() => {
    function ensure() {
        const template = document.getElementById('bide-deferred');
        if (!template) return;
        const content = template.content.cloneNode(true);
        I18n.applyTranslations(content);
        const info = content.querySelector('[data-bide-view="info"]');
        if (info) document.querySelector('.bide-views').appendChild(info);
        document.body.appendChild(content);
        template.remove();
        setUpLegal();
        window.dispatchEvent(new Event('bide:deferred'));
    }

    function setUpLegal() {
        const panel = document.getElementById('legal-panel');
        if (!panel) return;
        document.getElementById('legal-close').addEventListener('click', () => panel.close());
        panel.addEventListener('click', (e) => {
            if (e.target === panel) panel.close();
        });
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#legal-open')) return;
        ensure();
        document.getElementById('legal-panel').showModal();
    });

    return { ensure };
})();
