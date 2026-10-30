# Bide+

Apps de horarios de transporte de Euskadi (Bizkaibus, Metro Bilbao, Euskotren, Renfe Cercanías y los tranvías de Bilbao y Vitoria-Gasteiz) servidas desde un único código: PHP nativo (API REST) + HTML/CSS/JS vanilla, datos reales de Open Data Bizkaia y del Consorcio de Transportes de Bizkaia (GTFS, SIRI y GTFS-Realtime), desplegable en Vercel.

## Arquitectura

- **`api/Networks/`** — una ficha por red (`bus.php`, `metro.php`, `euskotren.php`, `renfe.php`, `tranvia-bilbao.php`, `tranvia-vitoria.php`). Es el núcleo común: todo lo demás lee de aquí.
- **`data/<red>.sqlite`** — paradas, líneas, patrones de ruta y horarios, precompilados por red desde su GTFS oficial (`scripts/build-database.php --network=<red>`). Se reconstruyen a diario (`.github/workflows/rebuild-schedule.yml`).
- **`api/`** — API REST en PHP nativo, sin framework:
  - `index.php` — punto de entrada de la API (Vercel enruta `/api/*` aquí vía `vercel.json`)
  - `shell.php` — elige la página: el menú, el mapa o la app de una red
  - `Views/app-page.php` y `Views/app.php` — los datos (título, descripción, JSON-LD) y la plantilla de la app de cada red
  - `Core/` — Router, Request, Response, `Networks` (el registro de redes), `Config` (la red activa), Database (SQLite de solo lectura), Cache, Http
  - `Controllers/`, `Models/`, `Services/` — API, consultas y clientes de los feeds (GTFS-RT, SIRI, avisos)
- **`engine/<motor>/`** — cálculo de llegadas en directo, un motor por red (`engine` en la ficha de la red).
- **`js/`** — frontend estático, sin build step. `js/networks.js` se genera desde `api/Networks` (`php scripts/build-networks-js.php`).

## Todas las redes, un solo código

Cada red se describe en `api/Networks/<red>.php`:

| Clave | Para qué |
| --- | --- |
| `slug`, `order`, `db`, `kind` | `?red=<slug>`, posición en el menú, `data/<db>.sqlite`, tipo de vehículo (`bus`, `metro`, `train`, `tram`) |
| `brand` | carpeta de iconos, color, título de la cabecera y logotipo |
| `texts` | nombre, textos del menú y de la app, en castellano (`es`) y euskera (`eu`) |
| `seo`, `attribution` | título y descripción de la página, atribución de los datos |
| `features` | andenes, mapa de la línea, horario en texto, tipo de avisos (`lines`, `stops`, `network`)… |
| `live` | fuentes en directo: `vehicle-monitoring` (SIRI-VM), `trip-updates` (GTFS-RT) o ninguna (`[]`, horario oficial) |
| `siri`, `gtfs_rt`, `metro_alerts`, `renfe_alerts` | URLs de los feeds |
| `gtfs` | origen del GTFS, operador, lector de paradas y si su reconstrucción diaria es obligatoria |

De ahí salen la API, la página de cada app, el menú, la barra lateral, el sitemap, la reconstrucción diaria, el comprobador de producción y `js/networks.js`.

### Añadir una red

1. Copiar la ficha de una red parecida a `api/Networks/<red>.php` y cambiar sus datos.
2. Portada: iconos en `icons-<red>/` (`icon-192.png`, `icon-512.png`, `apple-touch-icon.png`) y sus colores en `style-app.css` (`html.is-<red>`, `.tile--<red>`).
3. `php scripts/build-database.php --network=<red>`, `php scripts/build-networks-js.php` y `php scripts/build-sitemap.php`.
4. `python tests/static-checks.py` avisa de lo que falte.

## Tres temas visuales

