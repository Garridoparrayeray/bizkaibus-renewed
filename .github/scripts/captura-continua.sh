#!/usr/bin/env bash
# Captura continua del feed en directo de Bizkaibus para el workflow «Ritmo de los buses».
#
# Captura en bloques de una hora de reloj, uno detrás de otro: mientras se captura un bloque se procesa el anterior, así
# que no quedan huecos. Por cada bloque:
#   - mide la precisión de las llegadas (con todo, sin GPS y sin feed) y añade los histogramas a eta-eval.jsonl; las
#     capturas del bloque siguiente sirven para ver cuándo pasan los buses predichos al final del bloque;
#   - añade las muestras de ritmo, de tiempos por tramo y de cobertura a los acumulados de la release;
#   - archiva las capturas en bruto y las muestras nuevas del bloque en la release mensual capturas-AAAA-MM del
#     repositorio del algoritmo, para poder recalcularlo todo más adelante (los acumulados descartan lo antiguo).
# Los bloques no cruzan la medianoche: el horario de cada captura es el de su día.
#
# Variables:
#   MINUTOS        minutos de captura en total (330 por defecto: el trabajo tiene un límite de 6 horas)
#   BLOQUE         minutos por bloque; vacío, hasta la siguiente hora en punto (para probar, 2 o 3)
#   INTERVALO      segundos entre lecturas del feed (20 por defecto; el feed cambia cada 55 s más o menos)
#   SUBIR          1 sube los resultados; 0 solo calcula (para probar en local)
#   RELEASE        release de los acumulados (pace-data por defecto)
#   ARCHIVO_REPO   repositorio donde se archivan las capturas (Garridoparrayeray/bideplus-engine por defecto)
#   ARCHIVO_TOKEN  token con permiso de escritura en ARCHIVO_REPO; sin él no se archiva
#                  (con una RELEASE de pruebas, el archivo va a <RELEASE>-capturas-AAAA-MM para no mezclarse)
#   TRABAJO        carpeta de trabajo ($RUNNER_TEMP/captura-continua por defecto)
#   ENCADENAR      1 lanza al acabar la siguiente captura (GitHub retrasa o se salta muchas ejecuciones programadas);
#                  solo si esta ha capturado al menos una hora, para no encadenar fallos
set -uo pipefail

MINUTOS=${MINUTOS:-330}
BLOQUE=${BLOQUE:-}
INTERVALO=${INTERVALO:-20}
SUBIR=${SUBIR:-1}
ARCHIVO_REPO=${ARCHIVO_REPO:-Garridoparrayeray/bideplus-engine}
ARCHIVO_TOKEN=${ARCHIVO_TOKEN:-}
# Un secreto pegado con un salto de línea al final no vale como cabecera HTTP.
ARCHIVO_TOKEN=$(printf '%s' "$ARCHIVO_TOKEN" | tr -d '[:space:]')
TRABAJO=${TRABAJO:-${RUNNER_TEMP:-/tmp}/captura-continua}
RESUMEN=${GITHUB_STEP_SUMMARY:-/dev/null}
MOTOR=bideplus/engine/bizkaibus/tools
ACUMULADOS=(pace-samples.jsonl segment-samples.jsonl coverage-samples.jsonl eta-eval.jsonl)
ACUM="$TRABAJO/acumulados"
PREFIJO_ARCHIVO=capturas
if [ "${RELEASE:-pace-data}" != pace-data ]; then
  PREFIJO_ARCHIVO="$RELEASE-capturas"
fi

source "$(dirname "$0")/pace-data.sh"

ENGINE_SHA=$(git -C bideplus/engine rev-parse --short HEAD 2>/dev/null)
export ENGINE_SHA

aviso() {
  echo "::warning::$*"
}

# Fecha y hora de Madrid de una hora unix: «20261031-0817».
sello() {
  TZ=Europe/Madrid date -d "@$1" +%Y%m%d-%H%M
}

# Día (AAAAMMDD) de la carpeta de un bloque: bloque-20261031-0817 → 20261031.
dia_de() {
  local sNombre
  sNombre=$(basename "$1")
  echo "${sNombre:7:8}"
}

# Número de capturas guardadas en un bloque.
capturas_de() {
  find "$1/capturas" -name 'vm_*.xml' | wc -l
}

# Hora unix en que debe acabar el bloque que empieza ahora: la siguiente hora en punto (o BLOQUE minutos), sin pasar del
# final del trabajo. Las horas en punto de UTC lo son también en Madrid, medianoche incluida.
fin_del_bloque() {
  local iAhora iFin
  iAhora=$(date +%s)
  iFin=$(((iAhora / 3600 + 1) * 3600))
  if [ -n "$BLOQUE" ]; then
    iFin=$((iAhora + BLOQUE * 60))
  fi
  if ((iFin > FIN_TRABAJO)); then
    iFin=$FIN_TRABAJO
  fi
  echo "$iFin"
}

