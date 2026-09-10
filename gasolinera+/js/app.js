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
        diesel_renovable: 'Diésel Renovable',
        gasolina_renovable: 'Gasolina Renovable',
        biogas_natural_comprimido: 'Biogás Natural Comprimido',
        biogas_natural_licuado: 'Biogás Natural Licuado',
    };

    const TREND_SYMBOL = { up: '▲', down: '▼', same: '=' };

    const PAGE_SIZE = 10;

    function todayIso() {
        return new Date().toISOString().slice(0, 10);
    }

    function daysAgoIso(n) {
        const d = new Date();
        d.setDate(d.getDate() - n);
        return d.toISOString().slice(0, 10);
    }

    const el = {
        searchForm: document.getElementById('search-form'),
        searchInput: document.getElementById('search-input'),
        backToNearby: document.getElementById('back-to-nearby'),
        filterFuel: document.getElementById('filter-fuel'),
        filterRadius: document.getElementById('filter-radius'),
        filterSort: document.getElementById('filter-sort'),
        filterOpen: document.getElementById('filter-open'),
        viewListBtn: document.getElementById('view-list-btn'),
        viewMapBtn: document.getElementById('view-map-btn'),
        viewStatsBtn: document.getElementById('view-stats-btn'),
        viewList: document.getElementById('view-list'),
        viewMap: document.getElementById('view-map'),
        viewStats: document.getElementById('view-stats'),
        stationsList: document.getElementById('stations-list'),
        stationsEmpty: document.getElementById('stations-empty'),
        stationsGeocodedNote: document.getElementById('stations-geocoded-note'),
        paginationNav: document.getElementById('stations-pagination'),
        paginationPrev: document.getElementById('pagination-prev'),
        paginationNext: document.getElementById('pagination-next'),
        paginationStatus: document.getElementById('pagination-status'),
        geoFallback: document.getElementById('geo-fallback'),
        geoRetry: document.getElementById('geo-retry'),
        heatmapToggle: document.getElementById('heatmap-toggle'),
        locateMe: document.getElementById('locate-me'),
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
        modalFavoriteToggle: document.getElementById('modal-favorite-toggle'),
        compareOpen: document.getElementById('compare-open'),
        compareCount: document.getElementById('compare-count'),
        comparePanel: document.getElementById('compare-panel'),
        compareClose: document.getElementById('compare-close'),
        compareEmpty: document.getElementById('compare-empty'),
        compareTable: document.getElementById('compare-table'),
        favoritesOpen: document.getElementById('favorites-open'),
        favoritesCount: document.getElementById('favorites-count'),
        favoritesPanel: document.getElementById('favorites-panel'),
        favoritesClose: document.getElementById('favorites-close'),
        favoritesEmpty: document.getElementById('favorites-empty'),
        favoritesList: document.getElementById('favorites-list'),
        toast: document.getElementById('toast'),
        nationalToggle: document.getElementById('national-stats-toggle'),
        nationalDetail: document.getElementById('national-stats-detail'),
        nationalGasoleoA: document.getElementById('national-gasoleo-a'),
        nationalGasolina95: document.getElementById('national-gasolina-95'),
        nationalChart: document.getElementById('national-chart'),
        nationalNote: document.getElementById('national-stats-note'),
        statsFuel: document.getElementById('stats-fuel'),
        statsGroupToggle: document.getElementById('stats-group-toggle'),
        statsFrom: document.getElementById('stats-from'),
        statsTo: document.getElementById('stats-to'),
        statsRangeNote: document.getElementById('stats-range-note'),
        statsNationalVariation: document.getElementById('stats-national-variation'),
        statsNationalChart: document.getElementById('stats-national-chart'),
        statsByFuelChart: document.getElementById('stats-by-fuel-chart'),
        statsProvinceChart: document.getElementById('stats-province-chart'),
        statsProvinceChartWrap: document.getElementById('stats-province-chart-wrap'),
        statsDistributionChart: document.getElementById('stats-distribution-chart'),
        statsDistributionNote: document.getElementById('stats-distribution-note'),
        statsStationSearch: document.getElementById('stats-station-search'),
        statsStationResults: document.getElementById('stats-station-results'),
        statsStationDetail: document.getElementById('stats-station-detail'),
        statsStationName: document.getElementById('stats-station-name'),
        statsStationVariation: document.getElementById('stats-station-variation'),
        statsStationChart: document.getElementById('stats-station-chart'),
    };

    const state = {
        userLat: null,
        userLon: null,
        currentStations: [],
        stationsMode: null,
        stationsQuery: '',
        stationsPage: 1,
        stationsPendingPage: null,
        stationsTotal: 0,
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
        statsGroup: 'day',
        statsNationalChart: null,
        statsByFuelChart: null,
        statsProvinceChart: null,
        statsDistributionChart: null,
        statsStationChart: null,
        statsSelectedIdeess: null,
        statsSearchTimer: null,
        compareList: loadCompareList(),
        favoritesList: loadFavorites(),
        toastTimer: null,
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

    // ---- Favoritas (localStorage) ----

    function loadFavorites() {
        try {
            const raw = localStorage.getItem('gasolinera_favorites');
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

    function saveFavorites() {
        try {
            localStorage.setItem('gasolinera_favorites', JSON.stringify(state.favoritesList));
        } catch (e) {
            // localStorage puede fallar en navegación privada; las favoritas
            // siguen funcionando en memoria durante la sesión, solo no persisten.
        }
        updateFavoritesCount();
    }

    function isFavorite(ideess) {
        return state.favoritesList.some((s) => s.ideess === ideess);
    }

    function favoriteToggleLabel(ideess) {
        if (isFavorite(ideess)) {
            return 'Quitar de favoritas';
        }
        return 'Añadir a favoritas';
    }

    function addToFavorites(ideess, rotulo, direccion, municipio) {
        if (isFavorite(ideess)) {
            return;
        }
        state.favoritesList.push({ ideess, rotulo, direccion, municipio });
        saveFavorites();
    }

    function removeFromFavorites(ideess) {
        state.favoritesList = state.favoritesList.filter((s) => s.ideess !== ideess);
        saveFavorites();
    }

    function updateFavoritesCount() {
        const count = state.favoritesList.length;
        el.favoritesCount.textContent = String(count);
        el.favoritesCount.hidden = count === 0;
    }

    function openFavoritesPanel() {
        renderFavoritesPanel();
        el.favoritesPanel.showModal();
    }

    function renderFavoritesPanel() {
        el.favoritesEmpty.hidden = state.favoritesList.length > 0;
        el.favoritesList.innerHTML = '';
        for (const entry of state.favoritesList) {
            const li = document.createElement('li');
            const openBtn = document.createElement('button');
            openBtn.type = 'button';
            openBtn.className = 'favorites-item-open';
            openBtn.innerHTML = `<strong>${escapeHtml(entry.rotulo)}</strong><small>${escapeHtml(entry.direccion)}, ${escapeHtml(entry.municipio || '')}</small>`;
            openBtn.addEventListener('click', () => {
                el.favoritesPanel.close();
                openStationModal(entry.ideess);
            });
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'favorites-item-remove';
            removeBtn.textContent = 'Quitar';
            removeBtn.addEventListener('click', () => {
                removeFromFavorites(entry.ideess);
                renderFavoritesPanel();
            });
            li.appendChild(openBtn);
            li.appendChild(removeBtn);
            el.favoritesList.appendChild(li);
        }
    }

    // ---- Aviso flotante (toast) ----

    function showToast(message) {
        clearTimeout(state.toastTimer);
        el.toast.textContent = message;
        el.toast.hidden = false;
        el.toast.style.opacity = '1';
        state.toastTimer = setTimeout(() => {
            el.toast.style.opacity = '0';
            setTimeout(() => { el.toast.hidden = true; }, 200);
        }, 1600);
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
                if (state.currentView === 'map') {
                    locateOnMap();
                }
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
            Api.nationalStats('gasoleo_a', daysAgoIso(14), todayIso()).catch(() => null),
            Api.nationalStats('gasolina_95_e5', daysAgoIso(14), todayIso()).catch(() => null),
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
            data = await Api.nationalStats(fuel, daysAgoIso(14), todayIso()).catch(() => null);
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
        el.backToNearby.hidden = true;
        await loadStationsPage(1);
    }

    async function backToNearby() {
        el.searchInput.value = '';
        el.stationsGeocodedNote.hidden = true;
        await loadNearby();
    }

    async function performSearch(query) {
        state.stationsMode = 'search';
        state.stationsQuery = query;
        el.backToNearby.hidden = state.userLat === null;
        await loadStationsPage(1);
    }

    // Cada página sustituye la lista anterior (paginación real, no scroll
    // infinito): pageNumber es 1-indexado. Si llega una llamada mientras ya
    // hay una carga en curso (dos filtros cambiados seguidos, p.ej. radio y
    // luego carburante), no se descarta sin más: se apunta como pendiente y
    // se relanza en cuanto termina la que está en curso, para que el
    // resultado final siempre refleje el ÚLTIMO filtro elegido.
    async function loadStationsPage(pageNumber) {
        if (state.stationsLoading) {
            state.stationsPendingPage = pageNumber;
            return;
        }
        state.stationsLoading = true;
        state.stationsPendingPage = null;
        updatePaginationControls();

        const filters = currentFilters();
        const page = { offset: (pageNumber - 1) * PAGE_SIZE, limit: PAGE_SIZE };
        let data;
        try {
            if (state.stationsMode === 'search') {
                data = await Api.search({ q: state.stationsQuery, lat: state.userLat, lon: state.userLon, ...filters, ...page });
            } else {
                data = await Api.near({ lat: state.userLat, lon: state.userLon, ...filters, ...page });
            }
        } catch (e) {
            state.stationsLoading = false;
            updatePaginationControls();
            return;
        }

        state.currentStations = data.stations;
        state.stationsPage = pageNumber;
        state.stationsTotal = data.total;
        state.stationsLoading = false;

        if (data.geocodedFrom) {
            el.stationsGeocodedNote.textContent = `${data.geocodedFrom} no tiene gasolineras propias registradas: se muestran las de alrededor (hasta 20 km).`;
            el.stationsGeocodedNote.hidden = false;
        } else {
            el.stationsGeocodedNote.hidden = true;
        }

        renderList(state.currentStations);
        updatePaginationControls();
        if (state.currentView === 'map') {
            refreshMapMarkers();
        }

        if (state.stationsPendingPage !== null) {
            const nextPage = state.stationsPendingPage;
            state.stationsPendingPage = null;
            loadStationsPage(nextPage);
        }
    }

    function updatePaginationControls() {
        const totalPages = Math.max(1, Math.ceil(state.stationsTotal / PAGE_SIZE));
        el.paginationNav.hidden = state.stationsTotal <= PAGE_SIZE;
        el.paginationPrev.disabled = state.stationsLoading || state.stationsPage <= 1;
        el.paginationNext.disabled = state.stationsLoading || state.stationsPage >= totalPages;
        el.paginationStatus.textContent = state.stationsLoading
            ? 'Cargando…'
            : `Página ${state.stationsPage} de ${totalPages}`;
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
            return `<th><strong>${escapeHtml(d.rotulo)}</strong><small>${escapeHtml(d.direccion)}, ${escapeHtml(d.municipio)}</small></th>`;
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

    function locateOnMap() {
        if (state.userLat === null || state.userLon === null) {
            requestGeolocation();
            return;
        }
        ensureMap();
        state.map.setView([state.userLat, state.userLon], 14);
        if (state.userMarker) {
            state.map.removeLayer(state.userMarker);
        }
        state.userMarker = L.circleMarker([state.userLat, state.userLon], {
            radius: 8,
            color: '#1D4E89',
            fillColor: '#4A90D9',
            fillOpacity: 1,
            weight: 2,
        }).addTo(state.map);
    }

    function switchView(view) {
        state.currentView = view;
        el.viewList.hidden = view !== 'list';
        el.viewMap.hidden = view !== 'map';
        el.viewStats.hidden = view !== 'stats';
        el.viewListBtn.setAttribute('aria-selected', String(view === 'list'));
        el.viewMapBtn.setAttribute('aria-selected', String(view === 'map'));
        el.viewStatsBtn.setAttribute('aria-selected', String(view === 'stats'));
        if (view === 'map') {
            refreshMapMarkers();
        }
        if (view === 'stats' && !state.statsNationalChart) {
            renderStatsNationalChart();
            renderStatsByFuelChart();
            renderStatsProvinceChart();
            renderStatsDistributionChart();
        }
    }

    // ---- Estadísticas (pestaña propia: nacional + por estación) ----

    const MONTH_LABELS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    function periodLabel(periodo) {
        if (state.statsGroup === 'month') {
            const [year, month] = periodo.split('-');
            return `${MONTH_LABELS[Number(month) - 1]} ${year}`;
        }
        return periodo.slice(5);
    }

    function variationText(serie, valueKey) {
        if (serie.length < 2) {
            return 'Aún no hay suficiente histórico para calcular una variación.';
        }
        const first = serie[0][valueKey];
        const last = serie[serie.length - 1][valueKey];
        const diff = last - first;
        const pct = first !== 0 ? (diff / first) * 100 : 0;
        const sign = diff > 0 ? '+' : '';
        const periodWord = state.statsGroup === 'month' ? 'meses' : 'días';
        return `<strong>${sign}${pct.toFixed(1)}%</strong> (${sign}${diff.toFixed(3)} €) entre ${periodLabel(serie[0].fecha)} y ${periodLabel(serie[serie.length - 1].fecha)} · ${serie.length} ${periodWord} con dato.`;
    }

    async function renderStatsNationalChart() {
        const fuel = el.statsFuel.value;
        let data;
        try {
            data = await Api.nationalStats(fuel, el.statsFrom.value, el.statsTo.value, state.statsGroup);
        } catch (e) {
            return;
        }
        updateStatsRangeNote(data.from, data.to);
        el.statsNationalVariation.innerHTML = variationText(data.serie, 'media');
        if (!data.serie.length || typeof Chart === 'undefined') {
            return;
        }
        if (state.statsNationalChart) {
            state.statsNationalChart.destroy();
            state.statsNationalChart = null;
        }
        state.statsNationalChart = new Chart(el.statsNationalChart, {
            type: 'line',
            data: {
                labels: data.serie.map((p) => periodLabel(p.fecha)),
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
    }

    async function searchStatsStation(query) {
        el.statsStationResults.innerHTML = '';
        if (query.trim().length < 2) {
            return;
        }
        let data;
        try {
            data = await Api.search({ q: query.trim(), lat: state.userLat, lon: state.userLon, sort: state.userLat !== null ? 'distance' : 'price', limit: 8 });
        } catch (e) {
            return;
        }
        for (const station of data.stations) {
            const li = document.createElement('li');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.innerHTML = `${escapeHtml(station.rotulo)}<small>${escapeHtml(station.direccion)}, ${escapeHtml(station.municipio)}</small>`;
            btn.addEventListener('click', () => selectStatsStation(station.ideess, station.rotulo));
            li.appendChild(btn);
            el.statsStationResults.appendChild(li);
        }
    }

    function selectStatsStation(ideess, rotulo) {
        state.statsSelectedIdeess = ideess;
        el.statsStationResults.innerHTML = '';
        el.statsStationSearch.value = rotulo;
        el.statsStationDetail.hidden = false;
        el.statsStationName.textContent = rotulo;
        renderStatsStationChart();
    }

    async function renderStatsStationChart() {
        if (!state.statsSelectedIdeess) {
            return;
        }
        const fuel = el.statsFuel.value;
        let data;
        try {
            data = await Api.history(state.statsSelectedIdeess, fuel, el.statsFrom.value, el.statsTo.value, state.statsGroup);
        } catch (e) {
            return;
        }
        el.statsStationVariation.innerHTML = variationText(data.serie, 'precio');
        if (!data.serie.length || typeof Chart === 'undefined') {
            return;
        }
        if (state.statsStationChart) {
            state.statsStationChart.destroy();
            state.statsStationChart = null;
        }
        state.statsStationChart = new Chart(el.statsStationChart, {
            type: 'line',
            data: {
                labels: data.serie.map((p) => periodLabel(p.fecha)),
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

    function updateStatsRangeNote(effectiveFrom, effectiveTo) {
        if (effectiveFrom === el.statsFrom.value && effectiveTo === el.statsTo.value) {
            el.statsRangeNote.textContent = '';
            return;
        }
        el.statsRangeNote.textContent = `Rango ajustado a los datos disponibles: ${effectiveFrom} a ${effectiveTo}.`;
    }

    const FUEL_CHART_COLORS = {
        gasoleo_a: '#B8860B',
        gasolina_95_e5: '#1D4E89',
        gasoleo_premium: '#7A5A05',
        gasolina_98_e5: '#4A90D9',
        adblue: '#5B8C5A',
        glp: '#B3261E',
    };

    async function renderStatsByFuelChart() {
        let data;
        try {
            data = await Api.statsByFuel(el.statsFrom.value, el.statsTo.value, state.statsGroup);
        } catch (e) {
            return;
        }
        if (typeof Chart === 'undefined') {
            return;
        }
        const allPeriods = new Set();
        Object.values(data.series).forEach((serie) => serie.forEach((p) => allPeriods.add(p.fecha)));
        const labels = Array.from(allPeriods).sort();

        const datasets = Object.keys(data.series)
            .filter((slug) => data.series[slug].length > 0)
            .map((slug) => {
                const bySeriesFecha = {};
                data.series[slug].forEach((p) => { bySeriesFecha[p.fecha] = p.media; });
                return {
                    label: FUEL_LABELS[slug] || slug,
                    data: labels.map((fecha) => bySeriesFecha[fecha] ?? null),
                    borderColor: FUEL_CHART_COLORS[slug] || '#8A8A8A',
                    backgroundColor: 'transparent',
                    tension: 0.15,
                    spanGaps: true,
                };
            });

        if (state.statsByFuelChart) {
            state.statsByFuelChart.destroy();
            state.statsByFuelChart = null;
        }
        state.statsByFuelChart = new Chart(el.statsByFuelChart, {
            type: 'line',
            data: { labels: labels.map(periodLabel), datasets },
            options: {
                responsive: true,
                plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } },
                scales: { y: { ticks: { callback: (v) => v.toFixed(2) + ' €' } } },
            },
        });
    }

    async function renderStatsProvinceChart() {
        const fuel = el.statsFuel.value;
        let data;
        try {
            data = await Api.statsByProvince(fuel);
        } catch (e) {
            return;
        }
        if (!data.provincias.length || typeof Chart === 'undefined') {
            return;
        }
        if (state.statsProvinceChart) {
            state.statsProvinceChart.destroy();
            state.statsProvinceChart = null;
        }
        // Con ~52 provincias, una altura fija deja las barras demasiado
        // finas y Chart.js se salta etiquetas por solape: la altura del
        // canvas escala con el numero de provincias, una fila por barra.
        el.statsProvinceChartWrap.style.height = (data.provincias.length * 18) + 'px';
        const cheapest = data.provincias[0];
        const priciest = data.provincias[data.provincias.length - 1];
        state.statsProvinceChart = new Chart(el.statsProvinceChart, {
            type: 'bar',
            data: {
                labels: data.provincias.map((p) => p.provincia),
                datasets: [{
                    label: `Media hoy · ${FUEL_LABELS[fuel] || fuel}`,
                    data: data.provincias.map((p) => p.media),
                    backgroundColor: data.provincias.map((p) => (p.provincia === cheapest.provincia ? '#5B8C5A' : p.provincia === priciest.provincia ? '#B3261E' : '#B8860B')),
                }],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { callback: (v) => v.toFixed(2) + ' €' } },
                    y: { ticks: { autoSkip: false, font: { size: 9 } } },
                },
            },
        });
    }

    async function renderStatsDistributionChart() {
        const fuel = el.statsFuel.value;
        let data;
        try {
            data = await Api.priceDistribution(fuel);
        } catch (e) {
            return;
        }
        if (!data.buckets.length || typeof Chart === 'undefined') {
            return;
        }
        const total = data.buckets.reduce((sum, b) => sum + b.estaciones, 0);
        el.statsDistributionNote.textContent = `${total.toLocaleString('es-ES')} gasolineras con precio de ${FUEL_LABELS[fuel] || fuel} hoy.`;
        if (state.statsDistributionChart) {
            state.statsDistributionChart.destroy();
            state.statsDistributionChart = null;
        }
        state.statsDistributionChart = new Chart(el.statsDistributionChart, {
            type: 'bar',
            data: {
                labels: data.buckets.map((b) => `${b.desde.toFixed(2)}-${b.hasta.toFixed(2)}`),
                datasets: [{
                    label: 'Gasolineras',
                    data: data.buckets.map((b) => b.estaciones),
                    backgroundColor: '#B8860B',
                }],
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { title: { display: true, text: '€/L', font: { size: 10 } } } },
            },
        });
    }

    function setStatsGroup(group) {
        state.statsGroup = group;
        el.statsGroupToggle.querySelectorAll('button').forEach((btn) => {
            btn.setAttribute('aria-pressed', String(btn.dataset.group === group));
        });
        renderStatsNationalChart();
        renderStatsByFuelChart();
        if (state.statsSelectedIdeess) {
            renderStatsStationChart();
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

        el.modalFavoriteToggle.setAttribute('aria-pressed', String(isFavorite(ideess)));
        el.modalFavoriteToggle.setAttribute('aria-label', favoriteToggleLabel(ideess));
        el.modalFavoriteToggle.onclick = () => {
            if (isFavorite(ideess)) {
                removeFromFavorites(ideess);
                el.modalFavoriteToggle.setAttribute('aria-pressed', 'false');
                el.modalFavoriteToggle.setAttribute('aria-label', favoriteToggleLabel(ideess));
            } else {
                addToFavorites(ideess, station.rotulo, station.direccion, station.municipio);
                el.modalFavoriteToggle.setAttribute('aria-pressed', 'true');
                el.modalFavoriteToggle.setAttribute('aria-label', favoriteToggleLabel(ideess));
                showToast(`${station.rotulo} añadida a favoritas`);
                setTimeout(() => el.stationModal.close(), 900);
            }
        };

        el.modalCompareToggle.textContent = compareToggleLabel(ideess);
        el.modalCompareToggle.onclick = () => {
            if (isInCompareList(ideess)) {
                removeFromCompare(ideess);
                el.modalCompareToggle.textContent = compareToggleLabel(ideess);
            } else {
                addToCompare(ideess, station.rotulo, station.direccion);
                el.modalCompareToggle.textContent = compareToggleLabel(ideess);
                showToast(`${station.rotulo} añadida a comparar`);
                setTimeout(() => el.stationModal.close(), 900);
            }
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
            data = await Api.history(ideess, fuel, daysAgoIso(14), todayIso());
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

    // ---- Eventos ----

    el.paginationPrev.addEventListener('click', async () => {
        if (state.stationsPage > 1) {
            await loadStationsPage(state.stationsPage - 1);
            el.viewList.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
    el.paginationNext.addEventListener('click', async () => {
        const totalPages = Math.max(1, Math.ceil(state.stationsTotal / PAGE_SIZE));
        if (state.stationsPage < totalPages) {
            await loadStationsPage(state.stationsPage + 1);
            el.viewList.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    el.searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const query = el.searchInput.value.trim();
        if (query.length === 0 && state.stationsMode === 'search') {
            backToNearby();
            return;
        }
        if (query.length < 2) {
            return;
        }
        performSearch(query);
    });

    el.searchInput.addEventListener('input', () => {
        if (el.searchInput.value.trim().length === 0 && state.stationsMode === 'search') {
            backToNearby();
        }
    });

    el.backToNearby.addEventListener('click', backToNearby);

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
    el.viewStatsBtn.addEventListener('click', () => switchView('stats'));
    el.heatmapToggle.addEventListener('click', toggleHeatmap);
    el.locateMe.addEventListener('click', locateOnMap);

    el.statsFuel.addEventListener('change', () => {
        renderStatsNationalChart();
        renderStatsProvinceChart();
        renderStatsDistributionChart();
        if (state.statsSelectedIdeess) {
            renderStatsStationChart();
        }
    });
    el.statsGroupToggle.querySelectorAll('button').forEach((btn) => {
        btn.addEventListener('click', () => setStatsGroup(btn.dataset.group));
    });
    [el.statsFrom, el.statsTo].forEach((input) => {
        input.addEventListener('change', () => {
            renderStatsNationalChart();
            renderStatsByFuelChart();
            if (state.statsSelectedIdeess) {
                renderStatsStationChart();
            }
        });
    });
    el.statsStationSearch.addEventListener('input', () => {
        clearTimeout(state.statsSearchTimer);
        const query = el.statsStationSearch.value;
        state.statsSearchTimer = setTimeout(() => searchStatsStation(query), 300);
    });

    el.modalClose.addEventListener('click', () => el.stationModal.close());
    el.stationModal.addEventListener('click', (e) => {
        if (e.target === el.stationModal) el.stationModal.close();
    });

    el.compareOpen.addEventListener('click', openComparePanel);
    el.compareClose.addEventListener('click', () => el.comparePanel.close());
    el.comparePanel.addEventListener('click', (e) => {
        if (e.target === el.comparePanel) el.comparePanel.close();
    });

    el.favoritesOpen.addEventListener('click', openFavoritesPanel);
    el.favoritesClose.addEventListener('click', () => el.favoritesPanel.close());
    el.favoritesPanel.addEventListener('click', (e) => {
        if (e.target === el.favoritesPanel) el.favoritesPanel.close();
    });

    el.statsTo.value = todayIso();
    el.statsFrom.value = daysAgoIso(30);
    el.statsTo.max = todayIso();
    el.statsFrom.max = todayIso();

    updateCompareCount();
    updateFavoritesCount();
    requestGeolocation();
    loadNationalHeadline();
})();