`api/Views/app-page.php` resuelve el título y las meta de Open Graph en servidor a partir de `?red=`, antes de que el HTML llegue al cliente — necesario porque los bots que generan la vista previa al compartir un enlace (WhatsApp, Telegram...) no ejecutan JavaScript. El resto de la personalización (tema, textos, iconos, manifest) se resuelve en el navegador: un script inline al principio de `<head>` (antes de cualquier `<link>` de estilos, para evitar parpadeo del tema equivocado) escribe (`document.write`) el `<title>`, `<link rel="manifest">`, `theme-color`, iconos y hoja de estilos correctos según `?red=` y `?tema=miamor`, antes de que el navegador empiece a pintar. No se usa `localStorage` para decidir el tema — cada carga de `/` sin el parámetro es siempre el tema normal, sin excepción.

- **`style-app.css`** — hoja única de Bizkaibus+, Metro+ y Euskotren+. Los colores de cada app son variables (`html.is-metro`, `html.is-euskotren`); cualquier cambio de diseño se hace una sola vez.
- **`style-miamor.css`** — el tema "mi amor": la misma app que la normal (`style-app.css` y el formato de Bide+) con sus rosas, el degradado de fondo y las tarjetas de cristal. Es el proyecto original, hecho para mi mujer por sus quejas sobre lo poco user-friendly que le parecía la app oficial de Bizkaibus. Sale igual en todas las redes y se activa con `?tema=miamor` o desde bideplusmiamor.vercel.app.
- **`manifest.json`**, **`manifest-metro.json`**, **`manifest-miamor.json`** — mismo contenido salvo tema, iconos y `theme_color`. El icono de instalación con el tema mi amor activo es siempre el corazón, en cualquiera de las dos redes.

## El modelo de calendario, y por qué cada operador lo rompe distinto

GTFS separa el patrón semanal (`calendar.txt`, opcional) de las excepciones puntuales por fecha (`calendar_dates.txt`). Bizkaibus y Metro Bilbao usan esta estructura de formas incompatibles entre sí, y tratarlas igual produjo dos bugs reales antes de corregirse:

- **Bizkaibus**: `calendar.txt` siempre tiene los días a cero — toda la información real de qué días circula un servicio viene de decenas de fechas puntuales en `calendar_dates.txt`. Antes se deducía un patrón semanal (dos fechas de un mismo día de la semana bastaban para «todos esos días» de todo el periodo) y el viaje se mostraba también en festivos entre semana y antes de que empezara su servicio: con el GTFS del 15 oct, 3 794 de 6 000 viajes tenían alguna fecha de más (el 8 dic en 3 103 de ellos). Ahora el calendario de cada viaje es **exacto dentro del periodo publicado** (`feed_start_date` a `feed_end_date`): un patrón semanal compacto (los días en que circula al menos la mitad de las veces) más las fechas sueltas, incluidas y excluidas, que lo corrigen (`exactCalendarForWindow`). La construcción lo comprueba de punta a punta con `auditExactCalendars` y debe imprimir «0 con diferencias contra el GTFS»; la misma comprobación se hizo aparte en Python contra el GTFS oficial. Fuera del periodo publicado, el patrón semanal sigue siendo la mejor suposición (la app avisa de que el horario no está publicado). Si la comprobación encontrara alguna diferencia, la construcción **se reconstruye sola en modo literal** (sin patrón semanal, solo las fechas exactas en que circula cada viaje, que no puede fallar) en vez de abortar; solo aborta si ni así cuadra. Probado contra el GTFS real, un cambio de temporada simulado (fechas desplazadas), un formato semanal con rangos de `calendar.txt` y un periodo corto: 0 diferencias en los cuatro.
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

Al seleccionar una línea, `GET /api/lines/{id}/live` (`RealtimeController::lineLive`) devuelve la ruta de cada patrón (paradas ordenadas, para dibujar la polyline) y los vehículos activos ahora mismo en esa línea. El marcador de cada bus se sitúa en la parada que da el feed (`StopPointRef`), que es la **próxima** parada del bus, no la última visitada. El frontend refresca esta llamada cada 25s mientras la línea esté abierta.

## Menú lateral: incidencias

