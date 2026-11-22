#!/usr/bin/env bash
# Tras desplegar, comprueba que la web no sirve nada privado ni interno: código, bases, el motor y las herramientas del
# planificador. Uso: bash .github/scripts/comprobar-privado.sh <dirección del sitio>
# Escribe las que se sirven, una por línea, y sale con 1 si hay alguna. Va despacio: el cortafuegos de Vercel corta las
# ráfagas con un 403 (x-vercel-mitigated), y esa ruta queda sin comprobar, sin contar como fallo.
set -u

ESPERA_SEGUNDOS=3

SITIO="$1"
RUTAS=(
  /api/Config/site.php
  /api/Core/Store.php
  /engine/bizkaibus/Live.php
  /data/bizkaibus.sqlite
  /data/metrobilbao.sqlite
  /scripts/build-database.php
  /tests/run-all.py
  /dev-router.php
  /vercel.json
  /README.md
  /.htaccess
  /.gitignore
  /miamor.html
  /planificador/origen.json
  /planificador/pantallas/medir.html
  /planificador/pantallas/medir.js
  /planificador/pantallas/medir-worker.js
  /planificador/pantallas/screens.html
  /planificador/pantallas/app.js
  /docs/manual/hoja-de-ruta/fonts/hanken-grotesk-latin.woff2
)

EXPUESTAS=0
for RUTA in "${RUTAS[@]}"; do
  CABECERAS=$(curl -s -o /dev/null -D - "$SITIO$RUTA")
  # La última línea de estado: con un proxy, la primera es la de su túnel.
  CODE=$(awk '/^HTTP\// { sCode = $2 } END { print sCode }' <<<"$CABECERAS")
  if [ "$CODE" = 403 ] && grep -qi '^x-vercel-mitigated:' <<<"$CABECERAS"; then
    CODE="$CODE (cortafuegos: sin comprobar)"
  fi
  echo "$RUTA -> $CODE" >&2
  if [ "${CODE:0:1}" = 2 ]; then
    echo "$RUTA"
    EXPUESTAS=1
  fi
  sleep "$ESPERA_SEGUNDOS"
done
exit "$EXPUESTAS"
