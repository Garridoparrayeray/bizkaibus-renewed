// Cerrar la parada o la línea, el menú de redes y la navegación con el historial (atrás, enlaces /stops y /lines).
import { el, networkQuery, state } from './core.js';
import { clearLineMap, stopLineMapRefresh } from './line-map.js';
import { selectStop, stopDeparturesRefresh } from './stop.js';
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

export function closeLiveCard() {
    state.currentStop = null;
    el.liveCard.hidden = true;
    el.platformPanel.hidden = true;
    el.liveEmpty.hidden = false;
    stopDeparturesRefresh();
    if (state.currentLine && state.timetableStopId !== null) {
        closeTimetableSection();
    }
    if (window.location.pathname.startsWith('/stops/')) history.replaceState(null, '', '/?red=' + window.__bbNetwork);
}

export function closeTimetableSection() {
    state.currentLine = null;
    state.timetableStopId = null;
    el.timetableSection.hidden = true;
    stopLineMapRefresh();
    clearLineMap();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (window.location.pathname.startsWith('/lines/')) {
        if (state.currentStop) {
            history.replaceState({ view: 'stop', id: state.currentStop.id, fromApp: true }, '', '/stops/' + state.currentStop.id + networkQuery);
        } else {
            history.replaceState(null, '', '/?red=' + window.__bbNetwork);
        }
    }
}

export function closeFromButton(view, close) {
    if (history.state && history.state.view === view && history.state.fromApp) {
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

export function startRouting() {
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
        // Esa notificación ya se abrió: es el sistema recargando la dirección con la que se abrió la app. Se queda en
        // el inicio, sin la parada, la ficha del viaje ni la pestaña Avisos.
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

// Un viaje compartido o de una notificación («?viaje=») se abre al cargar y se quita en el acto de la dirección: al
// recargar ya no vuelve a salir. La pestaña que pida la notificación (#avisos) se queda: la abre js/bide.js.
function removeLaunchParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('viaje');
    url.searchParams.delete('aviso');
    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
}