# Arranca en segundo plano la captura de un bloque nuevo; deja su carpeta en BLOQUE_ACTUAL y el proceso en PID_CAPTURA.
iniciar_bloque() {
  local iFin
  iFin=$(fin_del_bloque)
  BLOQUE_ACTUAL="$TRABAJO/bloque-$(sello "$(date +%s)")"
  mkdir -p "$BLOQUE_ACTUAL/capturas"
  echo "$(date +%H:%M:%S) captura $(basename "$BLOQUE_ACTUAL") hasta las $(TZ=Europe/Madrid date -d "@$iFin" +%H:%M)"
  php "$MOTOR/realtime-backtest.php" capture "$BLOQUE_ACTUAL/capturas" 0 "$INTERVALO" --hasta="$iFin" \
    >"$BLOQUE_ACTUAL/captura.log" 2>&1 &
  PID_CAPTURA=$!
}

descargar_acumulados() {
  mkdir -p "$ACUM"
  local sNombre iEstado
  if [ "$SUBIR" != 1 ]; then
    for sNombre in "${ACUMULADOS[@]}"; do
      touch "$ACUM/$sNombre"
    done
    return 0
  fi
  for sNombre in "${ACUMULADOS[@]}"; do
    descargar_acumulado "$sNombre" "$ACUM"
    iEstado=$?
    if ((iEstado == 1)); then
      # Está en la release pero no baja: mejor parar que sustituirlo luego por uno casi vacío.
      echo "No se ha podido descargar $sNombre de $RELEASE; no se captura para no pisar el acumulado" >&2
      exit 1
    fi
    if ((iEstado == 2)); then
      echo "$sNombre no está todavía en $RELEASE; se empieza uno nuevo"
      touch "$ACUM/$sNombre"
    fi
  done
  if [ ! -s "$ACUM/segment-samples.jsonl" ]; then
    gunzip -c bideplus/engine/bizkaibus/data/segment-samples-seed.jsonl.gz >"$ACUM/segment-samples.jsonl"
  fi
}

subir_acumulados() {
  if [ "$SUBIR" != 1 ]; then
    return 0
  fi
  local sNombre
  for sNombre in "${ACUMULADOS[@]}"; do
    if [ ! -s "$ACUM/$sNombre" ]; then
      # GitHub no acepta ficheros vacíos; se subirá cuando tenga algo.
      continue
    fi
    if ! subir_acumulado "$ACUM/$sNombre"; then
      aviso "No se ha podido subir $sNombre; se reintenta con el bloque siguiente"
    fi
  done
}

# Muestras de ritmo, tiempos por tramo y cobertura de un bloque: se añaden a los acumulados y se guardan aparte, solo las
# nuevas, para el archivo.
procesar_muestras() {
  local sBloque=$1
  timeout 900 php "$MOTOR/realtime-backtest.php" samples "$sBloque/capturas" "$ACUM/pace-samples.jsonl" \
    --nuevas="$sBloque/muestras-ritmo.jsonl" || aviso "Fallan las muestras de ritmo de $(basename "$sBloque")"
  timeout 900 php "$MOTOR/realtime-backtest.php" segment-samples "$sBloque/capturas" "$ACUM/segment-samples.jsonl" \
    --nuevas="$sBloque/muestras-tramos.jsonl" || aviso "Fallan los tiempos por tramo de $(basename "$sBloque")"
  if timeout 600 php "$MOTOR/coverage-samples.php" "$sBloque/capturas" "$sBloque/cobertura.jsonl" 15; then
    cat "$sBloque/cobertura.jsonl" >>"$ACUM/coverage-samples.jsonl"
  else
    aviso "Falla la cobertura de $(basename "$sBloque")"
  fi
}

