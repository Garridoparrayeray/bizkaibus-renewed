// Horarios sin conexión: el horario oficial de las redes que elige la persona, guardado en el móvil (Cache Storage).
// Sin cobertura, la parada se abre con ese horario y sus salidas se calculan aquí, con la misma forma que las de la
// API. Donde la red trae horas estimadas con los tramos aprendidos (Bizkaibus), se aplican las mismas reglas que en el
// servidor sin datos del bus: «estimado» con la hora oficial al lado, «probablemente ya pasó» si el horario va muy
// lento, y si no, «solo horario». Los datos los genera scripts/build-offline-data.php en cada reconstrucción.
const BideOffline = (() => {
    const CACHE_NAME = 'bide-offline';
    const LIST_KEY = 'bide-offline-networks';
    // Marcas que pone la persona sin conexión: { 'AAAA-MM-DD': { tripKey: { passed: posición } | { coming: posición } } }.
    const MARKS_KEY = 'bide-offline-marks';
    const CHECKED_KEY = 'bide-offline-checked';
    // Quien quita una red en Ajustes ya elige las suyas: desde entonces no se guarda ninguna sola.
    const CHOSEN_KEY = 'bide-offline-chosen';
    const CHECK_EVERY_MS = 6 * 3600 * 1000;
    const DAY_SECONDS = 86400;
    const PAST_MARGIN_SECONDS = 60;
    // Las mismas que el motor: un bus «puede haber pasado» si la estimación quedó 3 min atrás y el horario va 10 min o
    // más lento que lo aprendido. Detrás de las que vienen salen como mucho dos.
    const LIKELY_PASSED_SECONDS = 180;
    const LIKELY_PAST_SHOWN = 2;
    const SLOW_SCHEDULE_SECONDS = 600;
    const SUNDAY_ONLY_MASK = 0b1000000;
    const prepared = new Map();
    let refreshing = null;

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

    function chosen() {
        try {
            return localStorage.getItem(CHOSEN_KEY) === '1';
        } catch (e) {
            return true;
        }
    }

    function choose() {
        try {
            localStorage.setItem(CHOSEN_KEY, '1');
        } catch (e) {
            // Sin almacenamiento tampoco se recuerdan las redes guardadas.
        }
    }

    // Por defecto se guardan todas, salvo con el ahorro de datos del móvil activado.
    function savesAll() {
        if (chosen()) return false;
        return !(navigator.connection && navigator.connection.saveData);
    }

    function checkedRecently() {
        try {
            return Date.now() - Number(localStorage.getItem(CHECKED_KEY) || 0) < CHECK_EVERY_MS;
        } catch (e) {
            return true;
        }
    }

    function markChecked() {
        try {
            localStorage.setItem(CHECKED_KEY, String(Date.now()));
        } catch (e) {
            // Sin almacenamiento se vuelve a mirar la próxima vez.
        }
    }

    async function refreshSaved() {
        const list = saved();
        const all = savesAll();
        if (!supported() || !navigator.onLine || checkedRecently()) return false;
        if (Object.keys(list).length === 0 && !all) return false;
        const response = await fetch('/offline-data/index.json', { cache: 'no-store' });
        if (!response.ok) return false;
        const index = await response.json();
        let updated = false;
        let failed = false;
        for (const network of index.networks || []) {
            const kept = list[network.slug];
            if ((kept && kept.generated !== network.generated) || (!kept && all)) {
                await save(network.slug).then(() => {
                    updated = true;
                }).catch(() => {
                    failed = true;
                });
            }
        }
        if (!failed) markChecked();
        return updated;
    }

    // Con conexión, como mucho cada 6 horas: vuelve a bajar las redes guardadas que tengan un horario más nuevo y, si la
    // persona no ha elegido las suyas, guarda las que falten.
    function refresh() {
        if (!refreshing) {
            refreshing = refreshSaved().finally(() => {
                refreshing = null;
            });
        }
        return refreshing;
    }

    /*
     * Sin conexión no hay directo ni registro de pasos, así que la persona puede decir lo que ve en la calle:
     * - «ya pasó» en una parada: el bus ya pasó por esa parada y por las anteriores de su recorrido (no se enseña).
     * - «no ha pasado»: el bus todavía no ha llegado a esa parada ni a las siguientes (deja de salir como
     *   «probablemente ya pasó» y vuelve con su hora oficial).
     * Valen para el día del viaje y se borran solas pasado ese día. Con conexión no se usan.
     */
    function readMarks() {
        try {
            return JSON.parse(localStorage.getItem(MARKS_KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function writeMarks(marks) {
        // Se guardan los de ayer (viajes de madrugada) en adelante; los anteriores ya no sirven.
        const yesterday = isoDate(new Date(Date.now() - DAY_SECONDS * 1000));
        for (const day of Object.keys(marks)) {
            if (day < yesterday) delete marks[day];
        }
        try {
            localStorage.setItem(MARKS_KEY, JSON.stringify(marks));
        } catch (e) {
            // Sin almacenamiento la marca no se recuerda.
        }
    }

    function mark(departure, kind) {
        const marks = readMarks();
        if (!marks[departure.serviceDay]) marks[departure.serviceDay] = {};
        const entry = {};
        entry[kind] = departure.position;
        marks[departure.serviceDay][departure.tripKey] = entry;
        writeMarks(marks);
    }

    function unmark(hidden) {
        const marks = readMarks();
        for (const { serviceDay, tripKey } of hidden) {
            if (marks[serviceDay]) delete marks[serviceDay][tripKey];
        }
        writeMarks(marks);
    }

    function markFor(marks, serviceDay, tripKey) {
        if (!marks[serviceDay]) return null;
        return marks[serviceDay][tripKey] || null;
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
        return { ...data, byStop, tripsByPattern, platformsByStation, holidays: holidays(data.calendars) };
    }

    // Bizkaibus publica los festivos añadiendo ese día sus calendarios de solo domingo.
    function holidays(calendars) {
        const days = new Set();
        for (const calendar of calendars) {
            if (calendar[0] !== SUNDAY_ONLY_MASK) continue;
            for (const day of calendar[1]) days.add(day);
        }
        return days;
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
        const hidden = [];
        const marks = readMarks();
        for (const target of targets) {
            const items = [];
            for (const [patternIndex, position] of data.byStop.get(target) || []) {
                const pattern = data.patterns[patternIndex];
                for (const trip of data.tripsByPattern[patternIndex]) {
                    const atStop = trip[2] + data.timings[trip[1]][position];
                    for (const day of days) {
                        const seconds = atStop + day.offset * DAY_SECONDS;
                        const timing = arrival(data, trip, position, day.date, seconds, nowSeconds);
                        if (timing.etaSeconds < nowSeconds - PAST_MARGIN_SECONDS || seconds > nowSeconds + DAY_SECONDS) continue;
                        if (!runsOn(data.calendars[trip[3]], day.date)) continue;
                        const serviceDay = isoDate(day.date);
                        const tripKey = tripKeyOf(data, pattern, trip);
                        const userMark = markFor(marks, serviceDay, tripKey);
                        if (userMark && userMark.passed !== undefined && position <= userMark.passed) {
                            hidden.push({ serviceDay, tripKey });
                            continue;
                        }
                        let finalTiming = timing;
                        if (userMark && userMark.coming !== undefined && position >= userMark.coming && timing.status === 'likely_departed') {
                            finalTiming = { status: 'scheduled', etaSeconds: seconds };
                        }
                        items.push({ seconds, trip, pattern, position, serviceDay, tripKey, ...finalTiming });
                    }
                }
            }
            // Por hora prevista; los que probablemente ya pasaron, al final (como la API).
            items.sort((a, b) => rank(a) - rank(b) || a.etaSeconds - b.etaSeconds);
            for (const item of limited(items, limit)) found.push(departureItem(data, item, target, platforms.length > 0, now, nowSeconds));
        }
        found.sort((a, b) => rank(a) - rank(b) || a.etaAt - b.etaAt);

        const stop = data.stops[id] || [''];
        return {
            stop: { id: outputId(id), name: stop[0] },
            platforms: platforms.map((platform) => ({ id: outputId(platform.id), label: platform.label })),
            directionLabels: directionLabels(found),
            departures: found,
            hiddenByMarks: hidden,
            offline: true,
        };
    }

    function limited(items, limit) {
        const coming = items.filter((item) => item.status !== 'likely_departed');
        const likelyPast = items.filter((item) => item.status === 'likely_departed');
        return coming.slice(0, limit).concat(likelyPast.slice(0, LIKELY_PAST_SHOWN));
    }

    function rank(item) {
        if (item.status === 'likely_departed') return 1;
        return 0;
    }

    // Posiciones 6 y 7 del viaje: estimación en laborable y en fin de semana (-1 si no hay).
    function arrival(data, trip, position, date, officialSeconds, nowSeconds) {
        const official = { status: 'scheduled', etaSeconds: officialSeconds };
        if (!data.estimates || position === 0) return official;
        let slot = trip[6];
        const weekday = date.getDay();
        if (weekday === 0 || weekday === 6 || data.holidays.has(isoDate(date))) slot = trip[7];
        if (slot === undefined || slot < 0) return official;
        const estimated = officialSeconds - data.timings[trip[1]][position] + data.estimates[slot][position];
        if (estimated >= nowSeconds) return { status: 'estimated', etaSeconds: estimated };
        if (nowSeconds - estimated > LIKELY_PASSED_SECONDS && officialSeconds - estimated >= SLOW_SCHEDULE_SECONDS && data.estimateTrusted[slot][position] === '1') {
            return { status: 'likely_departed', etaSeconds: officialSeconds, passedSeconds: estimated };
        }
        return official;
    }

    // Como Core\TripKey::build: un guion dentro del número de viaje (Tuvisa) va como «~».
    function tripKeyOf(data, pattern, trip) {
        return `${data.lines[pattern[0]][0]}-${String(trip[4]).replaceAll('-', '~')}-${trip[5]}`;
    }

    function departureItem(data, item, target, isPlatform, now, nowSeconds) {
        const line = data.lines[item.pattern[0]];
        let headsign = item.pattern[1];
        if (data.headsignFromLastStop || !headsign) headsign = lastStopName(data, item.pattern);
        let platformId = null;
        if (isPlatform) platformId = outputId(target);
        return {
            lineId: outputId(line[0]),
            lineCode: line[1],
            lineName: line[2],
            headsign,
            tripKey: item.tripKey,
            serviceDay: item.serviceDay,
            position: item.position,
            scheduledTime: hm(item.seconds),
            etaMinutes: Math.round((item.etaSeconds - nowSeconds) / 60),
            etaAt: Math.round(now.getTime() / 1000) + (item.etaSeconds - nowSeconds),
            status: item.status,
            delayMinutes: null,
            // Pasada la medianoche: la app la enseña como «mañana 07:00», igual que con conexión.
            tomorrow: item.seconds >= DAY_SECONDS,
            direction: direction(data, item.pattern, item.position, target),
            platformId,
            passedTime: passedTime(item),
        };
    }

    function hm(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        return `${String(((hours % 24) + 24) % 24).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
    }

    function passedTime(item) {
        if (item.passedSeconds === undefined) return null;
        return hm(item.passedSeconds);
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

    const SEARCH_STOPS = 60;
    const SEARCH_LINES = 30;
    const SEARCH_HINTS = 2;

    // Como Models\Search::normalize: sin tildes, en minúsculas y con un solo espacio.
    function normalize(text) {
        return String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function words(query) {
        return [...new Set(normalize(query).split(/[^\p{L}\p{N}]+/u).filter(Boolean))];
    }

    // Primero lo que más se parece a lo escrito: igual, empieza por ello, lo contiene, el resto.
    function matchRank(name, query) {
        if (name === query) return 0;
        if (name.startsWith(query)) return 1;
        if (name.includes(query)) return 2;
        return 3;
    }

    // «hacia Gernika, Lekeitio» con los destinos de los recorridos que salen de la parada; si no sale ninguno, es final.
    function hint(data, stopId) {
        const headsigns = [];
        for (const [patternIndex] of data.byStop.get(stopId) || []) {
            const pattern = data.patterns[patternIndex];
            let headsign = pattern[1];
            if (data.headsignFromLastStop) headsign = lastStopName(data, pattern);
            if (headsign && !headsigns.includes(headsign)) headsigns.push(headsign);
            if (headsigns.length === SEARCH_HINTS) break;
        }
        if (headsigns.length === 0) return 'final de línea';
        return 'hacia ' + headsigns.join(', ');
    }

    // El buscador con el horario guardado, como /api/search: cada palabra en el nombre o en el pueblo de la parada, y en
    // el número o el nombre de la línea.
    function search(data, query, withHints) {
        const found = words(query);
        if (normalize(query).length < 2 || found.length === 0) return { stops: [], lines: [] };
        const normalized = normalize(query);
        const areas = data.areas || {};
        const stops = [];
        for (const [stopId, entry] of Object.entries(data.stops)) {
            if (entry.length > 1) continue;
            const name = normalize(entry[0]);
            const haystack = name + ' ' + normalize(areas[stopId]);
            if (!found.every((word) => haystack.includes(word))) continue;
            stops.push({ id: outputId(stopId), name: entry[0], area: areas[stopId] || '', rank: matchRank(name, normalized) });
        }
        stops.sort((a, b) => a.rank - b.rank || a.name.length - b.name.length || a.area.localeCompare(b.area, 'es'));
        const hidden = data.hiddenLineCodes || [];
        const lines = data.lines
            .filter((line) => !hidden.includes(line[1]) && found.every((word) => (line[1].toLowerCase() + ' ' + normalize(line[2])).includes(word)))
            .sort((a, b) => Number(a[1] > b[1]) - Number(a[1] < b[1]))
            .slice(0, SEARCH_LINES)
            .map((line) => ({ id: outputId(line[0]), code: line[1], name: line[2] }));
        return {
            stops: stops.slice(0, SEARCH_STOPS).map((stop) => {
                let stopHint = null;
                if (withHints) stopHint = hint(data, String(stop.id));
                return { id: stop.id, name: stop.name, area: stop.area, hint: stopHint };
            }),
            lines,
            offline: true,
        };
    }

    function headsignOf(data, pattern) {
        if (data.headsignFromLastStop) return lastStopName(data, pattern);
        return pattern[1];
    }

    function lineIndex(data, lineId) {
        return data.lines.findIndex((line) => String(line[0]) === String(lineId));
    }

    // La línea, como /api/lines/{id} (sin recorridos).
    function line(data, lineId) {
        const index = lineIndex(data, lineId);
        if (index < 0) return null;
        const [id, code, name] = data.lines[index];
        return { id: outputId(id), code, name, offline: true };
    }

    // Dónde para el recorrido en la parada (o en un andén de la estación), sin contar la última.
    function positionsAt(data, pattern, stopId) {
        if (stopId === null || stopId === undefined || stopId === '') return [0];
        const id = String(stopId);
        const positions = [];
        pattern[2].forEach((patternStop, position) => {
            if (position === pattern[2].length - 1) return;
            const entry = data.stops[patternStop] || [];
            if (patternStop === id || entry[1] === id) positions.push(position);
        });
        return positions;
    }

    function departuresOn(data, index, date, from, to, stopId, shift) {
        const rows = [];
        data.patterns.forEach((pattern, patternIndex) => {
            if (pattern[0] !== index) return;
            const positions = positionsAt(data, pattern, stopId);
            for (const trip of data.tripsByPattern[patternIndex]) {
                if (!runsOn(data.calendars[trip[3]], date)) continue;
                for (const position of positions) {
                    // En la cabecera, la hora de salida (la de llegada puede ser antes); en las demás, la de llegada.
                    let seconds = trip[2] + data.timings[trip[1]][position];
                    if (position === 0) seconds = trip[5];
                    if (seconds < from || seconds > to) continue;
                    rows.push({ seconds: seconds + shift, pattern, trip });
                }
            }
        });
        return rows;
    }

    /*
     * Horario de una línea para un día y una franja, como /api/lines/{id}/timetable: desde la primera parada o, con
     * stopId, en esa parada. Si la franja pasa de medianoche, se suman las de la madrugada del día siguiente.
     * options: { date: Date, from, to (segundos), stopId, now: Date }.
     */
    function timetable(data, lineId, options) {
        const index = lineIndex(data, lineId);
        if (index < 0) return null;
        let rows;
        if (options.to >= options.from) {
            rows = departuresOn(data, index, options.date, options.from, options.to, options.stopId, 0);
        } else {
            rows = departuresOn(data, index, options.date, options.from, options.to + DAY_SECONDS, options.stopId, 0);
            const next = new Date(options.date);
            next.setDate(next.getDate() + 1);
            rows = rows.concat(departuresOn(data, index, next, 0, options.to, options.stopId, DAY_SECONDS));
        }
        rows.sort((a, b) => a.seconds - b.seconds);
        const day = isoDate(options.date);
        const today = isoDate(options.now);
        const nowSeconds = options.now.getHours() * 3600 + options.now.getMinutes() * 60 + options.now.getSeconds();
        const entries = rows.map((row) => {
            let status = 'scheduled';
            if (day < today || (day === today && row.seconds < nowSeconds)) status = 'departed';
            return { tripKey: tripKeyOf(data, row.pattern, row.trip), departure: hm(row.seconds), headsign: headsignOf(data, row.pattern), status, delayMinutes: 0 };
        });
        return { line: line(data, lineId), date: day, entries, generated: data.generated, offline: true };
    }

    // Las paradas de un viaje con su hora, como /api/trips/{tripKey}.
    function tripStops(data, tripKey, stopId) {
        for (const [patternIndex, pattern] of data.patterns.entries()) {
            const trip = data.tripsByPattern[patternIndex].find((candidate) => tripKeyOf(data, pattern, candidate) === tripKey);
            if (!trip) continue;
            const [, code, name] = data.lines[pattern[0]];
            const target = String(stopId || '');
            const stops = pattern[2].map((patternStop, position) => {
                const entry = data.stops[patternStop] || [patternStop];
                return {
                    stopId: outputId(entry[1] || patternStop),
                    name: entry[0],
                    scheduledTime: hm(trip[2] + data.timings[trip[1]][position]),
                    isTarget: target !== '' && (patternStop === target || entry[1] === target),
                };
            });
            return { lineCode: code, lineName: name, headsign: headsignOf(data, pattern), stops, offline: true };
        }
        return null;
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
        choose,
        savesAll,
        refresh,
        load,
        prepare,
        departures,
        arrival,
        mark,
        unmark,
        stop,
        search,
        line,
        timetable,
        tripStops,
    };
})();

if (typeof module !== 'undefined') module.exports = BideOffline;
