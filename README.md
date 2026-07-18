# BizkaiBus+

Versión mejorada de la app de Bizkaibus: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia (GTFS + SIRI), desplegable en Vercel.

## Arquitectura

- **`data/bizkaibus.sqlite`** — paradas, líneas, patrones de ruta y horarios, precompilados una única vez desde el export GTFS oficial (`scripts/build-database.php`). La app nunca parsea el CSV crudo en producción.
- **`api/`** — API REST en PHP nativo, sin framework:
  - `index.php` — único punto de entrada (Vercel enruta `/api/*` aquí vía `vercel.json`)
  - `Core/` — Router, Request, Response, Database (SQLite de solo lectura), Cache
  - `Controllers/`, `Models/`, `Services/` — lógica de negocio, separada por capa
  - `Config/config.php` — URLs de los feeds SIRI, TTLs de caché
- **`index.html` / `style.css` / `js/`** — frontend estático, sin build step. Favoritos guardados en `localStorage` del navegador — no hay cuentas ni backend para esto, cada dispositivo tiene los suyos. Iconos SVG propios (sin emoji). Mapa en vivo por línea con [Leaflet](https://leafletjs.com/) + tiles de OpenStreetMap (cargados por CDN, ver `index.html`).

## Dos temas visuales, un solo código

`index.html`, `js/app.js`, `js/api.js` y todo `api/` son exactamente los mismos para los dos temas — solo cambia qué hoja de estilos, manifest e iconos se cargan. Un script inline al principio de `<head>` (antes de cualquier `<link>` de estilos, para evitar parpadeo del tema equivocado) resuelve el tema con esta prioridad: parámetro `?tema=` en la URL (si aparece, se guarda en `localStorage` y se limpia de la URL) → `localStorage.getItem('bb_theme')` → por defecto `'pro'`. Con eso, escribe (`document.write`) el `<title>`, `<link rel="manifest">`, `theme-color`, iconos y hoja de estilos correctos antes de que el navegador empiece a pintar.

- **`style.css`** — el tema "miamor": rosa, glassmorphism, muy redondeado. Es el tema original de esta app, sin tocar.
- **`style-pro.css`** — el tema público: plano, tipo Google Maps, sin `backdrop-filter`, radios de borde pequeños, azul (`#1967D2`) en vez de rosa. Mismos selectores que `style.css`, así que cualquier funcionalidad nueva (por ejemplo, cuando se integre Metro Bilbao) solo necesita sus reglas añadidas a los dos ficheros — la estructura HTML/JS no cambia por tema.
- **`manifest.json`** (profesional, por defecto) y **`manifest-miamor.json`** — mismo contenido salvo tema, iconos y `theme_color`.
- **`miamor.html`** — un redirector: guarda `bb_theme=miamor` en `localStorage` y manda a `/`. Basta con visitarlo una vez desde un dispositivo — a partir de ahí, `/` carga su tema automáticamente en ese dispositivo, mientras que cualquier otro visitante sigue viendo el tema profesional por defecto.

## Mapa en vivo por línea

Al seleccionar una línea, `GET /api/lines/{id}/live` (`RealtimeController::lineLive`) devuelve la ruta de cada patrón (paradas ordenadas, para dibujar la polyline) y los vehículos activos ahora mismo en esa línea. El feed SIRI-VM no da GPS continuo, solo parada + orden — así que el marcador de cada bus se sitúa en su última parada conocida real, nunca interpolado entre paradas. El frontend refresca esta llamada cada 25s mientras la línea esté abierta (`setInterval`, cancelado al cerrar/cambiar de línea).

## Menú lateral: incidencias de tus líneas favoritas

Botón de menú (☰) en la cabecera → `GET /api/alerts` (todas las alertas activas) filtradas en el cliente a las líneas que tengas en favoritos. Si una alerta referencia una línea que no está en nuestro export estático, se muestra su número tal cual en vez de inventar un código.

## Datos en tiempo real: dos feeds SIRI en vivo

- Alertas de servicio (SIRI-SX): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml`
- Posición/retraso de buses (SIRI-VM, mal nombrado "trip-updates" en origen): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-trip-updates.xml`

Ambos con licencia CC-BY 4.0 (atribución visible en la app). Se piden en cada consulta relevante con caché corta (~25s) en `/tmp` para no saturar el origen.

### Por qué el emparejamiento en tiempo real usa un margen de tolerancia

El `VehicleJourneyRef` del feed en vivo (p. ej. `trp_A3513_907_OP44LV_61500_...`) **no coincide exactamente** con el `id` de `service_journeys` del export estático — verificado con datos reales. Además, `trip_number` (segundo token) **no identifica una salida única**: es un id de vuelta/bloque de vehículo que se repite en decenas de horarios distintos a lo largo del día. La única clave fiable es `(line_id, trip_number, hora_de_salida_del_primer_parada)`, y aun así la hora del feed en vivo difiere en segundos (no bytes exactos) de la estática. Por eso `RealtimeMatcher` agrupa por `(line_id, trip_number)` y elige la coincidencia más cercana dentro de ±180s — verificado contra tráfico real (>85% de coincidencia).

## Fuente estática: GTFS, no NeTEx (migrado julio 2026)

La app usaba originalmente el export NeTEx oficial (`https://ctb-netex.s3.eu-south-2.amazonaws.com/bizkaibus.zip`), verificado congelado desde el **24 de enero de 2025** por cabecera `Last-Modified` — nunca se actualizó, así que cualquier cambio de recorrido posterior a esa fecha (como el desvío de verano 2026 de la línea A3526 por Elantxobe) era invisible para la app.

Bizkaibus/Lantik también publica un export **GTFS** (`https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bizkaibus.zip`), y ese sí se mantiene activo: su propio `feed_info.txt` declara `feed_start_date`/`feed_end_date` acotados a la temporada vigente (verificado: el fichero se actualizó el día anterior a comprobarlo). `scripts/build-database.php` ahora parsea este GTFS en vez del NeTEx — mismo esquema SQLite de salida, sin cambios en la API ni en el frontend. Volver a ejecutar el script periódicamente recoge lo que Lantik tenga publicado como temporada actual en cada momento.

Igual que con NeTEx, los campos de calendario semanal de `calendar.txt` están siempre a cero (mismo comportamiento vestigial) — el calendario real sale de las fechas explícitas de `calendar_dates.txt`, generalizadas a un patrón semanal recurrente por `computeWeekdayMask()` (sin cambios respecto a la lógica anterior, solo cambia de qué fichero lee las fechas).

Detalle importante del ETL: los patrones de ruta (`journey_patterns`) se derivan de la secuencia real de paradas de cada viaje, no del `shape_id` de GTFS — se comprobó con datos reales que dos viajes de la línea A3526 comparten `shape_id` pero tienen paradas distintas (uno incluye el desvío de Elantxobe, el otro no), así que agrupar por `shape_id` habría fusionado el desvío con la ruta normal.

Además, `/api/lines/{id}/schedule-text` expone el horario oficial 2026 en texto libre (endpoint legado `GetLineasHorarios`) como referencia complementaria — no se parsea a datos estructurados porque no tiene granularidad por parada.

## Limitación conocida: "Detalle del bus" no incluye modelo/amenities

Ningún feed disponible (GTFS ni SIRI) da marca, modelo o WiFi del vehículo — solo un `VehicleRef` (número de flota). El detalle del bus muestra lo real (línea, retraso, parada actual, próximas paradas) y omite deliberadamente specs inventadas.

## Búsqueda por zona/barrio (Getxo, Las Arenas, Romo...)

Ni el export NeTEx original ni el GTFS actual tienen campo de municipio/localidad (`stop_desc` viene vacío en todas las filas de `stops.txt`) — cada parada solo tiene un nombre de calle y coordenadas. Buscar "Getxo" no encontraba nada aunque hay decenas de paradas allí, porque ninguna se llama literalmente "Getxo".

Solucionado con geocodificación inversa vía OpenStreetMap/Nominatim en el ETL (`scripts/build-database.php`): cada parada se resuelve a su barrio/zona/municipio real (p. ej. una parada en Santa Ana resuelve a "Erromo, Areeta / Las Arenas, Getxo" — confirmado contra una coordenada real). Para no hacer una petición por cada una de las ~2335 paradas, se agrupan primero por coordenada redondeada a 2 decimales (~680 grupos únicos), y el resultado se cachea en `scripts/geocache.json` (commiteado — son datos derivados de una fuente externa real, no un secreto). Al reconstruir la base de datos solo se piden a Nominatim las coordenadas nuevas que no estén ya en caché (respetando su límite de 1 petición/segundo) — la mayoría de paradas se mantienen entre reconstrucciones, así que esto suele tardar segundos, no minutos. Las búsquedas ahora comparan tanto el nombre de la parada como esta zona geocodificada.

## Uso local

1. **PHP**: necesitas PHP 8.x con extensiones `sqlite3`, `pdo_sqlite`, `curl`, `zip`, `mbstring`, `openssl` habilitadas. En Windows sin winget/instalador: descarga el zip NTS de https://windows.php.net/download/, copia `php.ini-development` a `php.ini`, descomenta esas `extension=`, y si `curl` da error de certificado, añade `curl.cainfo` / `openssl.cafile` apuntando a un `cacert.pem` (https://curl.se/ca/cacert.pem).

2. **Generar la base de datos** (una vez, o cuando Bizkaibus republique el export):
   ```
   php scripts/build-database.php
   ```
   Por defecto descarga el ZIP oficial en vivo. Para usar una copia local: `--source="C:\ruta\bizkaibus.zip"`. Para saltarse la geocodificación (más rápido en desarrollo, pero sin búsqueda por zona): `--skip-geocode`.

3. **Levantar el servidor de desarrollo**:
   ```
   php -S localhost:8000 dev-router.php
   ```
   `dev-router.php` reproduce en local el rewrite `/api/*` que hace Vercel en producción.

   El servidor embebido de PHP es **de un solo hilo** por defecto: una petición lenta (por ejemplo, esperar al feed SIRI en directo) bloquea todas las demás mientras tanto — se nota como "la búsqueda se queda pillada" hasta que esa petición termina. Esto no ocurre en Vercel (cada petición ya tiene su propia instancia), pero para desarrollo local conviene arrancar con varios workers:
   ```
   set PHP_CLI_SERVER_WORKERS=4 && php -S localhost:8000 dev-router.php
   ```
   (en PowerShell: `$env:PHP_CLI_SERVER_WORKERS = "4"` antes del comando).

## Despliegue en Vercel

- Runtime: [`vercel-community/php`](https://github.com/vercel-community/php), configurado en `vercel.json`.
- `data/bizkaibus.sqlite` debe estar commiteado en el repositorio (es el artefacto de build, no el CSV crudo del GTFS — ese nunca se sube).
- Antes de desplegar, comprueba que el rewrite `/api/*` preserva `$_SERVER['REQUEST_URI']` con la ruta original tal como se ha probado en local — es el único punto de la arquitectura que no se ha podido verificar sin un despliegue real.

## Atribución de datos

Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0).
