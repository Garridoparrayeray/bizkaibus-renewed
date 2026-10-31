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
const sample = { timings: [[0, 600, 2400]], estimates: [[0, 300, 600]], estimateTrusted: [1] };
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

const bad = results.filter((r) => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO horarios sin conexión: ${results.length - bad} OK, ${bad} MAL`);
let exitCode = 0;
if (bad) exitCode = 1;
process.exit(exitCode);
