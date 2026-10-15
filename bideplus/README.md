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
- Posición de buses (SIRI-VM, «Posición GPS» de Open Data Bizkaia): `https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions.xml` (GPS y hora del dato, ~1 actualización por minuto, ~250 vehículos). Es la única fuente: el feed de trip-updates no trae GPS, ni hora válida, ni retraso. Si no responde, la app usa el último dato guardado y, sin él, el horario oficial.

**Metro Bilbao** — el tiempo real llega del feed GTFS-Realtime del Consorcio de Transportes de Bizkaia (`metro-bilbao-trip-updates.pb`, [data.ctb.eus](https://data.ctb.eus/dataset/metro-bilbao-online), CC-BY 4.0): la hora prevista de cada tren en las estaciones que le quedan. `GtfsRealtimeClient` lo descarga y decodifica (sin librerías) con 15 s de caché y lo descarta si tiene más de 5 minutos. Aunque el horario estático sale del mismo CTB, un mismo `trip_id` del tiempo real no es el mismo tren del horario (se comprobó: los números se reutilizan entre calendarios), así que `TripUpdatesMatcher` asocia cada previsión a un tren del horario en esa estación: mismo orden de estaciones que su patrón y la menor diferencia de hora, como mucho 10 minutos. Una diferencia de menos de un minuto cuenta como «en hora». Sin previsión, la salida queda como horario programado.

Ambos feeds de bus se piden en cada consulta relevante con caché corta (~25s) en el directorio temporal, para no saturar el origen.

### Emparejamiento del feed con el horario

El `VehicleJourneyRef` del feed en vivo (p. ej. `trp_A3414_808_OP9LSEPT_54000_...`) es exactamente el `trip_id` del GTFS. Pero el ETL fusiona en un solo `service_journeys` las variantes de calendario de un mismo viaje (misma línea, número de viaje, recorrido y salida a menos de 90 s), así que el `id` guardado puede ser el de otra variante (`OP9LJIN` en vez de `OP9LSEPT`). La tabla `trip_aliases` guarda cada `trip_id` original con el viaje en que se fusionó, y `RealtimeMatcher` empareja primero por ahí (100 % de los buses del feed en las pruebas). Si una base de datos antigua no tiene la tabla, usa el emparejamiento anterior: `(line_id, trip_number)` más la salida más cercana dentro de `MATCH_TOLERANCE_SECONDS`, porque `trip_number` se repite en decenas de salidas a lo largo del día.

### Cómo se calcula la llegada

El feed GPS (`ctb-siri…/bizkaibus-vehicle-positions.xml`) **no publica retraso** (no trae `<Delay>`; el alternativo siempre dice `PT0S`), pero sí la posición GPS de cada bus, su próxima parada (`VisitNumber`) y la hora del dato. Además, los tiempos por parada del GTFS son aproximados (`timepoint=0`, interpolados a velocidad constante), así que "horario + retraso" no sirve: un bus puede ir 15-20 min por delante de ese horario en mitad del recorrido.

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

`k` sale de `data/pace-factors.json` (lo lee `ServicesPaceFactors`) por franja horaria y tipo de día (`laborable.punta_tarde`, `fin_semana.manana`…) y, cuando hay datos suficientes, por línea. Si no hay valor, `k = 1`, y siempre se limita a 0,6–1,2. El retraso que se muestra no sale de `k`: es la ETA menos la hora publicada de esa parada (ver «Bizkaibus en tres capas»).

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
ETA = T_gps + Σ tramos hasta la parada (tiempo aprendido del tramo; si no hay datos, proporción por largo × horario del tramo, o k × horario si no se conoce el largo)
```

El tiempo aprendido sale de `data/segment-times.json` (lo lee `Services\SegmentTimes`): la mediana de lo que tardan de verdad los buses en cada tramo entre dos paradas (`desde>hasta`, con ids de parada, así que lo comparten todas las líneas que pasan por él), por franja si hay datos y si no general. Hace falta un mínimo de 2 buses distintos por tramo: con las 12 755 medidas del seed, validación cruzada en dos mitades, bajar de 3 a 2 da un 17 % más de tramos cubiertos con el mismo error por tramo (0,39 min). Los tramos con tiempo aprendido son lo que más pesa en la precisión: con todos los tramos del camino aprendidos, el 93 % de las llegadas queda dentro de ±2 min y el 98 % dentro de ±3; con un tramo sin aprender baja al 76 % y con dos o más al 59 % (69 % y 49 % antes de la proporción por largo, descrita abajo). Sin el fichero, la fórmula es exactamente la de arriba.

**Tramos sin tiempo aprendido.** Para ellos no sirve un `k` único por línea y franja, porque el horario va interpolado a velocidad constante entre paradas: en ciudad (tramos de menos de 600 m) el bus tarda lo que dice el horario o más (proporción real/horario 1,0-1,1), y en autopista (más de 3 km) tarda la mitad (0,5). Por eso `calibrate-segments` calcula la mediana de tiempo real / horario por cubo de largo de tramo (menos de 300 m, 300-600, 600-1 000, 1 000-1 500, 1 500-3 000 y más de 3 000 m; el largo sale de `dist_m` de los pasos del GTFS) y franja, con las muestras de tramo individuales, y las guarda en `lengthRatios` de `data/segment-times.json`. Se usa la de la franja si tiene 20 muestras o más, y si no la general del cubo; sin largo del tramo (viajes sin `dist_m`) se vuelve a `k × horario`. `calibrate-segments` la valida por mitades de buses, igual que lo demás, y solo la guarda si baja el error. Medido: error medio por tramo 0,88 → 0,69 min (12 755 tramos); y contra las capturas del feed real del 14 oct por la tarde (las proporciones salen de muestras del 6 oct a mediodía, otro día y otra franja), un tramo sin aprender pasa del 69 % al 76 % dentro de ±2 min, dos o más del 49 % al 59 %, y a 10-20 min con algún tramo sin aprender del 23 % al 61 % (solo 77 llegadas). **Solo se aplica a Bizkaibus**: los ids de parada de otras redes pueden coincidir y estos tiempos se han medido solo con sus buses (Euskotren y el tranvía de Bilbao, que también usan SIRI, siguen con el cálculo de siempre).

```
php scripts/realtime-backtest.php calibrate-segments capturas            # solo informa
php scripts/realtime-backtest.php calibrate-segments capturas --write    # guarda data/segment-times.json
php scripts/realtime-backtest.php analyze capturas [tiempos.json] [--sin-tramos]
```

`calibrate-segments` valida igual que `calibrate`: aprende con la mitad de los buses y mide en la otra mitad; solo escribe si el error baja. El tiempo general de un tramo solo se guarda si hay datos de al menos dos franjas, para que lo medido a mediodía no se use en hora punta. `data/segment-samples-seed.jsonl.gz` son las primeras muestras (6 oct 2026, mediodía de laborable, 760 buses): el workflow empieza por ellas si la release aún no tiene `segment-samples.jsonl`.

### Precisión medida

`php scripts/eval-eta.php <carpeta con capturas del feed>` compara la ETA que muestra la app con el paso real por parada (el momento en que el feed cambia la próxima parada). Con 15 capturas del feed real (7 627 predicciones, 14 min, con una resolución de ±30 s en el paso real):

| La parada queda a | Error mediano | Dentro de ±1 min | Dentro de ±2 min | Dentro de ±3 min | Solo el horario, ±2 min |
|---|---|---|---|---|---|
| 0-3 min | 0,5 min | 80 % | 94 % | 96 % | 19 % |
| 3-6 min | 0,7 min | 63 % | 89 % | 95 % | 18 % |
| 6-10 min | 1,0 min | 49 % | 80 % | 90 % | 16 % |
| 10-20 min | 1,2 min | 41 % | 73 % | 89 % | 13 % |

Cada capa por separado, dentro de ±2 min, sobre las mismas capturas y los mismos pasos reales (`--sin-gps` quita el GPS a todos los buses; `--sin-feed` no da ningún bus a la app):

| La parada queda a | Capa 1, con GPS | Capa 2, solo parada registrada | Capa 3, sin ningún dato del bus | Horario publicado |
|---|---|---|---|---|
| 0-3 min | 94 % | 94 % | 42 % | 19 % |
| 3-6 min | 89 % | 84 % | 45 % | 18 % |
| 6-10 min | 80 % | 74 % | 43 % | 16 % |
| 10-20 min | 73 % | 67 % | 39 % | 13 % |

Sin sesgo (el error medio es 0). Por origen del dato, en llegadas en menos de 10 min: GPS en tramo corto, 92 % dentro de ±2 min; GPS en tramo de más de 10 min, 78 %; buses que en la captura real no tenían GPS utilizable y se calcularon por parada registrada, 78 % (un subconjunto distinto, con buses fuera de ruta, por eso queda por debajo del 83-93 % de la capa 2 medida quitando el GPS a todos). Las salvaguardas se miden contra este criterio: el tope de 45 min sobre el retraso, aplicado como «salir con horario oficial», bajaba la precisión (de 85,2 % a 84,1 % dentro de ±2 min), así que ahora solo deja de afirmar el retraso y mantiene la llegada estimada.

### Bizkaibus en tres capas: GPS, parada registrada, horario oficial

Cada salida usa la mejor capa que tenga datos fiables:

1. **En vivo con GPS:** el GPS se proyecta sobre el recorrido (hasta 50 m) y se estima la llegada tramo a tramo. Una parada que el bus ya dejó atrás sale como pasada y desaparece de la lista.
2. **Parada registrada (sin GPS utilizable):** si el bus no manda GPS o está fuera de la ruta pero el feed da su próxima parada y la hora del dato (menos de 10 min), el retraso sale de esa parada: hora del dato a mitad del tramo hasta la próxima, más los tiempos esperados hasta la parada pedida. Se usa si el tramo anterior→próxima parada dura 20 min o menos. Medido quitando el GPS a todos los buses (`eval-eta.php --sin-gps`), contra los pasos reales y frente al horario publicado en las mismas llegadas: con el bus en un tramo de hasta 3 min, 89 % dentro de ±2 min (horario: 18 %); de 3 a 6 min, 83 % (20 %); de 6 a 10 min, 86 % (15 %); de 10 a 20 min, 52 % (0 %); de más de 20 min, 7 % (1 %), donde no mejora al horario y por eso no se usa.
3. **Horario corregido:** sin datos en vivo, con datos viejos o sin hora del dato, la salida sale «Programado» y sin retraso, pero su llegada es la salida de cabecera + 1 min + lo que tardan los buses en llegar a esa parada (tiempos aprendidos), y no la hora publicada, que está interpolada. Si eso ya queda en el pasado (no se sabe si el bus ha pasado o va tarde) se deja el horario publicado: nunca se esconde una salida ni se deja una en «0 min». Medido sin ningún dato del bus (`eval-eta.php --sin-feed`): 36 % dentro de ±2 min frente al 19 % del horario publicado, y error mediano de 3-4 min frente a 7.

El retraso que se muestra en cada salida es la llegada estimada a esa parada menos su hora publicada, es decir, lo que se ve en pantalla (con el GPS y con la parada registrada). No es la diferencia del bus con el horario en el punto donde está: dentro de un tramo largo el horario oficial está interpolado a velocidad constante y esa diferencia cambia hasta 30 min en 14 min sin que el bus cambie de ritmo. Si el GPS y la parada registrada del mismo bus discrepan más de 6 min, no se fía de ninguna y sale el horario oficial: en una captura de 14 min del feed real, 3 buses cambiaron de retraso hasta 34 min con ambas medidas en desacuerdo, imposible para un bus real. Un retraso o adelanto de más de 45 min respecto a la hora publicada de la parada no se afirma (ni con GPS ni con parada registrada): con el feed real los retrasos llegan a +37 min y de ahí saltan a +111, +122, +306 y -61, y son buses en marcha cuyo viaje del feed no es el que hacen. La llegada estimada se mantiene, porque sale de la posición y de los tiempos por tramo y no de la etiqueta del viaje (medido: ocultarla bajaba la precisión), y la salida queda sin texto de retraso.

No hay más estimaciones (márgenes de adelanto, descarte por orden del feed sin hora): daban cifras que no se podían defender. Otras redes con SIRI (Euskotren, tranvías) no cambian.

Para ver desde el despliegue si la fuente responde: `/api/status` devuelve si el feed GPS de Open Data Bizkaia contesta, cuánto tarda y cuántos buses traen GPS, hora del dato y retraso.

### Cómo aprende, y cómo se mantiene dentro de los límites

El algoritmo no tiene reglas fijas para el ritmo de los buses: las aprende de los datos y solo adopta lo que mide mejor.

**Qué aprende.**
1. `k` por línea y franja horaria (`data/pace-factors.json`): cuánto más rápidos o lentos van los buses que el horario.
2. El tiempo de cada tramo entre dos paradas, por franja (`data/segment-times.json`): la mediana de lo que tardan de verdad. Es lo que más pesa en la precisión.

**El ciclo, sin intervención.**
1. **Captura** (`.github/workflows/pace-capture.yml`): cada hora, todo el día (también los nocturnos), captura el feed GPS unos 45 minutos. Son 24 capturas al día y 168 a la semana. De cada captura solo se guardan las muestras (cuánto tardó cada bus en cada tramo), no los XML.
2. **Acumulación:** las muestras van a los adjuntos `pace-samples.jsonl` y `segment-samples.jsonl` de la release `pace-data` (las releases no disparan despliegues). Se conservan 120 días y, como máximo, las 300 muestras más recientes de cada tramo (y 500 000 muestras de ritmo en total): el fichero no crece sin límite y el sistema olvida lo viejo, así que sigue los cambios (obras, horarios nuevos).
3. **Recalibrado** (cada día, 04:00 UTC): recalcula `k` y los tiempos por tramo con todo lo acumulado. Valida con una partición (aprende con la mitad de los buses y mide en la otra) y solo escribe el fichero si el error baja y el resultado es distinto. Entonces hace un commit y Vercel despliega. Si no mejora, no cambia nada.
4. **Medición:** cada captura calcula la precisión de las llegadas contra los pasos reales por parada (`scripts/eval-eta.php`) y la deja en el resumen del trabajo de GitHub Actions. Así se ve día a día cómo mejora el sistema con los datos.

**Qué se ha probado y descartado.** Cada idea se acepta solo si la medida mejora, con las capturas del feed real (7 627 predicciones, dentro de ±2 min):

| Idea | Resultado |
|---|---|
| Parada registrada hasta tramos de 20 min (antes 6) | Sin GPS: 79,8 % → 83,0 % dentro de ±2 min; con GPS no cambia | Se adopta |
| Horario corregido con los tiempos aprendidos (capa 3) | Sin ningún dato del bus: 18 % → 36 % dentro de ±2 min; con GPS, 83,5 % → 85,5 % | Se adopta |
| Tramos sin aprender: proporción por largo de tramo en vez de k × horario | Error medio por tramo 0,88 → 0,69 min (validación por mitades, 12 755 tramos); con capturas de otro día y otra franja, un tramo sin aprender 69 % → 76 % y dos o más 49 % → 59 % dentro de ±2 min; capa 3 36-39 % → 39-45 % | Se adopta |
| Franja horaria de cada tramo calculada a la hora a la que el bus llegará a él | Sin ninguna diferencia en las capturas (las franjas aprendidas solo tienen datos de mediodía) | Sin medir: se espera a datos de varias franjas |
| Tipo de día por calendario GTFS (festivos entre semana) | El GTFS solo trae días añadidos y un único festivo (12 oct), sin capturas | Sin medir |
| Reparto del tramo en la parada registrada distinto de la mitad (0,35-0,65) | La mitad es el óptimo: 83,0 % (0,45 y 0,5) frente a 82,1 % (0,35) y 81,4 % (0,65) | Sin cambio |
| Mínimo de 2 buses por tramo en vez de 3 | Se adopta: 17 % más de tramos cubiertos, mismo error por tramo (0,39 min) |
| Mantener la llegada estimada aunque el retraso no sea creíble (>45 min) | Se adopta: ocultarla bajaba de 85,2 % a 84,1 % |
| Mezclar lo aprendido con el horario cuando hay pocas muestras | Se descarta: 83,5 % → 83,2 % (peso 1) y hasta 80,0 % (peso 8) |
| Corregir la llegada con la velocidad del último minuto del bus | Se descarta: 92,5 % → 91,9 % y hasta 78,3 % con más peso |
| Rellenar los tramos sin aprender con la relación del propio viaje | Se descarta: 70 % → 67 % |
| Aprender tramos con una sola medida | No se adopta: +0,2 puntos, dentro del ruido |

**Límites, y por qué no se llega a ellos.** Las cifras de GitHub y Vercel son las de su documentación a fecha de hoy, de memoria y sin verificar desde aquí; conviene comprobarlas en «Usage limits» de GitHub Actions y en los límites de despliegue de Vercel.

| Recurso | Límite | Cómo se queda por debajo |
|---|---|---|
| Minutos de GitHub Actions | Gratis e ilimitados en repositorios públicos con runners estándar | El repositorio es público |
| Duración de un trabajo | 6 h | Una captura dura unos 55 min (45 de captura más preparar y subir); el trabajo tiene `timeout-minutes: 75`; el recalibrado, que carga las muestras en memoria, tiene 2 GB de tope en el script frente a los 7 GB del runner |
| Solape de trabajos | Un trabajo en marcha y uno en espera por grupo de concurrencia; los demás se cancelan | Todos los trabajos comparten `concurrency: pace-data` y van uno detrás de otro; el horario deja una hora entre inicios |
| Tamaño del fichero de muestras | 2 GiB por adjunto de una release | 120 días, 300 muestras por tramo (máximo teórico de unos 90 MB) y 500 000 muestras de ritmo (unos 75 MB): más de 10 veces por debajo del límite |
| Despliegues en Vercel | 100 al día en el plan gratuito | Como mucho 1 commit de recalibrado al día (solo si mejora) más el rebuild diario |
| Trabajos programados en repositorios sin actividad | Se pausan a los 60 días sin actividad | El repositorio tiene actividad continua; si se pausan, se reactivan desde la pestaña Actions |
| Carga sobre el origen de datos | No documentado | Una petición cada 45 s durante la captura al fichero público del feed, que se actualiza cada minuto |

Si dejan de llegar capturas, el resumen de precisión no aparece y GitHub avisa por correo de los trabajos fallidos. Para probarlo a mano: Actions → «Ritmo de los buses (k)» → Run workflow, con 3 minutos de captura. `php scripts/realtime-backtest.php samples <carpeta> <acumulado.jsonl>` y `segment-samples <carpeta> <tramos.jsonl>` hacen lo mismo en local.

**Lo que este método no puede dar.** No llega al 99 %. Mide contra el momento en que el feed cambia la próxima parada, con una resolución de ±30 s. El feed trae una posición por minuto. Hay semáforos, tráfico y esperas que nadie puede prever. Y el error crece con la distancia a la parada: 90 % dentro de ±2 min a 0-3 min, 61 % a 10-20 min. Lo que sí mejora con el tiempo es la cobertura de tramos aprendidos: con todos los del camino aprendidos, el 93 % de las llegadas queda dentro de ±2 min.

### Bancos de pruebas y precisión con el máximo de datos

**Los bancos de pruebas.** El algoritmo se prueba en tres niveles, más uno que mide cuánto mejora al darle datos:

| Banco | Qué comprueba | Cuándo corre |
|---|---|---|
| `tests/unit-test.php` | 132 comprobaciones sin servidor ni red sobre una base de datos en memoria: proyección del GPS, `T_pos`, ETA, pasillo de 50 m, tramos, las tres capas, parada pasada, discrepancia GPS / parada, tope de 45 min, tiempos por tramo, `trip_aliases`, `enrich()` | Cada commit y cada CI |
| `static-checks.py`, `data-integrity.php` | Sintaxis, ids, caché del service worker e integridad de las seis bases de datos | Cada commit, CI y antes de cada despliegue |
| `api-smoke.py`, `api-contract.py` | 245 peticiones a todas las rutas y más de 22 000 comprobaciones de la forma del JSON; que las salidas vengan ordenadas por llegada | Cada commit y cada CI |
| `ui-*.mjs` | Navegador headless en móvil y ordenador; falla con errores de consola o de red | Cada CI |
| `scripts/eval-eta.php` | **Precisión de lo que ve el usuario**, contra el momento real en que el feed cambia la próxima parada. Con `--sin-gps` mide la capa 2 y con `--sin-feed` la capa 3 | En cada captura, en el resumen del trabajo |
| `scripts/realtime-backtest.php` | `analyze` (error por posición), `calibrate` y `calibrate-segments` (aprender con la mitad de los buses y medir en la otra) | Recalibrado diario |
| `scripts/learning-curve.php <tramos.jsonl>` | **Curva de aprendizaje**: error y cobertura al aprender con más buses | A mano |

**Curva de aprendizaje, medida** (`php scripts/learning-curve.php`, con las 12 755 medidas iniciales, una sola sesión de laborable a mediodía; 381 buses apartados para probar). El error es por tramo, con lo que usa la app (lo aprendido si existe, y si no `k` × horario):

| Buses para aprender | Tramos aprendidos | Cobertura de tramos medidos | Error con lo aprendido + `k` | Error solo con `k` × horario |
|---|---|---|---|---|
| 16 | 278 | 1,2 % | 0,89 min | 0,89 min |
| 45 | 610 | 13,0 % | 0,84 min | 0,89 min |
| 92 | 1 001 | 32,2 % | 0,77 min | 0,89 min |
| 196 | 1 458 | 62,8 % | 0,62 min | 0,89 min |
| 379 | 1 916 | 77,5 % | 0,52 min | 0,89 min |

Con cada duplicación de buses, los tramos sin aprender se reducen aproximadamente un 40 %, y el error todavía no se ha estabilizado.

**Con el máximo de datos.** Las capturas son 24 al día y 168 a la semana, de unas 45 min: unas 126 horas de feed a la semana. Con topes de 120 días y 300 muestras por tramo, los tramos frecuentes irán alcanzando el tope y la cobertura quedará limitada por los tramos que los buses casi no recorren. La precisión depende de qué parte de las llegadas tiene todos los tramos de su camino aprendidos. Medido hoy, en llegadas a menos de 10 min: con todos aprendidos, el 93 % cae dentro de ±2 min y el 98 % dentro de ±3; con un tramo sin aprender, 70 % y 78 %; con dos o más, 49 % y 66 %. Hoy el 86 % de las llegadas tiene todo el camino aprendido. Combinando esos tres grupos:

| Llegadas con todo el camino aprendido | Dentro de ±2 min | Dentro de ±3 min | Estado |
|---|---|---|---|
| 86 % (hoy) | 90 % | 96 % | medido |
| 90 % | 91 % | 96 % | proyección |
| 95 % | 92 % | 97 % | proyección |
| 99 % | 93 % | 98 % | proyección |
| 100 % | 93 % | 98 % | techo medido |

**Viajes largos (10-20 min).** Medido hoy, por lo que falta para que llegue el bus y si todo su camino está aprendido (llegadas en vivo, 14 min de feed):

| El bus llega en | Todo el camino aprendido | Algún tramo sin aprender |
|---|---|---|
| 0-5 min | 96 % dentro de ±2 min, 99 % dentro de ±3 (n = 3 923) | 80 % y 88 % (n = 528) |
| 5-10 min | 87 % y 96 % (n = 1 811) | 59 % y 75 % (n = 369) |
| 10-20 min | 76 % y 93 % (n = 439) | 61 % y 79 % (n = 77, muestra pequeña) |

Con más datos, los viajes largos son los que más ganan, porque un camino largo atraviesa más tramos y es más probable que alguno no esté aprendido. Pero el error crece con la distancia aunque todo esté aprendido, porque cada tramo suma su variación: a 10-20 min el techo medido es el 76 % dentro de ±2 min y el 93 % dentro de ±3 (muestra pequeña). La llegada parte de la posición real del bus y no depende de si va retrasado respecto al horario; lo que no recoge es lo que aún no ha afectado a su posición (un corte, una obra), y los avisos de incidencias no entran en el cálculo.

Las proyecciones son aritmética sobre las tres precisiones medidas, suponiendo que lo que falta se reparte como hoy entre «un tramo» y «dos o más»; no son una medida. Para llegadas a 10-20 min la precisión es menor (73 % dentro de ±2 min hoy). **El techo es el 93 %, no el 99 %**: en llegadas con todo aprendido, el resto se debe al paso real medido con ±30 s, a una sola posición por minuto y a lo que nadie prevé (semáforos, tráfico, esperas). Para llegar más alto hace falta otra fuente de datos, no más capturas de esta. La rapidez con que sube la cobertura de tramos raros no se conoce: la tendencia está medida solo hasta 379 buses. El sistema la irá midiendo solo, porque cada captura escribe su precisión.

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
