// Mapa de la línea abierta en «Consultar horarios»: su recorrido, parada a parada.
import { FEATURES, NETWORK_SLUG, el, state } from './core.js';

const mapState = {
    map: null,
    lineId: null,
    layers: [],
};

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

function cssColor(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

// Los recorridos de una línea en el mapa. Devuelve las capas, para quitarlas, y los puntos, para encuadrar.
function drawLine(map, color, data) {
    const routeLayers = [];
    const points = [];
    for (const pattern of data.patterns || []) {
        const latlngs = (pattern.stops || []).map((s) => [s.lat, s.lon]);
        if (latlngs.length < 2) continue;
        routeLayers.push(L.polyline(latlngs, { color, weight: 3.5, opacity: 0.65 }).addTo(map));
        points.push(...latlngs);
    }
    return { routeLayers, points };
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

function renderLineMap(data) {
    if (!data || !data.line) return;
    const net = BideNetworks.get(NETWORK_SLUG);
    // El color de la app (el de la red, o el rosa de «mi amor»).
    const color = cssColor('--primary') || net?.brand?.color || cssColor('--bide-green');
    const map = ensureLineMap();
    removeLayers();
    const drawn = drawLine(map, color, data);
    mapState.layers = drawn.routeLayers;
    mapState.lineId = String(data.line.id);
    // Se encuadra cuando el mapa ya tiene su tamaño: la tarjeta acaba de aparecer.
    setTimeout(() => {
        map.invalidateSize();
        if (drawn.points.length > 0) map.fitBounds(drawn.points, { padding: [24, 24] });
    }, 100);
}

export async function loadLineMap(lineId) {
    let data;
    try {
        data = await Api.lineRoute(lineId);
    } catch (e) {
        return;
    }
    if (state.currentLine?.id !== lineId) return;
    renderLineMap(data);
}

export function setUpLineMap() {
    if (!FEATURES.line_map) el.lineMap.hidden = true;
}
