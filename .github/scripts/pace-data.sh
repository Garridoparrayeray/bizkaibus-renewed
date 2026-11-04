# Funciones para leer y escribir los ficheros acumulados de una release (por defecto pace-data) sin perderlos nunca.
#
# Subir un fichero con --clobber borra primero el que hay; si la subida se corta en ese momento, la release se queda sin
# él y la siguiente captura empezaría de cero. Por eso se sube como <nombre>.nuevo, se borra el viejo y se renombra el
# nuevo: siempre queda al menos una copia completa, y quien descarga busca <nombre>.nuevo si no encuentra <nombre>.
#
# Uso: source .github/scripts/pace-data.sh (necesita gh con GH_TOKEN y la variable RELEASE).

RELEASE=${RELEASE:-pace-data}
REPO_APP=${GITHUB_REPOSITORY:-Garridoparrayeray/bizkaibus-renewed}

# reintentar <veces> <orden...>: repite la orden con esperas crecientes hasta que salga bien.
reintentar() {
  local iVeces=$1
  shift
  local i
  for ((i = 1; i <= iVeces; i++)); do
    if "$@"; then
      return 0
    fi
    if ((i < iVeces)); then
      sleep $((i * 5))
    fi
  done
  return 1
}

# Nombres de los ficheros de la release, uno por línea.
ficheros_release() {
  reintentar 4 gh release view "$RELEASE" --repo "$REPO_APP" --json assets --jq '.assets[].name'
}

# descargar_acumulado <nombre> <carpeta>: deja <carpeta>/<nombre> con la copia de la release.
# Devuelve 0 si lo ha descargado, 2 si la release no lo tiene y 1 si lo tiene pero no se ha podido descargar.
descargar_acumulado() {
  local sNombre=$1 sCarpeta=$2 sLista
  if ! sLista=$(ficheros_release); then
    echo "No se puede leer la release $RELEASE" >&2
    return 1
  fi
  mkdir -p "$sCarpeta"
  if grep -qxF "$sNombre" <<<"$sLista"; then
    reintentar 4 gh release download "$RELEASE" --repo "$REPO_APP" -p "$sNombre" -D "$sCarpeta" --clobber || return 1
    return 0
  fi
  if grep -qxF "$sNombre.nuevo" <<<"$sLista"; then
    # Una subida anterior se cortó entre borrar el viejo y renombrar el nuevo: el nuevo está completo.
    reintentar 4 gh release download "$RELEASE" --repo "$REPO_APP" -p "$sNombre.nuevo" -D "$sCarpeta" --clobber || return 1
    mv "$sCarpeta/$sNombre.nuevo" "$sCarpeta/$sNombre"
    return 0
  fi
  return 2
}

# subir_acumulado <ruta>: sustituye en la release el fichero del mismo nombre sin dejarla nunca sin una copia.
subir_acumulado() {
  local sRuta=$1 sNombre sTemporal sIds iViejo iNuevo
  sNombre=$(basename "$sRuta")
  sTemporal=$(mktemp -d)
  cp "$sRuta" "$sTemporal/$sNombre.nuevo"
  if ! reintentar 3 gh release upload "$RELEASE" --repo "$REPO_APP" "$sTemporal/$sNombre.nuevo" --clobber; then
    rm -rf -- "$sTemporal"
    return 1
  fi
  rm -rf -- "$sTemporal"
  if ! sIds=$(reintentar 3 gh api "repos/$REPO_APP/releases/tags/$RELEASE" --jq '.assets[] | "\(.name) \(.id)"'); then
    return 1
  fi
  iViejo=$(awk -v n="$sNombre" '$1 == n { print $2 }' <<<"$sIds")
  iNuevo=$(awk -v n="$sNombre.nuevo" '$1 == n { print $2 }' <<<"$sIds")
  if [ -z "$iNuevo" ]; then
    return 1
  fi
  if [ -n "$iViejo" ]; then
    reintentar 3 gh api -X DELETE "repos/$REPO_APP/releases/assets/$iViejo" >/dev/null || return 1
  fi
  reintentar 3 gh api -X PATCH "repos/$REPO_APP/releases/assets/$iNuevo" -f name="$sNombre" >/dev/null
}
