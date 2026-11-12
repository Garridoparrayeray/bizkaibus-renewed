"""La regla de los retrasos de .github/scripts/live-health.py. Uso: python tests/live-health.py"""
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

print(f'\nRESULTADO live-health: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
