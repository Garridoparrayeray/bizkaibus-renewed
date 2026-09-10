# BizkaiBus+ / Metro+

Dos aplicaciones de horarios de transporte de Bizkaia — Bizkaibus y Metro Bilbao — servidas desde un único código: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia y Open Data Metro Bilbao (GTFS + SIRI), desplegable en Vercel.

## Arquitectura

- **`data/bizkaibus.sqlite`** y **`data/metrobilbao.sqlite`** — paradas, líneas, patrones de ruta y horarios, precompilados una única vez por red desde su export GTFS oficial (`scripts/build-database.php`). La app nunca parsea el CSV crudo en producción.
- **`api/`** — API REST en PHP nativo, sin framework:
  - `index.php` — punto de entrada de la API (Vercel enruta `/api/*` aquí vía `vercel.json`)
  - `shell.php` — el HTML de la aplicación. Vive dentro de `api/` porque el runtime PHP de Vercel solo reconoce como función servible lo que está físicamente en esa carpeta; un rewrite en `vercel.json` hace que `/` apunte aquí
  - `Core/` — Router, Request, Response, Config (resuelve la red activa), Database (SQLite de solo lectura, conexión cacheada por red), Cache, Http
  - `Controllers/`, `Models/`, `Services/` — lógica de negocio, separada por capa
  - `Config/config.php` (bus) y `Config/metro.php` — URLs de los feeds SIRI y del endpoint de avisos de Metro Bilbao, TTLs de caché, referencia de estación para calcular sentido de circulación
