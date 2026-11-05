// Mapa de la línea abierta en «Consultar horarios»: su recorrido y los vehículos en ruta, que se refrescan solos.
import { FEATURES, NETWORK, NETWORK_SLUG, el, onReturn, state, whenVisible } from './core.js';
import { escapeHtml } from './ui.js';

const mapState = {
    map: null,
    lineId: null,
    layers: [],
    refreshTimer: null,
};
const LINE_MAP_REFRESH_MS = 25000;

const MAP_BUS_ICON = '<svg viewBox="0 0 32 32"><rect x="3" y="3" width="26" height="26" rx="8" fill="currentColor"/><g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="8" width="14" height="14" rx="3"/><line x1="9" y1="14" x2="23" y2="14"/></g><circle cx="12.5" cy="24" r="1.6" fill="#ffffff"/><circle cx="19.5" cy="24" r="1.6" fill="#ffffff"/></svg>';

const MAP_TRAIN_ICON = '<svg viewBox="0 0 32 32"><rect x="3" y="3" width="26" height="26" rx="8" fill="currentColor"/><g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="10" y="7" width="12" height="15" rx="4"/><line x1="10" y1="14" x2="22" y2="14"/><line x1="13" y1="22" x2="11" y2="25"/><line x1="19" y1="22" x2="21" y2="25"/></g><circle cx="13" cy="18" r="1.2" fill="#ffffff"/><circle cx="19" cy="18" r="1.2" fill="#ffffff"/></svg>';
const IS_BUS = NETWORK.kind === 'bus';

function vehicleDivIcon() {
    let html = MAP_TRAIN_ICON;
    if (IS_BUS) html = MAP_BUS_ICON;
    return L.divIcon({ className: 'bus-marker', html, iconSize: [28, 28] });
}

function ensureLineMap() {
    if (mapState.map) return mapState.map;
    // attributionControl activo a propósito: la licencia ODbL de OpenStreetMap
    // exige que la atribución sea visible, no solo estar puesta en el string.
    mapState.map = L.map('line-map', { zoomControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
    }).addTo(mapState.map);
    mapState.map.attributionControl.setPrefix(false);
    mapState.map.setView([43.263, -2.935], 11);
    return mapState.map;
}

// Recorridos y vehículos de una línea en el mapa. Devuelve las capas, para quitarlas, y los puntos, para encuadrar.
// Los trenes van en su posición GPS; los autobuses, en su parada.
function cssColor(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function drawLine(map, lineItem, data) {
    const routeLayers = [];
    const vehicleMarkers = [];
    const points = [];
    const lineColor = lineItem.color || cssColor('--bide-green');

    for (const pattern of data.patterns || []) {
        const latlngs = (pattern.stops || []).map((s) => [s.lat, s.lon]);
        if (latlngs.length < 2) continue;
        routeLayers.push(L.polyline(latlngs, { color: lineColor, weight: 3.5, opacity: 0.65 }).addTo(map));
        points.push(...latlngs);
    }

    for (const vehicle of data.vehicles || []) {
        let place = vehicle.currentStop;
        if (vehicle.position) place = vehicle.position;
        if (!place || place.lat === null || place.lon === null) continue;
        const { lat, lon } = place;
        const marker = L.marker([lat, lon], { icon: vehicleDivIcon() })
            .addTo(map)
            .bindPopup(`<strong>${escapeHtml(vehicle.headsign || lineItem.name)}</strong><br>${escapeHtml(stopText(vehicle))}<br>${escapeHtml(vehicleStatusText(vehicle))}`);
        vehicleMarkers.push(marker);
        points.push([lat, lon]);
    }

    return { routeLayers, vehicleMarkers, points, vehicles: data.vehicles || [] };
}

// «En Ametzola», «Llegando a Ametzola», «Hacia Ametzola» o solo la estación, si el operador no lo dice.
function stopText(vehicle) {
    const name = vehicle.currentStop?.name || '';
    if (!name || !vehicle.stopStatus) return name;
    return I18n.t(`app.map.${vehicle.stopStatus}`, { stop: name });
}

function vehicleStatusText(vehicle) {
    if (vehicle.delayMinutes === null) return '';
    if (vehicle.delayMinutes > 0) return I18n.t('app.delay.late', { min: vehicle.delayMinutes });
    return I18n.t('app.status.onTime');
}

function removeLayers() {
    if (!mapState.map) return;
    mapState.layers.forEach((layer) => mapState.map.removeLayer(layer));
    mapState.layers = [];
}

// Al cerrar la línea: se quita del mapa y, al abrir otra (o la misma), se vuelve a encuadrar.
export function clearLineMap() {
    removeLayers();
    mapState.lineId = null;
}

// Solo la línea abierta. Se encuadra al abrirla; al refrescar los vehículos, el mapa se queda donde lo dejaste.
function renderLineMap(data) {
    if (!data || !data.line) return;
    const net = BideNetworks.get(NETWORK_SLUG);
    const lineItem = {
        id: String(data.line.id),
        name: String(data.line.name),
        // El color de la app (el de la red, o el rosa de «mi amor»).
        color: cssColor('--primary') || net?.brand?.color || cssColor('--bide-green'),
    };

    const map = ensureLineMap();
    removeLayers();
    const drawn = drawLine(map, lineItem, data);
    mapState.layers = [...drawn.routeLayers, ...drawn.vehicleMarkers];
    // Sin posiciones de la red (Euskotren, tranvías), no se dice que no circula nada.
    el.lineMapEmpty.hidden = !data.tracked || drawn.vehicles.length > 0;
    const firstView = mapState.lineId !== lineItem.id && drawn.points.length > 0;
    if (firstView) mapState.lineId = lineItem.id;
    // Se encuadra cuando el mapa ya tiene su tamaño: la tarjeta acaba de aparecer.
    setTimeout(() => {
        map.invalidateSize();
        if (firstView) map.fitBounds(drawn.points, { padding: [24, 24] });
    }, 100);
}

export async function loadLineMap(lineId) {
    let data;
    try {
        data = await Api.lineLive(lineId);
    } catch (e) {
        return;
    }
    if (state.currentLine?.id !== lineId) return;
    renderLineMap(data);
}

export function startLineMapRefresh(lineId) {
    stopLineMapRefresh();
    mapState.refreshTimer = setInterval(whenVisible(() => loadLineMap(lineId)), LINE_MAP_REFRESH_MS);
}

export function stopLineMapRefresh() {
    if (mapState.refreshTimer) clearInterval(mapState.refreshTimer);
    mapState.refreshTimer = null;
}

export function setUpLineMap() {
    if (!FEATURES.line_map) {
        el.lineMap.hidden = true;
        el.lineMapEmpty.hidden = true;
    }
    if (!IS_BUS) {
        el.lineMapEmpty.dataset.i18n = 'app.noLiveTrains';
        el.lineMapEmpty.textContent = I18n.t('app.noLiveTrains');
    }
    onReturn(() => {
        if (mapState.refreshTimer && state.currentLine) loadLineMap(state.currentLine.id);
    });
}
