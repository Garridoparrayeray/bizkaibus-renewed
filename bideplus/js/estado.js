// Estado de las redes (/estado): pide a cada red su directo (/api/status) y sus avisos (/api/alerts) y rellena su
// ficha; se repite cada minuto.
(function () {
    'use strict';

    const REFRESH_MS = 60000;
    const UNITS = { bus: 'autobuses', metro: 'trenes', train: 'trenes', tram: 'tranvías' };

    async function getJson(url) {
        try {
            const response = await fetch(url, { cache: 'no-store' });
            if (!response.ok) return null;
            return await response.json();
        } catch (e) {
            return null;
        }
    }

    // Cuántos van en directo; 0 si la red no publica directo, no trae nada o lo que trae es su propio horario.
    function liveCount(status) {
        if (!status || !status.ok || status.copiesSchedule) return 0;
        if (status.matching === undefined) return status.vehicles;
        return status.live ?? status.feedTrips;
    }

    function alertsText(count) {
        if (count === 1) return '1 aviso';
        return `${count} avisos`;
    }

    function fillTile(tile, status, alerts) {
        const count = liveCount(status);
        const unit = UNITS[tile.dataset.kind] || 'vehículos';
        tile.classList.toggle('is-schedule', count === 0);
        const strong = tile.querySelector('.status-count strong');
        const label = tile.querySelector('.status-count span');
        strong.textContent = String(count);
        label.textContent = `${unit} en directo`;
        strong.hidden = count === 0;
        if (count === 0) label.textContent = 'Ahora sin directo: sale el horario';
        let alertCount = 0;
        if (alerts && Array.isArray(alerts.alerts)) alertCount = alerts.alerts.length;
        const badge = tile.querySelector('.status-alerts');
        badge.hidden = alertCount === 0;
        badge.textContent = alertsText(alertCount);
        return { live: count, networks: Number(count > 0), alerts: alertCount };
    }

    async function refresh() {
        const tiles = Array.from(document.querySelectorAll('.status-tile'));
        const results = await Promise.all(tiles.map(async (tile) => {
            const red = encodeURIComponent(tile.dataset.red);
            const [status, alerts] = await Promise.all([
                getJson(`/api/status?red=${red}`),
                getJson(`/api/alerts?red=${red}`),
            ]);
            return fillTile(tile, status, alerts);
        }));
        const sum = (key) => results.reduce((total, result) => total + result[key], 0);
        const time = new Date().toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        const live = sum('live').toLocaleString('es-ES');
        document.getElementById('status-summary').textContent =
            `A las ${time} hay ${live} vehículos en directo en ${sum('networks')} de las ${tiles.length} redes, y ${alertsText(sum('alerts'))} de los operadores.`;
    }

    document.querySelectorAll('.status-bar[data-value]').forEach((bar) => {
        bar.style.setProperty('--value', `${bar.dataset.value}%`);
    });
    refresh();
    setInterval(refresh, REFRESH_MS);
})();
