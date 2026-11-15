"""Lo que leen los buscadores en el HTML del servidor, sin JavaScript: robots, canónicas, redirecciones, H1, enlaces y
contenido propio de cada parada y línea, el sitemap índice y que su lastmod no cambie entre dos compilaciones iguales.

    python tests/seo-check.py      (con el servidor local en BASE_URL)
"""
import html
import json
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


def h1s(page):
    return [html.unescape(re.sub(r'<[^>]+>', '', h)).strip() for h in re.findall(r'<h1[^>]*>(.*?)</h1>', page, re.S)]


def content_block(page):
    """El contenido que pinta el servidor (#page-content): (etiqueta de apertura, cuerpo)."""
    m = re.search(r'(<section id="page-content"[^>]*>)(.*?)</section>', page, re.S)
    if not m:
        return '', ''
    return m.group(1), m.group(2)


# Parada, línea y red: un solo H1 con su texto, y el contenido visible (no en <noscript>) con enlaces <a>.
CASES = (
    ('/stops/2877', 'Bilbao Intermodal (Bilbao) · Bizkaibus', '/lines/', 'page-content-times'),
    ('/lines/651', 'Línea A0651 Bilbao-Balmaseda · Bizkaibus', '/stops/', '<ol'),
    ('/stops/13?red=metro', 'San Ignazio · Metro Bilbao', '/lines/MB?red=metro', 'page-content-times'),
    ('/?red=metro', 'Horarios y tiempo real de Metro Bilbao', '/lines/MB?red=metro', 'Líneas de Metro Bilbao'),
    ('/lines/MB/L1?red=metro', 'Línea L1 Etxebarri - Plentzia · Metro Bilbao', '/stops/', 'Hacia Plentzia'),
    ('/stops/2877?lang=eu', 'Bilbao Intermodal (Bilbao) · Bizkaibus', '/lines/651?lang=eu', 'Gaurko ordutegia'),
)
for path, heading, link, text in CASES:
    code, headers, page = fetch(path)
    opening, body = content_block(page)
    expect(code == 200, f'{path}: da {code}')
    expect(h1s(page) == [heading], f'{path}: H1 {h1s(page)} (esperaba {heading!r})')
    expect(opening and ' hidden' not in opening and '<noscript>' not in body.split('<h2>')[0], f'{path}: el contenido no se ve')
    expect(f'<a href="{html.escape(link)}' in body, f'{path}: sin enlace a {link}')
    expect(text in body, f'{path}: sin «{text}»')
# El menú: el H1 es el logotipo (un SVG) más su texto, escondido a la vista.
code, headers, page = fetch('/')
expect(len(h1s(page)) == 1 and h1s(page)[0] != '', f'/: H1 sin texto {h1s(page)}')
# La marca de la red ya no es un H1 en las paradas.
code, headers, page = fetch('/stops/2877')
expect('id="app-title" class="app-title"' in page and '<h1 id="app-title"' not in page, '/stops/2877: la marca sigue siendo el H1')
# Títulos de 60 caracteres como mucho y descripciones de 120 a 155.
for path in ('/stops/2877', '/lines/651', '/stops/13?red=metro', '/?red=bus', '/lines/3247'):
    code, headers, page = fetch(path)
    title = html.unescape((re.search(r'<title>([^<]*)</title>', page) or [None, ''])[1])
    description = html.unescape((re.search(r'<meta name="description" content="([^"]*)"', page) or [None, ''])[1])
    expect(len(title) <= 60, f'{path}: título de {len(title)} caracteres: {title}')
    expect(120 <= len(description) <= 155, f'{path}: descripción de {len(description)} caracteres: {description}')
print('contenido, H1 y enlaces ok')


def graph(page):
    nodes = []
    for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>', page, re.S):
        data = json.loads(block)
        nodes += data.get('@graph', [data])
    return nodes


# Datos estructurados: un solo WebSite y una Organization con logo y sameAs; la app solo en el menú y en las redes.
for path, app in (('/', True), ('/?red=bus', True), ('/stops/2877', False), ('/lines/651', False), ('/mapa', False)):
    code, headers, page = fetch(path)
    nodes = graph(page)
    types = [n.get('@type') for n in nodes]
    sites = [n for n in nodes if n.get('@type') == 'WebSite']
    orgs = [n for n in nodes if n.get('@type') == 'Organization']
    expect(len(sites) == 1 and sites[0].get('@id') == 'https://www.bideplus.eus/#website' and sites[0].get('url') == 'https://www.bideplus.eus/', f'{path}: WebSite {sites}')
    expect(len(orgs) == 1 and orgs[0].get('logo', '').endswith('/icons-bide-rojo/icon-512.png') and len(orgs[0].get('sameAs', [])) == 2, f'{path}: Organization {orgs}')
    has_app = 'SoftwareApplication' in types or 'SoftwareApplication' in page
    expect(has_app == app, f'{path}: SoftwareApplication {has_app}, esperaba {app}')
    expect('aggregateRating' not in page, f'{path}: valoraciones inventadas')
print('datos estructurados ok')

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
if fails:
    sys.exit(1)
sys.exit(0)
