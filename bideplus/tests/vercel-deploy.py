"""El despliegue a Vercel: la limpieza (.github/scripts/vercel-cleanup.py) y el tamaño de la función
(scripts/vercel-launcher.py).

Uso: python tests/vercel-deploy.py
"""
import json
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + '/'
SCRIPT = ROOT + '../.github/scripts/vercel-cleanup.py'
LAUNCHER = ROOT + 'scripts/vercel-launcher.py'
results = []


def check(name, ok, detail=''):
    results.append(ok)
    if ok:
        print('OK  ' + name)
        return
    print('MAL ' + name + ' — ' + str(detail))


def deleted(deployments, current, aliased=''):
    env = dict(os.environ, KEEP_PREVIOUS='2', CURRENT=current, ALIASED=aliased)
    out = subprocess.run([sys.executable, '-I', SCRIPT], input=json.dumps({'deployments': deployments}), env=env,
                         capture_output=True, text=True, check=True).stdout
    return sorted(uid for uid in out.split('\n') if uid)


# D1 a D7 de producción listos; tras un «Rollback production» a D4, D5 a D7 no tienen el dominio: sirve D4, y
# targets.production.id puede seguir siendo D7. P1, la vista previa de la copia de prueba (con su alias); P2, una
# vista previa sin alias; B8, uno de producción que aún se construye, y E9, uno que falló.
deployments = [{'uid': f'D{n}', 'created': n, 'target': 'production', 'state': 'READY'} for n in range(1, 8)]
deployments += [{'uid': 'P1', 'created': 8, 'target': None, 'state': 'READY'},
                {'uid': 'P2', 'created': 9, 'target': None, 'state': 'READY'},
                {'uid': 'B8', 'created': 10, 'target': 'production', 'state': 'BUILDING'},
                {'uid': 'E9', 'created': 0, 'target': 'production', 'state': 'ERROR'}]
gone = deleted(deployments, 'D7', 'D4 P1')
check('tras un rollback se conserva el que tiene el alias del dominio, además de los 3 más recientes',
      gone == ['D1', 'D2', 'D3', 'E9'], gone)
check('la vista previa con alias (la copia de prueba) y las demás vistas previas no se borran',
      'P1' not in gone and 'P2' not in gone, gone)
check('el que está a medias (BUILDING) no se borra; el que falló, sí', 'B8' not in gone and 'E9' in gone, gone)
gone = deleted(deployments, 'D7', 'D7 P1')
check('sin rollback, el último y los 2 anteriores', gone == ['D1', 'D2', 'D3', 'D4', 'E9'], gone)
check('si no se sabe qué sirve cada dominio, no se borra nada', deleted(deployments, '', 'D4') == [])


def launcher_output(megabytes):
    # Una función de vercel-php con un fichero del tamaño dado en su filePathMap (vacío: no ocupa disco).
    with tempfile.TemporaryDirectory() as folder:
        func = os.path.join(folder, 'functions', 'api', 'index.func')
        os.makedirs(func)
        big = os.path.join(folder, 'grande.bin')
        with open(big, 'wb') as out:
            out.truncate(megabytes * 1000 * 1000)
        launcher = os.path.join(folder, 'launcher.js')
        with open(launcher, 'w', encoding='utf-8') as out:
            out.write('// lanzador\n')
        config = {'handler': 'launcher.launcher', 'filePathMap': {'grande.bin': big, 'launcher.js': launcher}}
        with open(os.path.join(func, '.vc-config.json'), 'w', encoding='utf-8') as out:
            json.dump(config, out)
        run = subprocess.run([sys.executable, '-I', LAUNCHER, folder], cwd=folder, capture_output=True, text=True)
        return run.returncode, run.stdout


code, out = launcher_output(311)
check('una función de 311 MB (sumando filePathMap) se despliega con un aviso de las funciones grandes',
      code == 0 and '311 MB' in out and '::notice::' in out and '::warning::' not in out, out)
code, out = launcher_output(4700)
check('cerca del tope de las funciones grandes, solo un aviso: no para el despliegue',
      code == 0 and '::warning::' in out, out)

print(f'\nRESULTADO despliegue a Vercel: {sum(results)} OK, {len(results) - sum(results)} MAL')
if not all(results):
    sys.exit(1)
