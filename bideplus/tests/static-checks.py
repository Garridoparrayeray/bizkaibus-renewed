import ast
import glob
import json
import os
import re
import shutil
import subprocess
import sys
import textwrap

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..')).replace('\\', '/') + '/'
PHP = os.environ.get('PHP_PATH') or shutil.which('php') or 'C:/xampp/php/php.exe'
NODE = os.environ.get('NODE_BIN') or shutil.which('node') or 'C:/Program Files/AMD/AI_Bundle/LMStudio/resources/app/.webpack/bin/node.exe'
failures = []

# La app de cada red está en módulos (js/app/*.js); main.js es la entrada.
APP_JS = sorted(glob.glob(ROOT + 'js/app/*.js'))
ids_js = set(re.findall(r"getElementById\('([^']+)'\)", ''.join(open(f, encoding='utf-8').read() for f in APP_JS)))
# La plantilla de las apps (Views/app.php) más las piezas que incluye (aviso legal y piezas comunes de Bide+).
shell = ''.join(open(ROOT + f, encoding='utf-8').read() for f in ('api/Views/app.php', 'api/Views/legal.php', 'api/Views/bide-components.php'))
ids_html = set(re.findall(r'id="([^"]+)"', shell))
missing_ids = sorted(ids_js - ids_html)
print('ids que js/app/ busca y no existen en la plantilla:', missing_ids or 'ninguno')
if missing_ids:
    failures.append('ids de js/app/ sin elemento')

# Los estilos van por clase: los id quedan para lo que busca el JavaScript.
css_with_ids = []
for f in sorted(glob.glob(ROOT + 'style*.css')):
    css = re.sub(r'#[0-9a-fA-F]{3,8}\b(?![\w-])', '', open(f, encoding='utf-8').read())
    if re.search(r'#[a-zA-Z][\w-]*', css):
        css_with_ids.append(os.path.basename(f))
print('hojas de estilo con selectores de id:', css_with_ids or 'ninguna')
if css_with_ids:
    failures.append('selectores de id en ' + ', '.join(css_with_ids))

php_files = [f for f in glob.glob(ROOT + '**/*.php', recursive=True) if 'vendor' not in f]
php_bad = 0
for f in php_files:
    r = subprocess.run([PHP, '-l', f], capture_output=True, text=True)
    if 'No syntax errors' not in r.stdout:
        php_bad += 1
        print('LINT', f, r.stdout, r.stderr)
print(f'php lint: {len(php_files)} archivos, {php_bad} con error')
if php_bad:
    failures.append('errores de sintaxis PHP')

# Los módulos (los que usan import o export) se comprueban como módulos: con `node --check archivo.js` un error de
# sintaxis en un módulo pasaría.
for f in glob.glob(ROOT + 'js/*.js') + APP_JS + [ROOT + 'sw.js']:
    if re.search(r'^\s*(import|export)\s', open(f, encoding='utf-8').read(), re.M):
        r = subprocess.run([NODE, '--input-type=module', '--check'], stdin=open(f, encoding='utf-8'), capture_output=True, text=True)
    else:
        r = subprocess.run([NODE, '--check', f], capture_output=True, text=True)
    ok = r.returncode == 0
    js_result = 'OK'
    if not ok:
        js_result = r.stderr[:200]
    print('js', os.path.basename(f), js_result)
    if not ok:
        failures.append('sintaxis JS ' + os.path.basename(f))

# Python sin ternarios («x if c else y»), como el PHP y el JavaScript (tests/code-quality.php): se buscan con su analizador,
# en los archivos y en el Python que llevan dentro los flujos de GitHub (python3 - <<'PY' … PY y python3 -c "…").
def python_sources():
    for f in sorted(glob.glob(ROOT + 'tests/*.py') + glob.glob(ROOT + 'scripts/**/*.py', recursive=True) + glob.glob(ROOT + '../.github/scripts/*.py')):
        yield os.path.basename(f), open(f, encoding='utf-8').read()
    for f in sorted(glob.glob(ROOT + '../.github/workflows/*.yml')):
        text = open(f, encoding='utf-8').read()
        for block in re.finditer(r"python3? - <<'(\w+)'[^\n]*\n(.*?)\n\s*\1\n", text, re.S):
            yield os.path.basename(f), textwrap.dedent(block.group(2))
        for inline in re.finditer(r'python3? -c "((?:[^"\\]|\\.)*)"', text):
            yield os.path.basename(f), inline.group(1)


