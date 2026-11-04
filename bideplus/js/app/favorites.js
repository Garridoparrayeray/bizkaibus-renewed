// Favoritos de paradas y líneas: guardarlos, pintarlos y el panel.
import { FAVORITES_STORAGE_KEY, ICONS, el, state } from './core.js';
import { selectStop } from './stop.js';
import { setPillContent } from './ui.js';
import { iconFor } from './search.js';
import { selectLine } from './timetable.js';
import { syncAlertFavorites } from './alerts.js';

const favoriteLabelCache = new Map();

export function favoriteKey(type, refId) {
    return `${type}:${refId}`;
}

function openFavoritesPanel() {
    el.favoritesPanel.classList.add('open');
}

function closeFavoritesPanel() {
    el.favoritesPanel.classList.remove('open');
}

export function readFavorites() {
    try {
        const raw = localStorage.getItem(FAVORITES_STORAGE_KEY);
        if (!raw) return [];
        return JSON.parse(raw);
    } catch (e) {
        return [];
    }
}

function writeFavorites(favorites) {
    localStorage.setItem(FAVORITES_STORAGE_KEY, JSON.stringify(favorites));
    syncAlertFavorites();
    window.dispatchEvent(new Event('bide:favorites'));
}

export async function loadFavorites() {
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
        const icon = iconFor(favorite.type);
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
    const icon = iconFor(favorite.type);
    try {
        let label;
        let area = '';
        if (favorite.type === 'stop') {
            const details = await Api.stop(favorite.refId);
            label = details.name;
            area = details.area;
        } else {
            const details = await Api.line(favorite.refId);
            label = `${details.code} ${details.name}`;
        }
        favoriteLabelCache.set(cacheKey, { label, area });
        setPillContent(button, icon, label, area);
    } catch (e) {
        setPillContent(button, icon, `#${favorite.refId}`, '');
    }
}

export function updateFavoriteButton(button, type, refId) {
    const isFavorite = !!refId && state.favoriteKeys.has(favoriteKey(type, refId));
    button.innerHTML = ICONS.heartOutline;
    if (isFavorite) button.innerHTML = ICONS.heartFilled;
    button.classList.toggle('is-favorite', isFavorite);
}

export function toggleFavorite(type, refId, button) {
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

export function setUpFavoritesPanel() {
    el.menuFavoritesOpen.addEventListener('click', () => {
        el.sideMenu.close();
        openFavoritesPanel();
    });
    el.favoritesClose.addEventListener('click', closeFavoritesPanel);
}
