#!/usr/bin/env python3
"""Pone en los datos del planificador publicado los paquetes rehechos con la herramienta del motor de esta prueba.

Las pruebas de la app usan los datos de producción (release «planificador») con el WebAssembly del motor que prueban,
que puede tener reglas más nuevas que la herramienta que hizo esos paquetes (y rechazarlos). Copia cada .bdtt de
PACKS que el manifiesto publicado ya nombra y pone al día su tamaño, su CRC y sus cuentas; lo demás (tablas de andar,
lugares, trazados) se queda como estaba.

Uso: python3 planner-packs.py PACKS DATA
"""
import json
import pathlib
import shutil
import sys

FIELDS = ('bytes', 'crc32', 'stops', 'lines', 'routes', 'trips')


def main():
    packs = pathlib.Path(sys.argv[1])
    data = pathlib.Path(sys.argv[2])
    built = {entry['slug']: entry for entry in json.loads((packs / 'manifest.json').read_text(encoding='utf-8'))['networks']}
    manifest = json.loads((data / 'manifest.json').read_text(encoding='utf-8'))
    for entry in manifest['networks']:
        new = built.get(entry['slug'])
        if new is None:
            print(f"{entry['slug']}: sin paquete nuevo, se queda el publicado")
            continue
        shutil.copyfile(packs / new['file'], data / entry['file'])
        for field in FIELDS:
            if field in new:
                entry[field] = new[field]
    (data / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False), encoding='utf-8')
    print(f"{len(manifest['networks'])} paquetes al día")


if __name__ == '__main__':
    main()
