// Estado de las redes (/estado): pide a cada red su directo (/api/status) y sus avisos (/api/alerts) y rellena su
// ficha; se repite cada minuto.
(function () {
    'use strict';

    const REFRESH_MS = 60000;
    const KINDS = ['bus', 'metro', 'train', 'tram'];
    const LOCALES = { es: 'es-ES', eu: 'eu-ES', en: 'en-GB' };
    const t = (key, vars) => I18n.t(key, vars);

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
        if (count === 1) return t('status.alerts.one');
        return t('status.alerts.many', { n: count });
    }

    function fillTile(tile, status, alerts) {
        const count = liveCount(status);
        let kind = tile.dataset.kind;
        if (!KINDS.includes(kind)) kind = 'bus';
        tile.classList.toggle('is-schedule', count === 0);
        const strong = tile.querySelector('.status-count strong');
        const label = tile.querySelector('.status-count span');
        strong.textContent = String(count);
        label.textContent = t(`status.live.${kind}`);
        strong.hidden = count === 0;
        if (count === 0) label.textContent = t('status.noLive');
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
        const locale = LOCALES[I18n.getLang()] || 'es-ES';
        const summary = document.getElementById('status-summary');
        summary.removeAttribute('data-i18n');
        summary.textContent = t('status.summary', {
            time: new Date().toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }),
            live: sum('live').toLocaleString(locale),
            networks: sum('networks'),
            total: tiles.length,
            alerts: alertsText(sum('alerts')),
        });
    }

    I18n.applyTranslations(document);

    document.querySelectorAll('.status-bar[data-value]').forEach((bar) => {
        bar.style.setProperty('--value', `${bar.dataset.value}%`);
    });
    refresh();
    setInterval(refresh, REFRESH_MS);
})();
