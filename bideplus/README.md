# BizkaiBus+ / Metro+

Dos aplicaciones de horarios de transporte de Bizkaia — Bizkaibus y Metro Bilbao — servidas desde un único código: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia y del Consorcio de Transportes de Bizkaia (GTFS, SIRI y GTFS-Realtime), desplegable en Vercel.

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

- **`style-app.css`** — hoja única de Bizkaibus+, Metro+ y Euskotren+. Los colores de cada app son variables (`html.is-metro`, `html.is-euskotren`); cualquier cambio de diseño se hace una sola vez.
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

Al seleccionar una línea, `GET /api/lines/{id}/live` (`RealtimeController::lineLive`) devuelve la ruta de cada patrón (paradas ordenadas, para dibujar la polyline) y los vehículos activos ahora mismo en esa línea. El marcador de cada bus se sitúa en la parada que da el feed (`StopPointRef`), que es la **próxima** parada del bus, no la última visitada. El retraso que se muestra sale de la posición GPS (ver "Cómo se calcula la llegada"). El frontend refresca esta llamada cada 25s mientras la línea esté abierta.

## Menú lateral: incidencias

Botón de menú (☰) en la cabecera → `GET /api/alerts`. En Bizkaibus, filtra en el cliente a las líneas en favoritos o a la que se esté consultando. En Metro+, no hay filtro por parada: `MetroAlertsClient` consume el endpoint JSON propio del CMS de Metro Bilbao, cuyo `station_id` pertenece a un sistema interno sin relación fiable con el `stop_id` del GTFS público, así que los avisos se muestran como lista global de la red — el propio texto del aviso suele nombrar la estación afectada.

## Datos en tiempo real

**Bizkaibus** — dos feeds SIRI en vivo, licencia CC-BY 4.0:
- Alertas de servicio (SIRI-SX): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml`
- Posición de buses (SIRI-VM): `https://opendata.euskadi.eus/transport/moveuskadi/bizkaibus/siri_bizkaibus_vehicle_monitoring.xml` (con GPS), y como respaldo `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-trip-updates.xml` (mal nombrado "trip-updates" en origen; sin GPS)

