"""Las reglas de .github/scripts/live-health.py (retrasos, feeds vacíos, caducidad y cron).

Uso: python tests/live-health.py
"""
import importlib.util
import os
import sys

sys.dont_write_bytecode = True
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
spec = importlib.util.spec_from_file_location('live_health', ROOT + '../.github/scripts/live-health.py')
live_health = importlib.util.module_from_spec(spec)
spec.loader.exec_module(live_health)

results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


def report(**changes):
    base = {
        'ok': True, 'scheduled': 10, 'live': 11, 'compared': 12, 'offOperator': 0,
        'delayMinutes': {'median': 45, 'p95': 70, 'max': 70},
    }
    base.update(changes)
    return base


errors, notices, summary = live_health.check('sncf', report())
check('retrasos grandes que son los del operador (diez comparadas, ninguna fuera): aviso, no error',
      errors == [] and len(notices) == 1 and 'operador' in notices[0], (errors, notices))

errors, notices, summary = live_health.check('sncf', report(offOperator=5))
check('retrasos grandes y más del 15 % fuera de la hora del operador: error', len(errors) == 2, errors)

errors, notices, summary = live_health.check('sncf', report(compared=6))
check('retrasos grandes con pocas comparadas: error, no se sabe de quién son',
      len(errors) == 1 and 'no creíbles' in errors[0], errors)

errors, notices, summary = live_health.check('sncf', report(delayMinutes={'median': 2, 'p95': 9, 'max': 12}))
check('retrasos normales: ni error ni aviso', errors == [] and notices == [], (errors, notices))

errors, notices, summary = live_health.check('sncf', report(live=2))
check('con pocos trenes en directo no se miran los retrasos', errors == [] and notices == [], (errors, notices))

empty = {'ok': False, 'oftenEmpty': True, 'scheduled': 60, 'vehicles': 0}
errors, notices, summary = live_health.check('bilbobus', dict(empty, lastFullSeconds=25 * 3600))
check('a menudo vacío, con servicio y más de 24 h sin venir lleno: error',
      len(errors) == 1 and '24 h' in errors[0], errors)
errors, notices, summary = live_health.check('bilbobus', dict(empty, lastFullSeconds=None))
check('sin marca de lleno en el almacén (el primer día tras desplegar): no se sabe, aviso',
      errors == [] and len(notices) == 1, (errors, notices))
strike = {'today': True, 'cause': 'strike'}
errors, notices, summary = live_health.check('renfe', {'ok': False, 'scheduled': 40, 'reducedService': strike})
check('feed vacío con huelga o servicio reducido hoy: aviso, no error', errors == [] and len(notices) == 1,
      (errors, notices))
normal = {'today': False}
errors, notices, summary = live_health.check('renfe', {'ok': False, 'scheduled': 40, 'reducedService': normal})
check('feed vacío con servicio normal: error', len(errors) == 1, errors)
errors, notices, summary = live_health.check('bilbobus', dict(empty, lastFullSeconds=3600))
check('vacío desde hace una hora: aviso', errors == [] and len(notices) == 1, (errors, notices))
errors, notices, summary = live_health.check('bilbobus', dict(empty, lastFullSeconds=25 * 3600, scheduled=1))
check('de noche, sin servicio, no es error aunque lleve días vacío', errors == [], errors)
errors, notices, summary = live_health.check('alavabus', empty)
check('sin la marca (red sin motor), a menudo vacío sigue siendo aviso',
      errors == [] and len(notices) == 1, (errors, notices))

errors, notices, summary = live_health.check('launion', {'noLive': True, 'publishedUntil': '2000-01-01'})
check('horario publicado caducado (también sin directo): error', len(errors) == 1 and 'caducó' in errors[0], errors)
errors, notices, summary = live_health.check('launion', {'noLive': True, 'publishedUntil': '2999-01-01'})
check('horario publicado vigente: bien', errors == [] and notices == [], (errors, notices))

check('cron con su latido de hace 12 min (cada 10): bien',
      live_health.cron_silent({'cronSeconds': 720, 'cronEverySeconds': 600}) is None)
check('cron callado 21 min: error',
      'no llama' in (live_health.cron_silent({'cronSeconds': 1260, 'cronEverySeconds': 600}) or ''))
check('cron sin latido en el almacén: error',
      live_health.cron_silent({'cronSeconds': None, 'cronEverySeconds': 600}) is not None)
check('sin almacén no se sabe: nada', live_health.cron_silent({'ok': True}) is None)

print(f'\nRESULTADO live-health: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
