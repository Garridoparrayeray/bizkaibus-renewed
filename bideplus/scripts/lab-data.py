#!/usr/bin/env python3
"""
Datos del laboratorio local (lab/): un viaje por fila, con su línea, su bus, su día, su franja y su razón real / horario
(suma de lo que tardó de verdad hasta las paradas dividida entre la suma de lo que decía el horario, igual que el factor k).
Sale como lab/data/lab-data.js (una variable global) para que lab/index.html se abra con doble clic, sin servidor.

Uso (desde la carpeta bideplus):
  curl -L -o /tmp/pace-samples.jsonl https://github.com/Garridoparrayeray/bizkaibus-renewed/releases/download/pace-data/pace-samples.jsonl
  python3 scripts/lab-data.py /tmp/pace-samples.jsonl
"""
import sys, json, base64, struct, collections, pathlib
ROOT = pathlib.Path(__file__).resolve().parents[1]
if len(sys.argv) < 2:
    sys.exit(__doc__)
trips = {}
for line in open(sys.argv[1]):
    s = json.loads(line)
    t = trips.setdefault(s['track'], {'a': 0.0, 's': 0.0, 'line': s['line'], 'veh': s['vehicle'], 'date': s['date'], 'band': s['band'], 'at': s['at'], 'n': 0})
    t['a'] += s['actualRemaining']; t['s'] += s['scheduledRemaining']; t['n'] += 1
rows = [t for t in trips.values() if t['s'] > 0]
dias = sorted({t['date'] for t in rows})
bandas = sorted({t['band'] for t in rows})
lineas = sorted({t['line'] for t in rows}); vehiculos = sorted({t['veh'] for t in rows})
li = {k: i for i, k in enumerate(lineas)}; vi = {k: i for i, k in enumerate(vehiculos)}
rows.sort(key=lambda t: (t['date'], t['at']))
def col(vals, fmt):
    return base64.b64encode(struct.pack('<%d%s' % (len(vals), fmt), *vals)).decode()
out = {
    'dias': dias, 'bandas': bandas, 'lineas': lineas, 'vehiculos': vehiculos, 'n': len(rows),
    'dia': col([dias.index(t['date']) for t in rows], 'B'), 'linea': col([li[t['line']] for t in rows], 'B'),
    'bus': col([vi[t['veh']] for t in rows], 'H'), 'franja': col([bandas.index(t['band']) for t in rows], 'B'),
    'hora': col([min(65535, int(t['at'] // 60)) for t in rows], 'H'), 'razon': col([min(65535, round(t['a'] / t['s'] * 1000)) for t in rows], 'H'),
    'muestras': col([min(255, t['n']) for t in rows], 'B'),
    'k': json.loads((ROOT / 'engine' / 'data' / 'pace-factors.json').read_text()),
}
dest = ROOT.parent / 'lab' / 'data' / 'lab-data.js'
dest.parent.mkdir(parents=True, exist_ok=True)
dest.write_text('window.LAB = ' + json.dumps(out, separators=(',', ':'), ensure_ascii=False) + ';\n')
print('Escrito', dest, '-', len(rows), 'viajes,', len(vehiculos), 'buses,', len(lineas), 'líneas,', dest.stat().st_size // 1024, 'KB')
