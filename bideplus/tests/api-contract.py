"""Contrato de la API: los campos y tipos que usa el frontend (js/app.js) en cada respuesta.

api-smoke.py comprueba que las rutas responden; esta prueba comprueba que la forma del JSON
no cambia sin querer. Se pueden añadir campos nuevos; quitar o cambiar de tipo uno de estos rompe la app.
"""
import datetime
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
fails = []
checked = 0

ID = (int, str)
NUM = (int, float)
HM = re.compile(r'^\d{2}:\d{2}$')
STATUS = {'scheduled', 'live', 'departed', 'finished'}


def opt(*schema):
    if len(schema) == 1:
        return ('opt', schema[0])
    return ('opt', schema)


LINE = {'id': ID, 'code': str, 'name': str}
STOP_REF = {'id': ID, 'name': str, 'lat': NUM, 'lon': NUM}

SCHEMAS = {
    'search': {'stops': [{'id': ID, 'name': str, 'area': str, 'lat': NUM, 'lon': NUM}], 'lines': [LINE]},
    'stop': {'id': ID, 'name': str, 'lat': NUM, 'lon': NUM, 'lines': [LINE]},
    'departures': {
        'stop': {'id': ID, 'name': str},
        'departures': [{
            'lineId': ID, 'lineCode': str, 'lineName': str, 'headsign': opt(str), 'tripKey': str,
            'scheduledTime': 'hm', 'etaMinutes': int, 'status': 'status', 'delayMinutes': int,
        }],
        'attribution': str,
    },
    'lines': {'lines': [LINE]},
    'line': {'id': ID, 'code': str, 'name': str, 'patterns': [{'id': ID, 'headsign': opt(str)}]},
    'timetable': {
        'line': LINE, 'date': str,
        'entries': [{'tripKey': str, 'departure': 'hm', 'headsign': opt(str), 'status': 'status', 'delayMinutes': int}],
        'beyondPublished': bool,
    },
    'trip': {'lineCode': str, 'lineName': str, 'headsign': opt(str),
             'stops': [{'stopId': ID, 'name': str, 'scheduledTime': 'hm', 'isTarget': bool}]},
    'vehicle': {'lineCode': str, 'lineName': str, 'headsign': opt(str), 'status': 'status', 'vehicleRef': opt(str),
                'delayMinutes': int,
                'stops': [{'stopId': ID, 'name': str, 'scheduledTime': 'hm', 'etaMinutes': int, 'isPast': bool, 'isCurrent': bool}]},
    'live': {'line': LINE, 'patterns': [{'id': ID, 'headsign': opt(str), 'stops': [STOP_REF]}],
             'vehicles': [{'vehicleRef': opt(str), 'delayMinutes': int, 'headsign': opt(str), 'currentStop': STOP_REF}]},
    'alerts': {'alerts': ['alert']},
    'nearby': {'stops': [{'id': ID, 'name': str, 'lat': NUM, 'lon': NUM, 'distanceM': NUM,
                          'next': opt({'lineCode': str, 'headsign': opt(str), 'etaMinutes': int, 'scheduledTime': 'hm'})}]},
    'error': {'error': str},
}


def check(value, schema, path, where):
    global checked
    checked += 1
    if isinstance(schema, tuple) and len(schema) == 2 and schema[0] == 'opt':
        if value is None:
            return
        schema = schema[1]
    if schema == 'hm':
        if not isinstance(value, str) or not HM.match(value):
            fails.append(f'{where} {path}: hora HH:MM esperada, llegó {value!r}')
        return
    if schema == 'alert':
        check_alert(value, path, where)
        return
    if schema == 'status':
        if value not in STATUS:
            fails.append(f'{where} {path}: estado desconocido {value!r} (la app conoce {sorted(STATUS)})')
        return
    if isinstance(schema, dict):
        if not isinstance(value, dict):
            fails.append(f'{where} {path}: objeto esperado, llegó {type(value).__name__}')
            return
        for key, sub in schema.items():
            if key not in value:
                fails.append(f'{where} {path}.{key}: falta el campo')
                continue
            check(value[key], sub, f'{path}.{key}', where)
        return
    if isinstance(schema, list):
        if not isinstance(value, list):
            fails.append(f'{where} {path}: lista esperada, llegó {type(value).__name__}')
            return
        for i, item in enumerate(value[:25]):
            check(item, schema[0], f'{path}[{i}]', where)
        return
    types = schema if isinstance(schema, tuple) else (schema,)
    if isinstance(value, bool) and bool not in types:
        fails.append(f'{where} {path}: {types} esperado, llegó bool')
    elif not isinstance(value, types):
        fails.append(f'{where} {path}: {"/".join(t.__name__ for t in types)} esperado, llegó {type(value).__name__} {value!r:.40}')


