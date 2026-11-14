#!/usr/bin/env bash
# El mapa base de Bizkaia en PMTiles (teselas vectoriales) para el mapa del viaje: recorta los mapas de OSM al recuadro
# de Bizkaia con margen, saca cada capa con ogr2ogr y las junta con tippecanoe (zoom 8 a 15; Leaflet amplía después).
#
# Uso: .github/scripts/mapa-base.sh <salida.pmtiles> <mar.geojson> <mapa.osm.pbf>...
# <mar.geojson> es el mar del recuadro; si no está, se recorta de water-polygons-split-4326.zip (osmdata) en MAR_ZIP.
# Necesita osmium-tool, gdal-bin y tippecanoe (apt).
set -euo pipefail

# Bizkaia (de -3,45 a -2,41 de longitud y de 42,98 a 43,46 de latitud) con unos 5 km de margen.
RECUADRO="-3.52,42.92,-2.34,43.51"
ZOOM_MINIMO=8
ZOOM_MAXIMO=15
SALIDA=$1
MAR=$2
shift 2
TRABAJO=$(mktemp -d)
trap 'rm -rf "$TRABAJO"' EXIT

if [ ! -s "$MAR" ]; then
  ogr2ogr -f GeoJSON -spat ${RECUADRO//,/ } -clipsrc spat_extent "$MAR" \
    "/vsizip/$MAR_ZIP/water-polygons-split-4326/water_polygons.shp" -sql "SELECT 'ocean' AS kind FROM water_polygons"
fi

aRecortes=()
for MAPA in "$@"; do
  RECORTE="$TRABAJO/$(basename "$MAPA")"
  osmium extract --bbox="$RECUADRO" --strategy=smart --overwrite --no-progress -o "$RECORTE" "$MAPA"
  aRecortes+=("$RECORTE")
done
osmium merge --overwrite --no-progress -o "$TRABAJO/bizkaia.osm.pbf" "${aRecortes[@]}"

# capa <nombre> <consulta>: una capa en GeoJSONSeq con solo sus columnas («kind» y, si hace falta, «name»).
capa() {
  OSM_USE_CUSTOM_INDEXING=NO ogr2ogr -f GeoJSONSeq -lco RS=NO "$TRABAJO/$1.geojsons" "$TRABAJO/bizkaia.osm.pbf" -dialect SQLite -sql "$2"
}
capa roads "SELECT highway AS kind, name, geometry FROM lines WHERE highway IS NOT NULL AND highway NOT IN ('proposed', 'construction', 'raceway', 'bus_guideway', 'platform')"
capa rail "SELECT railway AS kind, geometry FROM lines WHERE railway IN ('rail', 'subway', 'light_rail', 'tram', 'narrow_gauge', 'funicular')"
capa waterways "SELECT waterway AS kind, geometry FROM lines WHERE waterway IN ('river', 'canal')"
capa water "SELECT 'water' AS kind, geometry FROM multipolygons WHERE \"natural\" = 'water' OR landuse IN ('reservoir', 'basin')"
OCIO="'park', 'garden', 'pitch', 'golf_course', 'playground'"
USO="'forest', 'grass', 'residential', 'industrial', 'commercial', 'retail', 'cemetery', 'railway'"
NATURAL="'wood', 'beach', 'sand'"
capa landuse "SELECT CASE WHEN leisure IN ($OCIO) THEN leisure WHEN landuse IN ($USO) THEN landuse ELSE \"natural\" END AS kind, geometry FROM multipolygons WHERE leisure IN ($OCIO) OR landuse IN ($USO) OR \"natural\" IN ($NATURAL)"
capa buildings "SELECT 'building' AS kind, geometry FROM multipolygons WHERE building IS NOT NULL"
capa places "SELECT place AS kind, name, geometry FROM points WHERE place IN ('city', 'town', 'village', 'suburb', 'neighbourhood') AND name IS NOT NULL"

# Por zoom: lo menudo (calles, caminos, jardines, edificios, barrios) solo de cerca. Los lugares no se aclaran (-r1).
FILTRO='{
  "roads": ["any", ["in", "kind", "motorway", "trunk", "primary", "motorway_link", "trunk_link"],
    ["all", [">=", "$zoom", 11], ["in", "kind", "secondary", "tertiary", "primary_link", "secondary_link"]],
    ["all", [">=", "$zoom", 13], ["in", "kind", "residential", "unclassified", "living_street", "pedestrian",
      "service", "tertiary_link"]],
    [">=", "$zoom", 14]],
  "landuse": ["any", ["in", "kind", "forest", "wood", "residential", "industrial", "commercial", "retail", "railway",
    "cemetery", "beach", "sand"], [">=", "$zoom", 13]],
  "buildings": [">=", "$zoom", 14],
  "places": ["any", ["in", "kind", "city", "town"], ["all", [">=", "$zoom", 11], ["==", "kind", "village"]],
    [">=", "$zoom", 13]]
}'
tippecanoe --force --quiet -o "$SALIDA" -Z "$ZOOM_MINIMO" -z "$ZOOM_MAXIMO" -n "Bizkaia" -r1 \
  -A '&copy; OpenStreetMap' -j "$FILTRO" --clip-bounding-box="$RECUADRO" --detect-shared-borders \
  --coalesce-densest-as-needed --drop-densest-as-needed \
  -L "water:$MAR" \
  -L "water:$TRABAJO/water.geojsons" -L "landuse:$TRABAJO/landuse.geojsons" \
  -L "waterways:$TRABAJO/waterways.geojsons" -L "buildings:$TRABAJO/buildings.geojsons" \
  -L "roads:$TRABAJO/roads.geojsons" -L "rail:$TRABAJO/rail.geojsons" -L "places:$TRABAJO/places.geojsons"