- **`js/`** — frontend estático, sin build step. Favoritos guardados en `localStorage` del navegador (claves separadas por red) — no hay cuentas ni backend para esto, cada dispositivo tiene los suyos. Iconos SVG propios. Mapa en vivo por línea con [Leaflet](https://leafletjs.com/) + tiles de OpenStreetMap (solo Bizkaibus).

## Dos redes, un solo código

`api/shell.php`, `js/app.js`, `js/api.js` y todo `api/Controllers`/`Models`/`Services` son exactamente los mismos ficheros para bus y metro. Lo único que cambia es qué base de datos, config, hoja de estilos y manifest se cargan — resuelto por un único parámetro en la URL, `?red=metro` (por defecto, sin él, es Bizkaibus). `Core\Config::set()` carga `Config/config.php` o `Config/metro.php` una vez al arrancar cada petición; el resto del backend pregunta a `Config::current()`.

Rutas que solo existen para bus (Metro Bilbao no las tiene, ni feed que las alimente): `/lines/{id}/live`, `/vehicles/{tripKey}` (mapa y posición en vivo, SIRI-VM), `/lines/{id}/schedule-text` (horario legado en texto libre). `/alerts` sí se registra para las dos redes, pero `AlertsController` decide internamente qué cliente usar (`SiriAlertsClient` para bus, `MetroAlertsClient` para metro — formatos y semántica de datos completamente distintos entre operadores).

## Tres temas visuales

`api/shell.php` resuelve el título y las meta de Open Graph en servidor a partir de `?red=`, antes de que el HTML llegue al cliente — necesario porque los bots que generan la vista previa al compartir un enlace (WhatsApp, Telegram...) no ejecutan JavaScript. El resto de la personalización (tema, textos, iconos, manifest) se resuelve en el navegador: un script inline al principio de `<head>` (antes de cualquier `<link>` de estilos, para evitar parpadeo del tema equivocado) escribe (`document.write`) el `<title>`, `<link rel="manifest">`, `theme-color`, iconos y hoja de estilos correctos según `?red=` y `?tema=miamor`, antes de que el navegador empiece a pintar. No se usa `localStorage` para decidir el tema — cada carga de `/` sin el parámetro es siempre el tema normal, sin excepción.

- **`style-pro.css`** — tema por defecto de Bizkaibus, verde, plano.
- **`style-metro.css`** — tema por defecto de Metro+, rojo. Mismos selectores que `style-pro.css`, así que cualquier funcionalidad nueva se añade a los dos.
- **`style.css`** — el tema "mi amor": rosa, glassmorphism. Es el proyecto original, hecho para mi mujer por sus quejas sobre lo poco user-friendly que le parecía la app oficial de Bizkaibus. Es un tema compartido por **las dos redes** — activarlo no depende de si estás en bus o en metro, solo de `?tema=miamor` en la URL. Un corazón discreto, fijo en la esquina inferior de la pantalla, cambia entre el tema normal y este.
- **`manifest.json`**, **`manifest-metro.json`**, **`manifest-miamor.json`** — mismo contenido salvo tema, iconos y `theme_color`. El icono de instalación con el tema mi amor activo es siempre el corazón, en cualquiera de las dos redes.

## El modelo de calendario, y por qué cada operador lo rompe distinto

GTFS separa el patrón semanal (`calendar.txt`, opcional) de las excepciones puntuales por fecha (`calendar_dates.txt`). Bizkaibus y Metro Bilbao usan esta estructura de formas incompatibles entre sí, y tratarlas igual produjo dos bugs reales antes de corregirse:

- **Bizkaibus**: `calendar.txt` siempre tiene los días a cero — toda la información real de qué días circula un servicio viene de decenas de fechas puntuales en `calendar_dates.txt` que sí forman un patrón semanal recurrente genuino (p. ej. "todos los lunes de julio a septiembre"). Generalizar esas fechas a un `weekday_mask` es correcto y necesario aquí.
- **Metro Bilbao**: la mayoría de sus `service_id` no tienen fila en `calendar.txt` en absoluto, y cuando existen solo en `calendar_dates.txt`, suele ser con **una única fecha** — cada día de un evento como Aste Nagusia es su propio `service_id` puntual, no un patrón semanal. Generalizar esa fecha única a "este día de la semana, siempre" hacía que el servicio especial apareciera cualquier domingo del año, meses después de terminar el evento, y a la vez volvía indistinguible su horario nocturno ampliado del servicio normal de cualquier otro domingo.

`scripts/build-database.php` recibe un flag (`$generalizeSingleDatesToWeekday`, `true` solo para bus) que decide el comportamiento por red. Para metro, un `service_id` sin fila en `calendar.txt` guarda sus fechas puntuales como inclusiones exactas en la tabla `service_calendar_exceptions` (`available=1`), con `weekday_mask=0` — solo activo esas fechas concretas. Las consultas de horario (`ServiceJourney::upcomingAtStop()`, `timetableForLine()`) comprueban esta tabla como alternativa al patrón semanal, no solo como exclusión.

La misma tabla también resuelve el caso de exclusión pura: un servicio de obras que corre "todos los domingos" pero el operador excluye dos domingos sueltos por cambio de planificación (`exception_type=2` en el GTFS original) — sin esto, `weekday_mask` no puede representar "esta fecha en concreto no, aunque el patrón la cubra".

### Fusión de viajes: no mezclar lo que no es lo mismo viaje

GTFS repite el mismo viaje real una vez por cada variante de calendario en que circula. Guardarlas todas por separado infla la base de datos ~4,5× de lo necesario, así que `processStopTimes()` las fusiona: agrupa por `(routeId, tripNumber, patternKey)` y luego por proximidad de hora de salida (ventana de 90s), haciendo OR de las máscaras semanales del grupo.

El problema apareció cuando dos viajes que **no** eran variantes del mismo servicio — un tren de obras de domingo y un tren de Aste Nagusia, ese mismo domingo — coincidían por casualidad dentro de esa ventana de 90s y se fusionaban como si fueran uno solo, heredando el de obras la máscara "todos los días" del otro. `calendarGroupKeyFor()` añade una clave extra a la agrupación, solo para metro, que impide fusionar entre sí: (a) cualquier `service_id` con `from_date`/`to_date` explícito en `calendar.txt` (campañas de obras/desvíos), y (b) cualquier `service_id` sin patrón semanal cuya única presencia sea una fecha puntual (eventos de un solo día).

Esta regla **no se aplica a bus**: 94 de sus 105 calendarios tienen `from_date`/`to_date` por cómo Lantik publica sus temporadas (metadato sin relación con campañas especiales, a diferencia de metro) — aplicarla ahí deshace casi toda la fusión legítima entre variantes reales del mismo viaje (43803 trips → 43705 journeys en vez de ~6673; ~153MB en vez de ~24MB, por encima del límite de 100MB de Vercel). Bus tiene una señal fuerte para esto que metro no tiene: `trip_number`, extraído por regex del propio `trip_id` (`trp_A123_456_...`).

## Panel de andén y sentido de circulación (Metro+)

`ServiceJourney::upcomingAtStop()` acepta un `referenceStopId` opcional (en Metro+, siempre Abando) que añade una columna `direction` a cada salida: compara el `seq_order` de la parada consultada contra el de la parada de referencia, dentro del mismo `journey_pattern`. Es lo que permite agrupar las salidas en dos columnas — hacia Abando / sentido contrario — como un panel físico de andén, en vez de una lista única donde ambos sentidos se mezclan.

Se descartó explícitamente `direction_id` de GTFS como fuente: en el feed de Metro Bilbao es un binario tosco que no distingue las ramas de una red que se bifurca (Basauri/Etxebarri por un lado, Plentzia/Kabiezes/Larrabasterra/etc. por otro) — la comparación por posición dentro del patrón es estrictamente más correcta aquí.

## Mapa en vivo por línea (solo Bizkaibus)

Al seleccionar una línea, `GET /api/lines/{id}/live` (`RealtimeController::lineLive`) devuelve la ruta de cada patrón (paradas ordenadas, para dibujar la polyline) y los vehículos activos ahora mismo en esa línea. El feed SIRI-VM no da GPS continuo, solo parada + orden — así que el marcador de cada bus se sitúa en su última parada conocida real, nunca interpolado entre paradas. El frontend refresca esta llamada cada 25s mientras la línea esté abierta.

## Menú lateral: incidencias

Botón de menú (☰) en la cabecera → `GET /api/alerts`. En Bizkaibus, filtra en el cliente a las líneas en favoritos o a la que se esté consultando. En Metro+, no hay filtro por parada: `MetroAlertsClient` consume el endpoint JSON propio del CMS de Metro Bilbao, cuyo `station_id` pertenece a un sistema interno sin relación fiable con el `stop_id` del GTFS público, así que los avisos se muestran como lista global de la red — el propio texto del aviso suele nombrar la estación afectada.

## Datos en tiempo real

**Bizkaibus** — dos feeds SIRI en vivo, licencia CC-BY 4.0:
- Alertas de servicio (SIRI-SX): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml`
- Posición/retraso de buses (SIRI-VM, mal nombrado "trip-updates" en origen): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-trip-updates.xml`

**Metro Bilbao** — no publica un feed SIRI equivalente; solo el endpoint JSON de avisos ya mencionado. Sin tiempo real de posición de trenes.

Ambos feeds de bus se piden en cada consulta relevante con caché corta (~25s) en el directorio temporal, para no saturar el origen.

### Por qué el emparejamiento en tiempo real usa un margen de tolerancia

El `VehicleJourneyRef` del feed en vivo (p. ej. `trp_A3513_907_OP44LV_61500_...`) **no coincide exactamente** con el `id` de `service_journeys` del export estático — verificado con datos reales. Además, `trip_number` (segundo token) **no identifica una salida única**: es un id de vuelta/bloque de vehículo que se repite en decenas de horarios distintos a lo largo del día. La única clave fiable es `(line_id, trip_number, hora_de_salida_del_primer_parada)`, y aun así la hora del feed en vivo difiere en segundos de la estática. `RealtimeMatcher` agrupa por `(line_id, trip_number)` y elige la coincidencia más cercana dentro de la tolerancia definida en `MATCH_TOLERANCE_SECONDS` — verificado contra tráfico real (>85% de coincidencia). Prefiere calcular el ETA por posición real ("ahora + tiempo programado restante desde la última parada confirmada") antes que por retraso plano; si esa estimación se desvía más de `POSITION_SANITY_SECONDS` de lo esperable, cae al retraso plano.

## Fuente estática: GTFS

Ambas redes se generan desde su export GTFS oficial — Bizkaibus desde el feed de Lantik/CTB (activo, con `feed_info.txt` acotado a la temporada vigente), Metro Bilbao desde su Open Data propio (`cms.metrobilbao.eus`, sin `feed_info.txt`, manejado como opcional en el ETL).

Detalle importante del ETL, común a ambas redes: `streamStopTimesByTrip()` agrupa `stop_times.txt` completo en memoria por `trip_id` antes de generar nada — verificado con datos reales que el mismo `trip_id` puede reaparecer en bloques no contiguos del fichero en ambos operadores. Asumir contigüidad (una versión anterior del script lo hacía) producía patrones de recorrido truncados que colisionaban por casualidad con trips no relacionados, mostrando el mismo destino repetido dos veces con recorridos de longitud distinta.

Los patrones de ruta (`journey_patterns`) se derivan de la secuencia real de paradas de cada viaje, no del `shape_id` de GTFS — se comprobó con datos reales de Bizkaibus que dos viajes de la misma línea pueden compartir `shape_id` pero tener paradas distintas (uno con un desvío estacional, el otro sin él), así que agrupar por `shape_id` habría fusionado el desvío con la ruta normal.

`/api/lines/{id}/schedule-text` (solo bus) expone el horario oficial en texto libre (endpoint legado `GetLineasHorarios`) como referencia complementaria — no se parsea a datos estructurados porque no tiene granularidad por parada.

## Limitación conocida: "Detalle del bus" no incluye modelo/amenities

Ningún feed disponible (GTFS ni SIRI) da marca, modelo o WiFi del vehículo — solo un `VehicleRef` (número de flota). El detalle del bus muestra lo real (línea, retraso, parada actual, próximas paradas) y omite deliberadamente specs inventadas.

## Búsqueda por zona/barrio (solo Bizkaibus)

Ni el GTFS de Bizkaibus tiene campo de municipio/localidad (`stop_desc` viene vacío en todas las filas de `stops.txt`) — cada parada solo tiene un nombre de calle y coordenadas. Buscar "Getxo" no encontraba nada aunque hay decenas de paradas allí, porque ninguna se llama literalmente "Getxo".

Solucionado con geocodificación inversa vía OpenStreetMap/Nominatim en el ETL: cada parada se resuelve a su barrio/zona/municipio real. Se agrupan primero por coordenada redondeada (~clusters de 1km) y el resultado se cachea en `scripts/geocache.json` (commiteado — son datos derivados de una fuente externa real, no un secreto). Al reconstruir la base de datos solo se piden a Nominatim las coordenadas nuevas que no estén ya en caché, respetando su límite de 1 petición/segundo. Metro Bilbao no necesita esto: sus 42 estaciones ya tienen nombre real de localidad/barrio en el propio GTFS.

La pista "hacia X" en los resultados de búsqueda (`SearchController::addDirectionHints()`) solo se calcula para Bizkaibus — ninguna de las 42 estaciones de Metro+ comparte nombre, así que ahí esa pista no desambigua nada.

## Uso local

1. **PHP**: PHP 8.x con extensiones `sqlite3`, `pdo_sqlite`, `curl`, `zip`, `mbstring`, `openssl` habilitadas. En Windows sin winget/instalador: descarga el zip NTS de https://windows.php.net/download/, copia `php.ini-development` a `php.ini`, descomenta esas `extension=`, y si `curl` da error de certificado, añade `curl.cainfo` / `openssl.cafile` apuntando a un `cacert.pem` (https://curl.se/ca/cacert.pem).

2. **Generar las bases de datos** (al menos una vez por red, o cuando el operador republique el export):
   ```
   php scripts/build-database.php --network=bus
   php scripts/build-database.php --network=metro
   ```
   Por defecto descarga el ZIP oficial en vivo de cada red. Para usar una copia local: `--source="C:\ruta\al.zip"`. Para saltarse la geocodificación (solo aplica a bus): `--skip-geocode`.

3. **Levantar el servidor de desarrollo**:
   ```
   php -S localhost:8000 dev-router.php
   ```
   `dev-router.php` reproduce en local los rewrites de Vercel: `/api/*` → `api/index.php`, `/` → `api/shell.php`.

   El servidor embebido de PHP es de un solo hilo por defecto — una petición lenta (esperar al feed SIRI en directo) bloquea las demás mientras tanto. En Vercel no ocurre (cada petición tiene su propia instancia), pero en local conviene arrancar con varios workers:
   ```
   set PHP_CLI_SERVER_WORKERS=4 && php -S localhost:8000 dev-router.php
   ```
   (PowerShell: `$env:PHP_CLI_SERVER_WORKERS = "4"` antes del comando).

## Despliegue

- Runtime: `vercel-php@0.9.0`, configurado en `vercel.json`. Solo reconoce como función servible el PHP que está físicamente dentro de `api/` — por eso el shell del frontend vive en `api/shell.php` y no en la raíz.
- `data/bizkaibus.sqlite` y `data/metrobilbao.sqlite` deben estar commiteados (son el artefacto de build, no el CSV crudo del GTFS — ese nunca se sube).
- **Un `git push` a la rama principal no despliega nada por sí solo.** El único disparador es `workflow_dispatch` manual desde GitHub Actions, o el cron diario.
- `.github/workflows/rebuild-schedule.yml` corre a diario a las 03:00 UTC: reconstruye las dos bases de datos desde el GTFS más reciente de cada operador y redespliega a producción. Cualquier cambio que los operadores publiquen — nuevo evento, corrección de horario, fin de una campaña especial — se refleja solo, sin tocar código, con un margen máximo de 24h.

## Atribución de datos

- BizkaiBus+: Bizkaibus / Open Data Bizkaia (CC-BY 4.0).
- Metro+: Metro Bilbao / Open Data Metro Bilbao (metrobilbao.eus).

Ambas aplicaciones son proyectos independientes, sin relación con Bizkaibus, Metro Bilbao S.A. ni la Diputación Foral de Bizkaia.
