import datetime
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
fails = []
count = 0


def get(path, expect=200):
    global count
    count += 1
    try:
        with urllib.request.urlopen(BASE + path, timeout=60) as r:
            code, body = r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode('utf-8')
    except Exception as e:
        fails.append(f'{path}: {e}')
        return None
    try:
        data = json.loads(body)
    except Exception:
        fails.append(f'{path}: respuesta no JSON ({code})')
        return None
    if code != expect:
        fails.append(f'{path}: esperaba {expect}, dio {code} {str(data)[:80]}')
    return data


NETS = {
    'bus': {'q': 'moyua', 'suffix': ''},
    'metro': {'q': 'aba', 'suffix': 'red=metro'},
    'euskotren': {'q': 'ama', 'suffix': 'red=euskotren'},
    'tranvia-bilbao': {'q': 'atxuri', 'suffix': 'red=tranvia-bilbao'},
    'tranvia-vitoria': {'q': 'abetxuko', 'suffix': 'red=tranvia-vitoria'},
    'renfe': {'q': 'abando', 'suffix': 'red=renfe'},
    'lurraldebus': {'q': 'tolosa', 'suffix': 'red=lurraldebus'},
}


def with_net(path, suffix):
    if not suffix:
        return path
    separator = '?'
    if '?' in path:
        separator = '&'
    return path + separator + suffix


def enc(value):
    return urllib.parse.quote(str(value), safe=':')


today = datetime.date.today().isoformat()
for name, cfg in NETS.items():
    s = cfg['suffix']
    res = get(with_net('/api/search?q=' + cfg['q'], s))
    stops = (res or {}).get('stops', [])[:3]
    if not stops:
        fails.append(f'{name}: la busqueda no devuelve paradas')
        continue
    for st in stops:
        sid = enc(st['id'])
        stop = get(with_net(f'/api/stops/{sid}', s)) or {}
        dep = get(with_net(f'/api/stops/{sid}/departures?limit=8', s)) or {}
        if 'departures' not in dep:
            fails.append(f'{name}: departures sin campo departures ({sid})')
        for line in (stop.get('lines') or [])[:2]:
            lid = enc(line['id'])
            get(with_net(f'/api/lines/{lid}', s))
            tt = get(with_net(f'/api/lines/{lid}/timetable?date={today}&hourFrom=00:00&hourTo=23:59&stopId={sid}', s))
            if tt and tt['entries']:
                trip = enc(tt['entries'][0]['tripKey'])
                get(with_net(f'/api/vehicles/{trip}', s))
                get(with_net(f'/api/trips/{trip}?stopId={sid}', s))
            get(with_net(f'/api/lines/{lid}/timetable?date={today}&hourFrom=22:00&hourTo=05:00', s))
            get(with_net(f'/api/lines/{lid}/live', s))
    get(with_net('/api/lines', s))
    get(with_net('/api/alerts', s))
    get(with_net('/api/search?q=x', s))
    get(with_net('/api/stops/999999999', s), expect=404)
    get(with_net('/api/lines/999999999', s), expect=404)
    get(with_net('/api/nope', s), expect=404)
    print(name, 'ok')

NEARBY = {
    'bus': (43.26347, -2.93506, 'MOYUA'),
    'metro': (43.32595, -3.00961, 'Areeta'),
    'euskotren': (43.313179, -1.981685, 'Amara'),
    'tranvia-bilbao': (43.254113, -2.921513, 'Atxuri'),
    'tranvia-vitoria': (42.876277, -2.679599, 'Abetxuko'),
    'renfe': (43.2601304, -2.9285592, 'Abando'),
    'lurraldebus': (43.31619, -1.97738, 'Donostia'),
}
for name, (lat, lon, fragment) in NEARBY.items():
    s = NETS[name]['suffix']
    res = get(with_net(f'/api/nearby?lat={lat}&lon={lon}&limit=5', s)) or {}
    stops = res.get('stops', [])
    if not stops:
        fails.append(f'{name}: nearby no devuelve paradas')
        continue
    if fragment.lower() not in stops[0]['name'].lower():
        fails.append(f"{name}: la parada mas cercana deberia contener {fragment}, es {stops[0]['name']}")
    distances = [x['distanceM'] for x in stops]
    if distances != sorted(distances):
        fails.append(f'{name}: nearby no esta ordenado por distancia {distances}')
    for x in stops:
        nxt = x.get('next')
        if nxt is not None and (nxt['etaMinutes'] < 0 or not nxt['lineCode'] or not nxt['scheduledTime']):
            fails.append(f'{name}: proxima salida invalida {nxt}')
    print(name, 'nearby ok', distances)
status = get('/api/status') or {}
if status.get('source') != 'ctb-siri.s3.eu-south-2.amazonaws.com' or 'ok' not in status or 'ms' not in status:
    fails.append(f'/api/status: la fuente de los buses no es el feed GPS de Open Data Bizkaia o falta el diagnostico {status}')
get('/api/status?red=metro', 404)
# Aviso de versión nueva.
version = get('/api/app/version?desde=0&lang=eu') or {}
if not isinstance(version.get('version'), int) or not version.get('notes') or not all(n.get('title') and n.get('text') for n in version['notes']):
    fails.append(f'/api/app/version: sin versión o sin novedades {version}')
