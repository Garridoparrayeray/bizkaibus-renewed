// Cerrar la parada o la línea, el menú de redes y la navegación con el historial (atrás, enlaces /stops y /lines).
import { NETWORK, el, followAppLink, hideStaleSchedule, networkQuery, state, syncPageContent } from './core.js';
import { clearLineMap } from './line-map.js';
import { forgetStopRequests, selectStop, stopDeparturesRefresh } from './stop.js';
import { selectLine } from './timetable.js';
import { openVehicleModal } from './vehicle-modal.js';

function toggleNetworkMenu() {
    const isOpen = el.networkSwitch.classList.toggle('open');
    el.homeLink.setAttribute('aria-expanded', String(isOpen));
}

function closeNetworkMenu() {
    el.networkSwitch.classList.remove('open');
    el.homeLink.setAttribute('aria-expanded', 'false');
}

// El título de la página con que se abrió la app en cada idioma, el mismo que da el servidor con ?lang=.
const serverTitles = JSON.parse(document.documentElement.getAttribute('data-titles') || '{}');

// Lo que se ve ahora: la línea, la parada o la red.
function currentView() {
    if (state.currentLine) return ['line', String(state.currentLine.id)];
    if (state.currentStop) return ['stop', String(state.currentStop.id)];
    return ['network', window.__bbNetwork];
}

// La pestaña dice lo que se ve, en el idioma elegido: en la página con que se abrió, su título del servidor.
export function updateTitle() {
    if (window.__bbTheme === 'miamor') return;
    const [type, id] = currentView();
    if (type === el.pageContent.dataset.pageType && id === el.pageContent.dataset.pageId && serverTitles[I18n.getLang()]) {
        document.title = serverTitles[I18n.getLang()];
        return;
    }
    const net = window.__bbNetwork;
    let title = `${I18n.t('net.' + net + '.name')} · ${I18n.t('net.' + net + '.switcher')}`;
    if (type === 'line') {
        title = I18n.t('seo.lineTitleShort', { code: state.currentLine.code, operator: NETWORK.operator });
    } else if (type === 'stop') {
        title = I18n.t('seo.stopTitle', { stop: state.currentStop.name, operator: NETWORK.operator });
    }
    document.title = title;
}

// Con { push: true } (el botón de cerrar) la red entra en el historial y «atrás» vuelve a la parada.
export function closeLiveCard({ push = false } = {}) {
    forgetStopRequests();
    state.currentStop = null;
    el.liveCard.hidden = true;
    el.platformPanel.hidden = true;
    el.liveEmpty.hidden = false;
    stopDeparturesRefresh();
    if (state.currentLine && state.timetableStopId !== null) {
        closeTimetableSection();
    }
    if (window.location.pathname.startsWith('/stops/') && push) {
        history.pushState(null, '', '/?red=' + window.__bbNetwork);
    } else if (window.location.pathname.startsWith('/stops/')) {
        history.replaceState(null, '', '/?red=' + window.__bbNetwork);
    }
    syncPageContent('network', window.__bbNetwork);
    updateTitle();
}

export function closeTimetableSection() {
    state.currentLine = null;
    state.timetableStopId = null;
    el.timetableSection.hidden = true;
    clearLineMap();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (window.location.pathname.startsWith('/lines/')) {
        if (state.currentStop) {
            history.replaceState({ view: 'stop', id: state.currentStop.id, fromApp: true }, '', '/stops/' + state.currentStop.id + networkQuery);
        } else {
            history.replaceState(null, '', '/?red=' + window.__bbNetwork);
        }
    }
    if (!state.currentStop) syncPageContent('network', window.__bbNetwork);
    updateTitle();
}

// Si se llegó desde la entrada de antes (flag del estado del historial), cerrar es volver a ella; si no, close().
export function closeFromButton(view, close, flag = 'fromApp') {
    if (history.state && history.state.view === view && history.state[flag]) {
        history.back();
    } else {
        close();
    }
}

export function setUpNetworkMenu() {
    el.homeLink.addEventListener('click', toggleNetworkMenu);
    document.addEventListener('click', (e) => {
        if (!el.homeLink.contains(e.target) && !el.networkSwitch.contains(e.target)) {
            closeNetworkMenu();
        }
    });
}

// Los enlaces del contenido del servidor también abren dentro de la app (y con Ctrl, en otra pestaña).
function setUpPageContentLinks() {
    el.pageContent.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        const match = link && new URL(link.href).pathname.match(/^\/(stops|lines)\/([^/]+)/);
        if (!match) return;
        const id = decodeURIComponent(match[2]);
        followAppLink(event, link, () => {
            if (match[1] === 'stops') selectStop(id);
            else selectLine(id);
        });
    });
}

export function startRouting() {
    hideStaleSchedule();
    setUpPageContentLinks();
    updateTitle();
    window.addEventListener('popstate', (e) => {
        if (!e.state || !e.state.view) {
            closeLiveCard();
            if (state.currentLine) {
                closeTimetableSection();
            }
        } else if (e.state.view === 'stop') {
            if (state.currentLine) {
                closeTimetableSection();
            }
            if (!state.currentStop || String(state.currentStop.id) !== String(e.state.id)) {
                selectStop(e.state.id);
            }
        } else if (e.state.view === 'line') {
            if (!state.currentLine || String(state.currentLine.id) !== String(e.state.id)) {
                selectLine(e.state.id);
            }
        }
    });

    let pathMatch = window.location.pathname.match(/^\/(stops|lines)\/([^/]+)/);
    const launch = new URLSearchParams(window.location.search);
    let sharedTrip = launch.get('viaje');
    const alertToken = launch.get('aviso');
    if (sharedTrip || alertToken) removeLaunchParams();
    if (alertToken && !AlertsStore.firstOpening(alertToken)) {
        sharedTrip = null;
        pathMatch = null;
        history.replaceState(null, '', '/?red=' + window.__bbNetwork);
    }
    let opened = Promise.resolve();
    if (pathMatch) {
        // El id llega codificado (Euskotren usa «ES:Euskotren:StopPlace:2577:»): sin descodificar, la API no encuentra la parada.
        let pathId = pathMatch[2];
        try {
            pathId = decodeURIComponent(pathId);
        } catch (e) { /* se usa tal cual */ }
        if (pathMatch[1] === 'stops') {
            opened = selectStop(pathId);
        } else if (pathMatch[1] === 'lines') {
            opened = selectLine(pathId);
        }
    }
    if (sharedTrip) opened.then(() => openVehicleModal(sharedTrip));
}

function removeLaunchParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('viaje');
    url.searchParams.delete('aviso');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
}
