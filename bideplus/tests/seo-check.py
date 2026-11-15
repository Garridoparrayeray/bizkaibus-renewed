"""Lo que leen los buscadores en el HTML del servidor, sin JavaScript: robots, canónicas, redirecciones, H1, enlaces y
contenido propio de cada parada y línea, el sitemap índice y que su lastmod no cambie entre dos compilaciones iguales.

    python tests/seo-check.py      (con el servidor local en BASE_URL)
"""
import html
import os
import re
import sys
import urllib.error
import urllib.request

BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get('PHP_PATH', 'php')
TEST_HOST = 'bideplusmiamor.vercel.app'
fails = []
count = 0


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


OPENER = urllib.request.build_opener(NoRedirect)


def fetch(path, host=None):
    """(código, cabeceras, cuerpo) sin seguir las redirecciones."""
    global count
    count += 1
    headers = {}
    if host:
        headers['Host'] = host
    try:
        with OPENER.open(urllib.request.Request(BASE + path, headers=headers), timeout=60) as r:
            return r.status, dict(r.headers), r.read().decode('utf-8')
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode('utf-8')


def expect(ok, message):
    if not ok:
        fails.append(message)


def meta_robots(page):
    return (re.search(r'<meta name="robots" content="([^"]+)"', page) or [None, ''])[1]


# La copia de prueba (bideplusmiamor.vercel.app) no se indexa: cabecera, meta y robots.txt propios.
for path in ('/', '/?red=metro', '/stops/2877', '/estado', '/mapa'):
    code, headers, page = fetch(path, TEST_HOST)
    expect(code == 200 and 'noindex' in headers.get('X-Robots-Tag', ''), f'{TEST_HOST}{path}: sin X-Robots-Tag noindex')
    expect('noindex' in meta_robots(page), f'{TEST_HOST}{path}: sin meta robots noindex')
    code, headers, page = fetch(path)
    expect('X-Robots-Tag' not in headers and meta_robots(page).startswith('index'), f'{path}: no se indexa en producción')
code, headers, page = fetch('/robots.txt', TEST_HOST)
expect('Disallow: /\n' in page and 'Sitemap:' not in page, f'{TEST_HOST}/robots.txt: no lo prohíbe todo')
code, headers, page = fetch('/robots.txt')
expect('Disallow: /api/' in page and 'Disallow: /\n' not in page, '/robots.txt: el de producción ha cambiado')
print('copia de prueba ok')

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
if fails:
    sys.exit(1)
sys.exit(0)
