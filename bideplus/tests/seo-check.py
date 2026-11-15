"""Lo que leen los buscadores en el HTML del servidor, sin JavaScript: robots, canónicas, redirecciones, H1, enlaces y
contenido propio de cada parada y línea, el sitemap índice y que su lastmod no cambie entre dos compilaciones iguales.

    python tests/seo-check.py      (con el servidor local en BASE_URL)
"""
import datetime
import html
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import xml.dom.minidom as minidom

BASE = os.environ.get('BASE_URL', 'http://localhost:8011').rstrip('/')
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PHP = os.environ.get('PHP_PATH', 'php')
TEST_HOST = 'bideplusmiamor.vercel.app'
# Las redes del registro (api/Networks): una red nueva entra sola.
NETWORKS = subprocess.check_output([PHP, os.path.join(ROOT, 'scripts/networks.php'), '--slugs'], text=True).split()
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
# La vista previa al compartir: la de la red, con su texto alternativo traducido, su tipo y el de Twitter.
code, headers, page = fetch('/stops/2877?lang=eu')
expect('og:image" content="https://www.bideplus.eus/icons-pro/og-image.png"' in page and '<meta property="og:image:type" content="image/png">' in page, '/stops/2877: sin la imagen de la red o sin og:image:type')
expect('<meta name="twitter:image:alt" content="Bizkaibus+: Bizkaibusen ordutegiak eta zuzenekoa">' in page and 'og:image:alt" content="Bizkaibus+: Bizkaibusen' in page, '/stops/2877?lang=eu: texto alternativo sin traducir')
code, headers, page = fetch('/?lang=en')
expect('og:image:alt" content="Bide+: bus, metro, train and tram' in page and 'twitter:image:alt" content="Bide+: bus, metro, train and tram' in page, '/?lang=en: texto alternativo sin traducir')
print('datos estructurados y vista previa ok')

# Una sola dirección por página: 301 a la buena (barra final, mayúsculas en la parte fija, red que no existe) y 404
# en lo que no es una página, en el idioma de la dirección y con noindex. Los ids conservan sus mayúsculas.
for path, location in (('/MAPA', '/mapa'), ('/mapa/', '/mapa'), ('/Stops/2877/', '/stops/2877'), ('/LINES/651?lang=eu', '/lines/651?lang=eu'),
                       ('/lines/MB/L1/?red=metro', '/lines/MB/L1?red=metro'), ('/?red=nada&lang=fr', '/?lang=fr'), ('/stops/2877?red=nada', '/')):
    code, headers, page = fetch(path)
    got = headers.get('Location', '').replace(BASE, '')
    expect(code == 301 and got == location, f'{path}: {code} -> {got!r} (esperaba 301 -> {location})')
for path, lang in (('/stops/', 'es'), ('/lines/', 'es'), ('/stops', 'es'), ('/nada-por-aqui', 'es'), ('/nada?lang=eu', 'eu'), ('/stops/99999999?lang=en', 'en'), ('/lines/MB/L9?red=metro&lang=fr', 'fr')):
    code, headers, page = fetch(path)
    expect(code == 404 and f'<html lang="{lang}"' in page and 'noindex' in meta_robots(page), f'{path}: {code}, sin 404 en {lang} con noindex')
for path, canonical in (('/stops/ES:Euskotren:StopPlace:2577:?red=euskotren', 'https://www.bideplus.eus/stops/ES%3AEuskotren%3AStopPlace%3A2577%3A?red=euskotren'),
                        ('/stops/MBAY01?red=txiktxak', 'https://www.bideplus.eus/stops/MBAY01?red=txiktxak'),
                        ('/lines/MB/L2?red=metro&lang=eu', 'https://www.bideplus.eus/lines/MB/L2?red=metro&lang=eu')):
    code, headers, page = fetch(path)
    got = html.unescape((re.search(r'<link rel="canonical" href="([^"]+)"', page) or [None, ''])[1])
    expect(code == 200 and got == canonical, f'{path}: {code} con canónica {got!r}')
