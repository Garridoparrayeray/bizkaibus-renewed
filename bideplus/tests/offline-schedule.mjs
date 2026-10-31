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

const bad = results.filter((r) => r.startsWith('MAL')).length;
console.log(results.join('\n'));
console.log(`\nRESULTADO horarios sin conexión: ${results.length - bad} OK, ${bad} MAL`);
let exitCode = 0;
if (bad) exitCode = 1;
process.exit(exitCode);
