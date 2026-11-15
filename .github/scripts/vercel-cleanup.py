"""Los despliegues de Vercel que se pueden borrar, uno por línea, a partir de la lista de /v6/deployments (stdin).

Se conservan el de producción actual (CURRENT, el que sirve el dominio, también tras un «Rollback production») y
los KEEP_PREVIOUS + 1 de producción listos más recientes. Sin CURRENT no se borra nada.
"""
import json
import os
import sys

current = os.environ.get('CURRENT', '').strip()
if not current:
    print('::warning::No se sabe cuál es el despliegue de producción actual: no se borra ninguno', file=sys.stderr)
    sys.exit(0)
deployments = sorted(json.load(sys.stdin)['deployments'], key=lambda d: d['created'], reverse=True)
ready = [d['uid'] for d in deployments if d.get('target') == 'production' and d.get('state') == 'READY']
kept = set(ready[:1 + int(os.environ['KEEP_PREVIOUS'])])
kept.add(current)
print('\n'.join(d['uid'] for d in deployments if d['uid'] not in kept))
