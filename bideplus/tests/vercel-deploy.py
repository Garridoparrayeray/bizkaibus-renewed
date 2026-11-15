"""El despliegue a Vercel: la limpieza de los despliegues viejos (.github/scripts/vercel-cleanup.py).

Uso: python tests/vercel-cleanup.py
"""
import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
SCRIPT = ROOT + '../.github/scripts/vercel-cleanup.py'
results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


def deleted(deployments, current):
    env = dict(os.environ, KEEP_PREVIOUS='2', CURRENT=current)
    out = subprocess.run([sys.executable, '-I', SCRIPT], input=json.dumps({'deployments': deployments}), env=env,
                         capture_output=True, text=True, check=True).stdout
    return sorted(uid for uid in out.split('\n') if uid)


# D1 a D7 de producción listos; tras un «Rollback production» a D4, D5 a D7 no tienen el dominio: sirve D4.
deployments = [{'uid': f'D{n}', 'created': n, 'target': 'production', 'state': 'READY'} for n in range(1, 8)]
deployments.append({'uid': 'P1', 'created': 8, 'target': None, 'state': 'READY'})
check('tras un rollback se conserva el que sirve el dominio, además de los 3 más recientes',
      deleted(deployments, 'D4') == ['D1', 'D2', 'D3', 'P1'], deleted(deployments, 'D4'))
check('sin rollback, el último y los 2 anteriores', deleted(deployments, 'D7') == ['D1', 'D2', 'D3', 'D4', 'P1'])
check('si no se sabe cuál sirve el dominio, no se borra nada', deleted(deployments, '') == [])


print(f'\nRESULTADO despliegue a Vercel: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
