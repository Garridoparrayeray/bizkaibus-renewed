"""Parchea las funciones PHP que deja `vercel build` para el lanzador nuevo de Vercel.

Desde agosto de 2026 Vercel arranca las funciones con un cargador que importa el «handler» como ruta de fichero:
`launcher.launcher` (lo que emite vercel-php) no existe y toda petición acaba en FUNCTION_INVOCATION_FAILED
(vercel-community/php#650; el runtime está archivado y sin arreglo). Este script, que corre entre `vercel build` y
`vercel deploy --prebuilt`, deja cada función así:

- el handler pasa a ser `launcher.js`, que sí se puede importar;
- `launcher.js` es el de vercel-php con un adaptador al final: con la llamada nueva `(req, res)` lee el cuerpo, pasa la
  petición al servidor PHP embebido y escribe la respuesta; con la llamada antigua (evento de Lambda) sigue como antes;
- `LAMBDA_TASK_ROOT`, que el lanzador nuevo no define, vale `/var/task` para encontrar `php/` y `user/`;
- fuera de la función los ficheros de control de versiones (`.git/`, `.gitignore`…), que Vercel no deja subir;
- PHP carga solo las extensiones que usa la app (EXTENSIONS) y la función lleva solo las librerías que necesitan
  (siguiendo las dependencias de cada binario con readelf): sin eso pesa más de 250 MB, el límite de una función;
- PHP atiende con varios procesos (PHP_CLI_SERVER_WORKERS), para que una petición lenta no haga esperar a las demás;
- las bases de datos y lo que no se publica (data/, scripts/, tests/, engine/) no se suben como ficheros estáticos:
  las rutas ya no los sirven y solo los lee la función.

Uso: python3 scripts/vercel-launcher.py [carpeta .vercel/output]   (por defecto, la de este proyecto)
"""
import json
import os
import re
import shutil
import subprocess
import sys