if (get(f"/api/app/version?desde={version.get('version', 0)}") or {}).get('notes') != []:
    fails.append('/api/app/version: con la versión al día no debería haber novedades pendientes')
# El endpoint del cron del registro de pasos no existe sin su clave secreta.
get('/api/live/record', 404)
get('/api/live/record?key=adivina', 404)
get('/api/live/history?line=3514', 404)

# Búsqueda por palabras: pueblo y parada en cualquier orden, sin importar mayúsculas.
for q in ('amorebieta san juan', 'SAN JUAN Amorebieta'):
    found = (get('/api/search?q=' + urllib.parse.quote(q)) or {}).get('stops', [])
    if not any(x['name'] == 'SAN JUAN' and 'amorebieta' in (x.get('area') or '').lower() for x in found):
        fails.append(f'búsqueda «{q}»: no encuentra SAN JUAN de Amorebieta')
lines_found = (get('/api/search?q=' + urllib.parse.quote('gernika bilbao')) or {}).get('lines', [])
if not lines_found:
    fails.append('búsqueda «gernika bilbao»: no encuentra líneas entre los dos pueblos')

# Lo que no se publica: bases de datos, scripts, pruebas y el motor (código y datos).
for private in ('/data/bizkaibus.sqlite', '/scripts/build-database.php', '/engine/bizkaibus/data/pace-factors.json', '/engine/bizkaibus/RealtimeMatcher.php', '/api/Networks/bus.php'):
    count += 1
    try:
        code = urllib.request.urlopen(BASE + private, timeout=60).status
    except urllib.error.HTTPError as e:
        code = e.code
    if code != 404:
        fails.append(f'{private}: se sirve (HTTP {code}) y no debería')
bus_alerts = (get('/api/alerts') or {}).get('alerts', [])
for name in [n for n in NETS if n != 'bus']:
    other = (get(with_net('/api/alerts', NETS[name]['suffix'])) or {}).get('alerts', [])
    if bus_alerts and other == bus_alerts:
        fails.append(f'{name}: los avisos son identicos a los de Bizkaibus (cache compartida entre redes)')
    if any('Bizkaibus' in (x.get('description') or '') + (x.get('summary') or '') + (x.get('title') or '') for x in other):
        fails.append(f'{name}: aparecen avisos de Bizkaibus')
renfe_alerts = (get('/api/alerts?red=renfe') or {}).get('alerts', [])
station_alerts = [x for x in renfe_alerts if x.get('scope') == 'stop']
for sample in station_alerts[:3]:
    if not sample.get('summary') or not sample.get('description'):
        fails.append(f'renfe: aviso de estacion sin titulo o descripcion {sample}')
if renfe_alerts and not any(x.get('scope') in ('stop', 'line') for x in renfe_alerts):
    fails.append('renfe: los avisos no indican si son de estacion o de linea')
renfe_stop_alerts = (get('/api/alerts?red=renfe&stop=05451') or {}).get('alerts', [])
if any(x.get('scope') == 'stop' for x in renfe_stop_alerts):
    fails.append('renfe: la parada 05451 (Bilbao la Concordia) recibe avisos de otras estaciones')
get('/api/nearby', expect=422)
get('/api/nearby?lat=abc&lon=1', expect=422)
get('/api/nearby?lat=40.4&lon=-3.7', expect=422)

found = (get('/api/search?q=A3513') or {}).get('lines', [])
if found:
    get(f"/api/lines/{found[0]['id']}/schedule-text")


# Sitemap: XML válido con el menú, las seis apps y líneas y paradas de cada red, y que una muestra
# de sus URLs abra la página con la misma canónica.
import re as _re
import xml.dom.minidom as _minidom


def fetch_text(path):
    global count
    count += 1
    try:
        with urllib.request.urlopen(BASE + path, timeout=60) as r:
            return r.status, r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8')
    except Exception as e:
        return 0, str(e)


code, sitemap = fetch_text('/sitemap.xml')
try:
    _minidom.parseString(sitemap)
except Exception:
    fails.append(f'/sitemap.xml: XML no válido ({code})')
    sitemap = ''
locs = [loc.replace('&amp;', '&') for loc in _re.findall(r'<loc>([^<]+)</loc>', sitemap)]
for red in NETS:
    suffix = ''
    if red != 'bus':
        suffix = '?red=' + red

    def of_network(loc):
        # La red por defecto no lleva ?red= en sus direcciones.
        if suffix:
            return loc.endswith(suffix)
        return '?' not in loc

    for kind in ('lines', 'stops'):
        sample = [loc for loc in locs if f'/{kind}/' in loc and of_network(loc)]
        if not sample:
            fails.append(f'/sitemap.xml: sin {kind} de {red}')
            continue
        for loc in sample[::max(1, len(sample) // 2)][:2]:
            parts = urllib.parse.urlparse(loc)
            target = parts.path
            if parts.query:
                target += '?' + parts.query
            code, page = fetch_text(target)
            canonical = (_re.search(r'rel="canonical" href="([^"]+)"', page) or [None, ''])[1].replace('&amp;', '&')
            if code != 200 or canonical != loc:
                fails.append(f'/sitemap.xml: {loc} da {code} con canónica {canonical!r}')
print('sitemap ok')

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
if fails:
    sys.exit(1)
sys.exit(0)
