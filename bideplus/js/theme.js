// Tema de la página, antes de pintar: «mi amor» en su dominio, o desde que se abre con ?tema=miamor hasta que se cierra
// la pestaña (?tema=pro lo quita). Lo usan el menú, el mapa y las apps; los colores están en style-miamor.css.
(function () {
    let isMiamor = location.hostname === 'bideplusmiamor.vercel.app';
    const tema = new URLSearchParams(location.search).get('tema');
    try {
        if (tema === 'miamor') sessionStorage.setItem('bide-tema', 'miamor');
        if (tema === 'pro') sessionStorage.removeItem('bide-tema');
        if (sessionStorage.getItem('bide-tema') === 'miamor') isMiamor = true;
    } catch (e) {
        if (tema === 'miamor') isMiamor = true;
    }
    window.__bbTheme = 'pro';
    if (isMiamor) {
        window.__bbTheme = 'miamor';
        document.documentElement.classList.add('is-miamor');
    }
})();