**Metro Bilbao** — el tiempo real llega del feed GTFS-Realtime del Consorcio de Transportes de Bizkaia (`metro-bilbao-trip-updates.pb`, [data.ctb.eus](https://data.ctb.eus/dataset/metro-bilbao-online), CC-BY 4.0): la hora prevista de cada tren en las estaciones que le quedan. `GtfsRealtimeClient` lo descarga y decodifica (sin librerías) con 15 s de caché y lo descarta si tiene más de 5 minutos. Aunque el horario estático sale del mismo CTB, un mismo `trip_id` del tiempo real no es el mismo tren del horario (se comprobó: los números se reutilizan entre calendarios), así que `TripUpdatesMatcher` asocia cada previsión a un tren del horario en esa estación: mismo orden de estaciones que su patrón y la menor diferencia de hora, como mucho 10 minutos. Una diferencia de menos de un minuto cuenta como «en hora». Sin previsión, la salida queda como horario programado.

Ambos feeds de bus se piden en cada consulta relevante con caché corta (~25s) en el directorio temporal, para no saturar el origen.

### Emparejamiento del feed con el horario

El `VehicleJourneyRef` del feed en vivo (p. ej. `trp_A3414_808_OP9LSEPT_54000_...`) es exactamente el `trip_id` del GTFS. Pero el ETL fusiona en un solo `service_journeys` las variantes de calendario de un mismo viaje (misma línea, número de viaje, recorrido y salida a menos de 90 s), así que el `id` guardado puede ser el de otra variante (`OP9LJIN` en vez de `OP9LSEPT`). La tabla `trip_aliases` guarda cada `trip_id` original con el viaje en que se fusionó, y `RealtimeMatcher` empareja primero por ahí (100 % de los buses del feed en las pruebas). Si una base de datos antigua no tiene la tabla, usa el emparejamiento anterior: `(line_id, trip_number)` más la salida más cercana dentro de `MATCH_TOLERANCE_SECONDS`, porque `trip_number` se repite en decenas de salidas a lo largo del día.

### Cómo se calcula la llegada

El feed principal (`opendata.euskadi.eus`) **no publica retraso** (no trae `<Delay>`; el alternativo siempre dice `PT0S`), pero sí la posición GPS de cada bus, su próxima parada (`VisitNumber`) y la hora del dato. Además, los tiempos por parada del GTFS son aproximados (`timepoint=0`, interpolados a velocidad constante), así que "horario + retraso" no sirve: un bus puede ir 15-20 min por delante de ese horario en mitad del recorrido.

Por eso la llegada se calcula por posición:

```
posición = GPS proyectado sobre el tramo entre paradas más cercano (de 3 tramos atrás a 2 adelante de la próxima parada)
llegada  = hora del dato GPS + (horario en la parada destino − horario en esa posición)
```

Solo se usan diferencias de horario entre dos puntos del recorrido, no el horario absoluto. Se usa la hora del dato GPS y no "ahora" porque el feed se actualiza cada 1-2,5 min. Si el viaje aún no ha empezado (el feed asigna al bus su próximo viaje antes de salir), si el GPS está a más de `max(150 m, 15 % del tramo)` de la ruta o si el dato tiene más de 10 min, la salida se muestra como programada.

`scripts/realtime-backtest.php` mide el error con datos reales usando el `RealtimeMatcher` de verdad:

```
php scripts/realtime-backtest.php capture <carpeta> 40 45   # 40 min, una consulta cada 45 s (solo guarda si el feed cambió)
php scripts/realtime-backtest.php analyze <carpeta>
```

Compara, por minutos mostrados, lo que mostraba la app antes (horario + 0) con lo que muestra ahora, frente al momento real en que el GPS ve pasar al bus por la parada. `analyze <carpeta> --sin-k` repite la medida con k = 1.

### Factor de ritmo k

Los tiempos por parada del GTFS son aproximados (interpolados a velocidad constante), y en esperas largas los buses suelen llegar algo antes. `RealtimeMatcher::etaForStop` aplica un factor `k` a la resta «horario restante»:

```
ETA = T_gps + k × (T_destino − T_pos)
```

`k` sale de `data/pace-factors.json` (lo lee `ServicesPaceFactors`) por franja horaria y tipo de día (`laborable.punta_tarde`, `fin_semana.manana`…) y, cuando hay datos suficientes, por línea. Si no hay valor, `k = 1`, y siempre se limita a 0,6–1,2. No cambia el retraso que se muestra (`T_gps − T_pos`).

Para calibrarlo hay que capturar el feed en distintas franjas y días (cada captura solo se guarda si el feed cambió):

```
php scripts/realtime-backtest.php capture capturas/manana 120 45
php scripts/realtime-backtest.php calibrate capturas            # solo informa
php scripts/realtime-backtest.php calibrate capturas --write    # guarda data/pace-factors.json
```

`calibrate` calcula `k` como la mediana, entre buses, de (tiempo real restante / horario restante), lo encoge hacia 1 cuando hay pocos buses y exige un mínimo de 8 buses distintos por franja (25 buses y 5 vehículos distintos por línea). Valida con una partición: calibra con la mitad de los buses y mide en la otra mitad. Solo escribe el fichero si el error baja en esa mitad de prueba.

### Tiempos por tramo

En algunos tramos el horario oficial no se parece a la realidad: por ejemplo, el A3514 hacia Gernika tiene entre 34 y 49 minutos de horario entre Zabalburu y el peaje de Boroa (18 km de autopista), y el bus lo hace en unos 15. Un `k` por línea no lo arregla, porque en la misma línea los buses van más lentos que el horario en la ciudad y mucho más rápidos en la autopista. Por eso `RealtimeMatcher` suma el tiempo restante tramo a tramo (de parada a parada):

```
ETA = T_gps + Σ tramos hasta la parada (tiempo aprendido del tramo, o k × horario del tramo si no hay datos)
```

El tiempo aprendido sale de `data/segment-times.json` (lo lee `Services\SegmentTimes`): la mediana de lo que tardan de verdad los buses en cada tramo entre dos paradas (`desde>hasta`, con ids de parada, así que lo comparten todas las líneas que pasan por él), por franja si hay datos y si no general. Hace falta un mínimo de 3 buses distintos por tramo. Sin el fichero, la fórmula es exactamente la de arriba.

```
php scripts/realtime-backtest.php calibrate-segments capturas            # solo informa
php scripts/realtime-backtest.php calibrate-segments capturas --write    # guarda data/segment-times.json
php scripts/realtime-backtest.php analyze capturas [tiempos.json] [--sin-tramos]
```

`calibrate-segments` valida igual que `calibrate`: aprende con la mitad de los buses y mide en la otra mitad; solo escribe si el error baja. El tiempo general de un tramo solo se guarda si hay datos de al menos dos franjas, para que lo medido a mediodía no se use en hora punta. `data/segment-samples-seed.jsonl.gz` son las primeras muestras (6 oct 2026, mediodía de laborable, 760 buses): el workflow empieza por ellas si la release aún no tiene `segment-samples.jsonl`.

**Se hace solo.** El workflow `.github/workflows/pace-capture.yml` captura el feed en directo unos 25 minutos varias veces al día (punta de mañana, mañana, mediodía, punta de tarde y noche entre semana; mañana y tarde del sábado y mañana del domingo) y acumula las muestras en los adjuntos `pace-samples.jsonl` y `segment-samples.jsonl` de la release `pace-data` (60 días; las releases no disparan despliegues). Cada día calcula `k` y los tiempos por tramo con todo lo acumulado y, solo si el error baja en la mitad de prueba y el resultado cambia, hace un commit de `data/pace-factors.json` y `data/segment-times.json`. Para probarlo a mano: Actions → «Ritmo de los buses (k)» → Run workflow, con 3 minutos de captura. Los trabajos programados de GitHub se pausan si el repositorio pasa 60 días sin actividad. `php scripts/realtime-backtest.php samples <carpeta> <acumulado.jsonl>` y `segment-samples <carpeta> <tramos.jsonl>` hacen lo mismo en local.

## Fuente estática: GTFS

Ambas redes se generan desde su export GTFS oficial — Bizkaibus desde el feed de Lantik/CTB (activo, con `feed_info.txt` acotado a la temporada vigente), Metro Bilbao desde el GTFS que publica el Consorcio de Transportes de Bizkaia (`ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip`, el mismo origen que su tiempo real; sin `feed_info.txt`, manejado como opcional en el ETL), con el NAP como respaldo si no responde.

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

## Licencia

El código, el diseño y los iconos de este proyecto se publican bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE): se pueden copiar y adaptar citando al autor, sin fines comerciales y compartiendo las obras derivadas con la misma licencia. Los datos de transporte son de sus operadores y mantienen sus propias licencias (véase «Atribución de datos»). Los nombres y logotipos de Bizkaibus, Metro Bilbao, Euskotren y Renfe son de sus titulares.

## Atribución de datos

- BizkaiBus+: Bizkaibus / Open Data Bizkaia (CC-BY 4.0).
- Metro+: horario y tiempo real de Metro Bilbao publicados por el Consorcio de Transportes de Bizkaia / [data.ctb.eus](https://data.ctb.eus/dataset/horario-metro-bilbao) (CC-BY 4.0).

Ambas aplicaciones son proyectos independientes, sin relación con Bizkaibus, Metro Bilbao S.A., el Consorcio de Transportes de Bizkaia ni la Diputación Foral de Bizkaia.
