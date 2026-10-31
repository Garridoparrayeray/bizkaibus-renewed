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
    if (alertToken && !firstOpening(alertToken)) {
        // Esa notificación ya se abrió: es el sistema recargando la dirección con la que se abrió la app. Se queda en
        // el inicio, sin la parada ni la ficha del viaje.
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
// recargar ya no vuelve a salir.
function removeLaunchParams() {
    const url = new URL(window.location.href);
    url.searchParams.delete('viaje');
    url.searchParams.delete('aviso');
    history.replaceState(history.state, '', url.pathname + url.search);
}

// Una notificación abre su viaje una sola vez: su dirección lleva «?aviso=», distinto en cada envío, y se apunta al
// abrirla. Android, al reabrir la app, puede volver a cargar la dirección con la que se abrió: entonces ya no sale.
const OPENED_ALERTS_KEY = 'bide-opened-alerts';
const OPENED_ALERTS_KEEP_MS = 7 * 24 * 3600 * 1000;

function firstOpening(token) {
    let opened = {};
    try {
        opened = JSON.parse(localStorage.getItem(OPENED_ALERTS_KEY) || '{}') || {};
    } catch (e) {
        opened = {};
    }
    if (opened[token]) return false;
    for (const [key, at] of Object.entries(opened)) {
        if (Date.now() - at > OPENED_ALERTS_KEEP_MS) delete opened[key];
    }
    opened[token] = Date.now();
    try {
        localStorage.setItem(OPENED_ALERTS_KEY, JSON.stringify(opened));
    } catch (e) {
        // Sin almacenamiento se abre igual: la dirección ya no lleva el viaje.
    }
    return true;
}
