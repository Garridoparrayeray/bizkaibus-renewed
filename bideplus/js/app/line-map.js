// Mapa de líneas: buscador, líneas activas, recorridos y vehículos en ruta.
import { FEATURES, NETWORK_SLUG, el, state } from './core.js';
import { escapeHtml } from './ui.js';

const mapState = {
    map: null,
    activeLines: new Map(),
    allLinesCache: null,
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

async function loadAllNetworkLines() {
    if (mapState.allLinesCache) return mapState.allLinesCache;
    const slugs = BideNetworks.slugs;
    const results = await Promise.all(
        slugs.map(async (slug) => {
            try {
                const res = await fetch('/api/lines?red=' + encodeURIComponent(slug));
                if (!res.ok) return [];
                const lines = await res.json();
                const net = BideNetworks.get(slug);
                const netName = BideNetworks.name(slug);
                const color = net?.brand?.color || '#01573c';
                return (lines || []).map((l) => ({
                    id: String(l.id),
                    code: String(l.code),
                    name: String(l.name),
                    network: slug,
                    networkName: netName,
                    color,
                }));
            } catch (e) {
                return [];
            }
        })
    );
    mapState.allLinesCache = results.flat();
    return mapState.allLinesCache;
}

function renderActiveChips() {
    el.mapActiveChips.innerHTML = '';
    const count = mapState.activeLines.size;
    el.mapClearAll.hidden = count < 2;

    for (const [key, item] of mapState.activeLines) {
        const chip = document.createElement('span');
        chip.className = 'map-chip';

        const dot = document.createElement('span');
        dot.className = 'map-chip-dot';
        dot.style.backgroundColor = item.color;

        const code = document.createElement('strong');
        code.textContent = item.code;

        const name = document.createElement('span');
        name.textContent = item.name;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'map-chip-remove';
        removeBtn.setAttribute('aria-label', (I18n.t('map.active.remove') || 'Quitar') + ' ' + item.code);
        removeBtn.textContent = '✕';
        removeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            removeMapLine(key);
        });

        chip.appendChild(dot);
        chip.appendChild(code);
        chip.appendChild(name);
        chip.appendChild(removeBtn);
        el.mapActiveChips.appendChild(chip);
    }

    updateMapViewState();
}

function updateMapViewState() {
    const map = ensureLineMap();
    const allPoints = [];
    let totalVehicles = 0;

    for (const item of mapState.activeLines.values()) {
        allPoints.push(...item.points);
        totalVehicles += (item.vehicles || []).length;
    }

    if (mapState.activeLines.size === 0) {
        el.lineMapEmpty.textContent = I18n.t('map.empty.hint');
        el.lineMapEmpty.hidden = false;
        map.setView([43.263, -2.935], 11);
    } else {
        el.lineMapEmpty.textContent = I18n.t('app.noLiveBuses');
        el.lineMapEmpty.hidden = totalVehicles > 0;
        if (allPoints.length > 0) {
            map.fitBounds(allPoints, { padding: [24, 24] });
        }
    }
    setTimeout(() => map.invalidateSize(), 100);
}

async function addMapLine(lineItem) {
    const key = `${lineItem.network}:${lineItem.id}`;
    if (mapState.activeLines.has(key)) return;

    const map = ensureLineMap();
    let data;
    try {
        const res = await fetch('/api/lines/' + encodeURIComponent(lineItem.id) + '/live?red=' + encodeURIComponent(lineItem.network));
        if (!res.ok) return;
        data = await res.json();
    } catch (e) {
        return;
    }

    mapState.activeLines.set(key, { ...lineItem, ...drawLine(map, lineItem, data) });
    renderActiveChips();
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
    if (vehicle.delayMinutes > 0) return `Retraso +${vehicle.delayMinutes}m`;
    return 'En hora';
}

function removeMapLine(key) {
    const item = mapState.activeLines.get(key);
    if (!item) return;

    const map = ensureLineMap();
    (item.routeLayers || []).forEach((layer) => map.removeLayer(layer));
    (item.vehicleMarkers || []).forEach((marker) => map.removeLayer(marker));
    mapState.activeLines.delete(key);

    renderActiveChips();
}

