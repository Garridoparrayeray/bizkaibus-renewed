"""Los despliegues de Vercel que se pueden borrar, uno por línea, a partir de la lista de /v6/deployments (stdin).

Solo se borran los que están en estado final (READY, ERROR o CANCELED), nunca los que están a medias.
Producción: se conservan el actual (CURRENT), los que tienen un alias (ALIASED, ids separados por espacios:
www.bideplus.eus tras un «Rollback production», la copia de prueba) y los KEEP_PREVIOUS + 1 listos más recientes.
Vistas previas (cada «Desplegar la prueba» y cada rama crea una): se conservan las aliasadas y las KEEP_PREVIEWS
más recientes. Sin CURRENT no se borra nada.
"""
import json
import os
import sys

FINAL_STATES = ('READY', 'ERROR', 'CANCELED')

current = os.environ.get('CURRENT', '').strip()
if not current:
    print('::warning::No se sabe qué despliegues sirven los dominios: no se borra ninguno', file=sys.stderr)
    sys.exit(0)
deployments = sorted(json.load(sys.stdin)['deployments'], key=lambda d: d['created'], reverse=True)
production = [d for d in deployments if d.get('target') == 'production']
previews = [d for d in deployments if d.get('target') != 'production']

ready = [d['uid'] for d in production if d.get('state') == 'READY']
kept = set(ready[:1 + int(os.environ['KEEP_PREVIOUS'])])
kept.add(current)
kept.update(os.environ.get('ALIASED', '').split())
kept.update(d['uid'] for d in previews[:int(os.environ.get('KEEP_PREVIEWS', '3'))])

deletable = production + previews
print('\n'.join(d['uid'] for d in deletable if d.get('state') in FINAL_STATES and d['uid'] not in kept))