def check_alert(alert, path, where):
    """Cada operador publica los avisos con su forma; esto es lo que lee la app de cada uno.

    - Metro, Euskotren y tranvías: muestran `summary || title` (Metro mezcla su CMS, con
      `summary`, y su SIRI, con `title`) y la descripción si la hay.
    - Bizkaibus: además filtra por línea con `lineRefs`, que tiene que ser una lista.
    - Renfe: `summary`, `description` y `scope` (estación o línea).
    """
    if not isinstance(alert, dict):
        fails.append(f'{where} {path}: objeto esperado, llegó {type(alert).__name__}')
        return
    if not isinstance(alert.get('summary'), str) and not isinstance(alert.get('title'), str):
        fails.append(f'{where} {path}: falta el texto del aviso (ni summary ni title)')
    for key in ('summary', 'title', 'description', 'startTime', 'endTime', 'scope'):
        if alert.get(key) is not None and not isinstance(alert[key], str):
            fails.append(f'{where} {path}.{key}: str esperado, llegó {type(alert[key]).__name__}')
    if where == 'bus' or 'lineRefs' in alert:
        refs = alert.get('lineRefs')
        if not isinstance(refs, list) or not all(isinstance(r, (str, int)) for r in refs):
            fails.append(f'{where} {path}.lineRefs: lista de líneas esperada, llegó {refs!r:.60}')
    if where == 'renfe' and alert.get('scope') not in ('stop', 'line', 'network', None):
        fails.append(f"{where} {path}.scope: valor desconocido {alert.get('scope')!r}")


def get(path, schema, where, expect=200):
    try:
        with urllib.request.urlopen(BASE + path, timeout=60) as r:
            code, body = r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode('utf-8')
    except Exception as e:
        fails.append(f'{where} {path}: {e}')
        return None
    if code != expect:
        fails.append(f'{where} {path}: esperaba {expect}, dio {code}')
        return None
    try:
        data = json.loads(body)
    except ValueError:
        fails.append(f'{where} {path}: respuesta no JSON')
        return None
    check(data, SCHEMAS[schema], schema, where)
    return data


NETS = {
    'bus': ('moyua', '', (43.26347, -2.93506)),
    'metro': ('aba', 'red=metro', (43.32595, -3.00961)),
    'euskotren': ('ama', 'red=euskotren', (43.313179, -1.981685)),
    'tranvia-bilbao': ('atxuri', 'red=tranvia-bilbao', (43.254113, -2.921513)),
    'tranvia-vitoria': ('abetxuko', 'red=tranvia-vitoria', (42.876277, -2.679599)),
    'renfe': ('abando', 'red=renfe', (43.2601304, -2.9285592)),
}


def net(path, suffix):
    if not suffix:
        return path
    return path + ('&' if '?' in path else '?') + suffix


def enc(value):
    return urllib.parse.quote(str(value), safe=':')


today = datetime.date.today().isoformat()

try:
    from zoneinfo import ZoneInfo
    madrid_hour = datetime.datetime.now(ZoneInfo('Europe/Madrid')).hour
except Exception:
    madrid_hour = (datetime.datetime.now(datetime.timezone.utc).hour + 1) % 24
# Las próximas salidas miran 4 h por delante: de noche una red pequeña puede no tener ninguna.
quiet_hours = madrid_hour < 8 or madrid_hour >= 21
for name, (q, s, (lat, lon)) in NETS.items():
    before = len(fails)
    departures_seen = 0
    entries_seen = 0
    beyond_published = False
    res = get(net('/api/search?q=' + q, s), 'search', name) or {}
    get(net('/api/lines', s), 'lines', name)
    get(net('/api/alerts', s), 'alerts', name)
    get(net(f'/api/nearby?lat={lat}&lon={lon}&limit=5', s), 'nearby', name)
    get(net('/api/stops/999999999', s), 'error', name, expect=404)
    get(net('/api/nearby?lat=abc&lon=1', s), 'error', name, expect=422)
    for st in (res.get('stops') or [])[:2]:
        sid = enc(st['id'])
        stop = get(net(f'/api/stops/{sid}', s), 'stop', name) or {}
        dep = get(net(f'/api/stops/{sid}/departures?limit=8', s), 'departures', name) or {}
        departures_seen += len(dep.get('departures') or [])
        for line in (stop.get('lines') or [])[:3]:
            lid = enc(line['id'])
            get(net(f'/api/lines/{lid}', s), 'line', name)
            get(net(f'/api/lines/{lid}/live', s), 'live', name)
            tt = get(net(f'/api/lines/{lid}/timetable?date={today}&hourFrom=00:00&hourTo=23:59&stopId={sid}', s), 'timetable', name) or {}
            entries_seen += len(tt.get('entries') or [])
            if tt.get('beyondPublished'):
                beyond_published = True
            for entry in (tt.get('entries') or [])[:1]:
                trip = enc(entry['tripKey'])
                get(net(f'/api/trips/{trip}?stopId={sid}', s), 'trip', name)
                get(net(f'/api/vehicles/{trip}', s), 'vehicle', name)
    if entries_seen == 0 and not beyond_published:
        fails.append(f'{name}: ningún horario de hoy tiene salidas (una lista vacía no comprueba nada)')
    if departures_seen == 0 and not quiet_hours:
        fails.append(f'{name}: ninguna parada muestra próximas salidas')
    print(name, 'OK' if len(fails) == before else f'{len(fails) - before} fallos')

print(f'RESULTADO contrato: {checked} comprobaciones, {len(fails)} fallos')
for f in fails[:60]:
    print(' -', f)
sys.exit(1 if fails else 0)
