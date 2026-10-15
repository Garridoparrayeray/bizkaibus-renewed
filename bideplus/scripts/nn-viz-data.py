#!/usr/bin/env python3
"""
Datos de la página docs/red-neuronal.html: las muestras de tramos acumuladas (release "pace-data") empaquetadas en columnas
binarias (base64) para que el navegador las lea sin pedir nada. Cada fila es un tramo medido de un bus.

Uso (desde la carpeta bideplus):
  curl -L -o /tmp/segment-samples.jsonl https://github.com/Garridoparrayeray/bizkaibus-renewed/releases/download/pace-data/segment-samples.jsonl
  curl -L -o /tmp/pace-samples.jsonl https://github.com/Garridoparrayeray/bizkaibus-renewed/releases/download/pace-data/pace-samples.jsonl
  python3 scripts/nn-viz-data.py /tmp/segment-samples.jsonl /tmp/pace-samples.jsonl --page ../docs/red-neuronal.html   # reescribe "const DATA = ...;" y "const DATA2 = ...;"
  python3 scripts/nn-viz-data.py /tmp/segment-samples.jsonl /tmp/pace-samples.jsonl                                    # o imprime el JSON de los dos

Segundo conjunto (pace-samples.jsonl): trayectos completos hasta una parada (tiempo que faltaba según el horario y lo que tardó
de verdad): día, viaje, línea, horario restante (s), real restante (s), minuto del día, franja y fin de semana.

Columnas del primero: largo del tramo (m, del GTFS), horario del tramo (s), tiempo real (décimas de s), minuto del día, franja, fin de
semana, bus, día, tramo (id denso) y la ubicación del tramo (latitud menos 42,5 y longitud más 3,5, en diezmilésimas de grado, del punto medio entre sus dos paradas).
"""
import sys, re, json, base64, struct, sqlite3, statistics as st, collections, datetime, pathlib
ROOT = pathlib.Path(__file__).resolve().parents[1]
args = [a for a in sys.argv[1:] if not a.startswith('--')]
if not args:
    sys.exit(__doc__)
PAGE = pathlib.Path(sys.argv[sys.argv.index('--page') + 1]).resolve() if '--page' in sys.argv else None

c = sqlite3.connect(str(ROOT / 'data' / 'bizkaibus.sqlite'))
loc = {str(i): (la, lo) for i, la, lo in c.execute('select id,lat,lon from stops')}
d = collections.defaultdict(list); prev = None
for sj, seq, stop, dist in c.execute('select service_journey_id,seq_order,stop_id,dist_m from passing_times order by service_journey_id,seq_order'):
    if prev and prev[0] == sj and dist is not None and prev[3] is not None:
        d[(prev[2], str(stop))].append(dist - prev[3])
    prev = (sj, seq, str(stop), dist)
dist = {k: st.median(v) for k, v in d.items()}
BANDS = {'manana': 0, 'mediodia': 1, 'punta_tarde': 2, 'noche': 3}
rows = []; bus_id = {}; seg_id = {}; days = []
for line in open(args[0]):
    s = json.loads(line)
    m = dist.get((str(s['from']), str(s['to'])))
    if not m or m <= 0 or s['scheduled'] < 10 or s['actual'] < 5 or s['actual'] > 6500:
        continue
    a = loc.get(str(s['from'])); b = loc.get(str(s['to']))
    if not a or not b:
        continue
    if s['date'] not in days:
        days.append(s['date'])
    wd = datetime.date.fromisoformat(s['date']).isoweekday()
    rows.append((days.index(s['date']), bus_id.setdefault(s['track'], len(bus_id)), seg_id.setdefault((s['from'], s['to']), len(seg_id)),
                 min(65535, round(m)), min(65535, int(s['scheduled'])), round(s['actual'] * 10), min(65535, int(s['at'] // 60)),
                 BANDS.get(s['band'].split('.')[1], 1), 1 if wd >= 6 else 0,
                 round(((a[0] + b[0]) / 2 - 42.5) * 1e4), round(((a[1] + b[1]) / 2 + 3.5) * 1e4)))
rows.sort(key=lambda r: r[0])           # por días; dentro de cada día, el orden de captura
def col(idx, fmt):
    return base64.b64encode(struct.pack('<%d%s' % (len(rows), fmt), *[r[idx] for r in rows])).decode()
out = {'n': len(rows), 'buses': len(bus_id), 'tramos': len(seg_id), 'dias': days,
       'day': col(0, 'B'), 'bus': col(1, 'H'), 'seg': col(2, 'H'), 'm': col(3, 'H'), 'h': col(4, 'H'), 'y': col(5, 'H'),
       'at': col(6, 'H'), 'band': col(7, 'B'), 'we': col(8, 'B'), 'lat': col(9, 'H'), 'lon': col(10, 'H')}
def pace(path):
    prow = []; tr = {}; dys = []; lines = {}
    for line in open(path):
        q = json.loads(line)
        if q['scheduledRemaining'] < 30 or q['actualRemaining'] < 5 or q['actualRemaining'] > 9000 or q['scheduledRemaining'] > 9000:
            continue
        if q['date'] not in dys:
            dys.append(q['date'])
        wd = datetime.date.fromisoformat(q['date']).isoweekday()
        prow.append((dys.index(q['date']), tr.setdefault(q['track'], len(tr)), lines.setdefault(q['line'], len(lines)), round(q['scheduledRemaining']),
                     round(q['actualRemaining']), int(q['at'] // 60), BANDS.get(q['band'].split('.')[1], 1), 1 if wd >= 6 else 0))
    prow.sort(key=lambda r: r[0])
    cnt = collections.Counter(r[2] for r in prow)
    top = [i for i, _ in cnt.most_common(12)]
    def pc(idx, fmt):
        return base64.b64encode(struct.pack('<%d%s' % (len(prow), fmt), *[r[idx] for r in prow])).decode()
    codes = [None] * len(lines)
    for k, v in lines.items():
        codes[v] = k
    return {'n': len(prow), 'buses': len(tr), 'dias': dys, 'lineas': codes, 'top': top,
            'day': pc(0, 'B'), 'bus': pc(1, 'H'), 'line': pc(2, 'B'), 'h': pc(3, 'H'), 'y': pc(4, 'H'), 'at': pc(5, 'H'), 'band': pc(6, 'B'), 'we': pc(7, 'B')}

out2 = pace(args[1]) if len(args) > 1 else None
js = json.dumps(out, separators=(',', ':'))
js2 = json.dumps(out2, separators=(',', ':')) if out2 else None
if PAGE:
    page = PAGE.read_text()
    new_page, n = re.subn(r'^const DATA = .*;$', lambda m: 'const DATA = ' + js + ';', page, count=1, flags=re.M)
    if n != 1:
        sys.exit('No se encontró la línea "const DATA = ...;" en ' + str(PAGE))
    if js2:
        new_page, n2 = re.subn(r'^const DATA2 = .*;$', lambda m: 'const DATA2 = ' + js2 + ';', new_page, count=1, flags=re.M)
        if n2 != 1:
            sys.exit('No se encontró la línea "const DATA2 = ...;" en ' + str(PAGE))
    PAGE.write_text(new_page)
    print('Página actualizada:', PAGE, '(', len(js) // 1024, 'KB + ', (len(js2) // 1024) if js2 else 0, 'KB de datos,', len(rows), 'tramos y', out2['n'] if out2 else 0, 'trayectos )')
else:
    print(json.dumps({'tramos': out, 'trayectos': out2}, separators=(',', ':')))
