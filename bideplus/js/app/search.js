// Buscador de paradas y líneas, y paradas cercanas con la ubicación.
import { FEATURES, ICONS, appHref, el, openOnClick, savedSchedule } from './core.js';
import { selectStop } from './stop.js';
import { debounce, departureWhen, setPillContent } from './ui.js';
import { selectLine } from './timetable.js';
import { currentPosition, isLocationOff } from '../location-pref.js';

// La última búsqueda pedida: la respuesta de una anterior que llega tarde no pisa la de lo que hay escrito.
let searchRequest = 0;

async function performSearch(query) {
    const request = ++searchRequest;
    if (query.trim().length < 2) {
        el.searchResults.hidden = true;
        el.searchResults.innerHTML = '';
        return;
    }
    // Con la red guardada en el móvil se busca ahí, sin pedir nada al servidor y también sin conexión.
    const saved = await savedSchedule();
    let results;
    if (saved) {
        results = BideOffline.search(saved, query, Boolean(FEATURES.stop_hints));
    } else {
        try {
            results = await Api.search(query);
        } catch (e) {
            return;
        }
    }
    if (request !== searchRequest) return;
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
        const link = document.createElement('a');
        link.className = 'pill';
        link.href = appHref(item.type + 's', item.id);
        setPillContent(link, iconFor(item.type), item.label, item.area, item.hint);
        openOnClick(link, () => {
            el.searchInput.value = '';
            el.searchResults.hidden = true;
            if (item.type === 'stop') {
                selectStop(item.id);
            } else {
                selectLine(item.id);
            }
        });
        li.appendChild(link);
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

export function iconFor(type) {
    if (type === 'stop') return ICONS.pin;
    return ICONS.bus;
}

function formatDistance(meters) {
    if (meters < 1000) return `${Math.max(10, Math.round(meters / 10) * 10)} m`;
    return `${(meters / 1000).toFixed(1).replace('.', ',')} km`;
}

function renderNearbyResults(stops) {
    el.searchResults.innerHTML = '';
    for (const stop of stops) {
        const li = document.createElement('li');
        const link = document.createElement('a');
        link.className = 'pill';
        link.href = appHref('stops', stop.id);
        let next = I18n.t('app.noUpcomingDepartures');
        if (stop.next) next = `${stop.next.lineCode} → ${stop.next.headsign} · ${departureWhen(stop.next)}`;
        setPillContent(link, ICONS.pin, stop.name, stop.area, `${formatDistance(stop.distanceM)} · ${next}`);
        openOnClick(link, () => {
            el.searchResults.hidden = true;
            selectStop(stop.id);
        });
        li.appendChild(link);
        el.searchResults.appendChild(li);
    }
    el.searchResults.hidden = false;
}

async function findNearby() {
    if (!('geolocation' in navigator)) {
        showSearchMessage(I18n.t('app.nearby.noGeolocation'));
        return;
    }
    if (isLocationOff()) {
        showSearchMessage(I18n.t('app.nearby.off'));
        return;
    }
    el.nearbyBtn.classList.add('is-loading');
    showSearchMessage(I18n.t('app.nearby.loading'));
    try {
        let position;
        try {
            position = await currentPosition();
        } catch (error) {
            if (error.code === 1) {
                showSearchMessage(I18n.t('app.nearby.denied'));
            } else {
                showSearchMessage(I18n.t('app.nearby.failed'));
            }
            return;
        }
        showSearchMessage(I18n.t('app.nearby.loadingStops'));
        let data;
        try {
            data = await Api.nearby(position.coords.latitude.toFixed(5), position.coords.longitude.toFixed(5));
        } catch (error) {
            if (error.status === 422) {
                showSearchMessage(I18n.t('app.nearby.outOfArea'));
            } else {
                showSearchMessage(I18n.t('app.nearby.apiError'));
            }
            return;
        }
        if (data.stops.length === 0) {
            showSearchMessage(I18n.t('app.nearby.empty'));
            return;
        }
        renderNearbyResults(data.stops);
    } finally {
        el.nearbyBtn.classList.remove('is-loading');
    }
}

export function setUpSearch() {
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
}