# Archiva las capturas en bruto y las muestras nuevas del bloque en la release mensual del repositorio del algoritmo.
archivar() {
  local sBloque=$1 sNombre sEtiqueta sTar
  if [ "$SUBIR" != 1 ] || [ -z "$ARCHIVO_TOKEN" ]; then
    return 0
  fi
  sNombre=$(basename "$sBloque")
  sNombre=${sNombre#bloque-}
  sEtiqueta="$PREFIJO_ARCHIVO-${sNombre:0:4}-${sNombre:4:2}"
  sTar="$TRABAJO/vm-$sNombre.tar.xz"
  local aContenido=(capturas) sFichero
  for sFichero in muestras-ritmo.jsonl muestras-tramos.jsonl cobertura.jsonl; do
    if [ -f "$sBloque/$sFichero" ]; then
      aContenido+=("$sFichero")
    fi
  done
  if ! tar -cJf "$sTar" -C "$sBloque" "${aContenido[@]}"; then
    aviso "No se ha podido empaquetar $sNombre"
    return 0
  fi
  if ! GH_TOKEN=$ARCHIVO_TOKEN gh release view "$sEtiqueta" --repo "$ARCHIVO_REPO" >/dev/null 2>&1; then
    GH_TOKEN=$ARCHIVO_TOKEN gh release create "$sEtiqueta" --repo "$ARCHIVO_REPO" --prerelease \
      --title "Capturas del feed de Bizkaibus (${sNombre:0:4}-${sNombre:4:2})" \
      --notes "Copias del feed SIRI de Bizkaibus en bloques de una hora (hora de Madrid), con las muestras que se sacaron de cada bloque. Las genera el workflow Ritmo de los buses; no es una versión." \
      >/dev/null 2>&1
  fi
  if GH_TOKEN=$ARCHIVO_TOKEN reintentar 3 gh release upload "$sEtiqueta" --repo "$ARCHIVO_REPO" "$sTar" --clobber; then
    echo "$(date +%H:%M:%S) archivado $(basename "$sTar") ($(du -h "$sTar" | cut -f1)) en $sEtiqueta"
  else
    aviso "No se ha podido archivar $sNombre en $ARCHIVO_REPO"
  fi
  rm -f -- "$sTar"
}

# Precisión de las llegadas de un bloque. Si el bloque siguiente es del mismo día, sus capturas se usan para ver cuándo
# pasan los buses (solo para eso: las predicciones salen del bloque evaluado).
evaluar() {
  local sBloque=$1 sSiguiente=$2 sNombre sModo
  local aSeguimiento=()
  sNombre=$(basename "$sBloque")
  if [ -n "$sSiguiente" ] && [ "$(dia_de "$sBloque")" = "$(dia_de "$sSiguiente")" ]; then
    aSeguimiento=(--seguimiento="$sSiguiente/capturas")
  fi
  {
    echo "### Precisión de las llegadas, bloque ${sNombre#bloque-} (hora de Madrid)"
    echo '```'
    timeout 900 php "$MOTOR/eval-eta.php" "$sBloque/capturas" "${aSeguimiento[@]}" --jsonl="$ACUM/eta-eval.jsonl" \
      || echo "Sin datos suficientes"
    echo '```'
  } | tee -a "$RESUMEN"
  for sModo in --sin-gps --sin-feed; do
    echo "== $sModo"
    timeout 900 php "$MOTOR/eval-eta.php" "$sBloque/capturas" "${aSeguimiento[@]}" "$sModo" \
      --jsonl="$ACUM/eta-eval.jsonl" >/dev/null || aviso "Falla la medida $sModo de $sNombre"
  done
}

borrar_bloque() {
  local sBloque=$1
  if [[ "$sBloque" == "$TRABAJO"/bloque-* ]]; then
    rm -rf -- "$sBloque"
  fi
}

INICIO=$(date +%s)
FIN_TRABAJO=$((INICIO + MINUTOS * 60))
mkdir -p "$TRABAJO"
descargar_acumulados

BLOQUE_ACTUAL=""
PID_CAPTURA=""
ANTERIOR=""
VACIOS=0
iniciar_bloque
while true; do
  wait "$PID_CAPTURA"
  TERMINADO=$BLOQUE_ACTUAL
  tail -n 1 "$TERMINADO/captura.log"
  BLOQUE_ACTUAL=""
  if (($(capturas_de "$TERMINADO") == 0)); then
    # Sin ninguna captura (feed caído o fallo al arrancar): se espera un poco para no encadenar bloques vacíos.
    VACIOS=$((VACIOS + 1))
    aviso "Bloque $(basename "$TERMINADO") sin capturas: $(tail -n 3 "$TERMINADO/captura.log" | tr '\n' ' ')"
    borrar_bloque "$TERMINADO"
    if [ -n "$ANTERIOR" ]; then
      # Con un hueco en medio, el bloque anterior se mide solo con sus capturas.
      evaluar "$ANTERIOR" ""
      borrar_bloque "$ANTERIOR"
      ANTERIOR=""
    fi
    if ((VACIOS >= 5)); then
      echo "Cinco bloques seguidos sin capturas; se para" >&2
      break
    fi
    sleep 60
    if (($(date +%s) < FIN_TRABAJO - 60)); then
      iniciar_bloque
      continue
    fi
    break
  fi
  VACIOS=0
  if (($(date +%s) < FIN_TRABAJO - 60)); then
    iniciar_bloque
  fi
  procesar_muestras "$TERMINADO"
  archivar "$TERMINADO"
  if [ -n "$ANTERIOR" ]; then
    evaluar "$ANTERIOR" "$TERMINADO"
    borrar_bloque "$ANTERIOR"
  fi
  ANTERIOR=$TERMINADO
  if [ -z "$BLOQUE_ACTUAL" ]; then
    break
  fi
  subir_acumulados
done
if [ -n "$ANTERIOR" ]; then
  evaluar "$ANTERIOR" ""
  borrar_bloque "$ANTERIOR"
fi
subir_acumulados
if [ "${ENCADENAR:-0}" = 1 ] && (($(date +%s) - INICIO >= 3600)); then
  # La siguiente espera su turno (concurrency) y arranca en cuanto acabe esta.
  if gh workflow run pace-capture.yml --repo "$REPO_APP" --ref "${GITHUB_REF_NAME:-master}"; then
    echo "Siguiente captura lanzada"
  else
    aviso "No se ha podido lanzar la siguiente captura; queda el cron horario"
  fi
fi
