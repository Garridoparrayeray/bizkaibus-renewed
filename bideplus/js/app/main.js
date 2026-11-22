// Arranque de la app de una red: idioma y puesta en marcha de cada parte en orden.
import { el, state } from './core.js';
import { loadFavorites, setUpFavoritesPanel } from './favorites.js';
import { setUpLineMap } from './line-map.js';
import { renderDepartures, setUpConnectionBanner, setUpStop } from './stop.js';
import { setUpNetworkMenu, startRouting, updateTitle } from './navigation.js';
import { setUpSearch } from './search.js';
import { renderSchedulePublished, renderTimetableRows, setUpTimetable } from './timetable.js';
import { setUpScheduleModal } from './schedule-modal.js';
import { modalState, renderModalHeader, renderModalStops, setUpVehicleModal } from './vehicle-modal.js';
import { setUpStopShare } from './share.js';
import { setUpSideMenu } from './side-menu.js';
import { setUpStopAlerts } from './stop-alerts.js';
import { setUpReduced } from './reduced.js';
import { setUpAlerts } from './alerts.js';
import { setUpArrivalAlerts } from './arrival-alerts.js';

// Lo que hace el botón de idioma, en los tres idiomas a la vez (como en app.php): lo entiende cualquiera.
const LANG_TOGGLE_LABEL = 'Aldatu hizkuntza / Cambiar idioma / Change language';

// El botón enseña el idioma al que cambia.
function updateLangToggleLabel() {
    // La etiqueta empieza por lo que se ve en el botón (el idioma siguiente), para quien lo nombra en voz alta.
    el.langToggle.textContent = I18n.nextLang().toUpperCase();
    el.langToggle.setAttribute('aria-label', el.langToggle.textContent + ' · ' + LANG_TOGGLE_LABEL);
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
    renderSchedulePublished();
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
        updateTitle();
    });
}

setUpConnectionBanner();
setUpNetworkMenu();
setUpSideMenu();
setUpStopAlerts();
setUpReduced();
setUpFavoritesPanel();
setUpAlerts();
setUpLanguage();
setUpSearch();
setUpStop();
setUpTimetable();
setUpScheduleModal();
setUpVehicleModal();
setUpStopShare();
setUpArrivalAlerts();
setUpLineMap();
loadFavorites();
startRouting();
