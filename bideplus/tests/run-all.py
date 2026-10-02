"""Ejecuta toda la batería de pruebas en orden y resume el resultado.

    python tests/run-all.py            # todo
    python tests/run-all.py --rapido   # sin navegador (estáticas, unitarias, datos, API y contrato)

Si no hay un servidor en BASE_URL, arranca uno propio con dev-router.php y lo cierra al terminar.
"""
import os
import shutil
import subprocess
import sys
import time
import urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
PHP = os.environ.get('PHP_PATH') or shutil.which('php') or 'C:/xampp/php/php.exe'
PY = sys.executable
QUICK = '--rapido' in sys.argv

STEPS = [
    ('Estáticas, unitarias y datos', [PY, 'tests/static-checks.py']),
    ('API (todas las rutas)', [PY, 'tests/api-smoke.py']),
    ('Contrato de la API', [PY, 'tests/api-contract.py']),
]
if not QUICK:
    STEPS += [
        ('UI completa', ['node', 'tests/ui-battery.mjs']),
        ('UI sin conexión', ['node', 'tests/ui-offline.mjs']),
        ('UI avisos', ['node', 'tests/ui-alerts.mjs']),
        ('UI cerca de mí', ['node', 'tests/ui-nearby.mjs']),
        ('UI Bide+ (pestañas, columna, páginas)', ['node', 'tests/ui-bide.mjs']),
    ]


def server_up():
    try:
        urllib.request.urlopen(BASE + '/', timeout=3)
        return True
    except Exception:
        return False


server = None
if not server_up():
    port = BASE.rsplit(':', 1)[-1]
    env = dict(os.environ, PHP_CLI_SERVER_WORKERS='4')
    server = subprocess.Popen([PHP, '-S', 'localhost:' + port, 'dev-router.php'], cwd=ROOT, env=env,
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(30):
        if server_up():
            break
        time.sleep(1)
    else:
        server.terminate()
        sys.exit('No se pudo arrancar el servidor local en ' + BASE)

results = []
try:
    for label, cmd in STEPS:
        print(f'\n===== {label} =====', flush=True)
        start = time.time()
        code = subprocess.run(cmd, cwd=ROOT).returncode
        results.append((label, code == 0, time.time() - start))
finally:
    if server is not None:
        server.terminate()

print('\n===== Resumen =====')
for label, ok, secs in results:
    print(f"{'OK ' if ok else 'MAL'}  {label:<32} {secs:5.1f} s")
failed = [label for label, ok, _ in results if not ok]
print('\nRESULTADO: ' + ('todo en verde' if not failed else 'FALLA -> ' + ', '.join(failed)))
sys.exit(1 if failed else 0)
