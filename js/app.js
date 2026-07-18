(() => {
    const FAVORITES_STORAGE_KEY = 'bizkaibus_favorites';

    const ICONS = {
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.5-7-12a7 7 0 0 1 14 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>',
        bus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="12" rx="2"/><line x1="3" y1="11" x2="21" y2="11"/><circle cx="7.5" cy="19" r="1.5"/><circle cx="16.5" cy="19" r="1.5"/></svg>',
        heartOutline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
        heartFilled: '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
    };

    const state = {
        currentStop: null,
        currentLine: null,
        favoriteKeys: new Set(),
    };

    // Avoids re-fetching every favorite's display name on every add/remove —
    // without this, each toggle re-requests labels for ALL favorites, so the
    // number of in-flight requests grows every time you favorite one more thing.
    const favoriteLabelCache = new Map();

    const mapState = {
        map: null,
        routeLayers: [],
        vehicleMarkers: [],
        refreshTimer: null,
    };
    const LINE_MAP_REFRESH_MS = 25000;

    const el = {
        homeLink: document.getElementById('home-link'),
        menuOpen: document.getElementById('menu-open'),
        menuClose: document.getElementById('menu-close'),
        sideMenu: document.getElementById('side-menu'),
        menuAlertsEmpty: document.getElementById('menu-alerts-empty'),
        menuAlertsList: document.getElementById('menu-alerts-list'),
        favoritesOpen: document.getElementById('favorites-open'),
        favoritesClose: document.getElementById('favorites-close'),
        favoritesPanel: document.getElementById('favorites-panel'),
        searchForm: document.getElementById('search-form'),
        searchInput: document.getElementById('search-input'),
        searchResults: document.getElementById('search-results'),
        favoritesList: document.getElementById('favorites-list'),
        favoritesEmpty: document.getElementById('favorites-empty'),
        liveCard: document.getElementById('live-card'),
        liveEmpty: document.getElementById('live-empty'),
        liveBadge: document.getElementById('live-badge'),
        liveFavorite: document.getElementById('live-favorite'),
        liveClose: document.getElementById('live-close'),
        liveLine: document.getElementById('live-line'),
        liveHeadsign: document.getElementById('live-headsign'),
        liveOpenDetail: document.getElementById('live-open-detail'),
        liveMinutes: document.getElementById('live-minutes'),
        liveStatusDot: document.getElementById('live-status-dot'),
        liveStatusText: document.getElementById('live-status-text'),
        liveMoreList: document.getElementById('live-more-list'),
        timetableSection: document.getElementById('timetable-section'),
        timetableFavorite: document.getElementById('timetable-favorite'),
        timetableClose: document.getElementById('timetable-close'),
        timetableLine: document.getElementById('timetable-line'),
        timetableBody: document.getElementById('timetable-body'),
        timetableEmpty: document.getElementById('timetable-empty'),
        lineMapEmpty: document.getElementById('line-map-empty'),
        filterDate: document.getElementById('filter-date'),
        filterHourFrom: document.getElementById('filter-hour-from'),
        filterHourTo: document.getElementById('filter-hour-to'),
        scheduleTextToggle: document.getElementById('schedule-text-toggle'),
        attribution: document.getElementById('attribution'),
        scheduleModal: document.getElementById('schedule-modal'),
        scheduleModalClose: document.getElementById('schedule-modal-close'),
        scheduleModalLine: document.getElementById('schedule-modal-line'),
        scheduleModalContent: document.getElementById('schedule-modal-content'),
        modal: document.getElementById('vehicle-modal'),
        modalClose: document.getElementById('modal-close'),
        modalBadge: document.getElementById('modal-badge'),
        modalLine: document.getElementById('modal-line'),
        modalHeadsign: document.getElementById('modal-headsign'),
        modalVehicle: document.getElementById('modal-vehicle'),
        modalAlerts: document.getElementById('modal-alerts'),
        modalStops: document.getElementById('modal-stops'),
    };

    function debounce(fn, delayMs) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delayMs);
        };
    }

    function favoriteKey(type, refId) {
        return `${type}:${refId}`;
    }

    function statusLabel(status, delayMinutes) {
        if (status === 'live') {
            return delayMinutes > 0
                ? { text: `Retraso +${delayMinutes}m`, className: 'status-warn' }
                : { text: 'En hora', className: 'status-ok' };
        }
        return { text: 'Programado', className: 'status-muted' };
    }

    /** Builds <icon><label>[<small>area</small>] inside a pill button — icon markup is a static trusted constant, label/area go through textContent. */
    function setPillContent(button, iconSvg, label, area) {
        button.innerHTML = '';
        const icon = document.createElement('span');
        icon.innerHTML = iconSvg;
        const textWrap = document.createElement('span');
        const labelEl = document.createElement('span');
        labelEl.textContent = label;
        textWrap.appendChild(labelEl);
        if (area) {
            const small = document.createElement('small');
            small.textContent = area;
            textWrap.appendChild(small);
        }
        button.append(icon, textWrap);
    }

    // ---- Home / reset ----

    function goHome() {
        el.searchInput.value = '';
        el.searchResults.hidden = true;
        closeLiveCard();
        closeTimetableSection();
    }

    function closeLiveCard() {
        state.currentStop = null;
        el.liveCard.hidden = true;
        el.liveEmpty.hidden = false;
    }

    function closeTimetableSection() {
        state.currentLine = null;
        el.timetableSection.hidden = true;
        stopLineMapRefresh();
    }

    // ---- Search ----

    async function performSearch(query) {
        if (query.trim().length < 2) {
            el.searchResults.hidden = true;
            el.searchResults.innerHTML = '';
            return;
        }
        let results;
        try {
            results = await Api.search(query);
        } catch (e) {
            return;
        }
        renderSearchResults(results.stops, results.lines);
    }

    function renderSearchResults(stops, lines) {
        el.searchResults.innerHTML = '';
        const items = [
            ...stops.map((s) => ({ type: 'stop', id: s.id, label: s.name, area: s.area })),
            ...lines.map((l) => ({ type: 'line', id: l.id, label: `${l.code} · ${l.name}`, area: '' })),
        ];

        if (items.length === 0) {
            el.searchResults.hidden = true;
            return;
        }

        for (const item of items) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pill';
            setPillContent(button, item.type === 'stop' ? ICONS.pin : ICONS.bus, item.label, item.area);
            button.addEventListener('click', () => {
                el.searchInput.value = '';
                el.searchResults.hidden = true;
                if (item.type === 'stop') {
                    selectStop(item.id);
                } else {
                    selectLine(item.id);
                }
            });
            li.appendChild(button);
            el.searchResults.appendChild(li);
        }
        el.searchResults.hidden = false;
    }

    // ---- Stop / live departures ----

    async function selectStop(stopId) {
        let stop;
        try {
            stop = await Api.stop(stopId);
        } catch (e) {
            return;
        }
        state.currentStop = { id: stop.id, name: stop.name };
        updateFavoriteButton(el.liveFavorite, 'stop', stop.id);
        el.liveCard.hidden = false;
        el.liveEmpty.hidden = true;
        await loadDepartures();
    }

    async function loadDepartures() {
        if (!state.currentStop) return;
        let data;
        try {
            data = await Api.stopDepartures(state.currentStop.id, 8);
        } catch (e) {
            renderLiveCard([]);
            return;
        }
        renderLiveCard(data.departures);

        if (data.departures[0]) {
            selectLine(data.departures[0].lineId);
        }
    }

    // Shows the soonest departure as the big "next bus" display, and any
    // other upcoming departures — other lines/directions serving this same
    // stop — as a compact list below it, so a multi-line stop doesn't hide
    // everything behind whichever line happens to be soonest right now.
    function renderLiveCard(departures) {
        const departure = departures[0] || null;
        el.liveMoreList.innerHTML = '';

        if (!departure) {
            el.liveLine.textContent = state.currentStop.name;
            el.liveHeadsign.textContent = 'Sin próximas salidas';
            el.liveMinutes.textContent = '–';
            el.liveBadge.textContent = 'Sin datos';
            el.liveStatusText.textContent = '';
            el.liveStatusDot.className = 'status-dot';
            el.liveOpenDetail.disabled = true;
            return;
        }

        const { text, className } = statusLabel(departure.status, departure.delayMinutes);
        el.liveLine.textContent = `${departure.lineCode} · ${departure.headsign}`;
        el.liveHeadsign.textContent = state.currentStop.name;
        el.liveMinutes.textContent = Math.max(departure.etaMinutes, 0);
        el.liveBadge.textContent = departure.status === 'live' ? 'LLEGANDO' : 'PROGRAMADO';
        el.liveStatusText.textContent = `${departure.lineCode} · ${departure.scheduledTime}${departure.hasAlert ? ' · Incidencia' : ''}`;
        el.liveStatusDot.className = `status-dot ${className}`;
        el.liveOpenDetail.disabled = false;
        el.liveOpenDetail.dataset.tripKey = departure.tripKey;

        for (const other of departures.slice(1)) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<strong>${other.lineCode}</strong><span>${other.headsign}</span><span>${Math.max(other.etaMinutes, 0)} min</span>`;
            button.addEventListener('click', () => openVehicleModal(other.tripKey));
            li.appendChild(button);
            el.liveMoreList.appendChild(li);
        }
    }

    // ---- Line / timetable ----

    async function selectLine(lineId) {
        let line;
        try {
            line = await Api.line(lineId);
        } catch (e) {
            return;
        }
        state.currentLine = { id: line.id, code: line.code, name: line.name };
        updateFavoriteButton(el.timetableFavorite, 'line', line.id);
        el.timetableSection.hidden = false;
        el.timetableLine.textContent = `${line.code} · ${line.name}`;
        await loadTimetable();
        await loadLineMap(line.id);
        startLineMapRefresh(line.id);
    }

    async function loadTimetable() {
        if (!state.currentLine) return;
        let data;
        try {
            data = await Api.timetable(state.currentLine.id, {
                date: el.filterDate.value,
                hourFrom: el.filterHourFrom.value,
                hourTo: el.filterHourTo.value,
            });
        } catch (e) {
            return;
        }
        renderTimetableRows(data.entries);
        el.attribution.textContent = `Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0) · Horario base publicado: ${data.scheduleSourcePublished}`;
    }

    function renderTimetableRows(entries) {
        el.timetableBody.innerHTML = '';
        el.timetableEmpty.hidden = entries.length > 0;

        for (const entry of entries) {
            const { text, className } = statusLabel(entry.status, entry.delayMinutes);
            const tr = document.createElement('tr');

            const departureCell = document.createElement('td');
            departureCell.textContent = entry.departure;

            const headsignCell = document.createElement('td');
            headsignCell.textContent = entry.headsign;

            const statusCell = document.createElement('td');
            statusCell.textContent = text;
            statusCell.className = className;

            tr.append(departureCell, headsignCell, statusCell);
            tr.addEventListener('click', () => openVehicleModal(entry.tripKey));
            el.timetableBody.appendChild(tr);
        }
    }

    // ---- Live line map ----

    function busDivIcon() {
        return L.divIcon({ className: 'bus-marker', html: ICONS.bus, iconSize: [28, 28] });
    }

    function ensureLineMap() {
        if (mapState.map) return mapState.map;
        mapState.map = L.map('line-map', { zoomControl: false, attributionControl: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap',
        }).addTo(mapState.map);
        L.control.attribution({ prefix: false }).addTo(mapState.map);
        return mapState.map;
    }

    function renderLineMap(data) {
        const map = ensureLineMap();
        mapState.routeLayers.forEach((layer) => map.removeLayer(layer));
        mapState.vehicleMarkers.forEach((marker) => map.removeLayer(marker));
        mapState.routeLayers = [];
        mapState.vehicleMarkers = [];

        const allPoints = [];
        for (const pattern of data.patterns) {
            const latlngs = pattern.stops.map((s) => [s.lat, s.lon]);
            if (latlngs.length < 2) continue;
            const polyline = L.polyline(latlngs, { color: '#db2777', weight: 3, opacity: 0.45 }).addTo(map);
            mapState.routeLayers.push(polyline);
            allPoints.push(...latlngs);
        }

        for (const vehicle of data.vehicles) {
            const { lat, lon, name } = vehicle.currentStop;
            const statusText = vehicle.delayMinutes > 0 ? `Retraso +${vehicle.delayMinutes}m` : 'En hora';
            const marker = L.marker([lat, lon], { icon: busDivIcon() })
                .addTo(map)
                .bindPopup(`<strong>${vehicle.headsign || ''}</strong><br>${name}<br>${statusText}`);
            mapState.vehicleMarkers.push(marker);
            allPoints.push([lat, lon]);
        }

        el.lineMapEmpty.hidden = data.vehicles.length > 0;

        if (allPoints.length > 0) {
            map.fitBounds(allPoints, { padding: [24, 24] });
        }
        setTimeout(() => map.invalidateSize(), 100);
    }

    async function loadLineMap(lineId) {
        let data;
        try {
            data = await Api.lineLive(lineId);
        } catch (e) {
            return;
        }
        if (state.currentLine?.id !== lineId) return; // a different line was selected meanwhile
        renderLineMap(data);
    }

    function startLineMapRefresh(lineId) {
        stopLineMapRefresh();
        mapState.refreshTimer = setInterval(() => loadLineMap(lineId), LINE_MAP_REFRESH_MS);
    }

    function stopLineMapRefresh() {
        if (mapState.refreshTimer) clearInterval(mapState.refreshTimer);
        mapState.refreshTimer = null;
    }

    // ---- Official schedule text (popup, Spanish only, structured) ----

    /** Raw fields are generic key/value pairs straight from the legacy XML — group them into season/dates/ida/vuelta, Spanish variants only. */
    function extractScheduleFields(block) {
        const fields = { season: '', from: '', to: '', outbound: '', returnTrip: '' };
        for (const [key, value] of Object.entries(block)) {
            if (!value) continue;
            const k = key.toUpperCase();
            if (k.includes('EU') && !k.includes('CAS')) continue; // skip Basque variants
            if (k.includes('TEMPORADA')) fields.season = value;
            else if (k.includes('DESDE')) fields.from = value;
            else if (k.includes('HASTA')) fields.to = value;
            else if (k.includes('CAS') && (k.includes('JOAN') || k.includes('IDA'))) fields.outbound = value;
            else if (k.includes('CAS') && (k.includes('ETORRI') || k.includes('VUELTA'))) fields.returnTrip = value;
        }
        return fields;
    }

    async function openScheduleModal() {
        if (!state.currentLine) return;

        el.scheduleTextToggle.disabled = true;
        el.scheduleTextToggle.textContent = 'Cargando…';

        let data;
        try {
            data = await Api.lineScheduleText(state.currentLine.id);
        } catch (e) {
            el.scheduleTextToggle.disabled = false;
            el.scheduleTextToggle.textContent = 'Ver horario oficial 2026';
            return;
        }
        el.scheduleTextToggle.disabled = false;
        el.scheduleTextToggle.textContent = 'Ver horario oficial 2026';

        el.scheduleModalLine.textContent = `${state.currentLine.code} · ${state.currentLine.name}`;
        el.scheduleModalContent.innerHTML = '';

        if (data.schedule.length === 0) {
            const p = document.createElement('p');
            p.textContent = 'No hay horario oficial en texto disponible para esta línea.';
            el.scheduleModalContent.appendChild(p);
        } else {
            for (const rawBlock of data.schedule) {
                const fields = extractScheduleFields(rawBlock);
                const block = document.createElement('div');
                block.className = 'schedule-block';

                const h4 = document.createElement('h4');
                h4.textContent = fields.season || 'Horario';
                block.appendChild(h4);

                if (fields.from || fields.to) {
                    const dates = document.createElement('p');
                    dates.className = 'schedule-dates';
                    dates.textContent = [fields.from, fields.to].filter(Boolean).join(' – ');
                    block.appendChild(dates);
                }

                if (fields.outbound) {
                    const h5 = document.createElement('h5');
                    h5.textContent = 'Ida';
                    const p = document.createElement('p');
                    p.textContent = fields.outbound;
                    block.append(h5, p);
                }

                if (fields.returnTrip) {
                    const h5 = document.createElement('h5');
                    h5.textContent = 'Vuelta';
                    const p = document.createElement('p');
                    p.textContent = fields.returnTrip;
                    block.append(h5, p);
                }

                el.scheduleModalContent.appendChild(block);
            }
        }

        el.scheduleModal.showModal();
    }

    // ---- Vehicle detail modal ----

    async function openVehicleModal(tripKey) {
        if (!tripKey) return;
        let data;
        try {
            data = await Api.vehicle(tripKey);
        } catch (e) {
            return;
        }

        const { text } = statusLabel(data.status, data.delayMinutes);
        el.modalBadge.textContent = text;
        el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
        el.modalHeadsign.textContent = `Dirección: ${data.headsign}`;
        el.modalVehicle.textContent = data.vehicleRef
            ? `Vehículo en seguimiento en vivo · Ref. ${data.vehicleRef}`
            : 'Sin seguimiento en vivo en este momento — se muestra el horario programado.';

        el.modalAlerts.innerHTML = '';
        for (const alert of data.alerts) {
            const li = document.createElement('li');
            li.textContent = `${alert.summary}: ${alert.description}`;
            el.modalAlerts.appendChild(li);
        }

        el.modalStops.innerHTML = '';
        for (const stop of data.stops) {
            const li = document.createElement('li');
            li.className = stop.isCurrent ? 'stop-current' : '';
            li.textContent = `${stop.scheduledTime} · ${stop.name}`;
            el.modalStops.appendChild(li);
        }

        el.modal.showModal();
    }

    // ---- Favorites (browser-local: localStorage, no account/server needed) ----

    function openFavoritesPanel() {
        el.favoritesPanel.classList.add('open');
    }

    function closeFavoritesPanel() {
        el.favoritesPanel.classList.remove('open');
    }

    function readFavorites() {
        try {
            const raw = localStorage.getItem(FAVORITES_STORAGE_KEY);
            return raw ? JSON.parse(raw) : [];
        } catch (e) {
            return [];
        }
    }

    function writeFavorites(favorites) {
        localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify(favorites));
    }

    // ---- Side menu: alerts for favorite lines, plus whichever line is open right now ----

    async function openSideMenu() {
        el.sideMenu.showModal();

        const favoriteLines = readFavorites().filter((f) => f.type === 'line');
        const relevantLineIds = new Set(favoriteLines.map((f) => String(f.refId)));
        if (state.currentLine) {
            relevantLineIds.add(String(state.currentLine.id));
        }

        el.menuAlertsList.innerHTML = '';
        el.menuAlertsEmpty.hidden = false;
        el.menuAlertsEmpty.textContent = 'Guarda una línea en favoritos, o abre una, para ver aquí sus incidencias activas.';

        if (relevantLineIds.size === 0) {
            return;
        }

        let allAlerts;
        try {
            allAlerts = (await Api.alerts()).alerts;
        } catch (e) {
            el.menuAlertsEmpty.textContent = 'No se han podido cargar las incidencias ahora mismo.';
            return;
        }

        const matching = allAlerts.filter((alert) => alert.lineRefs.some((ref) => relevantLineIds.has(String(ref))));

        el.menuAlertsEmpty.hidden = matching.length > 0;
        if (matching.length === 0) {
            el.menuAlertsEmpty.textContent = 'Ninguna de esas líneas tiene incidencias activas ahora mismo.';
            return;
        }

        const lineLabels = {};
        for (const lineId of relevantLineIds) {
            try {
                const line = await Api.line(lineId);
                lineLabels[lineId] = line.code;
            } catch (e) {
                lineLabels[lineId] = `#${lineId}`;
            }
        }

        for (const alert of matching) {
            const affectedLabels = alert.lineRefs.filter((ref) => relevantLineIds.has(String(ref))).map((ref) => lineLabels[ref] || ref);
            const li = document.createElement('li');

            const lineSpan = document.createElement('span');
            lineSpan.className = 'alert-line';
            lineSpan.textContent = affectedLabels.join(', ');

            const summarySpan = document.createElement('span');
            summarySpan.className = 'alert-summary';
            summarySpan.textContent = alert.summary;

            const descSpan = document.createElement('span');
            descSpan.className = 'alert-description';
            descSpan.textContent = alert.description;

            li.append(lineSpan, summarySpan, descSpan);
            el.menuAlertsList.appendChild(li);
        }
    }

    async function loadFavorites() {
        const favorites = readFavorites();

        state.favoriteKeys = new Set(favorites.map((f) => favoriteKey(f.type, f.refId)));
        updateFavoriteButton(el.liveFavorite, 'stop', state.currentStop?.id);
        updateFavoriteButton(el.timetableFavorite, 'line', state.currentLine?.id);

        el.favoritesList.querySelectorAll('li:not(#favorites-empty)').forEach((li) => li.remove());
        el.favoritesEmpty.hidden = favorites.length > 0;

        for (const favorite of favorites) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pill';
            const icon = favorite.type === 'stop' ? ICONS.pin : ICONS.bus;
            const cacheKey = favoriteKey(favorite.type, favorite.refId);
            const cached = favoriteLabelCache.get(cacheKey);

            if (cached) {
                setPillContent(button, icon, cached.label, cached.area);
            } else {
                setPillContent(button, icon, '…', '');
                hydrateFavoriteLabel(favorite, button, cacheKey);
            }

            button.addEventListener('click', () => {
                if (favorite.type === 'stop') selectStop(favorite.refId);
                else selectLine(favorite.refId);
                closeFavoritesPanel();
            });
            li.appendChild(button);
            el.favoritesList.appendChild(li);
        }
    }

    async function hydrateFavoriteLabel(favorite, button, cacheKey) {
        const icon = favorite.type === 'stop' ? ICONS.pin : ICONS.bus;
        try {
            const details = favorite.type === 'stop'
                ? await Api.stop(favorite.refId)
                : await Api.line(favorite.refId);
            const label = favorite.type === 'stop' ? details.name : `${details.code} ${details.name}`;
            const area = favorite.type === 'stop' ? details.area : '';
            favoriteLabelCache.set(cacheKey, { label, area });
            setPillContent(button, icon, label, area);
        } catch (e) {
            setPillContent(button, icon, `#${favorite.refId}`, '');
        }
    }

    function updateFavoriteButton(button, type, refId) {
        const isFavorite = !!refId && state.favoriteKeys.has(favoriteKey(type, refId));
        button.innerHTML = isFavorite ? ICONS.heartFilled : ICONS.heartOutline;
        button.classList.toggle('is-favorite', isFavorite);
    }

    function toggleFavorite(type, refId, button) {
        if (!refId) return;
        const key = favoriteKey(type, refId);
        const favorites = readFavorites();

        if (state.favoriteKeys.has(key)) {
            writeFavorites(favorites.filter((f) => favoriteKey(f.type, f.refId) !== key));
        } else {
            writeFavorites([...favorites, { type, refId: String(refId) }]);
        }
        loadFavorites();
    }

    // ---- Wiring ----

    el.homeLink.addEventListener('click', goHome);

    el.menuOpen.addEventListener('click', openSideMenu);
    el.menuClose.addEventListener('click', () => el.sideMenu.close());
    el.sideMenu.addEventListener('click', (e) => {
        if (e.target === el.sideMenu) el.sideMenu.close();
    });

    el.favoritesOpen.addEventListener('click', openFavoritesPanel);
    el.favoritesClose.addEventListener('click', closeFavoritesPanel);

    el.searchInput.addEventListener('input', debounce((e) => performSearch(e.target.value), 300));
    el.searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        performSearch(el.searchInput.value);
    });
    document.addEventListener('click', (e) => {
        if (!el.searchForm.contains(e.target) && !el.searchResults.contains(e.target)) {
            el.searchResults.hidden = true;
        }
    });

    el.liveFavorite.addEventListener('click', () => toggleFavorite('stop', state.currentStop?.id, el.liveFavorite));
    el.liveClose.addEventListener('click', closeLiveCard);
    el.timetableFavorite.addEventListener('click', () => toggleFavorite('line', state.currentLine?.id, el.timetableFavorite));
    el.timetableClose.addEventListener('click', closeTimetableSection);
    el.liveOpenDetail.addEventListener('click', () => openVehicleModal(el.liveOpenDetail.dataset.tripKey));

    el.filterDate.addEventListener('change', loadTimetable);
    el.filterHourFrom.addEventListener('change', loadTimetable);
    el.filterHourTo.addEventListener('change', loadTimetable);
    el.scheduleTextToggle.addEventListener('click', openScheduleModal);

    el.modalClose.addEventListener('click', () => el.modal.close());
    el.modal.addEventListener('click', (e) => {
        if (e.target === el.modal) el.modal.close();
    });
    el.scheduleModalClose.addEventListener('click', () => el.scheduleModal.close());
    el.scheduleModal.addEventListener('click', (e) => {
        if (e.target === el.scheduleModal) el.scheduleModal.close();
    });

    el.filterDate.value = new Date().toISOString().slice(0, 10);

    loadFavorites();
})();
