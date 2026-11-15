"""La poda de los acumulados de la captura continua (.github/scripts/podar-acumulado.py).

Uso: python tests/podar-acumulado.py
"""
import json
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
SCRIPT = ROOT + '../.github/scripts/podar-acumulado.py'
results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


rows = [{'date': '2026-01-01', 'line': 'viejo'}, {'date': '2026-11-10', 'line': 'nuevo'},
        {'fecha': '2026-06-01', 'n': 1}, {'fecha': '2026-10-01', 'n': 2}, {'fecha': None, 'n': 3}]
with tempfile.TemporaryDirectory() as folder:
    path = os.path.join(folder, 'acumulado.jsonl')
    with open(path, 'w', encoding='utf-8') as out:
        out.write(''.join(json.dumps(row) + '\n' for row in rows) + 'no es json\n')
    subprocess.run([sys.executable, '-I', SCRIPT, path, '120', '2026-11-15'], check=True)
    with open(path, encoding='utf-8') as source:
        kept = [json.loads(line) for line in source]
check('se quedan los de los últimos 120 días (date y fecha) y los que no dicen su día',
      kept == [rows[1], rows[3], rows[4]], kept)

print(f'\nRESULTADO poda de acumulados: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