# En las redes de Iparralde (seo.default_lang de su ficha), el x-default es la versión en francés.
for path, default in (('/stops/MBAY01?red=txiktxak', 'https://www.bideplus.eus/stops/MBAY01?red=txiktxak&lang=fr'), ('/?red=sncf', 'https://www.bideplus.eus/?red=sncf&lang=fr'),
                      ('/stops/2877', 'https://www.bideplus.eus/stops/2877')):
    code, headers, page = fetch(path)
    got = html.unescape((re.search(r'hreflang="x-default" href="([^"]+)"', page) or [None, ''])[1])
    expect(got == default, f'{path}: x-default {got!r}')
print('canónicas, 301, 404 y x-default ok')

# El sitemap índice: XML válido, un sitemap por red y uno general, todos accesibles y sin changefreq ni priority.
code, headers, index = fetch('/sitemap-index.xml')
children = re.findall(r'<loc>([^<]+)</loc>', index)
try:
    minidom.parseString(index)
except Exception:
    fails.append('/sitemap-index.xml: XML no válido')
expect(code == 200 and '<sitemapindex' in index and len(children) == len(NETWORKS) + 1, f'/sitemap-index.xml: {code}, {len(children)} sitemaps')
for child in children:
    code, headers, part = fetch(urllib.parse.urlparse(child).path)
    expect(code == 200 and '<urlset' in part and '<changefreq>' not in part and '<priority>' not in part, f'{child}: {code} o con changefreq/priority')
code, headers, page = fetch('/sitemap.xml')
expect(code == 301 and headers.get('Location', '').endswith('/sitemap-index.xml'), '/sitemap.xml: no lleva al índice')
code, headers, page = fetch('/robots.txt')
expect('Sitemap: https://www.bideplus.eus/sitemap-index.xml' in page, '/robots.txt: sin el sitemap índice')

# El lastmod es el día del último cambio de verdad: dos compilaciones sin cambios dan los mismos sitemaps, y una fecha
# guardada se conserva mientras la huella de la página no cambie.
with tempfile.TemporaryDirectory() as tmp:
    state = os.path.join(tmp, 'lastmod.json')
    shutil.copy(os.path.join(ROOT, 'scripts/sitemap-lastmod.json'), state)

    def build():
        subprocess.run([PHP, os.path.join(ROOT, 'scripts/build-sitemap.php'), '--output-dir=' + tmp, '--state=' + state], check=True, capture_output=True)
        return {name: open(os.path.join(tmp, 'sitemaps', name), encoding='utf-8').read() for name in os.listdir(os.path.join(tmp, 'sitemaps'))}

    first = build()
    expect(first == build(), 'sitemaps: dos compilaciones sin cambios dan lastmod distintos')
    saved = json.load(open(state))
    json.dump({path: [value[0], '2020-01-01'] for path, value in saved.items()}, open(state, 'w'))
    old = build()
    dates = set(re.findall(r'<lastmod>([^<]+)</lastmod>', old['bus.xml'] + old['metro.xml']))
    expect(dates == {'2020-01-01'}, f'sitemaps: con la misma huella el lastmod cambia {sorted(dates)[:3]}')
    touched = json.load(open(state))
    touched['/stops/2877'][0] = 'otra'
    json.dump(touched, open(state, 'w'))
    changed = build()['bus.xml']
    today = (re.search(r'/stops/2877</loc><lastmod>([^<]+)</lastmod>', changed) or [None, ''])[1]
    expect(today == datetime.date.today().isoformat(), f'sitemaps: una parada que cambia no pasa a hoy ({today})')
print('sitemaps ok')

print(f'{count} peticiones, {len(fails)} fallos')
for f in fails:
    print(' -', f)
if fails:
    sys.exit(1)
sys.exit(0)