python_ternaries = []
for name, source in python_sources():
    for node in ast.walk(ast.parse(source)):
        if isinstance(node, ast.IfExp):
            python_ternaries.append(name + ':' + str(node.lineno))
print('Python sin ternarios (también el de los flujos de GitHub):', python_ternaries or 'ninguno')
if python_ternaries:
    failures.append('ternarios en Python (' + ', '.join(python_ternaries) + ')')

sw = open(ROOT + 'sw.js', encoding='utf-8').read()
# Solo las líneas que son una ruta entera: los iconos de cada red se generan desde js/networks.js.
shell_files = re.findall(r"^\s*'(/[^']+)',?\s*$", sw.split('SHELL_FILES = [')[1].split('];')[0], re.M)
missing_files = [f for f in shell_files if f != '/' and not os.path.exists(ROOT + f.lstrip('/'))]
print('archivos del precache que no existen:', missing_files or 'ninguno')
if missing_files:
    failures.append('precache con archivos inexistentes')

geo = subprocess.run([PHP, ROOT + 'tests/geocache-test.php'], capture_output=True, text=True)
geo_result = 'OK'
if geo.returncode != 0:
    geo_result = geo.stdout[-400:] + geo.stderr[-200:]
print('geocache (caché de geocodificación):', geo_result)
if geo.returncode != 0:
    failures.append('prueba de geocache')

# sitemap.xml: lo genera scripts/build-sitemap.php; el del repositorio debe coincidir con las bases de datos.
fresh = os.path.join(os.environ.get('RUNNER_TEMP') or os.environ.get('TEMP') or '/tmp', 'sitemap-check.xml')
r = subprocess.run([PHP, ROOT + 'scripts/build-sitemap.php', '--output=' + fresh], capture_output=True, text=True)
stale = r.returncode != 0 or open(fresh, encoding='utf-8').read() != open(ROOT + 'sitemap.xml', encoding='utf-8').read()
sitemap_result = 'al día (' + r.stdout.strip() + ')'
if stale:
    sitemap_result = 'desactualizado, ejecuta php scripts/build-sitemap.php'
print('sitemap.xml:', sitemap_result)
if stale:
    failures.append('sitemap.xml desactualizado')

# js/networks.js: lo genera scripts/build-networks-js.php desde api/Networks.
fresh_js = os.path.join(os.environ.get('RUNNER_TEMP') or os.environ.get('TEMP') or '/tmp', 'networks-check.js')
r = subprocess.run([PHP, ROOT + 'scripts/build-networks-js.php', '--output=' + fresh_js], capture_output=True, text=True)
stale_js = r.returncode != 0 or open(fresh_js, encoding='utf-8').read() != open(ROOT + 'js/networks.js', encoding='utf-8').read()
networks_result = 'al día (' + r.stdout.strip() + ')'
if stale_js:
    networks_result = 'desactualizado, ejecuta php scripts/build-networks-js.php'
print('js/networks.js:', networks_result)
if stale_js:
    failures.append('js/networks.js desactualizado')

# style-networks.css: los colores de cada red, generados por scripts/build-networks-css.php desde api/Networks.
fresh_css = os.path.join(os.environ.get('RUNNER_TEMP') or os.environ.get('TEMP') or '/tmp', 'networks-check.css')
r = subprocess.run([PHP, ROOT + 'scripts/build-networks-css.php', '--output=' + fresh_css], capture_output=True, text=True)
stale_css = r.returncode != 0 or open(fresh_css, encoding='utf-8').read() != open(ROOT + 'style-networks.css', encoding='utf-8').read()
css_result = 'al día (' + r.stdout.strip() + ')'
if stale_css:
    css_result = 'desactualizado, ejecuta php scripts/build-networks-css.php'
print('style-networks.css:', css_result)
if stale_css:
    failures.append('style-networks.css desactualizado')