MARKER = '/* --- adaptador de Bide+ para el lanzador de Vercel --- */'
VERSION_CONTROL = ('.git', '.gitignore', '.gitattributes', '.gitmodules', '.github')
# Las extensiones de PHP que usa la app (cURL, SQLite, SimpleXML, mbstring, ctype, OpenSSL va dentro de PHP) y unas
# pocas ligeras sin librerías propias. Las demás (intl, gd, bases de datos…) arrastran más de 60 MB de librerías.
EXTENSIONS = ('apcu', 'bcmath', 'calendar', 'ctype', 'curl', 'dom', 'fileinfo', 'iconv', 'mbstring', 'pdo', 'pdo_sqlite',
              'posix', 'simplexml', 'sodium', 'sqlite3', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter')
PHP_WORKERS = '4'
NOT_PUBLISHED = ('data', 'scripts', 'tests', 'engine')

ADAPTER = r'''
/* --- adaptador de Bide+ para el lanzador de Vercel --- */
process.env.LAMBDA_TASK_ROOT = process.env.LAMBDA_TASK_ROOT || '/var/task';

async function bideReadBody(stream) {
    const chunks = [];
    for await (const chunk of stream) {
        chunks.push(chunk);
    }
    return Buffer.concat(chunks);
}

// Con la llamada nueva de Vercel, (req, res) de Node; con la antigua, el evento de Lambda que ya entendía el lanzador.
async function bideHandler(reqOrEvent, res) {
    const modern = reqOrEvent && typeof reqOrEvent.url === 'string' && res && typeof res.writeHead === 'function';
    if (!modern) {
        return launcher(reqOrEvent);
    }
    const body = await bideReadBody(reqOrEvent);
    const headers = Object.assign({}, reqOrEvent.headers);
    delete headers['transfer-encoding'];
    if (body.length > 0) {
        headers['content-length'] = String(body.length);
    } else {
        delete headers['content-length'];
    }
    let requestBody = undefined;
    if (body.length > 0) {
        requestBody = body;
    }
    const input = await helpers_1.transformFromAwsRequest({
        method: reqOrEvent.method || 'GET',
        path: reqOrEvent.url,
        host: headers.host || '',
        headers: headers,
        body: requestBody,
    });
    const output = await query(input);
    const outHeaders = Object.assign({}, output.headers);
    delete outHeaders['transfer-encoding'];
    delete outHeaders['connection'];
    res.writeHead(output.statusCode, outHeaders);
    res.end(output.body);
}

module.exports = bideHandler;
module.exports.default = bideHandler;
module.exports.handler = bideHandler;
module.exports.launcher = launcher;
'''


def is_version_control(path):
    for part in path.split('/'):
        if part in VERSION_CONTROL:
            return True
    return False


def elf_needed(path):
    out = subprocess.run(['readelf', '-d', path], capture_output=True, text=True, check=True).stdout
    return re.findall(r'\(NEEDED\).*\[(.+?)\]', out)


def keep_extension_line(match):
    if match.group(1) in EXTENSIONS:
        return match.group(0)
    return ''


# PHP con solo EXTENSIONS: su php.ini, sin los módulos de las demás, y lib/ con solo lo que piden PHP y esos módulos.
def trim_php(func_dir, file_map):
    ini_source = file_map.pop('php/php.ini')
    with open(ini_source, encoding='utf-8') as f:
        ini = f.read()
    loaded = re.findall(r'^extension=(\w+)', ini, re.M)
    ini = re.sub(r'^extension=(\w+)\n', keep_extension_line, ini, flags=re.M)
    os.makedirs(os.path.join(func_dir, 'php'), exist_ok=True)
    with open(os.path.join(func_dir, 'php', 'php.ini'), 'w', encoding='utf-8') as f:
        f.write(ini)
    for name in loaded:
        if name not in EXTENSIONS:
            file_map.pop(f'php/modules/{name}.so', None)
    libs = {k[len('lib/'):]: v for k, v in file_map.items() if k.startswith('lib/')}
    todo = [file_map['php/php']] + [file_map[f'php/modules/{n}.so'] for n in EXTENSIONS if f'php/modules/{n}.so' in file_map]
    used = set()
    while todo:
        for name in elf_needed(todo.pop()):
            if name in libs and name not in used:
                used.add(name)
                todo.append(libs[name])
    for name in libs:
        if name not in used:
            del file_map['lib/' + name]
    return len(libs) - len(used)


def patch_function(func_dir):
    config_path = os.path.join(func_dir, '.vc-config.json')
    with open(config_path, encoding='utf-8') as f:
        config = json.load(f)
    if config.get('handler') != 'launcher.launcher':
        print(f'{func_dir}: handler {config.get("handler")}, no es de vercel-php; se deja')
        return
    file_map = config.get('filePathMap', {})
    launcher_path = os.path.join(func_dir, 'launcher.js')
    source_path = launcher_path
    if 'launcher.js' in file_map:
        source_path = os.path.join(os.getcwd(), file_map['launcher.js'])
    with open(source_path, encoding='utf-8') as f:
        source = f.read()
    if MARKER not in source:
        source = source + '\n' + ADAPTER
    with open(launcher_path, 'w', encoding='utf-8') as f:
        f.write(source)
    file_map.pop('launcher.js', None)
    for key in [k for k in file_map if is_version_control(k) or is_version_control(file_map[k])]:
        del file_map[key]
    removed_libs = 0
    if 'php/php.ini' in file_map:
        removed_libs = trim_php(func_dir, file_map)
    config['handler'] = 'launcher.js'
    config.setdefault('environment', {})['PHP_CLI_SERVER_WORKERS'] = PHP_WORKERS
    with open(config_path, 'w', encoding='utf-8') as f:
        json.dump(config, f, indent=2)
        f.write('\n')
    size = sum(os.path.getsize(v) for v in file_map.values() if os.path.exists(v))
    print(f'{func_dir}: handler launcher.js con el adaptador; {removed_libs} librerías fuera; {size / 1e6:.0f} MB')


def main():
    output = '.vercel/output'
    if len(sys.argv) > 1:
        output = sys.argv[1]
    functions = os.path.join(output, 'functions')
    patched = 0
    for root, dirs, files in os.walk(functions):
        if root.endswith('.func') and '.vc-config.json' in files:
            patch_function(root)
            patched += 1
            dirs[:] = []
    if patched == 0:
        print(f'No hay funciones en {functions}', file=sys.stderr)
        sys.exit(1)
    for name in NOT_PUBLISHED:
        static_dir = os.path.join(output, 'static', name)
        if os.path.isdir(static_dir):
            shutil.rmtree(static_dir)


main()
