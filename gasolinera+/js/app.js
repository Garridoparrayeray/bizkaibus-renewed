(function () {
    'use strict';

    const FUEL_LABELS = {
        gasoleo_a: 'Gasóleo A',
        gasolina_95_e5: 'Gasolina 95',
        gasoleo_premium: 'Gasóleo Premium',
        gasolina_98_e5: 'Gasolina 98',
        adblue: 'AdBlue',
        glp: 'GLP',
        gasoleo_b: 'Gasóleo B',
        gasolina_95_e5_premium: 'Gasolina 95 Premium',
        gnc: 'Gas Natural Comprimido',
        gnl: 'Gas Natural Licuado',
        hidrogeno: 'Hidrógeno',
        biodiesel: 'Biodiésel',
        bioetanol: 'Bioetanol',
    };

    const TREND_SYMBOL = { up: '▲', down: '▼', same: '=' };

    const PAGE_SIZE = 30;

    const el = {
        searchForm: document.getElementById('search-form'),
        searchInput: document.getElementById('search-input'),
        filterFuel: document.getElementById('filter-fuel'),
        filterRadius: document.getElementById('filter-radius'),
        filterSort: document.getElementById('filter-sort'),
        filterOpen: document.getElementById('filter-open'),
        viewListBtn: document.getElementById('view-list-btn'),
        viewMapBtn: document.getElementById('view-map-btn'),
        viewList: document.getElementById('view-list'),
        viewMap: document.getElementById('view-map'),
        stationsList: document.getElementById('stations-list'),
        stationsEmpty: document.getElementById('stations-empty'),
        stationsLoadSentinel: document.getElementById('stations-load-sentinel'),
        geoFallback: document.getElementById('geo-fallback'),
        geoRetry: document.getElementById('geo-retry'),
        heatmapToggle: document.getElementById('heatmap-toggle'),
        map: document.getElementById('map'),
        stationModal: document.getElementById('station-modal'),
        modalClose: document.getElementById('modal-close'),
        modalRotulo: document.getElementById('modal-rotulo'),
        modalDireccion: document.getElementById('modal-direccion'),
        modalMunicipio: document.getElementById('modal-municipio'),
        modalHorario: document.getElementById('modal-horario'),
        modalFuels: document.getElementById('modal-fuels'),
        modalZoneComparison: document.getElementById('modal-zone-comparison'),
        modalChart: document.getElementById('modal-chart'),
        modalDirections: document.getElementById('modal-directions'),
        modalCompareToggle: document.getElementById('modal-compare-toggle'),
        compareOpen: document.getElementById('compare-open'),
        compareCount: document.getElementById('compare-count'),
        comparePanel: document.getElementById('compare-panel'),
        compareClose: document.getElementById('compare-close'),
        compareEmpty: document.getElementById('compare-empty'),
        compareTable: document.getElementById('compare-table'),
        nationalToggle: document.getElementById('national-stats-toggle'),
        nationalDetail: document.getElementById('national-stats-detail'),
        nationalGasoleoA: document.getElementById('national-gasoleo-a'),
        nationalGasolina95: document.getElementById('national-gasolina-95'),
        nationalChart: document.getElementById('national-chart'),
        nationalNote: document.getElementById('national-stats-note'),
    };

    const state = {
        userLat: null,
        userLon: null,
        currentStations: [],
        stationsMode: null,
        stationsQuery: '',
        stationsOffset: 0,
        stationsHasMore: false,
        stationsLoading: false,
        currentView: 'list',
        mapReady: false,
        map: null,
        markerLayer: null,
        heatLayer: null,
        heatVisible: false,
        userMarker: null,
        chart: null,
        nationalChart: null,
        nationalSeries: {},
        compareList: loadCompareList(),
    };

    // ---- Comparador (localStorage) ----

    function loadCompareList() {
        try {
            const raw = localStorage.getItem('gasolinera_compare');
            let parsed = [];
            if (raw) {
                parsed = JSON.parse(raw);
            }
            if (Array.isArray(parsed)) {
                return parsed;
            }
            return [];
        } catch (e) {
            return [];
        }
    }

    function saveCompareList() {
        try {
            localStorage.setItem('gasolinera_compare', JSON.stringify(state.compareList));
        } catch (e) {
            // localStorage puede fallar en navegación privada; el comparador
            // sigue funcionando en memoria durante la sesión, solo no persiste.
        }
        updateCompareCount();
    }

    function isInCompareList(ideess) {
        return state.compareList.some((s) => s.ideess === ideess);
    }

    function compareToggleLabel(ideess) {
        if (isInCompareList(ideess)) {
            return 'Quitar de comparar';
        }
        return 'Añadir a comparar';
    }

    function addToCompare(ideess, rotulo, direccion) {
        if (isInCompareList(ideess) || state.compareList.length >= 5) {
            return;
        }
        state.compareList.push({ ideess, rotulo, direccion });
        saveCompareList();
    }

    function removeFromCompare(ideess) {
        state.compareList = state.compareList.filter((s) => s.ideess !== ideess);
        saveCompareList();
    }

    function updateCompareCount() {
        const count = state.compareList.length;
        el.compareCount.textContent = String(count);
        el.compareCount.hidden = count === 0;
    }

    // ---- Geolocalización ----

    function requestGeolocation() {
        if (!('geolocation' in navigator)) {
            el.geoFallback.hidden = false;
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => {
                state.userLat = position.coords.latitude;
                state.userLon = position.coords.longitude;
                el.geoFallback.hidden = true;
                loadNearby();
            },
            () => {
                el.geoFallback.hidden = false;
            },
            { timeout: 8000 }
        );
    }

    // ---- Estadísticas nacionales ----

    async function loadNationalHeadline() {
        const [gasoleoA, gasolina95] = await Promise.all([
            Api.nationalStats('gasoleo_a', 14).catch(() => null),
            Api.nationalStats('gasolina_95_e5', 14).catch(() => null),
        ]);
        if (gasoleoA) {
            state.nationalSeries.gasoleo_a = gasoleoA;
            if (gasoleoA.hoy) {
                el.nationalGasoleoA.textContent = `${gasoleoA.hoy.media.toFixed(3)} €`;
            } else {
                el.nationalGasoleoA.textContent = 'Sin datos';
            }
        }
        if (gasolina95) {
            state.nationalSeries.gasolina_95_e5 = gasolina95;
            if (gasolina95.hoy) {
                el.nationalGasolina95.textContent = `${gasolina95.hoy.media.toFixed(3)} €`;
            } else {
                el.nationalGasolina95.textContent = 'Sin datos';
            }
        }
    }

    async function toggleNationalDetail() {
        const isOpen = el.nationalToggle.getAttribute('aria-expanded') === 'true';
        el.nationalToggle.setAttribute('aria-expanded', String(!isOpen));
        el.nationalDetail.hidden = isOpen;
        if (isOpen) {
            return;
        }
        // Se renderiza en el primer despliegue, no antes: Chart.js necesita
        // el canvas ya visible con tamaño real para calcular sus ejes.
        if (!state.nationalChart) {
            await renderNationalChart();
        }
    }

    async function renderNationalChart() {
        const fuel = el.filterFuel.value || 'gasoleo_a';
        let data = state.nationalSeries[fuel];
        if (!data) {
            data = await Api.nationalStats(fuel, 14).catch(() => null);
        }
        if (!data || !data.serie.length || typeof Chart === 'undefined') {
            return;
        }
        if (state.nationalChart) {
            state.nationalChart.destroy();
            state.nationalChart = null;
        }
        state.nationalChart = new Chart(el.nationalChart, {
            type: 'line',
            data: {
                labels: data.serie.map((p) => p.fecha.slice(5)),
                datasets: [{
                    label: `Media nacional · ${FUEL_LABELS[fuel] || fuel}`,
                    data: data.serie.map((p) => p.media),
                    borderColor: '#B8860B',
                    backgroundColor: 'rgba(184, 134, 11, 0.12)',
                    tension: 0.15,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: (v) => v.toFixed(2) + ' €' } } },
            },
        });
        const last = data.serie[data.serie.length - 1];
        el.nationalNote.textContent = `Media de ${last.estaciones.toLocaleString('es-ES')} gasolineras el ${last.fecha}. Datos del Ministerio para la Transición Ecológica.`;
    }

    // ---- Carga y render de lista ----

    function currentFilters() {
        return {
            fuel: el.filterFuel.value,
            radius: el.filterRadius.value,
            sort: el.filterSort.value,
            open: el.filterOpen.value || undefined,
        };
    }

    async function loadNearby() {
        if (state.userLat === null || state.userLon === null) {
            return;
        }
        state.stationsMode = 'nearby';
        await fetchStationsPage(true);
    }

    async function performSearch(query) {
        state.stationsMode = 'search';
        state.stationsQuery = query;
        await fetchStationsPage(true);
    }

    // reset=true empieza una búsqueda nueva desde el offset 0; reset=false
    // añade la siguiente página a la lista ya cargada (scroll infinito).
    async function fetchStationsPage(reset) {
        if (state.stationsLoading) {
            return;
        }
        if (reset) {
            state.stationsOffset = 0;
            state.stationsHasMore = false;
        } else if (!state.stationsHasMore) {
            return;
        }
        state.stationsLoading = true;
        updateLoadSentinel();

        const filters = currentFilters();
        const page = { offset: state.stationsOffset, limit: PAGE_SIZE };
        let data;
        try {
            if (state.stationsMode === 'search') {
                data = await Api.search({ q: state.stationsQuery, lat: state.userLat, lon: state.userLon, ...filters, ...page });
            } else {
                data = await Api.near({ lat: state.userLat, lon: state.userLon, ...filters, ...page });
            }
        } catch (e) {
            state.stationsLoading = false;
            updateLoadSentinel();
            return;
        }

        if (reset) {
            state.currentStations = data.stations;
        } else {
            state.currentStations = state.currentStations.concat(data.stations);
        }
        state.stationsOffset += data.stations.length;
        state.stationsHasMore = Boolean(data.hasMore);
        state.stationsLoading = false;

        renderList(state.currentStations);
        updateLoadSentinel();
        if (state.currentView === 'map') {
            refreshMapMarkers();
        }
    }

    function updateLoadSentinel() {
        el.stationsLoadSentinel.hidden = !state.stationsHasMore && !state.stationsLoading;
        if (state.stationsLoading) {
            el.stationsLoadSentinel.textContent = 'Cargando más gasolineras…';
        } else {
            el.stationsLoadSentinel.textContent = 'Desplázate para ver más';
        }
    }

    function fuelPriceLabel(station, fuelSlug) {
        const price = station.precios[fuelSlug];
        if (price === undefined) {
            return null;
        }
        const trend = station.tendencias[fuelSlug];
        let symbol = '';
        if (trend && TREND_SYMBOL[trend]) {
            symbol = TREND_SYMBOL[trend];
        }
        let label = `${price.toFixed(3)} €`;
        if (symbol) {
            label += ' ' + symbol;
        }
        return label;
    }

    function renderList(stations) {
        el.stationsList.innerHTML = '';
        el.stationsEmpty.hidden = stations.length > 0;

        const filterFuel = el.filterFuel.value;
        for (const station of stations) {
            const li = document.createElement('li');
            li.className = 'station-card';

            let distance = '';
            if (station.distanciaKm !== null) {
                distance = `${station.distanciaKm} km`;
            }
            const gasoleoA = fuelPriceLabel(station, 'gasoleo_a');
            const gasolina95 = fuelPriceLabel(station, 'gasolina_95_e5');
            let filteredPrice = null;
            if (filterFuel !== 'gasoleo_a' && filterFuel !== 'gasolina_95_e5') {
                filteredPrice = fuelPriceLabel(station, filterFuel);
            }

            let badge24hHtml = '';
            if (station.is24h) {
                badge24hHtml = '<span class="badge badge--24h">24h</span>';
            }
            let distanceHtml = '';
            if (distance) {
                distanceHtml = `<span class="station-card__distance">${distance}</span>`;
            }
            let gasoleoAHtml = '';
            if (gasoleoA) {
                gasoleoAHtml = `<span>Gasóleo A: ${gasoleoA}</span>`;
            }
            let gasolina95Html = '';
            if (gasolina95) {
                gasolina95Html = `<span>Gasolina 95: ${gasolina95}</span>`;
            }
            let filteredPriceHtml = '';
            if (filteredPrice) {
                filteredPriceHtml = `<span>${escapeHtml(FUEL_LABELS[filterFuel] || filterFuel)}: ${filteredPrice}</span>`;
            }

            li.innerHTML = `
                <div class="station-card__main">
                    <strong>${escapeHtml(station.rotulo)}</strong>
                    ${badge24hHtml}
                    <span class="station-card__address">${escapeHtml(station.direccion)}, ${escapeHtml(station.municipio)}</span>
                    ${distanceHtml}
                </div>
                <div class="station-card__prices">
                    ${gasoleoAHtml}
                    ${gasolina95Html}
                    ${filteredPriceHtml}
                </div>
            `;
            li.addEventListener('click', () => openStationModal(station.ideess));
            el.stationsList.appendChild(li);
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function stationHeaderCell(d) {
        if (d) {
            return `<th>${escapeHtml(d.rotulo)}</th>`;
        }
        return '<th>—</th>';
    }

    // ---- Vista mapa: Leaflet + clustering + capa de calor ----

    function ensureMap() {
        if (state.mapReady) {
            return;
        }
        let initialLat = 40.4168;
        let initialLon = -3.7038;
        let initialZoom = 6;
        if (state.userLat !== null) {
            initialLat = state.userLat;
            initialLon = state.userLon;
            initialZoom = 13;
        }

        state.map = L.map(el.map).setView([initialLat, initialLon], initialZoom);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19,
        }).addTo(state.map);

        state.markerLayer = L.markerClusterGroup();
        state.map.addLayer(state.markerLayer);

        if (state.userLat !== null) {
            state.userMarker = L.circleMarker([state.userLat, state.userLon], {
                radius: 8,
                color: '#1D4E89',
                fillColor: '#4A90D9',
                fillOpacity: 1,
                weight: 2,
            }).addTo(state.map);
        }

        state.map.on('moveend', loadBboxForMap);
        state.mapReady = true;
    }

    function priceColor(precio, min, max) {
        if (precio === null || min === max) {
            return '#8A8A8A';
        }
        const ratio = (precio - min) / (max - min);
        // Verde (barato) a rojo (caro), interpolando por el canal rojo/verde en HSL.
        const hue = 120 - ratio * 120;
        return `hsl(${hue}, 70%, 42%)`;
    }

    async function loadBboxForMap() {
        if (!state.mapReady) {
            return;
        }
        const bounds = state.map.getBounds();
        const filters = currentFilters();
        let data;
        try {
            data = await Api.bbox({
                north: bounds.getNorth(),
                south: bounds.getSouth(),
                east: bounds.getEast(),
                west: bounds.getWest(),
                fuel: filters.fuel,
                open: filters.open,
            });
        } catch (e) {
            return;
        }
        renderMapStations(data.stations);
    }

    function renderMapStations(stations) {
        state.markerLayer.clearLayers();
        const withPrice = stations.filter((s) => s.precio !== null);
        const prices = withPrice.map((s) => s.precio);
        let min = 0;
        let max = 0;
        if (prices.length) {
            min = Math.min(...prices);
            max = Math.max(...prices);
        }

        for (const station of stations) {
            const color = priceColor(station.precio, min, max);
            const marker = L.circleMarker([station.lat, station.lon], {
                radius: 7,
                color,
                fillColor: color,
                fillOpacity: 0.85,
                weight: 1,
            });
            let priceText = 'Sin dato';
            if (station.precio !== null) {
                priceText = `${station.precio.toFixed(3)} €`;
            }
            let open24hText = '';
            if (station.is24h) {
                open24hText = ' · 24h';
            }
            marker.bindPopup(`
                <strong>${escapeHtml(station.rotulo)}</strong><br>
                ${priceText}${open24hText}<br>
                <button class="popup-view-btn" data-ideess="${station.ideess}">Ver ficha</button>
            `);
            marker.on('popupopen', (e) => {
                const btn = e.popup.getElement().querySelector('.popup-view-btn');
                if (btn) {
                    btn.addEventListener('click', () => openStationModal(station.ideess));
                }
            });
            state.markerLayer.addLayer(marker);
        }

        if (state.heatVisible) {
            renderHeatLayer(withPrice, min, max);
        }
    }

    function renderHeatLayer(stationsWithPrice, min, max) {
        if (state.heatLayer) {
            state.map.removeLayer(state.heatLayer);
            state.heatLayer = null;
        }
        if (!stationsWithPrice.length) {
            return;
        }
        const points = stationsWithPrice.map((s) => {
            // Intensidad invertida: precio bajo = intensidad alta (más "caliente" visualmente donde es barato).
            let ratio = 0.5;
            if (max !== min) {
                ratio = 1 - (s.precio - min) / (max - min);
            }
            return [s.lat, s.lon, 0.3 + ratio * 0.7];
        });
        state.heatLayer = L.heatLayer(points, { radius: 30, blur: 20, maxZoom: 15 }).addTo(state.map);
    }

    function refreshMapMarkers() {
        ensureMap();
        state.map.invalidateSize();
        if (state.userLat !== null) {
            state.map.setView([state.userLat, state.userLon], 13);
        }
        loadBboxForMap();
    }

    function toggleHeatmap() {
        state.heatVisible = !state.heatVisible;
        el.heatmapToggle.setAttribute('aria-pressed', String(state.heatVisible));
        if (state.heatVisible) {
            loadBboxForMap();
        } else if (state.heatLayer) {
            state.map.removeLayer(state.heatLayer);
            state.heatLayer = null;
        }
    }

    function switchView(view) {
        state.currentView = view;
        el.viewList.hidden = view !== 'list';
        el.viewMap.hidden = view !== 'map';
        el.viewListBtn.setAttribute('aria-selected', String(view === 'list'));
        el.viewMapBtn.setAttribute('aria-selected', String(view === 'map'));
        if (view === 'map') {
            refreshMapMarkers();
        }
    }

    // ---- Panel de detalle ----

    async function openStationModal(ideess) {
        let station;
        try {
            station = await Api.station(ideess);
        } catch (e) {
            return;
        }

        el.modalRotulo.textContent = station.rotulo;
        el.modalDireccion.textContent = station.direccion;
        let localidadPrefix = '';
        if (station.localidad) {
            localidadPrefix = station.localidad + ', ';
        }
        el.modalMunicipio.textContent = `${localidadPrefix}${station.municipio} (${station.provincia})`;
        el.modalHorario.textContent = station.horario.texto;

        el.modalFuels.innerHTML = '';
        const fuelSlugs = Object.keys(station.combustibles);
        let defaultFuel = fuelSlugs[0];
        if (fuelSlugs.includes('gasoleo_a')) {
            defaultFuel = 'gasoleo_a';
        }
        for (const slug of fuelSlugs) {
            const info = station.combustibles[slug];
            const li = document.createElement('li');
            let symbol = '';
            if (info.tendencia && TREND_SYMBOL[info.tendencia]) {
                symbol = TREND_SYMBOL[info.tendencia];
            }
            li.innerHTML = `<span>${escapeHtml(FUEL_LABELS[slug] || slug)}</span><span>${info.precio.toFixed(3)} € ${symbol}</span>`;
            el.modalFuels.appendChild(li);
        }

        el.modalDirections.href = `https://www.google.com/maps/dir/?api=1&destination=${station.lat},${station.lon}`;

        el.modalCompareToggle.textContent = compareToggleLabel(ideess);
        el.modalCompareToggle.onclick = () => {
            if (isInCompareList(ideess)) {
                removeFromCompare(ideess);
            } else {
                addToCompare(ideess, station.rotulo, station.direccion);
            }
            el.modalCompareToggle.textContent = compareToggleLabel(ideess);
        };

        loadZoneComparison(ideess, defaultFuel);
        loadHistoryChart(ideess, defaultFuel);

        el.stationModal.showModal();
    }

    async function loadZoneComparison(ideess, fuel) {
        el.modalZoneComparison.textContent = '';
        let data;
        try {
            data = await Api.zoneComparison(ideess, fuel);
        } catch (e) {
            return;
        }
        const diff = data.precioPropio - data.mediaZona;
        let diffText;
        if (diff <= 0) {
            diffText = `${Math.abs(diff).toFixed(3)} € por debajo de la media`;
        } else {
            diffText = `${diff.toFixed(3)} € por encima de la media`;
        }
        el.modalZoneComparison.textContent = `Media de la zona (${data.estacionesEnMedia} gasolineras): ${data.mediaZona.toFixed(3)} € · ${diffText}`;
    }

    async function loadHistoryChart(ideess, fuel) {
        let data;
        try {
            data = await Api.history(ideess, fuel, 14);
        } catch (e) {
            return;
        }
        if (state.chart) {
            state.chart.destroy();
            state.chart = null;
        }
        if (!data.serie.length || typeof Chart === 'undefined') {
            return;
        }
        state.chart = new Chart(el.modalChart, {
            type: 'line',
            data: {
                labels: data.serie.map((p) => p.fecha.slice(5)),
                datasets: [{
                    label: FUEL_LABELS[fuel] || fuel,
                    data: data.serie.map((p) => p.precio),
                    borderColor: '#B8860B',
                    backgroundColor: 'rgba(184, 134, 11, 0.12)',
                    tension: 0.15,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { y: { ticks: { callback: (v) => v.toFixed(2) + ' €' } } },
            },
        });
    }

    // ---- Panel de comparación ----

    function openComparePanel() {
        renderComparePanel();
        el.comparePanel.showModal();
    }

    async function renderComparePanel() {
        el.compareEmpty.hidden = state.compareList.length > 0;
        el.compareTable.innerHTML = '';
        if (!state.compareList.length) {
            return;
        }

        const details = await Promise.all(
            state.compareList.map((entry) => Api.station(entry.ideess).catch(() => null))
        );

        const table = document.createElement('table');
        const headRow = document.createElement('tr');
        headRow.innerHTML = '<th>Carburante</th>' + details.map(stationHeaderCell).join('');
        table.appendChild(headRow);

        const allFuelSlugs = new Set();
        details.forEach((d) => {
            if (d) Object.keys(d.combustibles).forEach((slug) => allFuelSlugs.add(slug));
        });

        for (const slug of allFuelSlugs) {
            const row = document.createElement('tr');
            let cells = `<td>${escapeHtml(FUEL_LABELS[slug] || slug)}</td>`;
            for (const d of details) {
                const info = d && d.combustibles[slug];
                let cellText = '—';
                if (info) {
                    cellText = info.precio.toFixed(3) + ' €';
                }
                cells += `<td>${cellText}</td>`;
            }
            row.innerHTML = cells;
            table.appendChild(row);
        }

        const removeRow = document.createElement('tr');
        removeRow.innerHTML = '<td></td>' + state.compareList.map((entry) =>
            `<td><button class="pill compare-remove-btn" data-ideess="${entry.ideess}">Quitar</button></td>`
        ).join('');
        table.appendChild(removeRow);

        el.compareTable.appendChild(table);
        el.compareTable.querySelectorAll('.compare-remove-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                removeFromCompare(btn.dataset.ideess);
                renderComparePanel();
            });
        });
    }

    // ---- Scroll infinito ----

    const loadMoreObserver = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
            fetchStationsPage(false);
        }
    }, { rootMargin: '200px' });
    loadMoreObserver.observe(el.stationsLoadSentinel);

    // ---- Eventos ----

    el.searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const query = el.searchInput.value.trim();
        if (query.length < 2) {
            return;
        }
        performSearch(query);
    });

    [el.filterFuel, el.filterRadius, el.filterSort, el.filterOpen].forEach((input) => {
        input.addEventListener('change', () => {
            if (el.searchInput.value.trim().length >= 2) {
                performSearch(el.searchInput.value.trim());
            } else {
                loadNearby();
            }
            if (state.currentView === 'map') {
                loadBboxForMap();
            }
        });
    });

    el.filterFuel.addEventListener('change', () => {
        if (!el.nationalDetail.hidden) {
            renderNationalChart();
        }
    });

    el.nationalToggle.addEventListener('click', toggleNationalDetail);

    el.geoRetry.addEventListener('click', requestGeolocation);
    el.viewListBtn.addEventListener('click', () => switchView('list'));
    el.viewMapBtn.addEventListener('click', () => switchView('map'));
    el.heatmapToggle.addEventListener('click', toggleHeatmap);

    el.modalClose.addEventListener('click', () => el.stationModal.close());
    el.stationModal.addEventListener('click', (e) => {
        if (e.target === el.stationModal) el.stationModal.close();
    });

    el.compareOpen.addEventListener('click', openComparePanel);
    el.compareClose.addEventListener('click', () => el.comparePanel.close());
    el.comparePanel.addEventListener('click', (e) => {
        if (e.target === el.comparePanel) el.comparePanel.close();
    });

    updateCompareCount();
    requestGeolocation();
    loadNationalHeadline();
})();
