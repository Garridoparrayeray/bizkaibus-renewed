// La hoja de filtros, que sube desde abajo: accesible, transbordos con margen, solo en directo y las redes (tus redes
// y las demás, sin dejar quitar la última). Se guardan en el aparato y la búsqueda se repite al cerrar la hoja.
import { byId, colorOf, el } from './common.js';

const MARGIN_MINUTES = 10;
const sheet = byId('route-filters');
let bChanged = false;

const activeCount = (filters) => Number(filters.accessible) + Number(filters.liveOnly) + Number(filters.margin > 0)
    + Number(filters.networksOff.length > 0);

const switchOn = (app, sFilter) => {
    if (sFilter === 'margin') {
        return app.filters.margin > 0;
    }
    return app.filters[sFilter] === true;
};

const networksValue = (app) => {
    const iOn = app.slugs.length - app.filters.networksOff.length;
    if (iOn === app.slugs.length) {
        return I18n.t('route.filters.allNetworks');
    }
    return I18n.t('route.filters.someNetworks', { n: iOn, total: app.slugs.length });
};

const networkCheck = (app, sSlug) => {
    const network = app.networkOf(sSlug);
    const bOn = !app.filters.networksOff.includes(sSlug);
    const check = el('button', 'route-check');
    const swatch = el('span', 'route-check-swatch');
    check.type = 'button';
    check.setAttribute('role', 'checkbox');
    check.setAttribute('aria-checked', String(bOn));
    swatch.style.background = colorOf(network);
    check.append(swatch, el('span', 'route-check-name', app.networkName(sSlug)), el('span', 'route-check-box'));
    check.addEventListener('click', () => {
        let aOff = app.filters.networksOff.concat([sSlug]);
        if (!bOn) {
            aOff = app.filters.networksOff.filter((sOther) => sOther !== sSlug);
        }
        bChanged = true;
        app.changeFilters({ networksOff: aOff }, false);
        byId('route-networks-' + groupOf(app, sSlug)).querySelector('[data-slug="' + sSlug + '"]').focus();
    });
    check.dataset.slug = sSlug;
    return check;
};

const groupOf = (app, sSlug) => {
    if (app.home.includes(sSlug)) {
        return 'yours';
    }
    return 'others';
};

const renderNetworks = (app) => {
    const aYours = app.slugs.filter((sSlug) => groupOf(app, sSlug) === 'yours');
    const aOthers = app.slugs.filter((sSlug) => groupOf(app, sSlug) === 'others');
    byId('route-networks-yours').replaceChildren(...aYours.map((sSlug) => networkCheck(app, sSlug)));
    byId('route-networks-others').replaceChildren(...aOthers.map((sSlug) => networkCheck(app, sSlug)));
    byId('route-networks-yours').previousElementSibling.hidden = aYours.length === 0;
    byId('route-networks-count').textContent = I18n.t('route.filters.networksHint', {
        n: app.slugs.length - app.filters.networksOff.length, total: app.slugs.length,
    });
};

// Pone la hoja y el contador del botón «Filtros» como dicen los filtros guardados.
export const refreshFilters = (app) => {
    const iCount = activeCount(app.filters);
    const count = byId('route-filters-count');
    count.textContent = String(iCount);
    count.hidden = iCount === 0;
    byId('route-filters-open').classList.toggle('is-on', iCount > 0);
    sheet.querySelectorAll('[data-route-filter]').forEach((toggle) => {
        toggle.setAttribute('aria-checked', String(switchOn(app, toggle.dataset.routeFilter)));
    });
    byId('route-networks-value').textContent = networksValue(app);
    if (app.slugs.length > 0) {
        renderNetworks(app);
    }
};

const showPage = (sPage) => {
    byId('route-filters-main').hidden = sPage !== 'main';
    byId('route-filters-networks').hidden = sPage !== 'networks';
};

const flip = (app, sFilter) => {
    const change = {};
    change[sFilter] = !switchOn(app, sFilter);
    if (sFilter === 'margin') {
        change.margin = 0;
        if (!switchOn(app, 'margin')) {
            change.margin = MARGIN_MINUTES;
        }
    }
    bChanged = true;
    app.changeFilters(change, false);
};

export const initFilters = (app) => {
    byId('route-filters-open').addEventListener('click', () => {
        app.wake();
        showPage('main');
        bChanged = false;
        sheet.showModal();
    });
    sheet.querySelectorAll('[data-route-filter]').forEach((toggle) => {
        toggle.addEventListener('click', () => flip(app, toggle.dataset.routeFilter));
    });
    byId('route-networks-open').addEventListener('click', () => {
        showPage('networks');
        byId('route-networks-title').focus();
    });
    byId('route-networks-back').addEventListener('click', () => {
        showPage('main');
        byId('route-networks-open').focus();
    });
    byId('route-networks-done').addEventListener('click', () => {
        showPage('main');
        byId('route-networks-open').focus();
    });
    byId('route-networks-all').addEventListener('click', () => {
        bChanged = true;
        app.changeFilters({ networksOff: [] }, false);
    });
    byId('route-filters-done').addEventListener('click', () => sheet.close());
    sheet.addEventListener('click', (event) => {
        if (event.target === sheet) {
            sheet.close();
        }
    });
    sheet.addEventListener('close', () => {
        if (bChanged) {
            bChanged = false;
            app.rerun();
        }
    });
    refreshFilters(app);
};