export function clearAllMapLines() {
    const map = ensureLineMap();
    for (const item of mapState.activeLines.values()) {
        (item.routeLayers || []).forEach((layer) => map.removeLayer(layer));
        (item.vehicleMarkers || []).forEach((marker) => map.removeLayer(marker));
    }
    mapState.activeLines.clear();
    renderActiveChips();
}

async function handleMapSearchInput(query) {
    const q = (query || '').trim().toLowerCase();
    if (!q) {
        el.mapSearchResults.hidden = true;
        el.mapSearchResults.innerHTML = '';
        el.mapSearchClear.hidden = true;
        return;
    }
    el.mapSearchClear.hidden = false;
    const allLines = await loadAllNetworkLines();
    const matches = allLines.filter((l) => {
        const code = l.code.toLowerCase();
        const name = l.name.toLowerCase();
        return code.includes(q) || name.includes(q);
    }).slice(0, 15);

    el.mapSearchResults.innerHTML = '';
    if (matches.length === 0) {
        const emptyLi = document.createElement('li');
        emptyLi.className = 'map-search-empty';
        emptyLi.textContent = I18n.t('map.search.empty');
        el.mapSearchResults.appendChild(emptyLi);
    } else {
        for (const item of matches) {
            const li = document.createElement('li');
            li.className = 'map-search-item';
            const key = `${item.network}:${item.id}`;
            const isActive = mapState.activeLines.has(key);
            if (isActive) li.classList.add('is-active');

            const badge = document.createElement('span');
            badge.className = 'map-badge';
            badge.style.backgroundColor = item.color;
            badge.textContent = item.networkName;

            const code = document.createElement('strong');
            code.className = 'map-line-code';
            code.textContent = item.code;

            const name = document.createElement('span');
            name.className = 'map-line-name';
            name.textContent = item.name;

            li.appendChild(badge);
            li.appendChild(code);
            li.appendChild(name);

            li.addEventListener('click', () => {
                if (isActive) {
                    removeMapLine(key);
                } else {
                    addMapLine(item);
                }
                el.mapSearchInput.value = '';
                el.mapSearchResults.hidden = true;
                el.mapSearchClear.hidden = true;
            });
            el.mapSearchResults.appendChild(li);
        }
    }
    el.mapSearchResults.hidden = false;
}

function renderLineMap(data) {
    if (!data || !data.line) return;
    const net = BideNetworks.get(NETWORK_SLUG);
    const lineItem = {
        id: String(data.line.id),
        code: String(data.line.code),
        name: String(data.line.name),
        network: NETWORK_SLUG,
        networkName: BideNetworks.name(NETWORK_SLUG),
        // El color de la app (el de la red, o el rosa de «mi amor»).
        color: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || net?.brand?.color || '#01573c',
    };

    const map = ensureLineMap();
    clearAllMapLines();
    mapState.activeLines.set(`${lineItem.network}:${lineItem.id}`, { ...lineItem, ...drawLine(map, lineItem, data) });
    renderActiveChips();
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
    let mapSearchDebounce = null;
    el.mapSearchInput.addEventListener('input', () => {
        clearTimeout(mapSearchDebounce);
        mapSearchDebounce = setTimeout(() => {
            handleMapSearchInput(el.mapSearchInput.value);
        }, 150);
    });

    el.mapSearchClear.addEventListener('click', () => {
        el.mapSearchInput.value = '';
        el.mapSearchResults.hidden = true;
        el.mapSearchClear.hidden = true;
        el.mapSearchInput.focus();
    });

    el.mapClearAll.addEventListener('click', () => {
        clearAllMapLines();
    });

    document.addEventListener('click', (e) => {
        if (el.mapSearchBar && !el.mapSearchBar.contains(e.target)) {
            el.mapSearchResults.hidden = true;
        }
    });

    if (!FEATURES.line_map) {
        el.lineMap.hidden = true;
        el.lineMapEmpty.hidden = true;
        el.mapSearchBar.hidden = true;
    }
}
