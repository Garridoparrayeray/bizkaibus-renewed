(() => {
    const IS_METRO = window.__bbNetwork === 'metro';
    const IS_EUSKOTREN = window.__bbNetwork === 'euskotren';
    let networkQuery = '';
    if (IS_METRO || IS_EUSKOTREN) {
        networkQuery = '?red=' + window.__bbNetwork;
    }
    const FAVORITES_STORAGE_KEY = IS_METRO ? 'metrobilbao_favorites'
        : IS_EUSKOTREN ? 'euskotren_favorites'
        : 'bizkaibus_favorites';

    const ICONS = {
        pin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-7.5-7-12a7 7 0 0 1 14 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></svg>',
        bus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="12" rx="2"/><line x1="3" y1="11" x2="21" y2="11"/><circle cx="7.5" cy="19" r="1.5"/><circle cx="16.5" cy="19" r="1.5"/></svg>',
        heartOutline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
        heartFilled: '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>',
    };

    const state = {
        currentStop: null,
        currentLine: null,
        timetableStopId: null,
        favoriteKeys: new Set(),
        departuresRefreshTimer: null,
    };

    const favoriteLabelCache = new Map();

    const mapState = {
        map: null,
        routeLayers: [],
        vehicleMarkers: [],
        refreshTimer: null,
    };
    const LINE_MAP_REFRESH_MS = 25000;
    const DEPARTURES_REFRESH_MS = 25000;

    const el = {
        homeLink: document.getElementById('home-link'),
        networkSwitch: document.getElementById('network-switcher'),
        menuOpen: document.getElementById('menu-open'),
        menuClose: document.getElementById('menu-close'),
        sideMenu: document.getElementById('side-menu'),
        menuFavoritesOpen: document.getElementById('menu-favorites-open'),
        menuAlertsEmpty: document.getElementById('menu-alerts-empty'),
        menuAlertsList: document.getElementById('menu-alerts-list'),
        favoritesClose: document.getElementById('favorites-close'),
        favoritesPanel: document.getElementById('favorites-panel'),
        searchForm: document.getElementById('search-form'),
        searchInput: document.getElementById('search-input'),
        searchResults: document.getElementById('search-results'),
        nearbyBtn: document.getElementById('nearby-btn'),
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
        liveTimetableLink: document.getElementById('live-timetable-link'),
        liveMinutes: document.getElementById('live-minutes'),
        liveStatusDot: document.getElementById('live-status-dot'),
        liveStatusText: document.getElementById('live-status-text'),
        liveIncidentsLink: document.getElementById('live-incidents-link'),
        liveMoreList: document.getElementById('live-more-list'),
        platformPanel: document.getElementById('platform-panel'),
        platformPanelStop: document.getElementById('platform-panel-stop'),
        platformFavorite: document.getElementById('platform-favorite'),
        platformClose: document.getElementById('platform-close'),
        platformTimetableLink: document.getElementById('platform-timetable-link'),
        platformTimetableLines: document.getElementById('platform-timetable-lines'),
        platformColumns: document.getElementById('platform-columns'),
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
        disclaimer: document.getElementById('disclaimer'),
        lineMap: document.getElementById('line-map'),
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
        modalAlertsToggle: document.getElementById('modal-alerts-toggle'),
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

    function liveBadgeText(status, etaMinutes) {
        if (status !== 'live') return 'PROGRAMADO';
        return etaMinutes <= 3 ? 'LLEGANDO' : 'EN RUTA';
    }

    function statusLabel(status, delayMinutes) {
        if (status === 'live') {
            return delayMinutes > 0
                ? { text: `Retraso +${delayMinutes}m`, className: 'status-warn' }
                : { text: 'En hora', className: 'status-ok' };
        }
        if (status === 'finished') return { text: 'Finalizado', className: 'status-muted' };
        if (status === 'departed') return { text: 'Ya salió', className: 'status-muted' };
        return { text: 'Programado', className: 'status-muted' };
    }

    function setPillContent(button, iconSvg, label, area, hint) {
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
        if (hint) {
            const hintEl = document.createElement('small');
            hintEl.className = 'pill-hint';
            hintEl.textContent = hint;
            textWrap.appendChild(hintEl);
        }
        button.append(icon, textWrap);
    }

    function toggleNetworkMenu() {
        const isOpen = el.networkSwitch.classList.toggle('open');
        el.homeLink.setAttribute('aria-expanded', String(isOpen));
    }

    function closeNetworkMenu() {
        el.networkSwitch.classList.remove('open');
        el.homeLink.setAttribute('aria-expanded', 'false');
    }

    function closeLiveCard() {
        state.currentStop = null;
        el.liveCard.hidden = true;
        el.platformPanel.hidden = true;
        el.liveEmpty.hidden = false;
        stopDeparturesRefresh();
        if (window.location.pathname.startsWith('/stops/')) history.replaceState(null, '', '/?red=' + window.__bbNetwork);
    }

    function closeTimetableSection() {
        state.currentLine = null;
        el.timetableSection.hidden = true;
        stopLineMapRefresh();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        if (window.location.pathname.startsWith('/lines/')) history.replaceState(null, '', '/?red=' + window.__bbNetwork);
    }

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
            ...stops.map((s) => ({ type: 'stop', id: s.id, label: s.name, area: s.area, hint: s.hint })),
            ...lines.map((l) => ({ type: 'line', id: l.id, label: `${l.code} · ${l.name}`, area: '', hint: null })),
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
            setPillContent(button, item.type === 'stop' ? ICONS.pin : ICONS.bus, item.label, item.area, item.hint);
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

    function showSearchMessage(text) {
        el.searchResults.innerHTML = '';
        const li = document.createElement('li');
        li.className = 'search-message';
        li.textContent = text;
        el.searchResults.appendChild(li);
        el.searchResults.hidden = false;
    }

    function formatDistance(meters) {
        return meters < 1000 ? `${Math.max(10, Math.round(meters / 10) * 10)} m` : `${(meters / 1000).toFixed(1).replace('.', ',')} km`;
    }

    function renderNearbyResults(stops) {
        el.searchResults.innerHTML = '';
        for (const stop of stops) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'pill';
            const next = stop.next
                ? `${stop.next.lineCode} → ${stop.next.headsign} · ${Math.max(stop.next.etaMinutes, 0)} min`
                : 'Sin salidas próximas';
            setPillContent(button, ICONS.pin, stop.name, stop.area, `${formatDistance(stop.distanceM)} · ${next}`);
            button.addEventListener('click', () => {
                el.searchResults.hidden = true;
                selectStop(stop.id);
            });
            li.appendChild(button);
            el.searchResults.appendChild(li);
        }
        el.searchResults.hidden = false;
    }

    async function findNearby() {
        if (!('geolocation' in navigator)) {
            showSearchMessage('Tu dispositivo no permite obtener la ubicación.');
            return;
        }
        showSearchMessage('Buscando tu ubicación…');
        let position;
        try {
            position = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
            });
        } catch (error) {
            showSearchMessage(error.code === 1
                ? 'Has denegado el permiso de ubicación. Actívalo en los ajustes del navegador para ver las paradas cercanas.'
                : 'No se pudo obtener tu ubicación. Inténtalo de nuevo.');
            return;
        }
        let data;
        try {
            data = await Api.nearby(position.coords.latitude.toFixed(5), position.coords.longitude.toFixed(5));
        } catch (error) {
            showSearchMessage(error.status === 422
                ? 'Tu ubicación está fuera de la zona cubierta por esta app.'
                : 'No se pudieron consultar las paradas cercanas.');
            return;
        }
        if (data.stops.length === 0) {
            showSearchMessage('No hay paradas a menos de 2 km de ti.');
            return;
        }
        renderNearbyResults(data.stops);
    }

    async function selectStop(stopId) {
        let stop;
        try {
            stop = await Api.stop(stopId);
        } catch (e) {
            return;
        }
        state.currentStop = { id: stop.id, name: stop.name, lines: stop.lines || [] };
        el.liveEmpty.hidden = true;
        if (IS_METRO || IS_EUSKOTREN) {
            updateFavoriteButton(el.platformFavorite, 'stop', stop.id);
            el.platformPanelStop.textContent = stop.name;
            el.platformPanel.hidden = false;
        } else {
            updateFavoriteButton(el.liveFavorite, 'stop', stop.id);
            el.liveCard.hidden = false;
        }
        if (!window.location.pathname.startsWith('/stops/' + stopId)) {
            history.pushState({ view: 'stop', id: stopId }, '', '/stops/' + stopId + networkQuery);
        } else if (!history.state || !history.state.view) {
            history.replaceState({ view: 'stop', id: stopId }, '', window.location.href);
        }

        await loadDepartures();
        startDeparturesRefresh();
    }

    function startDeparturesRefresh() {
        stopDeparturesRefresh();
        state.departuresRefreshTimer = setInterval(loadDepartures, DEPARTURES_REFRESH_MS);
    }

    function stopDeparturesRefresh() {
        if (state.departuresRefreshTimer) clearInterval(state.departuresRefreshTimer);
        state.departuresRefreshTimer = null;
    }

    async function loadDepartures() {
        if (!state.currentStop) return;
        let data;
        try {
            data = await Api.stopDepartures(state.currentStop.id, 20);
        } catch (e) {
            if (IS_METRO || IS_EUSKOTREN) {
                renderPlatformPanel({ departures: [], platforms: [] });
            } else {
                renderLiveCard([]);
            }
            return;
        }
        if (IS_METRO || IS_EUSKOTREN) {
            renderPlatformPanel(data);
        } else {
            renderLiveCard(data.departures);
        }
    }

    function renderStopLines(lines) {
        const single = lines.length === 1;
        el.liveTimetableLink.hidden = lines.length > 1;
        el.liveTimetableLink.disabled = !single;
        el.liveTimetableLink.dataset.lineId = single ? lines[0].id : '';
        if (lines.length < 2) return;

        for (const line of lines) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<strong>${line.code}</strong><span>${line.name}</span>`;
            button.addEventListener('click', () => selectLine(line.id, true));
            li.appendChild(button);
            el.liveMoreList.appendChild(li);
        }
    }

    function renderLiveCard(departures) {
        const favoriteIndex = departures.findIndex((d) => state.favoriteKeys.has(favoriteKey('line', d.lineId)));
        const heroIndex = favoriteIndex !== -1 ? favoriteIndex : 0;
        const departure = departures[heroIndex] || null;
        const others = departures.filter((_, i) => i !== heroIndex);
        el.liveMoreList.innerHTML = '';

        if (!departure) {
            el.liveLine.textContent = state.currentStop.name;
            el.liveHeadsign.textContent = 'Sin próximas salidas';
            el.liveMinutes.textContent = '–';
            el.liveBadge.textContent = 'Sin datos';
            el.liveStatusText.textContent = '';
            el.liveStatusDot.className = 'status-dot';
            el.liveIncidentsLink.hidden = true;
            el.liveOpenDetail.disabled = true;
            renderStopLines(state.currentStop.lines || []);
            return;
        }

        el.liveTimetableLink.hidden = false;

        const { text, className } = statusLabel(departure.status, departure.delayMinutes);
        el.liveLine.textContent = `${departure.lineCode} · ${departure.headsign}`;
        el.liveHeadsign.textContent = state.currentStop.name;
        el.liveMinutes.textContent = Math.max(departure.etaMinutes, 0);
        el.liveBadge.textContent = liveBadgeText(departure.status, departure.etaMinutes);
        el.liveStatusText.textContent = `${departure.lineCode} · ${departure.scheduledTime}`;
        el.liveStatusDot.className = `status-dot ${className}`;
        el.liveIncidentsLink.hidden = IS_METRO || IS_EUSKOTREN;
        el.liveOpenDetail.disabled = false;
        el.liveOpenDetail.dataset.tripKey = departure.tripKey;
        el.liveTimetableLink.disabled = false;
        el.liveTimetableLink.dataset.lineId = departure.lineId;

        for (const other of others) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<strong>${other.lineCode}</strong><span>${other.headsign}</span><span>${Math.max(other.etaMinutes, 0)} min</span>`;

            button.addEventListener('click', () => {
                if (button.dataset.tapped === 'true') {
                    openVehicleModal(other.tripKey);
                } else {
                    button.dataset.tapped = 'true';
                    selectLine(other.lineId, true);
                }
            });

            li.appendChild(button);
            el.liveMoreList.appendChild(li);
        }
    }

    const PLATFORM_DIRECTIONS = [
        { key: 'toward_reference', label: 'Sentido 1' },
        { key: 'away_from_reference', label: 'Sentido 2' },
    ];

    function renderPlatformColumn(direction, departures) {
        const column = document.createElement('div');
        column.className = 'platform-column';

        if (departures.length === 0) {
            const headsignEl = document.createElement('p');
            headsignEl.className = 'platform-column-headsign';
            headsignEl.textContent = direction.label;
            const empty = document.createElement('p');
            empty.className = 'platform-column-empty';
            empty.textContent = 'Sin próximas salidas';
            column.append(headsignEl, empty);
            return column;
        }

        const [next, ...rest] = departures;

        const headsignEl = document.createElement('p');
        headsignEl.className = 'platform-column-headsign';
        headsignEl.textContent = `→ ${next.headsign}`;

        const nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'time-display';
        nextButton.innerHTML = `<span class="platform-column-next"><strong>${Math.max(next.etaMinutes, 0)}</strong><span>min</span></span>`;
        nextButton.addEventListener('click', () => openVehicleModal(next.tripKey));

        const scheduled = document.createElement('p');
        scheduled.className = 'platform-column-scheduled';
        scheduled.textContent = next.scheduledTime;

        column.append(headsignEl, nextButton, scheduled);

        if (rest.length > 0) {
            const list = document.createElement('ul');
            list.className = 'platform-column-list';
            for (const departure of rest) {
                const li = document.createElement('li');
                const button = document.createElement('button');
                button.type = 'button';
                button.innerHTML = `<span>${departure.headsign}</span><span>${departure.scheduledTime} · ${Math.max(departure.etaMinutes, 0)} min</span>`;
                button.addEventListener('click', () => openVehicleModal(departure.tripKey));
                li.appendChild(button);
                list.appendChild(li);
            }
            column.appendChild(list);
        }

        return column;
    }

    function renderPlatformPanel(data) {
        el.platformColumns.innerHTML = '';
        const departures = data.departures || [];
        const platformNotice = document.getElementById('platform-notice');
        platformNotice.hidden = departures.length > 0;
        platformNotice.textContent = `No hay ${IS_METRO ? 'metros' : 'trenes'} en las próximas 4 horas: puede ser fuera de horario o sin servicio en esta estación. Consulta el horario completo.`;

        if (data.platforms && data.platforms.length) {
            for (const platform of data.platforms) {
                const platformDepartures = departures.filter((d) => d.platformId === platform.id);
                el.platformColumns.appendChild(renderPlatformColumn(platform, platformDepartures));
            }
        } else {
            for (const direction of PLATFORM_DIRECTIONS) {
                const columnDepartures = departures.filter((d) => d.direction === direction.key);
                const termini = data.directionLabels && data.directionLabels[direction.key];
                const label = termini ? 'Hacia ' + termini : direction.label;
                el.platformColumns.appendChild(renderPlatformColumn({ ...direction, label }, columnDepartures));
            }
        }
        const distinctLines = [];
        const seenLineIds = new Set();
        for (const departure of departures) {
            if (seenLineIds.has(departure.lineId)) continue;
            seenLineIds.add(departure.lineId);
            distinctLines.push(departure);
        }
        if (distinctLines.length === 0) {
            for (const line of state.currentStop?.lines || []) {
                distinctLines.push({ lineId: line.id, lineCode: line.code });
            }
        }

        if (distinctLines.length > 1) {
            el.platformTimetableLink.hidden = true;
            el.platformTimetableLines.hidden = false;
            el.platformTimetableLines.innerHTML = '';
            for (const line of distinctLines) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pill';
                button.textContent = line.lineCode;
                button.addEventListener('click', () => selectLine(line.lineId, true));
                el.platformTimetableLines.appendChild(button);
            }
        } else {
            el.platformTimetableLink.hidden = false;
            el.platformTimetableLines.hidden = true;
            const lineId = distinctLines[0]?.lineId;
            el.platformTimetableLink.disabled = lineId === undefined;
            el.platformTimetableLink.dataset.lineId = lineId ?? '';
        }
    }

    async function selectLine(lineId, scopeToCurrentStop = false) {
        let line;
        try {
            line = await Api.line(lineId);
        } catch (e) {
            return;
        }
        state.currentLine = { id: line.id, code: line.code, name: line.name };
        state.timetableStopId = scopeToCurrentStop ? (state.currentStop?.id ?? null) : null;
        updateFavoriteButton(el.timetableFavorite, 'line', line.id);
        el.timetableSection.hidden = false;
        el.timetableLine.textContent = `${line.code} · ${line.name}`;
        el.timetableSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

        if (!window.location.pathname.startsWith('/lines/' + lineId)) {
            history.pushState({ view: 'line', id: lineId }, '', '/lines/' + lineId + networkQuery);
        } else if (!history.state || !history.state.view) {
            history.replaceState({ view: 'line', id: lineId }, '', window.location.href);
        }

        await loadTimetable();
        if (!IS_METRO && !IS_EUSKOTREN) {
            await loadLineMap(line.id);
            startLineMapRefresh(line.id);
        }
    }

    async function loadTimetable() {
        if (!state.currentLine) return;
        let data;
        try {
            data = await Api.timetable(state.currentLine.id, {
                date: el.filterDate.value,
                hourFrom: el.filterHourFrom.value,
                hourTo: el.filterHourTo.value,
                stopId: state.timetableStopId,
            });
        } catch (e) {
            return;
        }
        renderTimetableRows(data.entries);
        const timetableNote = document.getElementById('timetable-note');
        timetableNote.hidden = !data.beyondPublished;
        if (data.beyondPublished) {
            const [year, month, day] = data.publishedUntil.split('-');
            timetableNote.textContent = `El operador solo ha publicado horarios hasta el ${day}/${month}/${year}; para este día se muestra el de un día normal y puede no coincidir.`;
        }
        const sourceLabel = IS_METRO
            ? 'Datos: Metro Bilbao / Open Data Metro Bilbao'
            : IS_EUSKOTREN
                ? 'Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)'
                : 'Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)';
        el.attribution.textContent = `${sourceLabel} · Horario base publicado: ${data.scheduleSourcePublished}`;
    }

    function renderTimetableRows(entries) {
        el.timetableBody.innerHTML = '';
        el.timetableEmpty.hidden = entries.length > 0;

        for (const entry of entries) {
            const { text, className } = statusLabel(entry.status, entry.delayMinutes);
            const tr = document.createElement('tr');
            if (entry.status === 'departed') tr.className = 'is-past';

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
        if (state.currentLine?.id !== lineId) return;
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

    function extractScheduleFields(block) {
        const fields = { season: '', from: '', to: '', outbound: '', returnTrip: '' };
        for (const [key, value] of Object.entries(block)) {
            if (!value) continue;
            const k = key.toUpperCase();
            if (k.includes('EU') && !k.includes('CAS')) continue;
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
            const parseDate = (text) => {
                const m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(text || '');
                return m ? new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1])) : null;
            };
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const entries = data.schedule.map((rawBlock) => {
                const fields = extractScheduleFields(rawBlock);
                const from = parseDate(fields.from);
                const to = parseDate(fields.to);
                const isCurrent = from !== null && to !== null && from <= today && today <= to;
                return { fields, isCurrent, isPast: to !== null && to < today };
            });
            entries.sort((x, y) => Number(y.isCurrent) - Number(x.isCurrent) || Number(x.isPast) - Number(y.isPast));

            for (const { fields, isCurrent, isPast } of entries) {
                const block = document.createElement('div');
                block.className = 'schedule-block' + (isCurrent ? ' is-current' : '') + (isPast ? ' is-past' : '');

                const h4 = document.createElement('h4');
                h4.textContent = fields.season || 'Horario';
                block.appendChild(h4);
                if (isCurrent) {
                    const tag = document.createElement('span');
                    tag.className = 'schedule-current-tag';
                    tag.textContent = 'Vigente hoy';
                    h4.appendChild(tag);
                }

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

    async function openVehicleModal(tripKey) {
        if (!tripKey) return;
        if (IS_METRO || IS_EUSKOTREN) {
            await openTripStopsModal(tripKey);
            return;
        }

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
            : data.status === 'finished'
                ? 'Este viaje ya ha finalizado. Se muestra el horario programado.'
                : 'Sin seguimiento en vivo en este momento. Se muestra el horario programado.';

        el.modalAlertsToggle.dataset.lineId = tripKey.split('-')[0];
        el.modalAlertsToggle.textContent = 'Ver incidencias';
        el.modalAlertsToggle.disabled = false;
        el.modalAlertsToggle.hidden = false;
        el.modalAlerts.hidden = true;
        el.modalAlerts.innerHTML = '';

        el.modalStops.innerHTML = '';
        for (const stop of data.stops) {
            const li = document.createElement('li');
            li.className = stop.isCurrent ? 'stop-current' : '';
            li.textContent = `${stop.scheduledTime} · ${stop.name}`;
            el.modalStops.appendChild(li);
        }

        el.modal.showModal();
    }

    async function openTripStopsModal(tripKey) {
        let data;
        try {
            data = await Api.tripStops(tripKey, state.currentStop?.id ?? '');
        } catch (e) {
            return;
        }

        el.modalBadge.textContent = 'Programado';
        el.modalLine.textContent = `${data.lineCode} · ${data.lineName}`;
        el.modalHeadsign.textContent = `Dirección: ${data.headsign}`;
        el.modalVehicle.hidden = true;
        el.modalAlertsToggle.hidden = true;
        el.modalAlerts.hidden = true;
        el.modalAlerts.innerHTML = '';

        const nowHm = new Date().toTimeString().slice(0, 5);
        el.modalStops.innerHTML = '';
        for (const stop of data.stops) {
            const li = document.createElement('li');
            const classes = [];
            if (stop.isTarget) classes.push('stop-current');
            if (stop.scheduledTime < nowHm) classes.push('stop-past');
            li.className = classes.join(' ');
            li.textContent = `${stop.scheduledTime} · ${stop.name}`;
            el.modalStops.appendChild(li);
        }

        el.modal.showModal();
    }

    async function loadModalAlerts() {
        const lineId = el.modalAlertsToggle.dataset.lineId;
        el.modalAlertsToggle.disabled = true;
        el.modalAlertsToggle.textContent = 'Cargando…';

        let alerts;
        try {
            alerts = (await Api.alerts(lineId)).alerts;
        } catch (e) {
            el.modalAlertsToggle.textContent = 'No se han podido cargar';
            return;
        }

        el.modalAlerts.innerHTML = '';
        if (alerts.length === 0) {
            const li = document.createElement('li');
            li.textContent = 'Sin incidencias activas para esta línea.';
            el.modalAlerts.appendChild(li);
        } else {
            for (const alert of alerts) {
                const li = document.createElement('li');
                li.textContent = `${alert.summary}: ${alert.description}`;
                el.modalAlerts.appendChild(li);
            }
        }
        el.modalAlerts.hidden = false;
        el.modalAlertsToggle.hidden = true;
    }

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
        syncAlertFavorites();
    }

    async function openSideMenu() {
        el.sideMenu.showModal();

        el.menuAlertsList.innerHTML = '';
        el.menuAlertsEmpty.hidden = false;

        if (IS_METRO) {
            el.menuAlertsEmpty.textContent = 'Cargando incidencias…';
            let metroAlerts;
            try {
                metroAlerts = (await Api.alerts()).alerts;
            } catch (e) {
                el.menuAlertsEmpty.textContent = 'No se han podido cargar las incidencias ahora mismo.';
                return;
            }
            el.menuAlertsEmpty.hidden = metroAlerts.length > 0;
            if (metroAlerts.length === 0) {
                el.menuAlertsEmpty.textContent = 'No hay incidencias activas ahora mismo.';
                return;
            }
            for (const alert of metroAlerts) {
                const li = document.createElement('li');
                const summarySpan = document.createElement('span');
                summarySpan.className = 'alert-summary';
                summarySpan.textContent = alert.summary;
                li.append(summarySpan);
                el.menuAlertsList.appendChild(li);
            }
            return;
        }

        const favoriteLines = readFavorites().filter((f) => f.type === 'line');
        const relevantLineIds = new Set(favoriteLines.map((f) => String(f.refId)));
        if (state.currentLine) {
            relevantLineIds.add(String(state.currentLine.id));
        }

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
        updateFavoriteButton(el.platformFavorite, 'stop', state.currentStop?.id);
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

    const netBanner = document.getElementById('net-banner');
    window.addEventListener('bb:api', (event) => {
        netBanner.hidden = event.detail.ok;
    });
    window.addEventListener('offline', () => { netBanner.hidden = false; });
    window.addEventListener('online', () => { netBanner.hidden = true; });
    document.getElementById('net-retry').addEventListener('click', () => {
        if (state.currentStop) loadDepartures();
        if (state.currentLine) loadTimetable();
        if (!state.currentStop && !state.currentLine) netBanner.hidden = true;
    });

    el.homeLink.addEventListener('click', toggleNetworkMenu);
    document.addEventListener('click', (e) => {
        if (!el.homeLink.contains(e.target) && !el.networkSwitch.contains(e.target)) {
            closeNetworkMenu();
        }
    });

    el.menuOpen.addEventListener('click', openSideMenu);
    el.liveIncidentsLink.addEventListener('click', openSideMenu);
    el.menuClose.addEventListener('click', () => el.sideMenu.close());
    el.sideMenu.addEventListener('click', (e) => {
        if (e.target === el.sideMenu) el.sideMenu.close();
    });

    const legalOpen = document.getElementById('legal-open');
    const legalPanel = document.getElementById('legal-panel');
    const legalClose = document.getElementById('legal-close');
    if (legalOpen && legalPanel && legalClose) {
        legalOpen.addEventListener('click', () => legalPanel.showModal());
        legalClose.addEventListener('click', () => legalPanel.close());
        legalPanel.addEventListener('click', (e) => {
            if (e.target === legalPanel) legalPanel.close();
        });
    }

    el.menuFavoritesOpen.addEventListener('click', () => {
        el.sideMenu.close();
        openFavoritesPanel();
    });
    el.favoritesClose.addEventListener('click', closeFavoritesPanel);

    const alertsToggle = document.getElementById('alerts-toggle');
    const alertsNote = document.getElementById('alerts-note');
    const ALERTS_SYNC_TAG = 'line-alerts-check';
    const ALERTS_SYNC_INTERVAL_MS = 12 * 60 * 60 * 1000;
    const ALERTS_FOREGROUND_COOLDOWN_MS = 5 * 60 * 1000;
    let lastForegroundAlertCheck = 0;

    function syncAlertFavorites() {
        const lineIds = readFavorites().filter((favorite) => favorite.type === 'line').map((favorite) => favorite.refId);
        return AlertsStore.setFavorites(window.__bbNetwork, lineIds).catch(() => {});
    }

    function setAlertsNote(message) {
        alertsNote.textContent = message;
        alertsNote.hidden = message === '';
    }

    async function enableLineAlerts() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            setAlertsNote('Tu navegador no admite notificaciones.');
            return false;
        }
        if ((await Notification.requestPermission()) !== 'granted') {
            setAlertsNote('Las notificaciones están bloqueadas. Actívalas en los ajustes del navegador para recibir avisos.');
            return false;
        }
        const registration = await navigator.serviceWorker.ready;
        await syncAlertFavorites();
        await AlertsStore.resetBaseline();
        await AlertsStore.set('enabled', true);
        await AlertsStore.checkAlerts();

        let background = false;
        if ('periodicSync' in registration) {
            try {
                await registration.periodicSync.register(ALERTS_SYNC_TAG, { minInterval: ALERTS_SYNC_INTERVAL_MS });
                background = true;
            } catch (error) {
                background = false;
            }
        }
        setAlertsNote(background
            ? 'Te avisaremos de las incidencias nuevas de tus líneas favoritas, incluso con la app cerrada.'
            : 'Te avisaremos al abrir la app: tu navegador no permite avisos en segundo plano.');
        return true;
    }

    async function disableLineAlerts() {
        await AlertsStore.set('enabled', false);
        setAlertsNote('');
        if ('serviceWorker' in navigator) {
            const registration = await navigator.serviceWorker.ready;
            if ('periodicSync' in registration) await registration.periodicSync.unregister(ALERTS_SYNC_TAG).catch(() => {});
        }
    }

    async function runForegroundAlertCheck() {
        if (Date.now() - lastForegroundAlertCheck < ALERTS_FOREGROUND_COOLDOWN_MS) return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        if ((await AlertsStore.get('enabled')) !== true) return;
        lastForegroundAlertCheck = Date.now();
        const fresh = await AlertsStore.checkAlerts();
        if (fresh.length > 0) await AlertsStore.notify(await navigator.serviceWorker.ready, fresh);
    }

    alertsToggle.addEventListener('change', async () => {
        alertsToggle.disabled = true;
        try {
            if (alertsToggle.checked) {
                alertsToggle.checked = await enableLineAlerts();
            } else {
                await disableLineAlerts();
            }
        } catch (error) {
            alertsToggle.checked = false;
            setAlertsNote('No se pudieron activar los avisos. Inténtalo de nuevo.');
        } finally {
            alertsToggle.disabled = false;
        }
    });

    (async () => {
        await syncAlertFavorites();
        const enabled = (await AlertsStore.get('enabled')) === true;
        alertsToggle.checked = enabled && 'Notification' in window && Notification.permission === 'granted';
        if (alertsToggle.checked) runForegroundAlertCheck();
    })().catch(() => {});

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') runForegroundAlertCheck().catch(() => {});
    });

    el.nearbyBtn.addEventListener('click', findNearby);
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
    el.platformFavorite.addEventListener('click', () => toggleFavorite('stop', state.currentStop?.id, el.platformFavorite));
    el.platformClose.addEventListener('click', closeLiveCard);
    el.platformTimetableLink.addEventListener('click', () => {
        if (el.platformTimetableLink.dataset.lineId) selectLine(el.platformTimetableLink.dataset.lineId, true);
    });
    el.timetableFavorite.addEventListener('click', () => toggleFavorite('line', state.currentLine?.id, el.timetableFavorite));
    el.timetableClose.addEventListener('click', closeTimetableSection);
    el.liveOpenDetail.addEventListener('click', () => openVehicleModal(el.liveOpenDetail.dataset.tripKey));
    el.liveTimetableLink.addEventListener('click', () => {
        if (el.liveTimetableLink.dataset.lineId) selectLine(el.liveTimetableLink.dataset.lineId, true);
    });

    el.filterDate.addEventListener('change', loadTimetable);
    el.filterHourFrom.addEventListener('change', loadTimetable);
    el.filterHourTo.addEventListener('change', loadTimetable);
    el.scheduleTextToggle.addEventListener('click', openScheduleModal);

    el.modalClose.addEventListener('click', () => el.modal.close());
    el.modalAlertsToggle.addEventListener('click', loadModalAlerts);
    el.modal.addEventListener('click', (e) => {
        if (e.target === el.modal) el.modal.close();
    });
    el.scheduleModalClose.addEventListener('click', () => el.scheduleModal.close());
    el.scheduleModal.addEventListener('click', (e) => {
        if (e.target === el.scheduleModal) el.scheduleModal.close();
    });

    const now = new Date();
    const pad2 = (n) => String(n).padStart(2, '0');
    el.filterDate.value = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}`;
    el.filterHourFrom.value = `${pad2(now.getHours())}:00`;
    el.filterHourTo.value = `${pad2((now.getHours() + 2) % 24)}:00`;

    if (IS_METRO) {
        el.lineMap.hidden = true;
        el.lineMapEmpty.hidden = true;
        el.scheduleTextToggle.hidden = true;
        el.disclaimer.textContent = 'Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A.';
        el.attribution.textContent = 'Datos: Metro Bilbao / Open Data Metro Bilbao';
        el.liveEmpty.textContent = 'Busca una estación para ver el próximo metro.';
    }

    if (IS_EUSKOTREN) {
        el.lineMap.hidden = true;
        el.lineMapEmpty.hidden = true;
        el.scheduleTextToggle.hidden = true;
        el.disclaimer.textContent = 'Proyecto independiente y no oficial, sin relación con Euskotren S.A.';
        el.attribution.textContent = 'Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)';
        el.liveEmpty.textContent = 'Busca una estación para ver el próximo tren.';
    }

    loadFavorites();

    window.addEventListener('popstate', (e) => {
        if (!e.state || !e.state.view) {
            closeLiveCard();
            closeTimetableSection();
        } else if (e.state.view === 'stop') {
            selectStop(e.state.id);
        } else if (e.state.view === 'line') {
            selectLine(e.state.id);
        }
    });

    const pathMatch = window.location.pathname.match(/^\/(stops|lines)\/([^/]+)/);
    if (pathMatch) {
        if (pathMatch[1] === 'stops') {
            selectStop(pathMatch[2]);
        } else if (pathMatch[1] === 'lines') {
            selectLine(pathMatch[2]);
        }
    }
})();