Botón de menú (☰) en la cabecera → `GET /api/alerts`. En Bizkaibus, filtra en el cliente a las líneas en favoritos o a la que se esté consultando. En Metro+, no hay filtro por parada: `MetroAlertsClient` consume el endpoint JSON propio del CMS de Metro Bilbao, cuyo `station_id` pertenece a un sistema interno sin relación fiable con el `stop_id` del GTFS público, así que los avisos se muestran como lista global de la red — el propio texto del aviso suele nombrar la estación afectada.

## Datos en tiempo real

**Bizkaibus** — dos feeds SIRI en vivo, licencia CC-BY 4.0:
- Alertas de servicio (SIRI-SX): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml`
- Posición de buses (SIRI-VM, «Posición GPS» de Open Data Bizkaia): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions.xml` (GPS y hora del dato, ~1 actualización por minuto, ~250 vehículos). Es la única fuente: el feed de trip-updates no trae GPS, ni hora válida, ni retraso. Si no responde, la app usa el último dato guardado y, sin él, el horario oficial.

**Metro Bilbao** — el tiempo real llega del feed GTFS-Realtime del Consorcio de Transportes de Bizkaia (`metro-bilbao-trip-updates.pb`, [data.ctb.eus](https://data.ctb.eus/dataset/metro-bilbao-online), CC-BY 4.0): la hora prevista de cada tren en las estaciones que le quedan. `GtfsRealtimeClient` lo descarga y decodifica (sin librerías) con 15 s de caché y lo descarta si tiene más de 5 minutos. Aunque el horario estático sale del mismo CTB, un mismo `trip_id` del tiempo real no es el mismo tren del horario (se comprobó: los números se reutilizan entre calendarios), así que `TripUpdatesMatcher` asocia cada previsión a un tren del horario en esa estación: mismo orden de estaciones que su patrón y la menor diferencia de hora, como mucho 10 minutos. Una diferencia de menos de un minuto cuenta como «en hora». Sin previsión, la salida queda como horario programado.

Ambos feeds de bus se piden en cada consulta relevante con caché corta (~25s) en el directorio temporal, para no saturar el origen.

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
- **Un `git push` a la rama principal no despliega por Git** (`vercel.json` lo desactiva: los `.sqlite` del repositorio son viejos y pisarían los reconstruidos). El despliegue a producción lo hace siempre `rebuild-schedule.yml`, que reconstruye las bases con el GTFS de hoy: a diario, a mano y en cada cambio que llega a la rama principal.
- `.github/workflows/rebuild-schedule.yml` corre a diario a las 03:00 UTC: reconstruye las dos bases de datos desde el GTFS más reciente de cada operador y redespliega a producción. Cualquier cambio que los operadores publiquen — nuevo evento, corrección de horario, fin de una campaña especial — se refleja solo, sin tocar código, con un margen máximo de 24h.

## Licencia

El código, el diseño y los iconos de este proyecto se publican bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE): se pueden copiar y adaptar citando al autor, sin fines comerciales y compartiendo las obras derivadas con la misma licencia. Los datos de transporte son de sus operadores y mantienen sus propias licencias (véase «Atribución de datos»). Los nombres y logotipos de Bizkaibus, Metro Bilbao, Euskotren y Renfe son de sus titulares.

## Atribución de datos

- BizkaiBus+: Bizkaibus / Open Data Bizkaia (CC-BY 4.0).
- Metro+: horario y tiempo real de Metro Bilbao publicados por el Consorcio de Transportes de Bizkaia / [data.ctb.eus](https://data.ctb.eus/dataset/horario-metro-bilbao) (CC-BY 4.0).

Ambas aplicaciones son proyectos independientes, sin relación con Bizkaibus, Metro Bilbao S.A., el Consorcio de Transportes de Bizkaia ni la Diputación Foral de Bizkaia.

## El algoritmo

La carpeta `engine/` (la capa de cálculo de llegadas de Bizkaibus, Lurraldebus y Metro) y su documentación no están en este repositorio: viven en un repositorio privado y los workflows de GitHub Actions la traen con el secreto `ENGINE_REPO_TOKEN` antes de probar o desplegar. Para trabajar en local, clona el repositorio privado en `bideplus/engine`.