# Cada red del registro tiene sus piezas: base de datos, iconos y textos en castellano.
registry = json.loads(subprocess.run([PHP, '-r', "require '" + ROOT + "api/Core/Networks.php'; echo json_encode(Core\\Networks::all());"], capture_output=True, text=True).stdout)
for slug, net in registry.items():
    files = ['data/' + net['db'] + '.sqlite'] + [net['brand']['icons'] + '/' + f for f in ('icon-192.png', 'icon-512.png', 'apple-touch-icon.png')]
    missing = [f for f in files if not os.path.exists(ROOT + f)]
    missing += ['texto ' + k for k in ('name', 'tile', 'switcher', 'subtitle', 'liveEmpty', 'disclaimer') if not net['texts']['es'].get(k)]
    missing += ['seo.' + k for k in ('title', 'description') if not net['seo'].get(k)]
    network_result = 'OK'
    if missing:
        network_result = 'falta ' + ', '.join(missing)
    print('red', slug + ':', network_result)
    if missing:
        failures.append('piezas de la red ' + slug)


# Seguridad: cabeceras de vercel.json, sin scripts en línea (la CSP los bloquearía) y HTML escapado.
vercel = json.load(open(ROOT + 'vercel.json', encoding='utf-8'))
headers = {}
for route in vercel.get('routes', []):
    headers.update(route.get('headers') or {})
csp = headers.get('Content-Security-Policy', '')
required = ['Content-Security-Policy', 'Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy']
missing_headers = [h for h in required if h not in headers]
weak_csp = [d for d in ("'unsafe-inline'", "'unsafe-eval'", '*;') if d in csp]
if "frame-ancestors 'none'" not in csp:
    weak_csp.append('sin frame-ancestors')
print('cabeceras de seguridad que faltan:', missing_headers or 'ninguna', '| CSP débil:', weak_csp or 'no')
if missing_headers or weak_csp:
    failures.append('cabeceras de seguridad')

inline = []
for f in [ROOT + 'api/Views/app.php', ROOT + 'api/Views/menu.php'] + glob.glob(ROOT + '*.html'):
    text = open(f, encoding='utf-8').read()
    for m in re.finditer(r'<script(?![^>]*\bsrc=)(?![^>]*application/ld\+json)[^>]*>', text):
        inline.append(os.path.basename(f) + ':' + str(text[:m.start()].count('\n') + 1))
    if re.search(r'\son[a-z]+\s*=\s*["\']', text) or re.search(r'\sstyle\s*=\s*["\']', text) or '<style' in text:
        inline.append(os.path.basename(f) + ' (atributo on*/style o <style>)')
print('scripts o estilos en línea (los bloquea la CSP):', inline or 'ninguno')
if inline:
    failures.append('scripts o estilos en línea')

unescaped = []
for f in glob.glob(ROOT + 'js/*.js') + APP_JS:
    for n, line in enumerate(open(f, encoding='utf-8'), 1):
        if re.search(r'(innerHTML|insertAdjacentHTML|bindPopup)', line) and '${' in line:
            exprs = re.findall(r'\$\{([^}]*)\}', line)
            bad = [e for e in exprs if not re.match(r'\s*(escapeHtml\(|Math\.|ICONS\.|I18n\.t\()', e)]
            if bad:
                unescaped.append(f'{os.path.basename(f)}:{n} {bad}')
print('HTML con datos sin escapar:', unescaped or 'ninguno')
if unescaped:
    failures.append('HTML sin escapar')

for label, script in (('unitarias (llegadas, k, SIRI, claves)', 'unit-test.php'), ('construcción de horarios (calendarios fusionados)', 'build-test.php'), ('integridad de las bases de datos', 'data-integrity.php'), ('registro de pasos (almacén y cron)', 'store-test.php'), ('notificaciones push (llegadas e incidencias)', 'push-test.php'), ('calidad del código (batería de limpieza)', 'code-quality.php')):
    r = subprocess.run([PHP, ROOT + 'tests/' + script], capture_output=True, text=True)
    script_result = 'OK'
    if r.returncode != 0:
        script_result = '\n'.join(l for l in r.stdout.splitlines() if l.startswith(('MAL', '      ')) or 'RESULTADO' in l) + r.stderr[-300:]
    print(label + ':', script_result)
    if r.returncode != 0:
        failures.append(label)

if failures:
    print('RESULTADO estatico: FALLA -> ' + '; '.join(failures))
    sys.exit(1)
print('RESULTADO estatico: OK')
sys.exit(0)
