"""El script del workflow «Aviso a los usuarios» (.github/scripts/aviso.py). Uso: python tests/aviso-script.py"""
import importlib.util
import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
PHP = os.environ.get('PHP_PATH', 'php')
spec = importlib.util.spec_from_file_location('aviso', ROOT + '../.github/scripts/aviso.py')
aviso = importlib.util.module_from_spec(spec)
spec.loader.exec_module(aviso)

NOW = 1800000000
results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


def fails(env):
    try:
        aviso.build(env, NOW)
    except aviso.NoticeError as error:
        return str(error)
    return None


GOOD = {'ACCION': 'publicar', 'TEXTO_ES': 'Huelga mañana: servicios mínimos', 'TEXTO_EU': 'Greba bihar', 'TEXTO_EN': '', 'ENLACE': 'https://www.bizkaia.eus/avisos', 'HORAS': '48', 'NOTIFICAR': 'true'}
notice = aviso.build(GOOD, NOW)
check('publicar: el texto, el enlace y los idiomas sin texto fuera', notice['text'] == {'es': 'Huelga mañana: servicios mínimos', 'eu': 'Greba bihar'} and notice['url'] == 'https://www.bizkaia.eus/avisos', notice)
check('publicar: caduca a las horas que se piden', notice['expiresAt'] == NOW + 48 * 3600)
check('publicar: el identificador es la fecha y hora en UTC', notice['id'] == '20270115T080000Z', notice['id'])
check('publicar: «notificar» solo con true', notice['notify'] is True and aviso.build({**GOOD, 'NOTIFICAR': 'false'}, NOW)['notify'] is False and aviso.build({**GOOD, 'NOTIFICAR': ''}, NOW)['notify'] is False)
check('publicar: sin enlace no hay clave url', 'url' not in aviso.build({**GOOD, 'ENLACE': ''}, NOW))
check('quitar: archivo vacío', aviso.build({'ACCION': 'quitar'}, NOW) == {})
check('falta el castellano', fails({**GOOD, 'TEXTO_ES': '   '}) is not None)
check('texto de más de 400 caracteres', fails({**GOOD, 'TEXTO_ES': 'a' * 401}) is not None and fails({**GOOD, 'TEXTO_ES': 'a' * 400}) is None)
check('horas fuera de 1 a 720 o que no son un número', all(fails({**GOOD, 'HORAS': h}) is not None for h in ('0', '721', 'muchas', '', '1.5')) and all(fails({**GOOD, 'HORAS': h}) is None for h in ('1', '720')))
check('el enlace tiene que ser http o https y sin espacios', all(fails({**GOOD, 'ENLACE': e}) is not None for e in ('javascript:alert(1)', 'www.bizkaia.eus', 'https://a b.eus', 'ftp://x.eus/a')) and fails({**GOOD, 'ENLACE': 'http://x.eus/a'}) is None)
check('una acción rara no vale', fails({**GOOD, 'ACCION': 'borrar'}) is not None)

# Lo que escribe el script lo tiene que aceptar la app: PHP lo valida con Services\Notice.
php = "require '" + ROOT + "api/autoload.php'; echo json_encode(Services\\Notice::fromArray(json_decode(stream_get_contents(STDIN), true), " + str(NOW) + "));"
accepted = subprocess.run([PHP, '-r', php], input=json.dumps(notice), capture_output=True, text=True)
parsed = json.loads(accepted.stdout or 'null')
check('la app (Services\\Notice) acepta lo que escribe el script', parsed is not None and parsed['id'] == notice['id'] and parsed['notify'] is True and parsed['url'] == notice['url'], accepted.stdout + accepted.stderr)
empty = subprocess.run([PHP, '-r', php], input='{}', capture_output=True, text=True)
check('y con el aviso quitado, la app no tiene aviso', json.loads(empty.stdout or '0') is None, empty.stdout + empty.stderr)

failed = results.count(False)
print(f'\nRESULTADO aviso general (script): {len(results) - failed} OK, {failed} MAL')
if failed:
    sys.exit(1)
