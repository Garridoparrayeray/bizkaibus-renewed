import datetime
import json
import unicodedata
import os
import re
import sqlite3
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get('PHP_PATH', 'php')
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


# Una muestra de cada red (tests/network-samples.php): una red nueva entra sola.
NETS = json.loads(subprocess.check_output([PHP, os.path.join(ROOT, 'tests/network-samples.php')], text=True))


def plain(text):
    return ''.join(c for c in unicodedata.normalize('NFD', text.lower()) if unicodedata.category(c) != 'Mn')


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
schedule_checked = set()
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
            tt = get(with_net(f'/api/lines/{lid}/timetable?date={cfg.get("serviceDate", today)}&hourFrom=00:00&hourTo=23:59&stopId={sid}', s))
            if tt and tt['entries']:
                trip = enc(tt['entries'][0]['tripKey'])
                get(with_net(f'/api/vehicles/{trip}', s))
                get(with_net(f'/api/trips/{trip}?stopId={sid}', s))
            get(with_net(f'/api/lines/{lid}/timetable?date={today}&hourFrom=22:00&hourTo=05:00', s))
            get(with_net(f'/api/lines/{lid}/route', s))
            # «Ver horario oficial»: el del operador (Bizkaibus) o el sacado del GTFS de la red (GtfsScheduleText).
            if name not in schedule_checked:
                schedule_checked.add(name)
                text = get(with_net(f'/api/lines/{lid}/schedule-text', s)) or {}
                blocks = text.get('schedule')
                if text.get('source') == 'gtfs' and (not blocks or any(
                        not block.get('PERIODO_DESDE') or not re.search(r'\d\d:\d\d', block.get('HORARIO_IDA_CAS', '') + block.get('HORARIO_VUELTA_CAS', ''))
                        for block in blocks)):
                    fails.append(f'{name}: horario oficial del GTFS vacío o sin horas ({lid}) {str(blocks)[:120]}')
    get(with_net('/api/lines', s))
    get(with_net('/api/alerts', s))
    get(with_net('/api/search?q=x', s))
    get(with_net('/api/stops/999999999', s), expect=404)
    get(with_net('/api/lines/999999999', s), expect=404)
    get(with_net('/api/nope', s), expect=404)
    print(name, 'ok')

# Equivalencias de id de línea (tabla line_aliases): con el id viejo salen la línea nueva y su mismo horario.
for name, cfg in NETS.items():
    s = cfg['suffix']
    with open(os.path.join(ROOT, 'api', 'Networks', f'{name}.php'), encoding='utf-8') as card:
        database = re.search(r"'db'\s*=>\s*'([^']+)'", card.read()).group(1)
    connection = sqlite3.connect(f'file:{os.path.join(ROOT, "data", database + ".sqlite")}?mode=ro', uri=True)
    try:
        aliases = connection.execute('SELECT old_id, line_id FROM line_aliases LIMIT 3').fetchall()
    except sqlite3.OperationalError:
        aliases = []
    connection.close()
    for old_id, line_id in aliases:
        day = cfg.get('serviceDate', today)
        old = get(with_net(f'/api/lines/{enc(old_id)}/timetable?date={day}', s)) or {}
        new = get(with_net(f'/api/lines/{enc(line_id)}/timetable?date={day}', s)) or {}
        if old.get('line', {}).get('id') != line_id or len(old.get('entries', [])) != len(new.get('entries', [])):
            fails.append(f'{name}: el horario con el id viejo {old_id} no es el de {line_id} '
                         f'({len(old.get("entries", []))} salidas frente a {len(new.get("entries", []))})')
    if aliases:
        print(name, 'equivalencias de línea ok', len(aliases))

for name, cfg in NETS.items():
    s = cfg['suffix']
    lat, lon, fragment = cfg['lat'], cfg['lon'], cfg['q']
    res = get(with_net(f'/api/nearby?lat={lat}&lon={lon}&limit=5', s)) or {}
    stops = res.get('stops', [])
    if not stops:
        fails.append(f'{name}: nearby no devuelve paradas')
        continue
    if fragment not in plain(stops[0]['name']):
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
if 'bus' in NETS and status.get('source') != 'ctb-siri.s3.eu-south-2.amazonaws.com' or 'ok' not in status or 'ms' not in status:
    fails.append(f'/api/status: la fuente de los buses no es el feed GPS de Open Data Bizkaia o falta el diagnostico {status}')
get('/api/status?red=launion', 404)
metro_status = get('/api/status?red=metro') or {}
if metro_status.get('ok') is None or 'feedTrips' not in metro_status or 'matching' not in metro_status:
    fails.append(f'/api/status?red=metro: sin el diagnóstico del directo {metro_status}')
if not isinstance(metro_status.get('reducedService'), dict) or 'today' not in metro_status['reducedService']:
    fails.append(f'/api/status?red=metro: sin el servicio reducido {metro_status.get("reducedService")}')
renfe_reduced = get('/api/reduced?red=renfe') or {}
if not isinstance(renfe_reduced.get('days'), list) or 'cause' not in renfe_reduced:
    fails.append(f'/api/reduced?red=renfe: sin el servicio reducido {renfe_reduced}')
# Aviso de versión nueva.
version = get('/api/app/version?desde=0&lang=eu') or {}
if not isinstance(version.get('version'), int) or not version.get('notes') or not all(n.get('title') and n.get('text') for n in version['notes']):
    fails.append(f'/api/app/version: sin versión o sin novedades {version}')
if (get(f"/api/app/version?desde={version.get('version', 0)}") or {}).get('notes') != []:
    fails.append('/api/app/version: con la versión al día no debería haber novedades pendientes')
