import glob
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..')).replace('\\', '/') + '/'
PHP = os.environ.get('PHP_PATH') or shutil.which('php') or 'C:/xampp/php/php.exe'
failures = []

ids_js = set(re.findall(r"getElementById\('([^']+)'\)", open(ROOT + 'js/app.js', encoding='utf-8').read()))
shell = open(ROOT + 'api/shell.php', encoding='utf-8').read()
ids_html = set(re.findall(r'id="([^"]+)"', shell))
missing_ids = sorted(ids_js - ids_html)
print('ids que app.js busca y no existen en shell.php:', missing_ids or 'ninguno')
if missing_ids:
    failures.append('ids de app.js sin elemento')

css = open(ROOT + 'style-app.css', encoding='utf-8').read()
css_ids = set(re.findall(r'#([a-zA-Z][\w-]*)', re.sub(r'#[0-9a-fA-F]{3,8}\b(?![\w-])', '', css)))
orphan_css = sorted(i for i in css_ids if i not in ids_html and i not in ids_js and i != 'app-logomark')
print('ids en CSS sin elemento en shell.php:', orphan_css or 'ninguno')
if orphan_css:
    failures.append('ids de style-app.css sin elemento')

php_files = [f for f in glob.glob(ROOT + '**/*.php', recursive=True) if 'vendor' not in f and 'test_gtfs' not in f]
php_bad = 0
for f in php_files:
    r = subprocess.run([PHP, '-l', f], capture_output=True, text=True)
    if 'No syntax errors' not in r.stdout:
        php_bad += 1
        print('LINT', f, r.stdout, r.stderr)
print(f'php lint: {len(php_files)} archivos, {php_bad} con error')
if php_bad:
    failures.append('errores de sintaxis PHP')

for f in glob.glob(ROOT + 'js/*.js') + [ROOT + 'sw.js']:
    r = subprocess.run(['node', '--check', f], capture_output=True, text=True)
    ok = r.returncode == 0
    print('js', os.path.basename(f), 'OK' if ok else r.stderr[:200])
    if not ok:
        failures.append('sintaxis JS ' + os.path.basename(f))

sw = open(ROOT + 'sw.js', encoding='utf-8').read()
shell_files = re.findall(r"'(/[^']+)'", sw.split('SHELL_FILES = [')[1].split('];')[0])
missing_files = [f for f in shell_files if f != '/' and not os.path.exists(ROOT + f.lstrip('/'))]
print('archivos del precache que no existen:', missing_files or 'ninguno')
if missing_files:
    failures.append('precache con archivos inexistentes')

geo = subprocess.run([PHP, ROOT + 'tests/geocache-test.php'], capture_output=True, text=True)
print('geocache (caché de geocodificación):', 'OK' if geo.returncode == 0 else geo.stdout[-400:] + geo.stderr[-200:])
if geo.returncode != 0:
    failures.append('prueba de geocache')

print('RESULTADO estatico:', 'OK' if not failures else 'FALLA -> ' + '; '.join(failures))
sys.exit(1 if failures else 0)
