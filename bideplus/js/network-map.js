// Mapa de la red: todas las líneas de las redes de Bide+ sobre su trazado, con el esquema de cada línea y la ficha de cada
// parada. Los datos (/map-data/*.json) se generan con las bases en cada reconstrucción; aquí solo se dibujan.
(() => {
    const DATA_URL = '/map-data/';
    // El mapa arranca vacío: las redes se ven al pulsar su filtro o al buscar una de sus líneas.
    const TRANSFER_METERS = 180;
    const BUS_STOPS_MIN_ZOOM = 13;
    const RAIL_STOPS_MIN_ZOOM = 0;
    // Con una línea elegida, las demás se quedan detrás pero se siguen viendo.
    const DIMMED_OPACITY = 0.25;
    const MAX_STOP_RESULTS = 8;

    const state = {
        map: null,
        renderer: null,
        index: [],
        networks: {},
        visible: new Set(),
        selectedLine: null,
        selectionLayer: null,
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

    function networkColor(slug) {
        const info = state.index.find((n) => n.slug === slug);
        if (!info) return '#555555';
        return info.color;
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
            // Mientras se cargaba se pudo volver a pulsar el filtro: manda lo último que se pidió.
            showNetwork(slug, state.visible.has(slug));
        } catch (error) {
            state.visible.delete(slug);
            if (button) button.setAttribute('aria-pressed', 'false');
        } finally {
            if (button) button.classList.remove('is-loading');
        }
        updateFiltersState();
        highlightSelection();
    }

    function updateFiltersState() {
        for (const button of document.querySelectorAll('[data-map-network]')) {
            const slug = button.dataset.mapNetwork;
            button.setAttribute('aria-pressed', String(state.visible.has(slug)));
        }
        const hint = document.getElementById('map-hint');
        if (hint) hint.hidden = state.visible.size > 0;
        const clearBtn = document.getElementById('map-clear-all');
        if (clearBtn) clearBtn.hidden = state.visible.size === 0;
    }

    async function clearAllNetworks() {
        for (const slug of [...state.visible]) {
            await toggleNetwork(slug, false);
        }
        closePanel();
        const searchInput = document.getElementById('map-search-input');
        if (searchInput) searchInput.value = '';
        const clearBtn = document.getElementById('map-search-clear');
        if (clearBtn) clearBtn.hidden = true;
    }

    // ---------- selección ----------

    function highlightSelection() {
        const selected = state.selectedLine;
        for (const network of Object.values(state.networks)) {
            for (const layer of network.lineLayers) {
                const isSelected = selected && layer.__line === selected.line;
                let opacity = lineStyle(layer.__bus).opacity;
                let weight = lineStyle(layer.__bus).weight;
                if (selected && !isSelected) opacity = DIMMED_OPACITY;
                if (isSelected) weight += 3;
                layer.setStyle({ opacity, weight });
                if (isSelected) layer.bringToFront();
            }
        }
    }

    // Margen para encuadrar una línea sin que la tape el panel: a la derecha en el ordenador, abajo en el móvil.
    function panelPadding() {
        const panel = document.getElementById('map-panel').getBoundingClientRect();
        if (window.innerWidth >= 760) return { paddingTopLeft: [40, 40], paddingBottomRight: [panel.width + 52, 40] };
        return { paddingTopLeft: [24, 24], paddingBottomRight: [24, panel.height + 24] };
    }

    function openPanel() {
        document.getElementById('map-panel').hidden = false;
        document.body.classList.add('has-panel');
    }

    function closePanel() {
        document.getElementById('map-panel').hidden = true;
        document.body.classList.remove('has-panel');
        state.selectedLine = null;
        const select = document.getElementById('map-line');
        if (select) select.value = '';
        drawSelection(null);
        highlightSelection();
    }

    // La línea elegida, encima de todo: su recorrido con un borde blanco y un punto en cada parada, a cualquier zoom.
    function drawSelection(selection) {
        if (state.selectionLayer) state.map.removeLayer(state.selectionLayer);
        state.selectionLayer = null;
        if (!selection) return;
        const { network, line, pattern } = selection;
        const layer = L.layerGroup();
        const casing = { renderer: state.renderer, color: '#ffffff', weight: 10, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false };
        const stroke = { renderer: state.renderer, color: line.color, weight: 6, opacity: 1, lineCap: 'round', lineJoin: 'round', interactive: false };
        L.polyline(pattern.path, casing).addTo(layer);
        L.polyline(pattern.path, stroke).addTo(layer);
        for (const stopId of pattern.stops) {
            const stop = network.data.stops[stopId];
            if (!stop) continue;
            const marker = L.circleMarker([stop[1], stop[2]], {
                renderer: state.renderer,
                radius: 5,
                color: line.color,
                weight: 3,
                fillColor: '#ffffff',
                fillOpacity: 1,
            });
            marker.bindTooltip(stop[0], { direction: 'top' });
            marker.on('click', () => selectStop(network.slug, stopId));
            marker.addTo(layer);
        }
        layer.addTo(state.map);
        state.selectionLayer = layer;
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

    // Transbordos de una parada en el esquema: las líneas de tren, metro y tranvía una a una; las de autobús, contadas.
    function transferChips(transfers, limit) {
        const wrap = el('span', 'map-transfers');
        const rail = transfers.filter((x) => !isBus(x.slug));
        const buses = transfers.length - rail.length;
        for (const transfer of rail.slice(0, limit)) {
            const chip = lineChip(transfer.line);
            chip.title = `${networkName(transfer.slug)} · ${transfer.line.name}`;
            wrap.appendChild(chip);
        }
        if (rail.length > limit) wrap.appendChild(el('span', 'map-more', `+${rail.length - limit}`));
        if (buses > 0) wrap.appendChild(el('span', 'map-bus-count', t('map.buses', { n: buses })));
        return wrap;
    }

    function selectLine(slug, line, patternIndex = 0) {
        state.selectedLine = { slug, line };
        const select = document.getElementById('map-line');
        if (select) select.value = `${slug}|${line.id}`;
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
            const network = state.networks[slug];
            // Cada recorrido por sus extremos («Bilbao → Balmaseda»): varios pueden ir al mismo destino.
            const labels = line.patterns.map((pattern) => {
                const first = network.data.stops[pattern.stops[0]];
                const last = network.data.stops[pattern.stops[pattern.stops.length - 1]];
                return `${first[0]} → ${last[0]}`;
            });
            line.patterns.forEach((pattern, index) => {
                let label = labels[index];
                if (labels.indexOf(label) !== labels.lastIndexOf(label)) label += ` · ${t('map.stopsCount', { n: pattern.stops.length })}`;
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
        drawSelection({ network, line, pattern });
        const list = el('ol', 'map-schematic');
        list.style.setProperty('--line-color', line.color);
        pattern.stops.forEach((stopId, index) => {
            const stop = network.data.stops[stopId];
            if (!stop) return;
            const item = el('li');
            // Minutos desde la parada anterior, en la barra entre las dos.
            if (index > 0 && pattern.mins && Number.isFinite(pattern.mins[index - 1])) {
                item.appendChild(el('span', 'map-min', `${pattern.mins[index - 1]}'`));
            }
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
        if (bounds.isValid()) state.map.fitBounds(bounds, { ...panelPadding(), maxZoom: 15 });
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

    // Selector de línea: todas las líneas de todas las redes, agrupadas por red. Elegir una de una red oculta la muestra.
    function buildLinePicker() {
        const select = document.getElementById('map-line');
        select.replaceChildren(el('option', null, t('map.pickLine')));
        select.firstChild.value = '';
        for (const info of state.index) {
            const group = el('optgroup');
            group.label = networkName(info.slug);
            for (const [id, code, name] of info.lines) {
                const option = el('option', null, `${code} · ${name}`);
                option.value = `${info.slug}|${id}`;
                group.appendChild(option);
            }
            select.appendChild(group);
        }
        select.addEventListener('change', async () => {
            if (!select.value) return;
            const [slug, id] = select.value.split('|');
            if (!state.visible.has(slug)) await toggleNetwork(slug, true);
            const line = state.networks[slug] && state.networks[slug].data.lines.find((l) => l.id === id);
            if (line) selectLine(slug, line);
        });
    }


    function normalize(str) {
        return (str || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function buildSearch() {
        const input = document.getElementById('map-search-input');
        const clear = document.getElementById('map-search-clear');
        const results = document.getElementById('map-search-results');
        if (!input || !results) return;

        let activeIndex = -1;

        function hideResults() {
            results.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function highlightResult(index) {
            const items = results.querySelectorAll('.map-search-item');
            if (!items.length) return;
            items.forEach((item, i) => {
                const isCurrent = i === index;
                item.classList.toggle('is-selected', isCurrent);
                if (isCurrent) {
                    item.scrollIntoView({ block: 'nearest' });
                    input.setAttribute('aria-activedescendant', item.id);
                }
            });
            activeIndex = index;
        }

        function doSearch() {
            const query = input.value.trim();
            if (clear) clear.hidden = query.length === 0;
            if (!query) {
                hideResults();
                return;
            }

            const normQ = normalize(query);
            const tokens = normQ.split(/\s+/).filter(Boolean);
            if (!tokens.length) {
                hideResults();
                return;
            }

            // 1. Líneas de todas las redes en state.index
            const lineMatches = [];
            for (const net of state.index) {
                const netName = networkName(net.slug);
                const normNet = normalize(netName);
                for (const line of net.lines) {
                    const [id, code, name, color] = line;
                    const normCode = normalize(code);
                    const normName = normalize(name);
                    const fullText = `${normCode} ${normName} ${normNet}`;
                    if (tokens.every((tok) => fullText.includes(tok))) {
                        let score = 10;
                        if (normCode === normQ) score = 0;
                        else if (normCode.startsWith(normQ)) score = 1;
                        else if (normCode.includes(normQ)) score = 2;
                        else if (normName.startsWith(normQ)) score = 3;
                        lineMatches.push({ score, net, id, code, name, color });
                    }
                }
            }

            lineMatches.sort((a, b) => a.score - b.score || a.code.localeCompare(b.code));

            // 2. Paradas de las redes cargadas (las de tren se cargan al empezar a buscar): primero las que empiezan así.
            const stopMatches = [];
            for (const [slug, net] of Object.entries(state.networks)) {
                for (const [stopId, stop] of Object.entries(net.data.stops)) {
                    const normStop = normalize(stop[0]);
                    if (tokens.every((tok) => normStop.includes(tok))) {
                        let score = 1;
                        if (normStop.startsWith(normQ)) score = 0;
                        stopMatches.push({ score, slug, stopId, name: stop[0] });
                    }
                }
            }
            stopMatches.sort((a, b) => a.score - b.score || a.name.localeCompare(b.name));
            // Una parada por nombre y red: los dos sentidos de una calle se llaman igual.
            const seenStops = new Set();
            const uniqueStops = stopMatches.filter((m) => {
                const key = `${m.slug}|${m.name}`;
                if (seenStops.has(key)) return false;
                seenStops.add(key);
                return true;
            });
            stopMatches.splice(0, stopMatches.length, ...uniqueStops.slice(0, MAX_STOP_RESULTS));

            results.replaceChildren();

            const totalMatches = lineMatches.slice(0, 20);
            if (totalMatches.length === 0 && stopMatches.length === 0) {
                const emptyItem = el('li', 'map-search-empty', t('map.search.empty'));
                results.appendChild(emptyItem);
                results.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                return;
            }

            totalMatches.forEach((m, idx) => {
                const li = el('li');
                const btn = el('button', 'map-search-item');
                btn.type = 'button';
                btn.id = `map-opt-${idx}`;
                btn.setAttribute('role', 'option');

                const chip = lineChip({ code: m.code, color: m.color });
                btn.appendChild(chip);

                const info = el('div', 'map-search-info');
                info.appendChild(el('span', 'map-search-title', m.name));
                info.appendChild(el('span', 'map-search-net', networkName(m.net.slug)));
                btn.appendChild(info);

                btn.addEventListener('click', async () => {
                    if (!state.visible.has(m.net.slug)) {
                        await toggleNetwork(m.net.slug, true);
                    }
                    const fullLine = state.networks[m.net.slug]?.data.lines.find((l) => l.id === m.id);
                    if (fullLine) {
                        selectLine(m.net.slug, fullLine);
                        input.value = `${m.code} · ${m.name}`;
                        if (clear) clear.hidden = false;
                    }
                    hideResults();
                });

                li.appendChild(btn);
                results.appendChild(li);
            });

            if (stopMatches.length > 0) {
                stopMatches.forEach((s, idx) => {
                    const li = el('li');
                    const btn = el('button', 'map-search-item is-stop');
                    btn.type = 'button';
                    btn.id = `map-opt-stop-${idx}`;
                    btn.setAttribute('role', 'option');

                    const dot = el('span', 'map-stop-dot');
                    dot.style.setProperty('--net-color', networkColor(s.slug));
                    btn.appendChild(dot);

                    const info = el('div', 'map-search-info');
                    info.appendChild(el('span', 'map-search-title', s.name));
                    info.appendChild(el('span', 'map-search-net', `${networkName(s.slug)} · ${t('map.search.stop')}`));
                    btn.appendChild(info);

                    btn.addEventListener('click', async () => {
                        if (!state.visible.has(s.slug)) await toggleNetwork(s.slug, true);
                        const stop = state.networks[s.slug]?.data.stops[s.stopId];
                        if (stop) {
                            state.map.setView([stop[1], stop[2]], Math.max(state.map.getZoom(), 15));
                            selectStop(s.slug, s.stopId);
                            input.value = s.name;
                            if (clear) clear.hidden = false;
                        }
                        hideResults();
                    });

                    li.appendChild(btn);
                    results.appendChild(li);
                });
            }

            results.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            activeIndex = -1;
        }

        // Las paradas de tren, metro y tranvía pesan poco: se cargan al buscar para encontrar estaciones de redes ocultas.
        let railLoading = null;
        function loadRailForSearch() {
            if (!railLoading) {
                const rail = state.index.filter((n) => n.kind !== 'bus').map((n) => loadNetwork(n.slug).catch(() => null));
                railLoading = Promise.all(rail).then(() => {
                    if (input.value.trim()) doSearch();
                });
            }
        }

        input.addEventListener('input', () => {
            loadRailForSearch();
            doSearch();
        });
        input.addEventListener('focus', () => {
            if (input.value.trim()) doSearch();
        });

        if (clear) {
            clear.addEventListener('click', () => {
                input.value = '';
                clear.hidden = true;
                hideResults();
                input.focus();
            });
        }

        input.addEventListener('keydown', (e) => {
            const items = results.querySelectorAll('.map-search-item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (results.hidden) doSearch();
                else {
                    const next = (activeIndex + 1) % items.length;
                    highlightResult(next);
                }
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!results.hidden && items.length > 0) {
                    const prev = (activeIndex - 1 + items.length) % items.length;
                    highlightResult(prev);
                }
            } else if (e.key === 'Enter') {
                if (!results.hidden && items.length > 0) {
                    e.preventDefault();
                    let target = items[0];
                    if (activeIndex >= 0) target = items[activeIndex];
                    if (target) target.click();
                }
            } else if (e.key === 'Escape') {
                if (!results.hidden) {
                    e.stopPropagation();
                    hideResults();
                }
            }
        });

        document.addEventListener('click', (e) => {
            const bar = document.getElementById('map-search-bar');
            if (bar && !bar.contains(e.target)) {
                hideResults();
            }
        });
    }

    function buildFilters() {
        const nav = document.getElementById('map-filters');
        nav.replaceChildren();
        for (const info of state.index) {
            const button = el('button', 'map-filter');
            button.type = 'button';
            button.dataset.mapNetwork = info.slug;
            button.setAttribute('aria-pressed', 'false');
            button.style.setProperty('--net-color', info.color);
            button.textContent = networkName(info.slug);
            button.addEventListener('click', () => toggleNetwork(info.slug, !state.visible.has(info.slug)));
            nav.appendChild(button);
        }
        const clearBtn = el('button', 'map-filter map-filter-clear', t('map.clearAll'));
        clearBtn.id = 'map-clear-all';
        clearBtn.type = 'button';
        clearBtn.hidden = true;
        clearBtn.addEventListener('click', clearAllNetworks);
        nav.appendChild(clearBtn);
    }

    async function start() {
        if (window.I18n) {
            document.documentElement.lang = I18n.getLang();
            I18n.applyTranslations(document);
            window.addEventListener('bide:lang', () => I18n.applyTranslations(document));
        }
        state.renderer = L.canvas({ padding: 0.5, tolerance: 6 });
        // Bizkaia y Gipuzkoa enteras; Renfe llega hasta León, pero eso no es lo que se busca al abrir el mapa.
        // zoomSnap de un cuarto: en el móvil Bizkaia y Gipuzkoa llenan la pantalla en vez de saltar un zoom entero hacia fuera.
        state.map = L.map('network-map', { zoomControl: true, preferCanvas: true, zoomSnap: 0.25, zoomDelta: 1 }).fitBounds([[42.83, -3.45], [43.45, -1.78]]);
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
            buildLinePicker();
            buildSearch();
            updateFiltersState();
            loading.hidden = true;
        } catch (error) {
            loading.textContent = t('map.error');
        }
    }

    // Para las pruebas de interfaz: abrir una línea o una parada sin tener que acertar con el ratón en el lienzo.
    window.BideMap = {
        selectLine: async (slug, lineId) => {
            if (!state.visible.has(slug)) {
                await toggleNetwork(slug, true);
            }
            const line = state.networks[slug] && state.networks[slug].data.lines.find((l) => l.id === lineId);
            if (line) selectLine(slug, line);
            return Boolean(line);
        },
        selectStop: async (slug, stopId) => {
            if (!state.visible.has(slug)) {
                await toggleNetwork(slug, true);
            }
            if (!state.networks[slug]) return false;
            selectStop(slug, String(stopId));
            return true;
        },
        loaded: () => Object.keys(state.networks),
        search: (query) => {
            const input = document.getElementById('map-search-input');
            if (input) {
                input.value = query;
                input.dispatchEvent(new Event('input'));
            }
        },
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
