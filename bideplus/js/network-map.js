// Mapa de la red: todas las líneas de las redes de Bide+ sobre su trazado, con el esquema de cada línea y la ficha de cada
// parada. Los datos (/map-data/*.json) se generan con las bases en cada reconstrucción; aquí solo se dibujan.
(() => {
    const DATA_URL = '/map-data/';
    // Las redes de tren se ven al entrar; las de autobús, más pesadas, al activarlas.
    const DEFAULT_ON = ['metro', 'euskotren', 'renfe', 'tranvia-bilbao', 'tranvia-vitoria'];
    const TRANSFER_METERS = 180;
    const BUS_STOPS_MIN_ZOOM = 14;
    const RAIL_STOPS_MIN_ZOOM = 11;

    const state = {
        map: null,
        renderer: null,
        index: [],
        networks: {},
        visible: new Set(),
        selectedLine: null,
    };

    const t = (key, vars) => {
        if (window.I18n) return I18n.t(key, vars);
        return key;
    };
    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };

    // Trazo de una línea: las de autobús más finas para que no tapen a las de tren.
    function lineStyle(bus) {
        if (bus) return { weight: 2.5, opacity: 0.7, radius: 3, stopsMinZoom: BUS_STOPS_MIN_ZOOM };
        return { weight: 4.5, opacity: 0.95, radius: 4.5, stopsMinZoom: RAIL_STOPS_MIN_ZOOM };
    }

    function isBus(slug) {
        const info = state.index.find((n) => n.slug === slug);
        return !info || info.kind === 'bus';
    }

    function distanceMeters(a, b) {
        const lat = ((a[0] + b[0]) / 2) * Math.PI / 180;
        const dx = (b[1] - a[1]) * 111320 * Math.cos(lat);
        const dy = (b[0] - a[0]) * 110540;
        return Math.hypot(dx, dy);
    }

    function lineChip(line) {
        const chip = el('span', 'map-chip', line.code);
        chip.style.background = line.color;
        chip.style.color = line.textColor || readableText(line.color);
        return chip;
    }

    function readableText(hex) {
        const value = parseInt((hex || '#555555').slice(1), 16);
        const r = (value >> 16) & 255;
        const g = (value >> 8) & 255;
        const b = value & 255;
        if ((0.299 * r + 0.587 * g + 0.114 * b) > 160) return '#111111';
        return '#ffffff';
    }

    function networkName(slug) {
        const info = state.index.find((n) => n.slug === slug);
        if (!info) return slug;
        if (document.documentElement.lang === 'eu' && info.nameEu) return info.nameEu;
        return info.name;
    }

    // ---------- carga ----------

    async function loadNetwork(slug) {
        if (state.networks[slug]) return state.networks[slug];
        const response = await fetch(DATA_URL + slug + '.json');
        if (!response.ok) throw new Error('map-data ' + slug + ' ' + response.status);
        const data = await response.json();
        const stopLines = {};
        for (const line of data.lines) {
            for (const pattern of line.patterns) {
                for (const stopId of pattern.stops) {
                    (stopLines[stopId] ||= new Set()).add(line);
                }
            }
        }
        const network = { slug, data, stopLines, lineLayers: [], stopLayer: L.layerGroup() };
        drawNetwork(network);
        state.networks[slug] = network;
        return network;
    }

    function drawNetwork(network) {
        const bus = isBus(network.slug);
        for (const line of network.data.lines) {
            for (const pattern of line.patterns) {
                const polyline = L.polyline(pattern.path, {
                    renderer: state.renderer,
                    color: line.color,
                    weight: lineStyle(bus).weight,
                    opacity: lineStyle(bus).opacity,
                    lineCap: 'round',
                    lineJoin: 'round',
                });
                polyline.bindTooltip(`${line.code} · ${line.name}`, { sticky: true, direction: 'top' });
                polyline.on('click', () => selectLine(network.slug, line));
                polyline.__line = line;
                polyline.__bus = bus;
                network.lineLayers.push(polyline);
            }
        }
        for (const [stopId, stop] of Object.entries(network.data.stops)) {
            const lines = [...(network.stopLines[stopId] || [])];
            let color = '#1c211d';
        if (lines.length === 1) color = lines[0].color;
            const marker = L.circleMarker([stop[1], stop[2]], {
                renderer: state.renderer,
                radius: lineStyle(bus).radius,
                color,
                weight: 2,
                fillColor: '#ffffff',
                fillOpacity: 1,
            });
            marker.bindTooltip(stop[0], { direction: 'top' });
            marker.on('click', () => selectStop(network.slug, stopId));
            network.stopLayer.addLayer(marker);
        }
    }

    function showNetwork(slug, visible) {
        const network = state.networks[slug];
        if (!network) return;
        for (const layer of network.lineLayers) {
            if (visible) layer.addTo(state.map);
            else state.map.removeLayer(layer);
        }
        updateStopLayers();
    }

    function updateStopLayers() {
        const zoom = state.map.getZoom();
        for (const network of Object.values(state.networks)) {
            const minZoom = lineStyle(isBus(network.slug)).stopsMinZoom;
            const show = state.visible.has(network.slug) && zoom >= minZoom;
            if (show && !state.map.hasLayer(network.stopLayer)) network.stopLayer.addTo(state.map);
            if (!show && state.map.hasLayer(network.stopLayer)) state.map.removeLayer(network.stopLayer);
        }
    }

    async function toggleNetwork(slug, on) {
        const button = document.querySelector(`[data-map-network="${slug}"]`);
        if (on) state.visible.add(slug);
        else state.visible.delete(slug);
        if (button) button.setAttribute('aria-pressed', String(on));
        try {
            if (on) {
                if (button) button.classList.add('is-loading');
                await loadNetwork(slug);
            }
            showNetwork(slug, on);
        } catch (error) {
            state.visible.delete(slug);
            if (button) button.setAttribute('aria-pressed', 'false');
        } finally {
            if (button) button.classList.remove('is-loading');
        }
        highlightSelection();
    }

    // ---------- selección ----------

    function highlightSelection() {
        const selected = state.selectedLine;
        for (const network of Object.values(state.networks)) {
            for (const layer of network.lineLayers) {
                const isSelected = selected && layer.__line === selected.line;
                let opacity = lineStyle(layer.__bus).opacity;
                let weight = lineStyle(layer.__bus).weight;
                if (selected && !isSelected) opacity = 0.18;
                if (isSelected) weight += 3;
                layer.setStyle({ opacity, weight });
                if (isSelected) layer.bringToFront();
            }
        }
    }

    function openPanel() {
        document.getElementById('map-panel').hidden = false;
        document.body.classList.add('has-panel');
    }

    function closePanel() {
        document.getElementById('map-panel').hidden = true;
        document.body.classList.remove('has-panel');
        state.selectedLine = null;
        highlightSelection();
    }

    // Transbordos de una parada: otras líneas en la misma parada y paradas cercanas de las redes cargadas.
    function transfersFor(slug, stopId, excludeLine) {
        const network = state.networks[slug];
        const stop = network.data.stops[stopId];
        const out = [];
        for (const line of network.stopLines[stopId] || []) {
            if (line !== excludeLine) out.push({ slug, line, stopId });
        }
        for (const other of Object.values(state.networks)) {
            for (const [otherId, otherStop] of Object.entries(other.data.stops)) {
                if (other.slug === slug && otherId === stopId) continue;
                if (other.slug === slug && isBus(slug)) continue;
                if (distanceMeters([stop[1], stop[2]], [otherStop[1], otherStop[2]]) > TRANSFER_METERS) continue;
                for (const line of other.stopLines[otherId] || []) {
                    if (line === excludeLine || out.some((x) => x.line === line)) continue;
                    out.push({ slug: other.slug, line, stopId: otherId });
                }
            }
        }
        return out;
    }

    function transferChips(transfers, limit) {
        const wrap = el('span', 'map-transfers');
        for (const transfer of transfers.slice(0, limit)) {
            const chip = lineChip(transfer.line);
            chip.title = `${networkName(transfer.slug)} · ${transfer.line.name}`;
            wrap.appendChild(chip);
        }
        if (transfers.length > limit) wrap.appendChild(el('span', 'map-more', `+${transfers.length - limit}`));
        return wrap;
    }

    function selectLine(slug, line, patternIndex = 0) {
        state.selectedLine = { slug, line };
        highlightSelection();
        const body = document.getElementById('map-panel-body');
        body.replaceChildren();

        const head = el('header', 'map-panel-head');
        head.appendChild(lineChip(line));
        const titles = el('div');
        titles.appendChild(el('h2', null, line.name));
        titles.appendChild(el('p', 'map-network', networkName(slug)));
        head.appendChild(titles);
        body.appendChild(head);

        if (line.patterns.length > 1) {
            const tabs = el('div', 'map-patterns');
            line.patterns.forEach((pattern, index) => {
                const network = state.networks[slug];
                const last = network.data.stops[pattern.stops[pattern.stops.length - 1]];
                let label = pattern.headsign;
                if (!label && last) label = last[0];
                if (!label) label = String(index + 1);
                const button = el('button', 'map-pattern', label);
                button.type = 'button';
                button.setAttribute('aria-pressed', String(index === patternIndex));
                button.addEventListener('click', () => selectLine(slug, line, index));
                tabs.appendChild(button);
            });
            body.appendChild(tabs);
        }

        const pattern = line.patterns[patternIndex];
        const network = state.networks[slug];
        const list = el('ol', 'map-schematic');
        list.style.setProperty('--line-color', line.color);
        pattern.stops.forEach((stopId, index) => {
            const stop = network.data.stops[stopId];
            if (!stop) return;
            const item = el('li');
            if (index === 0 || index === pattern.stops.length - 1) item.classList.add('is-end');
            const button = el('button', 'map-stop');
            button.type = 'button';
            button.appendChild(el('span', 'map-stop-name', stop[0]));
            const transfers = transfersFor(slug, stopId, line);
            if (transfers.length) button.appendChild(transferChips(transfers, 6));
            button.addEventListener('click', () => {
                state.map.setView([stop[1], stop[2]], Math.max(state.map.getZoom(), 15));
                selectStop(slug, stopId);
            });
            item.appendChild(button);
            list.appendChild(item);
        });
        body.appendChild(list);

        const link = el('a', 'map-action', t('map.openLine'));
        // L1 y L2 de Metro son una sola línea («MB») en sus datos.
        let lineId = line.id;
        if (slug === 'metro') lineId = 'MB';
        link.href = `/lines/${encodeURIComponent(lineId)}?red=${slug}`;
        body.appendChild(link);

        openPanel();
        const bounds = L.latLngBounds(pattern.path);
        if (bounds.isValid()) state.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
    }

    function selectStop(slug, stopId) {
        const network = state.networks[slug];
        const stop = network.data.stops[stopId];
        if (!stop) return;
        const body = document.getElementById('map-panel-body');
        body.replaceChildren();

        const head = el('header', 'map-panel-head');
        const titles = el('div');
        titles.appendChild(el('h2', null, stop[0]));
        titles.appendChild(el('p', 'map-network', networkName(slug)));
        head.appendChild(titles);
        body.appendChild(head);

        body.appendChild(el('h3', 'map-subtitle', t('map.linesHere')));
        const lines = el('div', 'map-line-list');
        for (const line of network.stopLines[stopId] || []) {
            const button = el('button', 'map-line-row');
            button.type = 'button';
            button.appendChild(lineChip(line));
            button.appendChild(el('span', null, line.name));
            button.addEventListener('click', () => selectLine(slug, line));
            lines.appendChild(button);
        }
        body.appendChild(lines);

        const nearby = transfersFor(slug, stopId, null).filter((x) => x.slug !== slug);
        if (nearby.length) {
            body.appendChild(el('h3', 'map-subtitle', t('map.transfers')));
            const transfers = el('div', 'map-line-list');
            for (const transfer of nearby) {
                const otherStop = state.networks[transfer.slug].data.stops[transfer.stopId];
                const button = el('button', 'map-line-row');
                button.type = 'button';
                button.appendChild(lineChip(transfer.line));
                button.appendChild(el('span', null, `${otherStop[0]} · ${networkName(transfer.slug)}`));
                button.addEventListener('click', () => selectLine(transfer.slug, transfer.line));
                transfers.appendChild(button);
            }
            body.appendChild(transfers);
        }

        const link = el('a', 'map-action', t('map.openStop'));
        link.href = `/stops/${encodeURIComponent(stopId)}?red=${slug}`;
        body.appendChild(link);
        openPanel();
    }

    // ---------- arranque ----------

    function buildFilters() {
        const nav = document.getElementById('map-filters');
        nav.replaceChildren();
        for (const info of state.index) {
            const button = el('button', 'map-filter');
            button.type = 'button';
            button.dataset.mapNetwork = info.slug;
            button.setAttribute('aria-pressed', 'false');
            const dot = el('span', 'map-filter-dot');
            dot.style.background = info.color;
            button.appendChild(dot);
            button.appendChild(el('span', null, networkName(info.slug)));
            button.addEventListener('click', () => toggleNetwork(info.slug, !state.visible.has(info.slug)));
            nav.appendChild(button);
        }
    }

    async function start() {
        if (window.I18n) {
            document.documentElement.lang = I18n.getLang();
            I18n.applyTranslations(document);
        }
        state.renderer = L.canvas({ padding: 0.5, tolerance: 6 });
        // Bizkaia y Gipuzkoa enteras; Renfe llega hasta León, pero eso no es lo que se busca al abrir el mapa.
        state.map = L.map('network-map', { zoomControl: true, preferCanvas: true }).fitBounds([[42.83, -3.45], [43.45, -1.78]]);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
        }).addTo(state.map);
        state.map.on('zoomend', updateStopLayers);
        document.getElementById('map-panel-close').addEventListener('click', closePanel);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closePanel();
        });

        const loading = document.getElementById('map-loading');
        try {
            const response = await fetch(DATA_URL + 'index.json');
            state.index = (await response.json()).networks;
            buildFilters();
            await Promise.all(state.index.filter((n) => DEFAULT_ON.includes(n.slug)).map((n) => toggleNetwork(n.slug, true)));
            loading.hidden = true;
        } catch (error) {
            loading.textContent = t('map.error');
        }
    }

    // Para las pruebas de interfaz: abrir una línea o una parada sin tener que acertar con el ratón en el lienzo.
    window.BideMap = {
        selectLine: (slug, lineId) => {
            const line = state.networks[slug] && state.networks[slug].data.lines.find((l) => l.id === lineId);
            if (line) selectLine(slug, line);
            return Boolean(line);
        },
        selectStop: (slug, stopId) => {
            if (!state.networks[slug]) return false;
            selectStop(slug, String(stopId));
            return true;
        },
        loaded: () => Object.keys(state.networks),
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
