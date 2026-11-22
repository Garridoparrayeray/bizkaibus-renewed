#!/usr/bin/env python3
"""Copia a la web solo lo del planificador que la app usa, para los dos despliegues (producción y prueba).

Uso: python3 .github/scripts/publicar-planificador.py <carpeta del planificador construido> <carpeta bideplus>
Van enteros los datos, el núcleo y su envoltorio (PUBLIC_DIRS). De las pantallas, solo los módulos que la app importa
y los que estos cargan a su vez; las herramientas del motor (medir, screens), su interfaz propia y origen.json no se
publican. La release «planificador» guarda el paquete entero. Si la app pide un módulo que no está, sale con error.
"""
import os
import re
import shutil
import sys

PUBLIC_DIRS = ('build', 'dist', 'js')
SCREENS_DIR = 'pantallas'
APP_DIRS = ('js', 'api')
APP_PREFIX = '/planificador/' + SCREENS_DIR + '/'
APP_REFERENCE = re.compile(re.escape(APP_PREFIX) + r'([\w./-]+\.(?:js|css))')
# Cualquier ruta entre comillas: import, import(), new URL('worker.js', …) o un mapa de rutas como el de engine.js.
MODULE_REFERENCE = re.compile(r'''['"`]((?:\.{1,2}/)?[\w./-]+\.(?:js|css|png|svg))['"`]''')
SOURCE_SUFFIXES = ('.js', '.css', '.php')


def app_entries(sAppRoot):
    aEntries = set()
    for sDir in APP_DIRS:
        for sFolder, _aSub, aFiles in os.walk(os.path.join(sAppRoot, sDir)):
            for sName in aFiles:
                if not sName.endswith(SOURCE_SUFFIXES):
                    continue
                with open(os.path.join(sFolder, sName), encoding='utf-8') as File:
                    aEntries.update(APP_REFERENCE.findall(File.read()))
    return aEntries


def reachable(sScreens, aEntries):
    aSeen = set()
    aPending = sorted(aEntries)
    while aPending:
        sFile = aPending.pop()
        if sFile in aSeen:
            continue
        sPath = os.path.join(sScreens, sFile)
        if not os.path.isfile(sPath):
            sys.exit(f'publicar-planificador: la app usa {APP_PREFIX}{sFile} y el planificador no lo trae')
        aSeen.add(sFile)
        if not sFile.endswith(('.js', '.css')):
            continue
        with open(sPath, encoding='utf-8') as File:
            sText = File.read()
        for sReference in MODULE_REFERENCE.findall(sText):
            sTarget = os.path.normpath(os.path.join(os.path.dirname(sFile), sReference))
            if not sTarget.startswith('..') and os.path.isfile(os.path.join(sScreens, sTarget)):
                aPending.append(sTarget)
    return aSeen


def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    sPlanner, sAppRoot = sys.argv[1], sys.argv[2]
    sOut = os.path.join(sAppRoot, 'planificador')
    shutil.rmtree(sOut, ignore_errors=True)
    for sDir in PUBLIC_DIRS:
        shutil.copytree(os.path.join(sPlanner, sDir), os.path.join(sOut, sDir))
    sScreens = os.path.join(sPlanner, SCREENS_DIR)
    aFiles = reachable(sScreens, app_entries(sAppRoot))
    for sFile in sorted(aFiles):
        sTarget = os.path.join(sOut, SCREENS_DIR, sFile)
        os.makedirs(os.path.dirname(sTarget), exist_ok=True)
        shutil.copy2(os.path.join(sScreens, sFile), sTarget)
    print(f'publicar-planificador: {len(aFiles)} ficheros de {SCREENS_DIR}/: {" ".join(sorted(aFiles))}')


main()
