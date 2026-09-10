# Gasolinera+

Precios de gasolina y diésel de toda España, cerca de ti: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos oficiales del Ministerio para la Transición Ecológica (geoportalgasolineras.es), desplegable en Vercel. Hermana de [BizkaiBus+/Metro+](https://github.com/Garridoparrayeray/bizkaibus-renewed), misma filosofía de stack, proyecto y repo independientes porque el dominio de datos (carburantes vs. transporte) no comparte esquema ni ciclo de vida del dato con esas apps.

## Arquitectura

- **`data/gasolinera.sqlite`**: el único fichero de datos. A diferencia de bizkaibus+ (que regenera su `.sqlite` entero cada noche y no lo versiona en git), aquí el histórico de precios se **acumula día a día y se comitea al repo**: `scripts/build-database.php --mode=daily` nunca borra el fichero, solo añade el snapshot de hoy. Esta es la decisión de diseño que más diverge del resto de la "red de apps", y está tomada así deliberadamente (ver más abajo).
- **`api/`**: API REST en PHP nativo, sin framework. `index.php` (punto de entrada), `shell.php` (el HTML de la app, vive dentro de `api/` por la misma razón que en bizkaibus+: el runtime `vercel-php` solo reconoce como función servible un PHP físicamente ahí dentro), `Core/` (Router, Request, Response, Database, Cache, Http, copiados sin cambios de bizkaibus+ porque son infraestructura HTTP genérica sin nada de transporte), `Controllers/StationsController.php`, `Models/Station.php`, `Models/Search.php` (normalización de texto, también copiado tal cual), `Services/OpeningHours.php`.
- **`js/`**: frontend estático, sin build step. Mapa con [Leaflet](https://leafletjs.com/) + [Leaflet.markercluster](https://github.com/Leaflet/Leaflet.markercluster) + [Leaflet.heat](https://github.com/Leaflet/Leaflet.heat), gráfica de evolución con [Chart.js](https://www.chartjs.org/), todo vía CDN. Comparador y favoritos en `localStorage`, sin cuentas ni backend para eso.

## El feed oficial: coma decimal y sin API key

`https://sedeaplicaciones.minetur.gob.es/ServiciosRESTCarburantes/PreciosCarburantes/EstacionesTerrestres/` devuelve el snapshot completo de España (~11.500 estaciones) en JSON, sin autenticación, aunque su `Content-Type` diga `text/html` (se parsea igual, ignorando la cabecera). Herencia del formato numérico español: coordenadas y precios llegan como **strings con coma decimal** (`"39,211417"`, `"1,749"`), no como números; `parseSpanishDecimal()` en `build-database.php` los normaliza antes de guardarlos. Cada carburante es un campo `"Precio <Nombre>"` separado, vacío cuando la estación no lo vende; el mapeo de esos ~20 nombres a una clave estable (`gasoleo_a`, `gasolina_95_e5`...) está fijado explícitamente en `FUEL_FIELD_MAP`, no inferido, para que un carburante nuevo del feed genere un aviso visible en vez de perderse en silencio.

## Por qué el histórico se rellena retroactivamente

El feed también expone `EstacionesTerrestresHist/{dd-mm-yyyy}`: el snapshot completo del país en una fecha pasada (no una serie temporal por estación). Antes del primer lanzamiento se ejecuta un backfill manual de un solo uso:

```
php scripts/build-database.php --mode=backfill --days=30
```

para que la gráfica de evolución semanal de cada gasolinera tenga datos reales desde el primer día, en vez de nacer vacía y tardar 1-2 semanas en llenarse con el cron diario. `--mode=backfill` nunca toca `current_prices` (esa tabla solo la actualiza `--mode=daily` con el snapshot de hoy) y usa `INSERT OR IGNORE` en `price_history`, así que repetir el backfill sobre días ya cubiertos es seguro y no duplica nada: verificado que ejecutar el mismo backfill dos veces deja el mismo número de filas.

El cron diario (`.github/workflows/rebuild-schedule.yml`, 03:00 UTC) ejecuta solo `--mode=daily`: descarga el snapshot de hoy, actualiza `stations`/`current_prices` con `UPSERT`, y añade una fila más a `price_history` por cada estación y carburante (misma garantía de idempotencia si el workflow se relanza el mismo día).

## Por qué `price_history` crece indefinidamente

No hay política de retención en v1, es una decisión consciente, no una omisión. Cada día añade una fila por estación y carburante con dato (~40.000 filas/día verificado con datos reales); el `.sqlite` completo, con ~8 días de histórico, ocupa ~62MB. Si el tamaño se vuelve un problema real (para el límite de Vercel, o para el propio repo git creciendo con un commit diario), la salida más simple sería purgar filas de `price_history` más antiguas que N meses en el propio `--mode=daily`, pero no se implementa hasta que haga falta de verdad.

## Estaciones que desaparecen del feed

Una estación que deja de aparecer en el snapshot diario **no se borra** de `stations`: se conserva su histórico y cualquier enlace directo (`/stations/{ideess}`) que alguien haya guardado o compartido. Su `last_seen_date` simplemente deja de actualizarse, y `stale_station_days` (`Config/config.php`, 10 días por defecto) decide cuándo dejar de mostrarla en los listados de búsqueda (`/stations/near`, `/stations/search`, `/stations/bbox`) sin dejar de servir su ficha si se pide por id.

## Búsqueda por radio sin extensión espacial

SQLite no trae R-tree/spatialite garantizado en el runtime `vercel-php`, así que `Models\Station::near()` filtra primero por un bounding-box en grados (usando los índices normales de `lat`/`lon`, reduce de ~11.500 candidatas a decenas/cientos) y solo sobre ese subconjunto ya pequeño aplica Haversine exacto en PHP. `/stations/bbox` (la vista del mapa) usa directamente el rectángulo sin el paso circular, porque ahí el área ya es rectangular por definición.

## Por qué el mapa usa Leaflet + clustering, no un motor WebGL

El radio de búsqueda normal es de 1 a 25 km, pero el mapa permite explorar libremente hasta ver toda España; en ese extremo, sin agrupar, serían miles de marcadores a la vez. La alternativa habitual para ese volumen es pasar a un motor WebGL (MapLibre GL/Mapbox), pero es sobre-ingeniería aquí: con `Leaflet.markercluster` (agrupa marcadores cercanos en un círculo numerado, técnica estándar de la industria) Leaflet aguanta bien miles de puntos sin cambiar de motor. Verificado en pruebas reales: con el zoom alejado sobre Bilbao/Basauri, el cluster agrupa correctamente las estaciones del área en vez de listarlas sueltas.

## Horario: heurístico, no estructurado

El campo `Horario` del feed es texto libre (`"L-D: 07:00-22:00"`, `"24H"`, `"L-V: 08:00-14:00 Y 15:00-20:00; S-D: 09:00-14:00"`). `Services/OpeningHours.php` lo parsea reconociendo:

- Bloques de días separados por `;` (no por coma, que aparece dentro de otros contextos del feed).
- Rangos de días (`L-V`, `S-D`) y días sueltos (`L`).
- Horario partido dentro de un mismo bloque, franjas separadas por la palabra `Y` (verificado con datos reales: patrón muy común, cierre a mediodía).
- `24H` como caso especial.

Verificado contra los 1.177 valores distintos de `horario_raw` de un snapshot real completo: el 100% se reconoce con estas reglas. Aun así, quien encuentre un patrón no reconocido no obtiene un estado inventado: `isOpenAt()` devuelve `null` y `describe()` cae al texto crudo del feed bajo "ver horario completo", nunca "Abierto"/"Cerrado" sin evidencia clara en el propio texto.

## Fuera de alcance de v1 (fases futuras, sin diseño)

- **Navegación turn-by-turn propia**: se usa un deep link a Google Maps (`https://www.google.com/maps/dir/?api=1&destination=lat,lon`, universal en iOS/Android/desktop) en vez de un motor de rutas propio (OSRM/Mapbox/OpenRouteService), que sería un proyecto en sí mismo.
- **Reseñas/estrellas de terceros**: evaluado Google Places (cuota gratuita mensual, pero exige API key + facturación activada en Google Cloud) y Trustpilot (peor opción: su API de lectura para negocios ajenos solo está detrás de un add-on de pago sin precio público, y tiene poca cobertura real de gasolineras). Si se retoma, la única opción viable es Google Places bajo demanda al abrir cada ficha (nunca en el cron de 11.500 estaciones), cacheado varios días.
- **Alertas de precio configurables por push**: diseño de datos claro (gasolinera + umbral propio de subida/bajada por usuario, comparando snapshot de ayer contra hoy tras el cron), pero Web Push real exige cifrado RFC 8291 y firma VAPID que la comunidad desaconseja reimplementar a mano; la librería estándar (`web-push-php`) requiere Composer, la única pieza que rompería la convención de PHP nativo sin dependencias del resto de la red de apps.
- **Mapa de calor de uso de la app** (distinto del mapa de calor de precio, que sí está en v1): requeriría trackear interacciones de cada usuario en una base de datos propia, con sus propias decisiones de privacidad no tomadas aún, y el día del lanzamiento no habría ningún dato real que mostrar.
- **Capa social** (valoraciones, cuentas de usuario).

## Uso local

```
php scripts/build-database.php --mode=backfill --days=14   # una vez, para tener histórico real
php scripts/build-database.php --mode=daily                # snapshot de hoy
php -S localhost:8000 dev-router.php
```

## Despliegue

`vercel.json` (mismo patrón que bizkaibus+: `api/shell.php` debe existir físicamente dentro de `api/` desde el primer commit, o el deploy falla con "pattern doesn't match any Serverless Functions"). El cron de `.github/workflows/rebuild-schedule.yml` ejecuta `--mode=daily`, comitea `data/gasolinera.sqlite` si cambió, y despliega con `vercel deploy --prod`; necesita los secrets `VERCEL_TOKEN`, `VERCEL_ORG_ID` y `VERCEL_PROJECT_ID` configurados en el repo de GitHub.
