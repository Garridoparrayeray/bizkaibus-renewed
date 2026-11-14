#!/usr/bin/env python3
"""Convierte la copia de la app del runner en la de prueba (bideplusmiamor.vercel.app), para «Desplegar la prueba».

Uso: python3 .github/scripts/modo-prueba.py <carpeta bideplus>
Solo toca esa copia, nunca el repositorio: estética de producción en el dominio de prueba, fuera de los buscadores (sin
Google, sitemap, llms.txt ni IndexNow), sin Vercel Insights, el almacén solo lee y no hay notificaciones ni cron. Si un
texto que hay que cambiar no está tal cual, sale con error: el parche ya no encaja con la app y no se despliega.
"""
import json
import os
import sys

TEST_DOMAIN = 'bideplusmiamor.vercel.app'
# Con un dominio que no existe, el tema «mi amor» no salta: la prueba se ve como producción.
NO_DOMAIN = 'miamor.invalid'
DOMAIN_FILES = ('js/theme.js', 'api/shell.php', 'api/Views/app-page.php')
INDEXED = '<meta name="robots" content="index, follow, max-image-preview:large">'
NOT_INDEXED = '<meta name="robots" content="noindex, nofollow">'
ROBOTS_FILES = ('api/Views/menu.php', 'api/Views/app.php', 'api/Views/estado.php', 'api/Views/network-map.php')
GOOGLE_LINE = ('    <meta name="google-site-verification" '
               'content="<?= htmlspecialchars($aSite[\'gsc_verification\']) ?>">\n')
GOOGLE_FILES = ('api/Views/menu.php', 'api/Views/app.php')
INSIGHTS_LINE = '    <script defer src="/_vercel/insights/script.js"></script>\n'
READ_COMMANDS = ('GET', 'MGET', 'HGET', 'HMGET', 'HGETALL', 'HSCAN', 'SCARD', 'SISMEMBER', 'SMEMBERS', 'EXISTS', 'TTL')
NO_ROBOTS = 'User-agent: *\nDisallow: /\n'
SEO_FILES = ('sitemap.xml', 'llms.txt')

STORE_PIPELINE = '''    public static function pipeline(array $aCommands, int $iTimeoutMs = 800): array|null
    {
'''
STORE_PAUSE = '''    private const PAUSE_AFTER_FAILURE_SECONDS = 60;
'''
STORE_READS = STORE_PAUSE + '''    private const READ_COMMANDS = %s;
''' % ('[' + ', '.join("'%s'" % sCommand for sCommand in READ_COMMANDS) + ']')
# El almacén de la prueba solo lee: las escrituras no salen y su resultado es null, como si fallaran.
STORE_READS_ONLY = '''    public static function pipeline(array $aCommands, int $iTimeoutMs = 800): array|null
    {
        $aReads = [];
        foreach (array_values($aCommands) as $iIndex => $aCommand) {
            if (in_array(strtoupper((string)($aCommand[0] ?? '')), self::READ_COMMANDS, true)) {
                $aReads[$iIndex] = $aCommand;
            }
        }
        if ($aReads === []) {
            return null;
        }
        $aResults = self::pipelineReads($aReads, $iTimeoutMs);
        if ($aResults === null) {
            return null;
        }
        $aAll = array_fill(0, count($aCommands), null);
        foreach (array_keys($aReads) as $iPosition => $iIndex) {
            $aAll[$iIndex] = $aResults[$iPosition] ?? null;
        }
        return $aAll;
    }

    private static function pipelineReads(array $aCommands, int $iTimeoutMs): array|null
    {
'''
STORE_ENV = '''    public static function env(string $sName): string|null
    {
'''
STORE_ENV_NO_SECRETS = STORE_ENV + '''        if (str_starts_with($sName, 'VAPID_') || $sName === 'BIDE_CRON_SECRET') {
            return null;
        }
'''
PUSH_KEY = '''    public function key(Request $Req, array $aParams): void
    {
'''
PUSH_KEY_NULL = PUSH_KEY + '''        Response::$iCacheSeconds = 0;
        Response::json(['publicKey' => null]);
        return;
'''


class PatchError(Exception):
    pass


def read(root, name):
    with open(os.path.join(root, name), encoding='utf-8') as f:
        return f.read()


def write(root, name, text):
    with open(os.path.join(root, name), 'w', encoding='utf-8') as f:
        f.write(text)


# Cambia un texto que tiene que estar exactamente una vez en el fichero.
def replace_once(root, name, old, new):
    text = read(root, name)
    found = text.count(old)
    if found != 1:
        raise PatchError(f'{name}: el texto a cambiar aparece {found} veces (tiene que ser una): {old.strip()[:80]}')
    write(root, name, text.replace(old, new))


def replace_all(root, name, old, new):
    text = read(root, name)
    if old not in text:
        raise PatchError(f'{name}: no está «{old}»')
    write(root, name, text.replace(old, new))


def remove(root, name):
    path = os.path.join(root, name)
    if not os.path.isfile(path):
        raise PatchError(f'{name}: no está')
    os.remove(path)


def add_noindex_header(root):
    config = json.loads(read(root, 'vercel.json'))
    for route in config['routes']:
        if route.get('src') == '/(.*)' and 'headers' in route:
            route['headers']['X-Robots-Tag'] = 'noindex, nofollow'
            write(root, 'vercel.json', json.dumps(config, indent=2, ensure_ascii=False) + '\n')
            return
    raise PatchError('vercel.json: no está la ruta /(.*) con las cabeceras de seguridad')


def indexnow_key(root):
    for line in read(root, 'api/Config/site.php').splitlines():
        if "'indexnow_key'" in line:
            return line.split("'")[3]
    raise PatchError('api/Config/site.php: no está indexnow_key')


def patch(root):
    for name in DOMAIN_FILES:
        replace_all(root, name, TEST_DOMAIN, NO_DOMAIN)
    for name in ROBOTS_FILES:
        replace_once(root, name, INDEXED, NOT_INDEXED)
    for name in GOOGLE_FILES:
        replace_once(root, name, GOOGLE_LINE, '')
    replace_once(root, 'api/Views/app.php', INSIGHTS_LINE, '')
    replace_once(root, 'api/Core/Store.php', STORE_PAUSE, STORE_READS)
    replace_once(root, 'api/Core/Store.php', STORE_PIPELINE, STORE_READS_ONLY)
    replace_once(root, 'api/Core/Store.php', STORE_ENV, STORE_ENV_NO_SECRETS)
    replace_once(root, 'api/Controllers/PushController.php', PUSH_KEY, PUSH_KEY_NULL)
    add_noindex_header(root)
    write(root, 'robots.txt', NO_ROBOTS)
    for name in SEO_FILES:
        remove(root, name)
    remove(root, indexnow_key(root) + '.txt')


def main():
    if len(sys.argv) != 2:
        print('Uso: python3 .github/scripts/modo-prueba.py <carpeta bideplus>', file=sys.stderr)
        sys.exit(2)
    try:
        patch(sys.argv[1])
    except PatchError as error:
        print(f'::error::Modo prueba: {error}', file=sys.stderr)
        sys.exit(1)
    print('Modo prueba: dominio sin tema «mi amor», noindex, sin Google, Insights, sitemap, llms.txt ni IndexNow; '
          'almacén de solo lectura, sin VAPID ni secreto del cron')


main()
