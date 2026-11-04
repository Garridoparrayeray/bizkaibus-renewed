// Buscador de paradas y líneas, y paradas cercanas con la ubicación.
import { ICONS, el } from './core.js';
import { selectStop } from './stop.js';
import { debounce, departureWhen, setPillContent } from './ui.js';
import { selectLine } from './timetable.js';
import { isLocationOff } from '../location-pref.js';

// Donde el navegador no guarda el permiso y pregunta en cada petición (la app instalada en iPhone), la última posición
// vale unos minutos, también al cambiar de red. Se guarda en la sesión, que se borra al cerrar la app.
const POSITION_REUSE_MS = 5 * 60 * 1000;
const POSITION_KEY = 'bide-last-position';

function savedPosition() {
    try {
        const saved = JSON.parse(sessionStorage.getItem(POSITION_KEY));
        if (saved && Date.now() - saved.at < POSITION_REUSE_MS) return saved;
    } catch (e) { /* sin almacenamiento: se pide otra vez */ }
    return null;
}

async function permissionState() {
    try {
        return (await navigator.permissions.query({ name: 'geolocation' })).state;
    } catch (e) {
        return 'unknown';
    }
}

async function currentPosition() {
    const state = await permissionState();
    const saved = savedPosition();
    if (saved && state !== 'granted' && state !== 'denied') {
        return { coords: { latitude: saved.lat, longitude: saved.lon } };
    }
    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition((position) => {
            try {
                sessionStorage.setItem(POSITION_KEY, JSON.stringify({ lat: position.coords.latitude, lon: position.coords.longitude, at: Date.now() }));
            } catch (e) { /* sin almacenamiento: vale igual esta vez */ }
            resolve(position);
        }, reject, { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 });
    });
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
        setPillContent(button, iconFor(item.type), item.label, item.area, item.hint);
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
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'pill';
        let next = 'Sin salidas próximas';
        if (stop.next) next = `${stop.next.lineCode} → ${stop.next.headsign} · ${departureWhen(stop.next)}`;
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
