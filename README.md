# BizkaiBus+

Versión mejorada de la app de Bizkaibus: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia (NeTEx + SIRI), desplegable en Vercel.

## Arquitectura

- **`data/bizkaibus.sqlite`** — paradas, líneas, patrones de ruta y horarios, precompilados una única vez desde el export NeTEx oficial (`scripts/build-database.php`). La app nunca parsea el XML crudo en producción.
- **`api/`** — API REST en PHP nativo, sin framework:
  - `index.php` — único punto de entrada (Vercel enruta `/api/*` aquí vía `vercel.json`)
  - `Core/` — Router, Request, Response, Database (SQLite de solo lectura), Cache
  - `Controllers/`, `Models/`, `Services/` — lógica de negocio, separada por capa
  - `Config/config.php` — URLs de los feeds SIRI, TTLs de caché
- **`index.html` / `style.css` / `js/`** — frontend estático, sin build step. Favoritos guardados en `localStorage` del navegador — no hay cuentas ni backend para esto, cada dispositivo tiene los suyos. Iconos SVG propios (sin emoji). Mapa en vivo por línea con [Leaflet](https://leafletjs.com/) + tiles de OpenStreetMap (cargados por CDN, ver `index.html`).

## Mapa en vivo por línea

Al seleccionar una línea, `GET /api/lines/{id}/live` (`RealtimeController::lineLive`) devuelve la ruta de cada patrón (paradas ordenadas, para dibujar la polyline) y los vehículos activos ahora mismo en esa línea. El feed SIRI-VM no da GPS continuo, solo parada + orden — así que el marcador de cada bus se sitúa en su última parada conocida real, nunca interpolado entre paradas. El frontend refresca esta llamada cada 25s mientras la línea esté abierta (`setInterval`, cancelado al cerrar/cambiar de línea).

## Menú lateral: incidencias de tus líneas favoritas

Botón de menú (☰) en la cabecera → `GET /api/alerts` (todas las alertas activas) filtradas en el cliente a las líneas que tengas en favoritos. Si una alerta referencia una línea que no está en nuestro export estático (puede pasar, dado que ese export está desactualizado — ver más abajo), se muestra su número tal cual en vez de inventar un código.

## Datos en tiempo real: dos feeds SIRI en vivo

- Alertas de servicio (SIRI-SX): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml`
- Posición/retraso de buses (SIRI-VM, mal nombrado "trip-updates" en origen): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-trip-updates.xml`

Ambos con licencia CC-BY 4.0 (atribución visible en la app). Se piden en cada consulta relevante con caché corta (~25s) en `/tmp` para no saturar el origen.

### Por qué el emparejamiento en tiempo real usa un margen de tolerancia

El `VehicleJourneyRef` del feed en vivo (p. ej. `trp_A3513_907_OP44LV_61500_...`) **no coincide exactamente** con el `id` de `service_journeys` del export estático — verificado con datos reales. Además, `trip_number` (segundo token) **no identifica una salida única**: es un id de vuelta/bloque de vehículo que se repite en decenas de horarios distintos a lo largo del día. La única clave fiable es `(line_id, trip_number, hora_de_salida_del_primer_parada)`, y aun así la hora del feed en vivo difiere en segundos (no bytes exactos) de la estática. Por eso `RealtimeMatcher` agrupa por `(line_id, trip_number)` y elige la coincidencia más cercana dentro de ±180s — verificado contra tráfico real (>85% de coincidencia).

## Limitación conocida: el export estático NeTEx está caducado

Verificado por cabecera HTTP `Last-Modified`: el ZIP NeTEx oficial (paradas/líneas/horarios en `https://ctb-netex.s3.eu-south-2.amazonaws.com/bizkaibus.zip`) no se actualiza desde el **24 de enero de 2025**, y todos sus calendarios de validez caducan como muy tarde el 13 de abril de 2025. No es un problema de esta descarga — es el estado real del origen.

Mitigación implementada: en vez de exigir que la fecha de hoy caiga dentro de la ventana de validez (que nunca ocurriría), `scripts/build-database.php` deriva de las asignaciones explícitas `DayTypeAssignment` (fechas concretas, no el `ValidDayBits` — ese campo está siempre vacío en este export) qué días de la semana corresponden a cada calendario, y lo trata como un patrón que se repite semanalmente. Combinado con los feeds SIRI en vivo como fuente de verdad para lo que realmente circula ahora. La UI muestra siempre "Horario base publicado: 2025-01-24" para ser honestos sobre el origen del dato. Además, `/api/lines/{id}/schedule-text` expone el horario oficial 2026 en texto libre (endpoint legado `GetLineasHorarios`) como referencia complementaria — no se parsea a datos estructurados porque no tiene granularidad por parada.

## Limitación conocida: "Detalle del bus" no incluye modelo/amenities

Ningún feed disponible (NeTEx ni SIRI) da marca, modelo o WiFi del vehículo — solo un `VehicleRef` (número de flota). El detalle del bus muestra lo real (línea, retraso, parada actual, próximas paradas) y omite deliberadamente specs inventadas.

## Búsqueda por zona/barrio (Getxo, Las Arenas, Romo...)

El export NeTEx no tiene ningún campo de municipio/localidad (verificado: cero etiquetas `Locality`/`Municipality`/`PostalAddress` en `stops.xml`) — cada parada solo tiene un nombre de calle y coordenadas. Buscar "Getxo" no encontraba nada aunque hay decenas de paradas allí, porque ninguna se llama literalmente "Getxo".

Solucionado con geocodificación inversa vía OpenStreetMap/Nominatim en el ETL (`scripts/build-database.php`): cada parada se resuelve a su barrio/zona/municipio real (p. ej. una parada en Santa Ana resuelve a "Erromo, Areeta / Las Arenas, Getxo" — confirmado contra una coordenada real). Para no hacer 2263 peticiones, las paradas se agrupan primero por coordenada redondeada a 2 decimales (~1741 → ~650 grupos únicos), y el resultado se cachea en `scripts/geocache.json` (commiteado — son datos derivados de una fuente externa real, no un secreto; volver a generarlo desde cero tarda ~12 minutos respetando el límite de 1 petición/segundo de Nominatim). Las búsquedas ahora comparan tanto el nombre de la parada como esta zona geocodificada.

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
- `data/bizkaibus.sqlite` debe estar commiteado en el repositorio (es el artefacto de build, no el XML crudo — ese nunca se sube).
- Antes de desplegar, comprueba que el rewrite `/api/*` preserva `$_SERVER['REQUEST_URI']` con la ruta original tal como se ha probado en local — es el único punto de la arquitectura que no se ha podido verificar sin un despliegue real.

## Atribución de datos

Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0).
