# Las redes con una captura en marcha o esperando turno, separadas por espacios (por ejemplo «bus bilbobus»).
# Salen de los nombres de los trabajos de la matriz: «capture (bilbobus, pace-data-bilbobus)».
#
# Uso: bash .github/scripts/redes-capturando.sh (necesita gh con GH_TOKEN).

REPO_APP=${GITHUB_REPOSITORY:-Garridoparrayeray/bizkaibus-renewed}

for sEstado in in_progress queued; do
  gh api "repos/$REPO_APP/actions/workflows/pace-capture.yml/runs?status=$sEstado&per_page=50" --jq '.workflow_runs[].id'
done | sort -u | while read -r iRun; do
  gh api "repos/$REPO_APP/actions/runs/$iRun/jobs" --jq '.jobs[] | select(.status != "completed") | .name'
done | sed -n -e "s/^capture (\([^,]*\),.*/\1/p" -e "s/^capture\$/bus/p" | sort -u | tr "\n" " "
