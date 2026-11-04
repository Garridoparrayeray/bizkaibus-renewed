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
    ('Horarios sin conexión', ['node', 'tests/offline-schedule.mjs']),
    ('Periodos del horario oficial', ['node', 'tests/schedule-periods.mjs']),
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

# Sin el mapa y los horarios sin conexión generados, sus pruebas fallan.
for script, folder in (('scripts/build-network-map.php', 'map-data'), ('scripts/build-offline-data.php', 'offline-data')):
    if not os.path.exists(os.path.join(ROOT, folder, 'index.json')):
        print(f'Generando {folder} con {script}…', flush=True)
        subprocess.run([PHP, script], cwd=ROOT, stdout=subprocess.DEVNULL)

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
    mark = 'MAL'
    if ok:
        mark = 'OK '
    print(f"{mark}  {label:<32} {secs:5.1f} s")
failed = [label for label, ok, _ in results if not ok]
if failed:
    print('\nRESULTADO: FALLA -> ' + ', '.join(failed))
    sys.exit(1)
print('\nRESULTADO: todo en verde')
sys.exit(0)
