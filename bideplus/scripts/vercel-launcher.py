"""Parchea las funciones PHP que deja `vercel build` para el lanzador nuevo de Vercel.

Desde agosto de 2026 Vercel arranca las funciones con un cargador que importa el «handler» como ruta de fichero:
`launcher.launcher` (lo que emite vercel-php) no existe y toda petición acaba en FUNCTION_INVOCATION_FAILED
(vercel-community/php#650; el runtime está archivado y sin arreglo). Este script, que corre entre `vercel build` y
`vercel deploy --prebuilt`, deja cada función así:

- el handler pasa a ser `launcher.js`, que sí se puede importar;
- `launcher.js` es el de vercel-php con un adaptador al final: con la llamada nueva `(req, res)` lee el cuerpo, pasa la
  petición al servidor PHP embebido y escribe la respuesta; con la llamada antigua (evento de Lambda) sigue como antes;
- `LAMBDA_TASK_ROOT`, que el lanzador nuevo no define, vale `/var/task` para encontrar `php/` y `user/`;
- fuera de la función los ficheros de control de versiones (`.git/`, `.gitignore`…), que Vercel no deja subir.

Uso: python3 scripts/vercel-launcher.py [carpeta .vercel/output]   (por defecto, la de este proyecto)
"""
import json
import os
import sys

MARKER = '/* --- adaptador de Bide+ para el lanzador de Vercel --- */'
VERSION_CONTROL = ('.git', '.gitignore', '.gitattributes', '.gitmodules', '.github')

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
    config['handler'] = 'launcher.js'
    with open(config_path, 'w', encoding='utf-8') as f:
        json.dump(config, f, indent=2)
        f.write('\n')
    print(f'{func_dir}: handler launcher.js con el adaptador')


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


main()
