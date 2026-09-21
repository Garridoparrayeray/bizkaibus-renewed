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
}


def with_net(path, suffix):
    if not suffix:
        return path
    return path + ('&' if '?' in path else '?') + suffix


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

found = (get('/api/search?q=A3513') or {}).get('lines', [])
if found:
    get(f"/api/lines/{found[0]['id']}/schedule-text")

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
sys.exit(1 if fails else 0)
