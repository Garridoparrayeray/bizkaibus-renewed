// Cerrar la parada o la línea, el menú de redes y la navegación con el historial (atrás, enlaces /stops y /lines).
import { el, networkQuery, state } from './core.js';
import { clearAllMapLines, stopLineMapRefresh } from './line-map.js';
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
    clearAllMapLines();
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

    const pathMatch = window.location.pathname.match(/^\/(stops|lines)\/([^/]+)/);
    const sharedTrip = new URLSearchParams(window.location.search).get('viaje');
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
    if (sharedTrip) opened.then(() => openSharedTrip(sharedTrip));
}

// Un viaje compartido («?viaje=») se abre una vez y se quita de la dirección.
async function openSharedTrip(tripKey) {
    await openVehicleModal(tripKey);
    const url = new URL(window.location.href);
    url.searchParams.delete('viaje');
    history.replaceState(history.state, '', url.pathname + url.search);
}