# Aviso general: sin aviso publicado (o sin salida a GitHub) responde {"notice": null}; si hay, trae lo que usa el cartel.
notice = get('/api/notice') or {}
if 'notice' not in notice:
    fails.append(f'/api/notice: sin la clave notice {notice}')
elif notice['notice'] is not None and not all(k in notice['notice'] for k in ('id', 'text', 'expiresAt')):
    fails.append(f"/api/notice: al aviso le faltan campos {notice['notice']}")
# El endpoint del cron del registro de pasos no existe sin su clave secreta.
get('/api/live/record', 404)
get('/api/live/record?key=adivina', 404)
get('/api/live/history?line=3514', 404)
get('/api/planner/live?legs=')
get('/api/planner/live?legs=metro%7C1', 422)

# Búsqueda por palabras: pueblo y parada en cualquier orden, sin importar mayúsculas.
for q in ('amorebieta san juan', 'SAN JUAN Amorebieta'):
    found = (get('/api/search?q=' + urllib.parse.quote(q)) or {}).get('stops', [])
    if not any(x['name'].upper() == 'SAN JUAN' and 'amorebieta' in (x.get('area') or '').lower() for x in found):
        fails.append(f'búsqueda «{q}»: no encuentra SAN JUAN de Amorebieta')
lines_found = (get('/api/search?q=' + urllib.parse.quote('gernika bilbao')) or {}).get('lines', [])
if not lines_found:
    fails.append('búsqueda «gernika bilbao»: no encuentra líneas entre los dos pueblos')

# Lo que no se publica: bases de datos, scripts, pruebas y el motor (código y datos).
# También escrito de otra forma («//», «/./», «..») y los ficheros que empiezan por punto.
for private in ('/data/bizkaibus.sqlite', '/scripts/build-database.php', '/engine/bizkaibus/data/pace-factors.json', '/engine/bizkaibus/RealtimeMatcher.php', '/api/Networks/bus.php',
                '//data/bizkaibus.sqlite', '/./data/bizkaibus.sqlite', '/js/../data/bizkaibus.sqlite', '//tests/README.md', '/.gitignore', '/.vercelignore', '/.htaccess'):
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


def seo_problems(where, page):
    # Ni un mapa con los vehículos en directo, que no existe, ni la doble puntuación de una lista recortada («N1….»).
    description = (_re.search(r'<meta property="og:description" content="([^"]*)"', page) or [None, ''])[1]
    if '….' in description or 'Mapa con los' in page:
        fails.append(f'{where}: descripción para buscadores con «….» o con el mapa en directo ({description[:80]!r})')


# El índice (sitemap-index.xml) y un sitemap por red.
code, index = fetch_text('/sitemap-index.xml')
sitemap = ''
for child in _re.findall(r'<loc>([^<]+)</loc>', index):
    code, part = fetch_text(urllib.parse.urlparse(child).path)
    try:
        _minidom.parseString(part)
        sitemap += part
    except Exception:
        fails.append(f'{child}: XML no válido ({code})')
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
            fails.append(f'sitemaps: sin {kind} de {red}')
            continue
        for loc in sample[::max(1, len(sample) // 2)][:2]:
            parts = urllib.parse.urlparse(loc)
            target = parts.path
            if parts.query:
                target += '?' + parts.query
            code, page = fetch_text(target)
            canonical = (_re.search(r'rel="canonical" href="([^"]+)"', page) or [None, ''])[1].replace('&amp;', '&')
            if code != 200 or canonical != loc:
                fails.append(f'sitemaps: {loc} da {code} con canónica {canonical!r}')
            seo_problems(loc, page)
    code, page = fetch_text(with_net(f"/stops/{enc(NETS[red]['stopId'])}", NETS[red]['suffix']))
    seo_problems(f"/stops/{NETS[red]['stopId']}", page)
print('sitemap ok')

# La ruta vieja de Leaflet (/lib/leaflet/) sirve lo mismo que la de ahora: las pestañas abiertas antes de actualizar.
code_old, old_leaflet = fetch_text('/lib/leaflet/leaflet.js')
code_new, new_leaflet = fetch_text('/lib/leaflet-1.9.4/leaflet.js')
if code_old != 200 or code_new != 200 or old_leaflet != new_leaflet:
    fails.append(f'/lib/leaflet/leaflet.js: {code_old}, no sirve lo de /lib/leaflet-1.9.4/ ({code_new})')

# Las versiones en euskera e inglés (?lang=): traducidas en el servidor, con su lang, su canónica y las tres hreflang.
for path, lang, text in (('/?lang=eu', 'eu', 'Aplikazio guztiak'), ('/?lang=en', 'en', 'All the apps'),
                         ('/mapa?lang=eu', 'eu', None), ('/?red=metro&lang=en', 'en', None), ('/?lang=fr', 'fr', None)):
    code, page = fetch_text(path)
    canonical = (_re.search(r'rel="canonical" href="([^"]+)"', page) or [None, ''])[1].replace('&amp;', '&')
    hreflangs = set(_re.findall(r'hreflang="([a-z-]+)"', page))
    if code != 200 or f'<html lang="{lang}"' not in page or not canonical.endswith('lang=' + lang):
        fails.append(f'{path}: {code}, sin lang="{lang}" o con canónica {canonical!r}')
    if hreflangs != {'es', 'eu', 'en', 'fr', 'x-default'}:
        fails.append(f'{path}: hreflang {sorted(hreflangs)}')
    if text and text not in page:
        fails.append(f'{path}: no sale traducido («{text}»)')
code, page = fetch_text('/')
if '<html lang="es"' not in page or 'FAQPage' not in page:
    fails.append('/: sin lang="es" o sin las preguntas frecuentes (FAQPage)')
print('idiomas ok')

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
if fails:
    sys.exit(1)
sys.exit(0)
