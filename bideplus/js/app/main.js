// Arranque de la app de una red: idioma y puesta en marcha de cada parte en orden.
import { el, state } from './core.js';
import { loadFavorites, setUpFavoritesPanel } from './favorites.js';
import { setUpLineMap } from './line-map.js';
import { renderDepartures, setUpConnectionBanner, setUpStop } from './stop.js';
import { setUpNetworkMenu, startRouting } from './navigation.js';
import { setUpSearch } from './search.js';
import { renderTimetableRows, setUpTimetable } from './timetable.js';
import { setUpScheduleModal } from './schedule-modal.js';
import { modalState, renderModalHeader, renderModalStops, setUpVehicleModal } from './vehicle-modal.js';
import { setUpSideMenu } from './side-menu.js';
import { setUpAlerts } from './alerts.js';

// El botón enseña el idioma al que cambia.
function updateLangToggleLabel() {
    el.langToggle.textContent = I18n.nextLang().toUpperCase();
}
function updateNetworkSubtitle() {
    if (window.__bbTheme === 'miamor' && window.__bbNetwork === BideNetworks.DEFAULT) return;
    el.appSubtitle.textContent = I18n.t(`net.${window.__bbNetwork}.subtitle`);
}

// Los textos que se pintan por código (estado, retraso, avisos de la tarjeta, recorrido y horario) no llevan data-i18n: al cambiar de idioma se vuelven a pintar.
function refreshLanguageDependentViews() {
    if (state.currentStop && state.lastDepartures) renderDepartures(state.lastDepartures);
    if (el.modal.open && modalState.data) {
        modalState.signature = '';
        renderModalHeader(modalState.data);
        renderModalStops(modalState.data);
    }
    if (state.lastTimetableEntries && !el.timetableSection.hidden) renderTimetableRows(state.lastTimetableEntries);
}

function setUpLanguage() {
    I18n.applyTranslations();
    updateLangToggleLabel();
    updateNetworkSubtitle();
    el.langToggle.addEventListener('click', () => I18n.setLang(I18n.nextLang()));
    window.addEventListener('bide:lang', () => {
        I18n.applyTranslations();
        updateLangToggleLabel();
        updateNetworkSubtitle();
        refreshLanguageDependentViews();
    });
}

setUpConnectionBanner();
setUpNetworkMenu();
setUpSideMenu();
setUpFavoritesPanel();
setUpAlerts();
setUpLanguage();
setUpSearch();
setUpStop();
setUpTimetable();
setUpScheduleModal();
setUpVehicleModal();
setUpLineMap();
loadFavorites();
startRouting();
