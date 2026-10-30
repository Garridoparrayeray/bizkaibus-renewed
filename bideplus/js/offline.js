// Horarios sin conexión: el horario oficial de las redes que elige la persona, guardado en el móvil (Cache Storage).
// Sin cobertura, la parada se abre con ese horario y sus salidas se calculan aquí, con la misma forma que las de la
// API y el estado «solo horario». Los datos los genera scripts/build-offline-data.php en cada reconstrucción.
const BideOffline = (() => {
    const CACHE_NAME = 'bide-offline';
    const LIST_KEY = 'bide-offline-networks';
    const CHECKED_KEY = 'bide-offline-checked';
    const CHECK_EVERY_MS = 6 * 3600 * 1000;
    const DAY_SECONDS = 86400;
    const PAST_MARGIN_SECONDS = 60;
    const prepared = new Map();

    const dataUrl = (slug) => '/offline-data/' + slug + '.json';
    const supported = () => typeof caches !== 'undefined';

    // Redes guardadas: { slug: { generated, bytes } }.
    function saved() {
        try {
            return JSON.parse(localStorage.getItem(LIST_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function writeSaved(list) {
        try {
            localStorage.setItem(LIST_KEY, JSON.stringify(list));
        } catch (e) {
            // Sin almacenamiento la descarga sigue en la caché, pero no se recordará.
        }
    }

    async function save(slug) {
        const response = await fetch(dataUrl(slug), { cache: 'no-store' });
        if (!response.ok) throw new Error('offline-data ' + slug + ' ' + response.status);
        const copy = response.clone();
        const text = await response.text();
        const data = JSON.parse(text);
        const cache = await caches.open(CACHE_NAME);
        await cache.put(dataUrl(slug), copy);
        prepared.set(slug, prepare(data));
        const list = saved();
        list[slug] = { generated: data.generated, bytes: text.length };
        writeSaved(list);
        return list[slug];
    }

    async function remove(slug) {
        const list = saved();
        delete list[slug];
        writeSaved(list);
        prepared.delete(slug);
        if (supported()) {
            const cache = await caches.open(CACHE_NAME);
            await cache.delete(dataUrl(slug));
        }
    }

    async function load(slug) {
        if (!saved()[slug] || !supported()) return null;
        if (prepared.has(slug)) return prepared.get(slug);
        const cache = await caches.open(CACHE_NAME);
        const response = await cache.match(dataUrl(slug));
        if (!response) return null;
        const data = prepare(await response.json());
        prepared.set(slug, data);
        return data;
    }

    // Con conexión, como mucho cada 6 horas: vuelve a bajar las redes guardadas que tengan un horario más nuevo.
    async function refresh() {
        const list = saved();
        if (!supported() || !navigator.onLine || Object.keys(list).length === 0) return;
        try {
            if (Date.now() - Number(localStorage.getItem(CHECKED_KEY) || 0) < CHECK_EVERY_MS) return;
            localStorage.setItem(CHECKED_KEY, String(Date.now()));
        } catch (e) {
            return;
        }
        const response = await fetch('/offline-data/index.json', { cache: 'no-store' });
        if (!response.ok) return;
        const index = await response.json();
        for (const network of index.networks || []) {
            if (list[network.slug] && list[network.slug].generated !== network.generated) {
                await save(network.slug).catch(() => {});
            }
        }
    }

    // Índices para no recorrer todos los viajes en cada consulta: paradas → [recorrido, posición] y recorrido → viajes.
    function prepare(data) {
        const byStop = new Map();
        data.patterns.forEach((pattern, patternIndex) => {
            pattern[2].forEach((stopId, position) => {
                if (position === pattern[2].length - 1) return;
                if (!byStop.has(stopId)) byStop.set(stopId, []);
                byStop.get(stopId).push([patternIndex, position]);
            });
        });
        const tripsByPattern = data.patterns.map(() => []);
        data.trips.forEach((trip) => tripsByPattern[trip[0]].push(trip));
        const platformsByStation = new Map();
        for (const [stopId, stop] of Object.entries(data.stops)) {
            if (stop.length < 2) continue;
            if (!platformsByStation.has(stop[1])) platformsByStation.set(stop[1], []);
            platformsByStation.get(stop[1]).push({ id: stopId, label: stop[2] || '' });
        }
        return { ...data, byStop, tripsByPattern, platformsByStation };
    }

    function isoDate(date) {
        const pad = (n) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    // Igual que la API: corre si la fecha está incluida, o si el día de la semana está en la máscara y no está excluida.
    function runsOn(calendar, date) {
        const iso = isoDate(date);
        if (calendar[2].includes(iso)) return false;
        if (calendar[1].includes(iso)) return true;
        const weekdayBit = 1 << ((date.getDay() + 6) % 7);
        return (calendar[0] & weekdayBit) !== 0;
    }

    function outputId(id) {
        if (/^[1-9]\d*$/.test(id)) return Number(id);
        return id;
    }

    function lastStopName(data, pattern) {
        const last = data.stops[pattern[2][pattern[2].length - 1]];
        if (!last) return pattern[1];
        return last[0];
    }

    // Columna de Metro: hacia la estación de referencia o al revés (la regla de ServiceJourney::directionsByPattern).
    function direction(data, pattern, position, stopId) {
        const reference = data.directionReference;
        if (!reference) return null;
        const referencePosition = pattern[2].indexOf(reference);
        const lastLon = data.lon[pattern[2][pattern[2].length - 1]];
        const stopLon = data.lon[stopId];
        let toward;
        if (referencePosition !== -1 && referencePosition !== position) {
            toward = position < referencePosition;
        } else if (stopId === reference) {
            toward = lastLon > stopLon;
        } else {
            toward = (lastLon - stopLon) * (data.lon[reference] - stopLon) > 0;
        }
        if (toward) return 'toward_reference';
        return 'away_from_reference';
    }

    // Próximas salidas de una parada (o de los andenes de una estación) desde «now», en las 24 horas siguientes.
    function departures(data, stopId, now, limit) {
        const id = String(stopId);
        const platforms = data.platformsByStation.get(id) || [];
        let targets = [id];
        if (platforms.length > 0) targets = platforms.map((platform) => platform.id);
        const nowSeconds = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
        const midnight = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const days = [-1, 0, 1].map((offset) => {
            const date = new Date(midnight);
            date.setDate(date.getDate() + offset);
            return { offset, date };
        });

        const found = [];
        for (const target of targets) {
            const items = [];
            for (const [patternIndex, position] of data.byStop.get(target) || []) {
                const pattern = data.patterns[patternIndex];
                for (const trip of data.tripsByPattern[patternIndex]) {
                    const atStop = trip[2] + data.timings[trip[1]][position];
                    for (const day of days) {
                        const seconds = atStop + day.offset * DAY_SECONDS;
                        if (seconds < nowSeconds - PAST_MARGIN_SECONDS || seconds > nowSeconds + DAY_SECONDS) continue;
                        if (!runsOn(data.calendars[trip[3]], day.date)) continue;
                        items.push({ seconds, trip, pattern, position });
                    }
                }
            }
            items.sort((a, b) => a.seconds - b.seconds);
            for (const item of items.slice(0, limit)) found.push(departureItem(data, item, target, platforms.length > 0, now, nowSeconds));
        }
        found.sort((a, b) => a.etaAt - b.etaAt);

        const stop = data.stops[id] || [''];
        return {
            stop: { id: outputId(id), name: stop[0] },
            platforms: platforms.map((platform) => ({ id: outputId(platform.id), label: platform.label })),
            directionLabels: directionLabels(found),
            departures: found,
            offline: true,
        };
    }

    function departureItem(data, item, target, isPlatform, now, nowSeconds) {
        const line = data.lines[item.pattern[0]];
        let headsign = item.pattern[1];
        if (data.headsignFromLastStop || !headsign) headsign = lastStopName(data, item.pattern);
        const hours = Math.floor(item.seconds / 3600);
        const minutes = Math.floor((item.seconds % 3600) / 60);
        let platformId = null;
        if (isPlatform) platformId = outputId(target);
        return {
            lineId: outputId(line[0]),
            lineCode: line[1],
            lineName: line[2],
            headsign,
            tripKey: `${line[0]}-${item.trip[4]}-${item.trip[5]}`,
            scheduledTime: `${String(((hours % 24) + 24) % 24).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`,
            etaMinutes: Math.round((item.seconds - nowSeconds) / 60),
            etaAt: Math.round(now.getTime() / 1000) + (item.seconds - nowSeconds),
            status: 'scheduled',
            delayMinutes: null,
            direction: direction(data, item.pattern, item.position, target),
            platformId,
        };
    }

    // Destinos de cada columna de Metro («Hacia Basauri · Etxebarri»).
    function directionLabels(items) {
        const labels = { toward_reference: [], away_from_reference: [] };
        let any = false;
        for (const item of items) {
            if (!item.direction) continue;
            any = true;
            if (!labels[item.direction].includes(item.headsign)) labels[item.direction].push(item.headsign);
        }
        if (!any) return null;
        return { toward_reference: labels.toward_reference.join(' · '), away_from_reference: labels.away_from_reference.join(' · ') };
    }

    // La parada (o estación) con sus líneas, como /api/stops/{id}.
    function stop(data, stopId) {
        const id = String(stopId);
        const entry = data.stops[id];
        if (!entry) return null;
        let targets = [id];
        const platforms = data.platformsByStation.get(id) || [];
        if (platforms.length > 0) targets = platforms.map((platform) => platform.id);
        const seen = new Set();
        const lines = [];
        for (const target of targets) {
            for (const [patternIndex] of data.byStop.get(target) || []) {
                const line = data.lines[data.patterns[patternIndex][0]];
                if (seen.has(line[0])) continue;
                seen.add(line[0]);
                lines.push({ id: outputId(line[0]), code: line[1], name: line[2] });
            }
        }
        lines.sort((a, b) => a.code.localeCompare(b.code, 'es', { numeric: true }));
        return { id: outputId(id), name: entry[0], lines, offline: true };
    }

    return {
        supported,
        saved,
        save,
        remove,
        refresh,
        load,
        prepare,
        departures,
        stop,
    };
})();

if (typeof module !== 'undefined') module.exports = BideOffline;
