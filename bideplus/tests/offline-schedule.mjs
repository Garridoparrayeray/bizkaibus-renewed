// Horarios sin conexión: las salidas que calcula js/offline.js con offline-data/<red>.json son las de la API.
// Por cada red se comparan varias paradas: cada salida del horario que da la API para hoy tiene que estar en las del
// móvil. Las del móvil pueden tener de más las de mañana (la API solo mira el día de hoy).
process.env.TZ = 'Europe/Madrid';

import { createRequire } from 'node:module';
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';

const require = createRequire(import.meta.url);
const BideOffline = require('../js/offline.js');
const BASE = (process.env.BASE_URL || 'http://localhost:8011').replace(/\/$/, '');
const DIR = resolve(new URL('..', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'), 'offline-data');
const STOPS_PER_NETWORK = 8;

const results = [];
const check = (name, ok, extra = '') => {
    let line = `MAL ${name}`;
    if (ok) line = `OK  ${name}`;
    if (extra) line += ' — ' + extra;
    results.push(line);
};

if (!existsSync(resolve(DIR, 'index.json'))) {
    console.log('MAL falta offline-data: php scripts/build-offline-data.php');
    process.exit(1);
}
const index = JSON.parse(readFileSync(resolve(DIR, 'index.json'), 'utf8'));
const key = (d) => `${d.lineCode}|${d.scheduledTime}|${d.platformId}`;
const COMPARED = new Set(['scheduled', 'estimated', 'live', 'likely_departed']);

for (const { slug } of index.networks) {
    const data = BideOffline.prepare(JSON.parse(readFileSync(resolve(DIR, slug + '.json'), 'utf8')));
    const stations = [...data.platformsByStation.keys()];
    const stops = Object.keys(data.stops).filter((id) => data.byStop.has(id) && data.stops[id].length < 2);
    const sample = [...stations, ...stops].filter((_, i, all) => i % Math.max(1, Math.floor(all.length / STOPS_PER_NETWORK)) === 0).slice(0, STOPS_PER_NETWORK);
    let same = 0;
    const differences = [];
    for (const stopId of sample) {
        const response = await fetch(`${BASE}/api/stops/${encodeURIComponent(stopId)}/departures?limit=20&red=${slug}`);
        if (!response.ok) continue;
        const api = await response.json();
        const offline = BideOffline.departures(data, stopId, new Date(), 40);
        const offlineKeys = new Set(offline.departures.map(key));
        // Lo que la API da desde dentro de 2 minutos: lo que ya pasa en este momento depende del directo.
        const expected = api.departures.filter((d) => COMPARED.has(d.status) && d.etaMinutes >= 2 && d.status !== 'live').map(key);
        const missing = expected.filter((k) => !offlineKeys.has(k));
        if (missing.length === 0) same++;
        else differences.push(`${stopId}: ${missing.slice(0, 3).join(', ')}`);
        if (offline.stop.name !== api.stop.name) differences.push(`${stopId}: nombre «${offline.stop.name}» frente a «${api.stop.name}»`);
    }
    check(`${slug}: las salidas guardadas son las del horario (${same}/${sample.length} paradas)`, differences.length === 0, differences.slice(0, 3).join(' | '));
}

// Horas estimadas con los tramos aprendidos (las mismas reglas que el motor sin datos del bus). Viaje de ejemplo que sale
// a las 08:00 y llega a la tercera parada a las 08:40 según el horario, pero a las 08:10 según lo aprendido.
const T = 8 * 3600;
const sample = { timings: [[0, 600, 2400]], estimates: [[0, 300, 600]], estimateTrusted: [1], holidays: new Set() };
const trip = [0, 0, T, 0, '1', T, 0, -1];
const thursday = new Date(2026, 10, 5);
const saturday = new Date(2026, 10, 7);
const at = (date, now, data = sample) => BideOffline.arrival(data, trip, 2, date, T + 2400, now);
let r = at(thursday, T + 300);
check('sin conexión, antes de la hora estimada sale «estimado» con esa hora', r.status === 'estimated' && r.etaSeconds === T + 600, JSON.stringify(r));
r = at(thursday, T + 900);
check('sin conexión, si la estimación pasó hace menos de 10 min se deja la hora oficial', r.status === 'scheduled' && r.etaSeconds === T + 2400, JSON.stringify(r));
r = at(thursday, T + 1500);
check('sin conexión, horario 30 min más lento y estimación pasada hace 15 min: «probablemente ya pasó» con la hora oficial', r.status === 'likely_departed' && r.etaSeconds === T + 2400, JSON.stringify(r));
r = at(thursday, T + 1500, { ...sample, estimateTrusted: [0] });
check('sin conexión, con pocos tramos aprendidos no se dice que ya pasó', r.status === 'scheduled', JSON.stringify(r));
r = at(saturday, T + 300);
check('sin conexión, sin estimación para ese tipo de día sale la hora oficial', r.status === 'scheduled' && r.etaSeconds === T + 2400, JSON.stringify(r));
r = at(thursday, T + 300, { timings: sample.timings });
check('sin conexión, una red sin horas estimadas sale con la hora oficial', r.status === 'scheduled', JSON.stringify(r));

// Festivo entre semana: hora estimada de fin de semana (08:15 en vez de 08:10).
const withHoliday = BideOffline.prepare({
    lines: [], patterns: [], timings: [[0, 600, 2400]], trips: [], stops: {},
    estimates: [[0, 300, 600], [0, 400, 900]], estimateTrusted: [1, 1],
    calendars: [[31, [], ['2026-11-05']], [64, ['2026-11-05'], []], [96, ['2026-11-06'], []]],
});
check('festivos: son los días que añaden los calendarios de solo domingo', withHoliday.holidays.has('2026-11-05') && !withHoliday.holidays.has('2026-11-06'), [...withHoliday.holidays].join(','));
const bothDays = [0, 0, T, 0, '1', T, 0, 1];
r = BideOffline.arrival(withHoliday, bothDays, 2, thursday, T + 2400, T + 300);
check('festivos: un jueves festivo usa la hora estimada de fin de semana', r.status === 'estimated' && r.etaSeconds === T + 900, JSON.stringify(r));
r = BideOffline.arrival(withHoliday, bothDays, 2, new Date(2026, 10, 12), T + 2400, T + 300);
check('festivos: el jueves siguiente, la de laborable', r.status === 'estimated' && r.etaSeconds === T + 600, JSON.stringify(r));

// Marcas sin conexión («ya pasó» / «no ha pasado»), guardadas en el móvil.
const store = new Map();
globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
const net = BideOffline.prepare({
    lines: [['1', 'A1', 'Línea de ejemplo']],
    patterns: [[0, 'Final', ['S1', 'S2', 'S3', 'S4']]],
    timings: [[0, 600, 2400, 2500]],
    estimates: [[0, 300, 600, 700]],
    estimateTrusted: [1],
    trips: [[0, 0, T, 0, '7', T, 0, -1]],
    calendars: [[127, [], []]],
    stops: { S1: ['Uno'], S2: ['Dos'], S3: ['Tres'], S4: ['Cuatro'] },
    lon: {},
    directionReference: null,
});
const nowAt = (seconds) => new Date(2026, 10, 5, 0, 0, seconds);
const statusAt = (stopId, seconds) => BideOffline.departures(net, stopId, nowAt(seconds), 5).departures.map((d) => d.status).join(',');
check('marcas: sin marcas el bus sale estimado en la tercera parada', statusAt('S3', T + 100) === 'estimated', statusAt('S3', T + 100));
let shown = BideOffline.departures(net, 'S2', nowAt(T + 100), 5).departures[0];
BideOffline.mark(shown, 'passed');
check('marcas: «ya pasó» en la segunda parada lo esconde ahí', statusAt('S2', T + 100) === '', statusAt('S2', T + 100));
check('marcas: pero sigue saliendo en la parada siguiente', statusAt('S3', T + 100) === 'estimated', statusAt('S3', T + 100));
const withHidden = BideOffline.departures(net, 'S2', nowAt(T + 100), 5);
check('marcas: la parada sabe cuántos buses escondió la marca', withHidden.hiddenByMarks.length === 1);
BideOffline.unmark(withHidden.hiddenByMarks);
check('marcas: «deshacer» lo devuelve', statusAt('S2', T + 100) === 'estimated', statusAt('S2', T + 100));
check('marcas: sin marca, a los 25 min sale como «probablemente ya pasó»', statusAt('S3', T + 1500) === 'likely_departed', statusAt('S3', T + 1500));
shown = BideOffline.departures(net, 'S3', nowAt(T + 1500), 5).departures[0];
BideOffline.mark(shown, 'coming');
check('marcas: «no ha pasado» lo devuelve con su hora oficial', statusAt('S3', T + 1500) === 'scheduled', statusAt('S3', T + 1500));
const otherDay = BideOffline.departures(net, 'S3', new Date(2026, 10, 6, 0, 0, T + 1500), 5).departures.map((d) => d.status).join(',');
check('marcas: una marca vale solo para el día de ese viaje', otherDay === 'likely_departed', otherDay);

const bad = results.filter((r) => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO horarios sin conexión: ${results.length - bad} OK, ${bad} MAL`);
let exitCode = 0;
if (bad) exitCode = 1;
process.exit(exitCode);
