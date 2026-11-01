// Mapa de la línea abierta en «Consultar horarios»: su recorrido y los vehículos en ruta, que se refrescan solos.
import { FEATURES, NETWORK_SLUG, el, onReturn, state } from './core.js';
import { escapeHtml } from './ui.js';

const mapState = {
    map: null,
    lineId: null,
    layers: [],
    refreshTimer: null,
};
const LINE_MAP_REFRESH_MS = 25000;

const MAP_BUS_ICON = '<svg viewBox="0 0 32 32"><rect x="3" y="3" width="26" height="26" rx="8" fill="currentColor"/><g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="8" width="14" height="14" rx="3"/><line x1="9" y1="14" x2="23" y2="14"/></g><circle cx="12.5" cy="24" r="1.6" fill="#ffffff"/><circle cx="19.5" cy="24" r="1.6" fill="#ffffff"/></svg>';

function busDivIcon() {
    return L.divIcon({ className: 'bus-marker', html: MAP_BUS_ICON, iconSize: [28, 28] });
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

// Recorridos y autobuses de una línea en el mapa. Devuelve las capas, para quitarlas, y los puntos, para encuadrar.
function drawLine(map, lineItem, data) {
    const routeLayers = [];
    const vehicleMarkers = [];
    const points = [];
    const lineColor = lineItem.color || '#01573c';

    for (const pattern of data.patterns || []) {
        const latlngs = (pattern.stops || []).map((s) => [s.lat, s.lon]);
        if (latlngs.length < 2) continue;
        routeLayers.push(L.polyline(latlngs, { color: lineColor, weight: 3.5, opacity: 0.65 }).addTo(map));
        points.push(...latlngs);
    }

    for (const vehicle of data.vehicles || []) {
        if (!vehicle.currentStop || vehicle.currentStop.lat === null || vehicle.currentStop.lon === null) continue;
        const { lat, lon, name } = vehicle.currentStop;
        const marker = L.marker([lat, lon], { icon: busDivIcon() })
            .addTo(map)
            .bindPopup(`<strong>${escapeHtml(vehicle.headsign || lineItem.name)}</strong><br>${escapeHtml(name)}<br>${escapeHtml(vehicleStatusText(vehicle))}`);
        vehicleMarkers.push(marker);
        points.push([lat, lon]);
    }

    return { routeLayers, vehicleMarkers, points, vehicles: data.vehicles || [] };
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

// Solo la línea abierta. Se encuadra al abrirla; al refrescar los autobuses, el mapa se queda donde lo dejaste.
function renderLineMap(data) {
    if (!data || !data.line) return;
    const net = BideNetworks.get(NETWORK_SLUG);
    const lineItem = {
        id: String(data.line.id),
        name: String(data.line.name),
        // El color de la app (el de la red, o el rosa de «mi amor»).
        color: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || net?.brand?.color || '#01573c',
    };

    const map = ensureLineMap();
    removeLayers();
    const drawn = drawLine(map, lineItem, data);
    mapState.layers = [...drawn.routeLayers, ...drawn.vehicleMarkers];
    el.lineMapEmpty.hidden = drawn.vehicles.length > 0;
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
    mapState.refreshTimer = setInterval(() => loadLineMap(lineId), LINE_MAP_REFRESH_MS);
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
    onReturn(() => {
        if (mapState.refreshTimer && state.currentLine) loadLineMap(state.currentLine.id);
    });
}
