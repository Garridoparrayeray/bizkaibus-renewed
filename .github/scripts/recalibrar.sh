#!/usr/bin/env bash
# Recalibrado diario del workflow «Ritmo de los buses»: para cada red con motor, con las muestras acumuladas en su
# release (pace-data para Bizkaibus, pace-data-<red> para las demás), recalcula el factor de ritmo, los tiempos por tramo
# y la corrección por viaje, y los deja en la carpeta de datos de su motor (bideplus/engine/<motor>/data). Una red sin
# muestras todavía (su feed no ha traído vehículos) se salta.
#
# Variables:
#   REDES    redes a recalibrar, separadas por espacios (por defecto, todas las que tienen motor)
#   RELEASE  release de pruebas en vez de la de cada red (solo con una red)
set -uo pipefail

MOTOR=bideplus/engine/bizkaibus/tools
RESUMEN=${GITHUB_STEP_SUMMARY:-/dev/null}
RELEASE_PRUEBA=${RELEASE:-}
if [ -z "${REDES:-}" ]; then
  REDES=$(php -r 'require "bideplus/api/Core/Networks.php"; foreach (Core\Networks::all() as $s => $a) { if (!empty($a["engine"])) { echo $s, " "; } }')
fi

for RED in $REDES; do
  RELEASE=pace-data
  if [ "$RED" != bus ]; then
    RELEASE="pace-data-$RED"
  fi
  if [ -n "$RELEASE_PRUEBA" ] && [ "$RELEASE_PRUEBA" != pace-data ]; then
    RELEASE=$RELEASE_PRUEBA
  fi
  export RELEASE
  source .github/scripts/pace-data.sh
  DB=$(php -r 'require "bideplus/api/Core/Networks.php"; echo Core\Networks::get($argv[1])["db"];' "$RED")
  DATOS="$RUNNER_TEMP/muestras-$RED"
  mkdir -p "$DATOS"
  echo "## $RED ($RELEASE)" | tee -a "$RESUMEN"

  if ! gh release view "$RELEASE" --repo "$REPO_APP" >/dev/null 2>&1; then
    echo "Sin release de muestras todavía: no se recalibra" | tee -a "$RESUMEN"
    continue
  fi
  descargar_acumulado pace-samples.jsonl "$DATOS"
  if ! descargar_acumulado segment-samples.jsonl "$DATOS" && [ "$RED" = bus ]; then
    gunzip -c bideplus/engine/bizkaibus/data/segment-samples-seed.jsonl.gz > "$DATOS/segment-samples.jsonl"
  fi
  descargar_acumulado eta-eval.jsonl "$DATOS" || echo "Todavia no hay medidas de precision"
  descargar_acumulado eta-eval-historial.jsonl "$DATOS" || echo "Todavia no hay historial de precision"
  if [ ! -s "$DATOS/pace-samples.jsonl" ] && [ ! -s "$DATOS/segment-samples.jsonl" ]; then
    echo "Sin muestras: no se recalibra" | tee -a "$RESUMEN"
    continue
  fi

  # Con la base del día están todos los viajes de las muestras recientes; si no se puede construir, la del repositorio.
  if php bideplus/scripts/build-database.php --network="$RED" --output="$RUNNER_TEMP/$DB.sqlite" && [ -s "$RUNNER_TEMP/$DB.sqlite" ]; then
    mv "$RUNNER_TEMP/$DB.sqlite" "bideplus/data/$DB.sqlite"
  fi

  if [ -s "$DATOS/pace-samples.jsonl" ]; then
    php "$MOTOR/realtime-backtest.php" calibrate "$DATOS/pace-samples.jsonl" --write --red="$RED"
  fi
  if [ -s "$DATOS/segment-samples.jsonl" ]; then
    php "$MOTOR/realtime-backtest.php" calibrate-segments "$DATOS/segment-samples.jsonl" --write --red="$RED"
    # Después de los tramos: se mide contra ellos.
    php "$MOTOR/realtime-backtest.php" calibrate-trips "$DATOS/segment-samples.jsonl" --write --red="$RED"
  fi

  if [ -s "$DATOS/eta-eval.jsonl" ]; then
    HISTORIAL=()
    if [ -f "$DATOS/eta-eval-historial.jsonl" ]; then
      HISTORIAL=(--historial="$DATOS/eta-eval-historial.jsonl")
    fi
    python3 bideplus/engine/bizkaibus/tools/eta-report.py "$DATOS/eta-eval.jsonl" "${HISTORIAL[@]}" >> "$RESUMEN" || true
  fi
done
