# Llamadas a la API de Vercel para «Desplegar la prueba», con VERCEL_TOKEN, VERCEL_ORG_ID y VERCEL_PROJECT_ID.
#
# Uso: source .github/scripts/vercel-api.sh; vercel_api <método> <ruta> [cuerpo JSON] [códigos que valen, "200"]
# Deja la respuesta en $VERCEL_RESPUESTA y el código HTTP en $VERCEL_CODIGO. Un 403 o un código que no vale paran.

VERCEL_RESPUESTA="${RUNNER_TEMP:-/tmp}/vercel-respuesta.json"
VERCEL_CODIGO=""

vercel_api() {
  local sMetodo=$1 sRuta=$2 sCuerpo=${3:-} sValen=${4:-200} sUrl sSeparador
  local aOpciones=(-sS -o "$VERCEL_RESPUESTA" -w '%{http_code}' -X "$sMetodo" -H "Authorization: Bearer $VERCEL_TOKEN")
  sUrl="https://api.vercel.com$sRuta"
  # Un equipo (team_…) va en cada llamada; una cuenta personal, no.
  if [[ "$VERCEL_ORG_ID" == team_* ]]; then
    sSeparador='?'
    if [[ "$sRuta" == *\?* ]]; then
      sSeparador='&'
    fi
    sUrl="$sUrl${sSeparador}teamId=$VERCEL_ORG_ID"
  fi
  if [ -n "$sCuerpo" ]; then
    aOpciones+=(-H 'Content-Type: application/json' --data "$sCuerpo")
  fi
  VERCEL_CODIGO=$(curl "${aOpciones[@]}" "$sUrl")
  if [ "$VERCEL_CODIGO" = 403 ]; then
    # Pasa con un token de solo lectura o de otro equipo: hace falta uno con acceso al equipo del proyecto.
    echo "::error::Vercel da 403 a $sMetodo $sRuta: VERCEL_TOKEN no puede cambiar dominios, protección y variables del" \
      "proyecto. Hace falta un token con acceso completo al equipo del proyecto (Full Account)." \
      "$(jq -r '.error.message // empty' "$VERCEL_RESPUESTA" 2>/dev/null)"
    exit 1
  fi
  if ! grep -qw "$VERCEL_CODIGO" <<<"$sValen"; then
    echo "::error::Vercel da $VERCEL_CODIGO a $sMetodo $sRuta:" \
      "$(jq -r '.error.message // .' "$VERCEL_RESPUESTA" 2>/dev/null)"
    exit 1
  fi
}
