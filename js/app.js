(() => {
    const IS_METRO = window.__bbNetwork === 'metro';
    // Claves separadas por red — una parada de bus y una estación de metro
    // nunca deben mezclarse en el mismo panel de favoritos.
    const FAVORITES_STORAGE_KEY = IS_METRO ? 'metrobilbao_favorites' : 'bizkaibus_favorites';

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
        departuresRefreshTimer: null,
    };

    // Evita repedir el nombre de cada favorito en cada añadir/quitar — sin
    // esto, cada toggle vuelve a pedir el label de TODOS los favoritos, así
    // que el número de peticiones en vuelo crece cada vez que añades uno más.
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
        networkSwitch: document.getElementById('network-switch'),
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
        liveIncidentsLink: document.getElementById('live-incidents-link'),
        liveMoreList: document.getElementById('live-more-list'),
        platformPanel: document.getElementById('platform-panel'),
        platformPanelStop: document.getElementById('platform-panel-stop'),
        platformFavorite: document.getElementById('platform-favorite'),
        platformClose: document.getElementById('platform-close'),
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

    // "LLEGANDO" implica que está a punto de llegar — mostrarlo para un bus
    // en vivo que aún está a 30+ minutos engaña solo porque haya match de
    // GPS. Lo que está en vivo pero aún lejos lleva su propia etiqueta.
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
        return { text: 'Programado', className: 'status-muted' };
    }

    /** Monta <icon><label>[<small>area</small>][<small>hint</small>] dentro de un botón pill — el icono es una constante estática de confianza, label/area/hint van por textContent. */
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

    // ---- Selector de red (BizkaiBus+ / Metro+) ----
    // Tocar el logo despliega, con animación, la otra red disponible. Elegirla
    // navega (recarga completa) a /?red=metro o / — misma filosofía que el
    // selector de tema (?tema=miamor): URL distinta = estado distinto, sin
    // reconstruir la app en caliente para un cambio tan infrecuente.

    function toggleNetworkMenu() {
        el.networkSwitch.classList.toggle('open');
    }

    function closeNetworkMenu() {
        el.networkSwitch.classList.remove('open');
    }

    function closeLiveCard() {
        state.currentStop = null;
        el.liveCard.hidden = true;
        el.platformPanel.hidden = true;
        el.liveEmpty.hidden = false;
        stopDeparturesRefresh();
    }

    function closeTimetableSection() {
        state.currentLine = null;
        el.timetableSection.hidden = true;
        stopLineMapRefresh();
    }

    // ---- Búsqueda ----

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

    // ---- Parada / salidas en vivo ----

    async function selectStop(stopId) {
        let stop;
        try {
            stop = await Api.stop(stopId);
        } catch (e) {
            return;
        }
        state.currentStop = { id: stop.id, name: stop.name };
        el.liveEmpty.hidden = true;
        if (IS_METRO) {
            updateFavoriteButton(el.platformFavorite, 'stop', stop.id);
            el.platformPanelStop.textContent = stop.name;
            el.platformPanel.hidden = false;
        } else {
            updateFavoriteButton(el.liveFavorite, 'stop', stop.id);
            el.liveCard.hidden = false;
        }
        await loadDepartures();
        startDeparturesRefresh();
    }

    // Las salidas se piden una vez al seleccionar y si no, no se actualizan —
    // sin refresco, un bus que ya se fue se queda en "0 min" para siempre en
    // vez de desaparecer de la lista como en un panel de salidas real.
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
            if (IS_METRO) {
                renderPlatformPanel([]);
            } else {
                renderLiveCard([]);
            }
            return;
        }
        if (IS_METRO) {
            renderPlatformPanel(data.departures);
        } else {
            renderLiveCard(data.departures);
        }
    }

    // Muestra una salida como el gran "próximo bus" y el resto como lista
    // compacta debajo. Si hay una línea favorita entre las salidas, se pone
    // arriba del todo sin importar el orden de llegada — para eso sirve
    // marcar una línea como favorita en esta parada. Si no, gana la salida
    // más próxima, como antes.
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
            return;
        }

        const { text, className } = statusLabel(departure.status, departure.delayMinutes);
        el.liveLine.textContent = `${departure.lineCode} · ${departure.headsign}`;
        el.liveHeadsign.textContent = state.currentStop.name;
        el.liveMinutes.textContent = Math.max(departure.etaMinutes, 0);
        el.liveBadge.textContent = liveBadgeText(departure.status, departure.etaMinutes);
        el.liveStatusText.textContent = `${departure.lineCode} · ${departure.scheduledTime}`;
        el.liveStatusDot.className = `status-dot ${className}`;
        el.liveIncidentsLink.hidden = IS_METRO;
        el.liveOpenDetail.disabled = false;
        el.liveOpenDetail.dataset.tripKey = departure.tripKey;

        for (const other of others) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<strong>${other.lineCode}</strong><span>${other.headsign}</span><span>${Math.max(other.etaMinutes, 0)} min</span>`;

            // El primer toque en una entrada de "más salidas" abre el
            // horario de esa línea (Consultar Horarios, con filtros de
            // fecha/hora); el segundo toque en la misma entrada va directo
            // al detalle en vivo de ese bus concreto.
            button.addEventListener('click', () => {
                if (button.dataset.tapped === 'true') {
                    openVehicleModal(other.tripKey);
                } else {
                    button.dataset.tapped = 'true';
                    selectLine(other.lineId);
                }
            });

            li.appendChild(button);
            el.liveMoreList.appendChild(li);
        }
    }

    // Panel de andén (Metro+): un cuadro por sentido de circulación en vez
    // de una única lista — direction viene ya calculado por el backend
    // (ver ServiceJourney::upcomingAtStop()). No hay doble-toque como en
    // renderLiveCard (no tiene sentido "abrir la línea", solo hay una) —
    // cualquier entrada de la lista va directa al detalle del trayecto.
    const PLATFORM_DIRECTIONS = [
        { key: 'toward_reference', label: 'Sentido Abando / Bilbao centro' },
        { key: 'away_from_reference', label: 'Sentido contrario' },
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
                button.innerHTML = `<span>${departure.headsign}</span><span>${Math.max(departure.etaMinutes, 0)} min</span>`;
                button.addEventListener('click', () => openVehicleModal(departure.tripKey));
                li.appendChild(button);
                list.appendChild(li);
            }
            column.appendChild(list);
        }

        return column;
    }

    function renderPlatformPanel(departures) {
        el.platformColumns.innerHTML = '';
        for (const direction of PLATFORM_DIRECTIONS) {
            const columnDepartures = departures.filter((d) => d.direction === direction.key);
            el.platformColumns.appendChild(renderPlatformColumn(direction, columnDepartures));
        }
    }

    // ---- Línea / horario ----

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
        // Metro+ no tiene mapa en vivo por línea — la red es lineal
        // y se entiende con texto, sin necesidad de mapa.
        if (!IS_METRO) {
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
            });
        } catch (e) {
            return;
        }
        renderTimetableRows(data.entries);
        const sourceLabel = IS_METRO
            ? 'Datos: Metro Bilbao / Open Data Metro Bilbao'
            : 'Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)';
        el.attribution.textContent = `${sourceLabel} · Horario base publicado: ${data.scheduleSourcePublished}`;
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

    // ---- Mapa en vivo de la línea ----

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
        if (state.currentLine?.id !== lineId) return; // mientras tanto se seleccionó otra línea
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

    // ---- Horario oficial en texto (popup, solo castellano, estructurado) ----

    /** Los campos crudos son pares clave/valor genéricos del XML legado — se agrupan en temporada/fechas/ida/vuelta, solo variantes en castellano. */
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

    // ---- Modal de detalle del vehículo (bus) / trayecto (metro) ----

    async function openVehicleModal(tripKey) {
        if (!tripKey) return;
        if (IS_METRO) {
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
            : 'Sin seguimiento en vivo en este momento — se muestra el horario programado.';

        // Las incidencias se piden solo bajo demanda (al tocar), nunca antes
        // — este popup se abre a menudo (cada fila/click) y la mayoría de viajes no tienen ninguna.
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

    // Metro+ no tiene tiempo real — reutiliza el mismo modal que bus (mismos
    // IDs/estructura, "Detalle del bus" del CSS) pero sin badge de en-vivo,
    // vehicleRef ni incidencias: solo la secuencia de estaciones programadas,
    // con la parada de origen (si se abrió desde ahí) resaltada como "isTarget".
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

        el.modalStops.innerHTML = '';
        for (const stop of data.stops) {
            const li = document.createElement('li');
            li.className = stop.isTarget ? 'stop-current' : '';
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

    // ---- Favoritos (locales al navegador: localStorage, sin cuenta ni servidor) ----

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

    // ---- Menú lateral: incidencias de las líneas favoritas, más la que esté abierta ahora mismo ----

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

    // ---- Enganche de eventos ----

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
    el.platformFavorite.addEventListener('click', () => toggleFavorite('stop', state.currentStop?.id, el.platformFavorite));
    el.platformClose.addEventListener('click', closeLiveCard);
    el.timetableFavorite.addEventListener('click', () => toggleFavorite('line', state.currentLine?.id, el.timetableFavorite));
    el.timetableClose.addEventListener('click', closeTimetableSection);
    el.liveOpenDetail.addEventListener('click', () => openVehicleModal(el.liveOpenDetail.dataset.tripKey));

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

    el.filterDate.value = new Date().toISOString().slice(0, 10);

    // Metro+ no tiene mapa en vivo (sin SIRI de posición) ni el endpoint
    // legado de horario oficial en texto libre — se ocultan sus controles
    // en vez de dejarlos ahí sin función.
    if (IS_METRO) {
        el.lineMap.hidden = true;
        el.lineMapEmpty.hidden = true;
        el.scheduleTextToggle.hidden = true;
        el.disclaimer.textContent = 'Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A.';
        el.attribution.textContent = 'Datos: Metro Bilbao / Open Data Metro Bilbao';
        // Metro Bilbao no tiene alertas SIRI ni concepto de varias líneas que
        // vigilar (solo hay una) — el menú de incidencias no tiene qué mostrar.
        el.menuOpen.hidden = true;
        el.liveIncidentsLink.hidden = true;
        el.liveEmpty.textContent = 'Busca una estación para ver el próximo metro.';
    }

    loadFavorites();
})();
