# Montar Bide+ para otra red o para un ayuntamiento

Esta guía explica, paso a paso, cómo añadir una red de transporte a Bide+ o cómo sacar una versión propia para una ciudad («Getxo+», por ejemplo). Todo sale del mismo código: una red es **un archivo de configuración y sus iconos**.

## Qué hace falta

| Dato | ¿Obligatorio? | Para qué |
| --- | --- | --- |
| **GTFS** (un ZIP con paradas, líneas y horarios) | Sí | Horarios, paradas, buscador, mapa de la red y horarios sin conexión. Casi todos los operadores lo publican; en España, en el [Punto de Acceso Nacional](https://nap.transportes.gob.es/) (NAP). |
| **Directo de vehículos** (SIRI Vehicle Monitoring) | No | Llegadas en tiempo real con la posición de cada autobús. Usa el algoritmo de `engine/` (repositorio privado `bideplus-engine`). |
| **Horas previstas o retrasos** (GTFS-Realtime Trip Updates) | No | Llegadas en tiempo real cuando el operador ya publica la hora prevista o el retraso de cada vehículo (Metro Bilbao, Euskotren, Renfe, Tuvisa, Dbus). |
| **Posiciones** (GTFS-Realtime Vehicle Positions) | No | Los vehículos en el mapa de la línea (Renfe, Metro, Tuvisa, Dbus). |
| **Incidencias** (GTFS-Realtime Service Alerts o SIRI Situation Exchange) | No | La pestaña Avisos y las notificaciones de incidencias. |

Sin directo, la app enseña el horario oficial y lo marca como «Solo horario».

## 1. Crear la red

```bash
cd bideplus
php scripts/new-network.php --slug=getxo --name="Getxo Bus+" --color=#E30613 \
    --gtfs=https://…/gtfs.zip --kind=bus --city=Getxo --operator="Getxoko Udala" \
    --trip-updates=https://…/trip_updates.pb --vehicle-positions=https://…/vehicle_positions.pb \
    --alerts=https://…/alerts.pb
```

Con los feeds GTFS-Realtime la red sale ya en directo (retrasos, vehículos en el mapa e incidencias); sin ellos, con el
horario oficial. No hace falta escribir código: todo lo de la red está en su archivo de configuración.

El script:

- crea `api/Networks/getxo.php` con todo rellenado: textos en castellano, euskera e inglés, colores calculados desde `--color` y funciones básicas;
- copia unos iconos provisionales en `icons-getxo/`;
- regenera `js/networks.js` y `style-networks.css`, que salen de la configuración.

| Opción | Qué es |
| --- | --- |
| `--slug` | El nombre interno: va en las direcciones (`/?red=getxo`). Minúsculas, números y guiones. |
| `--name` | El nombre de la app, con su «+». |
| `--color` | El color principal, en hexadecimal (`#E30613`). |
| `--gtfs` | La dirección del ZIP del GTFS. |
| `--kind` | `bus`, `metro`, `tram` o `train`. Cambia los textos («el próximo autobús») y el tipo de parada en Google. |
| `--city`, `--operator` | Para los textos: «Horarios del autobús de Getxo», «sin relación con Getxoko Udala». |
| `--trip-updates`, `--vehicle-positions`, `--alerts` | Opcionales: las direcciones de los feeds GTFS-Realtime del operador (horas previstas o retrasos, posiciones e incidencias). |
| `--matching` | Cómo se empareja el directo con el horario: `trip` (por defecto; el feed usa los viajes del horario, como Renfe, Tuvisa o Dbus) o `stops` (por paradas y hora, como Metro). |

## 2. Revisar la configuración

Abre `api/Networks/<red>.php`. Lo que más conviene revisar:

- **Textos** (`texts`): el euskera y el inglés salen de una plantilla; corrígelos. `disclaimer` es el aviso de que el proyecto no es oficial.
- **`attribution`**: la frase de atribución que pide la licencia de los datos (por ejemplo, «Datos: Getxoko Udala (CC-BY 4.0)»). Sale bajo cada pantalla y en Info › Fuentes de datos. En euskera e inglés va traducida en `texts.eu.attribution` y `texts.en.attribution`.
- **Colores** (`brand`): `tile_color` (ficha del menú), `swatch_color` (cuadradito en Favoritos y Avisos) y `palette` (los colores de la app). Si los cambias, ejecuta `php scripts/build-networks-css.php`.

### Todas las claves

| Clave | Qué hace |
| --- | --- |
| `slug`, `order`, `db`, `kind` | Nombre en las direcciones, posición en el menú, nombre de la base de datos (`data/<db>.sqlite`) y tipo de vehículo. |
| `brand.icons` | Carpeta de iconos (`icons-<red>/`). |
| `brand.theme_color` | Color de la barra del navegador y de la pantalla de parada. |
| `brand.title` | Título de la cabecera en dos partes: `['TRANVÍA', 'VITORIA']`. |
| `brand.logomark` | El logotipo de la cabecera (una imagen o un SVG). |
| `brand.tile_color`, `brand.swatch_color`, `brand.palette` | Colores; ver arriba. Sin paleta, la app usa la de Bizkaibus (verde). |
| `texts.<idioma>` | `name`, `tile`, `switcher`, `subtitle`, `liveEmpty`, `disclaimer`; opcionales `tile_title` y `tile_city` (ficha en dos líneas; también el título y la ciudad de la cabecera de la app, en mayúsculas). Los tres idiomas llevan las mismas claves (lo comprueba `tests/code-quality.php`); `attribution` en castellano es la clave `attribution` de la ficha. |
| `seo` | Título y descripción para Google. |
| `features.platforms` | Estaciones con andenes: las salidas se separan por andén (Metro, Euskotren). |
| `features.headsign_from_last_stop` | El destino de cada viaje es su última parada, no el rótulo del GTFS. |
| `features.stop_hints` | En el buscador, cada parada dice hacia dónde van sus autobuses («hacia Gernika»), para distinguir las dos aceras. |
| `features.line_map` | Mapa de la línea en «Consultar horarios». |
| `features.schedule_text` | Botón «Ver horario oficial» con el horario en texto del operador (necesita `schedule_text.url`). |
| `features.trip_detail` | `vehicle`: ficha del vehículo en directo; `stops`: lista de paradas del viaje. |
| `features.alerts` | Cómo se piden las incidencias: `lines` (por línea favorita), `stops` (por estación, como Renfe) o `network` (todas las de la red). |
| `features.alerts_detail` | Las incidencias del menú lateral enseñan también su texto largo. |
| `live` | Fuentes en directo: `['vehicle-monitoring']` (SIRI con el algoritmo), `['trip-updates']` (GTFS-RT), y además `'vehicle-positions'` si el operador publica dónde está cada tren (los trenes en el mapa de la línea), o `[]` (horario oficial). |
| `engine` | Con `vehicle-monitoring`: el motor de `engine/` que se usa (`bizkaibus`, `lurraldebus`, `bilbobus`; los dos últimos heredan del primero). |
| `siri` | Direcciones de los feeds SIRI: `vehicle_monitoring_url`, `alerts_url` (o `alerts_urls`, varias) y tiempos de caché. |
| `gtfs_rt` | Direcciones de los feeds GTFS-Realtime (`trip_updates_url`; con `vehicle-positions`, `vehicle_positions_url`; y `alerts_url` para las incidencias) y cómo se empareja: `matching => 'stops'` (por defecto, por paradas y hora, con `match_tolerance_seconds`) o `'trip'` (por `trip_id`, cuando el feed usa los mismos que el horario, como Renfe). |
| `direction_reference_stop_id` | Una parada central: cada salida se marca «hacia» o «desde» ella (los dos sentidos de Metro). |
| `hidden_line_codes` | Líneas que no se enseñan aunque estén en el GTFS. |
| `map` | El mapa de la red (`/mapa`), opcional: `label` (nombre en el mapa, si no es el de la app sin el «+»), `colors` (color de cada línea en el plano del operador, por código, cuando el GTFS no los trae bien) y `splits` (redes que publican varias líneas como una sola: para cada línea del mapa, su `code`, `name`, los nombres de sus dos extremos en `ends` y la línea de la app a la que enlaza en `line`; Metro Bilbao separa «MB» en L1 y L2). |
| `gtfs.source`, `gtfs.fallback_source` | De dónde se descarga el GTFS (y de dónde, si falla). `gtfs.sources`: varios GTFS que se juntan (Lurraldebus). |
| `gtfs.agency_id`, `gtfs.route_id_prefixes` | Para quedarse con un operador o unas líneas de un GTFS que trae más. |
| `gtfs.stops` | Cómo se leen las paradas: `generic` (cualquier GTFS), o los ajustes de `bus`, `metro`, `euskotren` y `renfe`. |
| `gtfs.skip_geocode` | No buscar el barrio de cada parada en OpenStreetMap. |
| `gtfs.headsign_pattern` | Si el rótulo del GTFS lleva más cosas que el destino: una expresión regular cuyo primer grupo es el destino (Bilbobus: `/\(([^)]+)\)\s*$/` para «PT-Semana / (PLAZA BIRIBILA)»). El rótulo que no encaja, y cualquier viaje sin rótulo, va hacia su última parada. |
| `gtfs.required` | Si su reconstrucción diaria falla, se para el despliegue (para las redes principales). |
| `gtfs.feed_window` | Cada viaje con las fechas exactas del periodo publicado (el de `feed_info.txt` o, si no lo trae, el que cubren sus calendarios) y una comprobación contra el GTFS: si no cuadra, se reconstruye con fechas literales. Para GTFS con servicios de días sueltos (Bizkaibus, Renfe, Euskotren); las redes nuevas lo llevan. |
| `gtfs.merge_calendars` | Ajuste para GTFS con calendarios raros (Metro); normalmente no hace falta. |

## 3. Iconos

Cambia los provisionales de `icons-<red>/` por los de la red:

- `icon-192.png` y `icon-512.png`, cuadrados;
- `apple-touch-icon.png`, de 180 × 180 y sin transparencia.

Si el logotipo de la cabecera es otra imagen (por ejemplo `glyph.png`), ponla en la misma carpeta y cambia `brand.logomark`.

Bilbobus, Tuvisa, Dbus y Álavabus guardan además su dibujo en SVG (`icon.svg`, y `glyph.svg` en negro):
sirven de plantilla para redibujar los PNG o para una red nueva con el mismo estilo (marca blanca y «+» sobre su color).

## 4. Construir los datos

```bash
php scripts/build-database.php --network=<red>   # descarga el GTFS y crea data/<db>.sqlite
php scripts/build-sitemap.php                    # las páginas de paradas y líneas para Google
php scripts/build-network-map.php                # el mapa de la red (/mapa)
php scripts/build-offline-data.php               # los horarios sin conexión
```

Algunas fuentes piden clave: el NAP necesita la variable `NAP_API_KEY`. La primera vez, buscar el barrio de cada parada en OpenStreetMap tarda (como mucho 300 paradas nuevas por vez); se guarda en `scripts/geocache.json`.

## 5. Probarla en local

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8011 dev-router.php
# abre http://127.0.0.1:8011/?red=<red>
python3 tests/static-checks.py
BASE_URL=http://127.0.0.1:8011 python3 tests/run-all.py   # toda la batería (necesita Chrome)
```

`static-checks.py` avisa si falta algo: un icono, un texto o archivos generados sin regenerar.

## 6. Una app solo para tu ciudad

Para que la app sea **solo** de una ciudad:

1. **Redes**: deja en `api/Networks/` solo las suyas, y en `data/` solo sus bases de datos. El menú, la barra lateral, el buscador, los avisos y el mapa salen de esa carpeta.
2. **`api/Config/site.php`**:
   - `name`, `url` y `locale`;
   - `default_network`, la red que se abre en «/» y que no lleva `?red=` en sus direcciones;
   - `author`, `author_links`, `source_url` y `contact_email` (sugerencias y errores, y firma de las notificaciones);
   - `gsc_verification`, la verificación de Google Search Console (o vacío);
   - `donate_url`, la página de Ko-fi de la tarjeta «Apoya Bide+» de Info (o vacío, y la tarjeta no sale).
3. **Textos de Bide+** en `js/i18n.js` (castellano, euskera e inglés):
   - `bide.info.aboutText` (Sobre Bide+);
   - `menu.footer` y `bide.footer.text` (pie);
   - las preguntas frecuentes `bide.faq.*` (la versión en castellano está también en `api/Views/bide-components.php`, `BIDE_FAQ`);
   - las novedades: `Changelog::ENTRIES` en `api/Services/Changelog.php` y `bide.changelog.*`.
4. **Aviso legal** (`api/Views/legal.php`): el autor, el contacto y la lista de apps salen solos de la configuración; revisa los párrafos de licencia, responsable y analíticas.
5. **Marca**:
   - el logotipo «BIDE+» está en `icons-bide-rojo/` y en las cabeceras;
   - los manifests son `manifest-bide.json` (nombre y colores de la app instalable) y `manifest-miamor.json`;
   - el tema «mi amor» se puede quitar: `style-miamor.css` y `js/theme.js`.

## 7. Publicarla

1. **Vercel**: un proyecto nuevo con la carpeta `bideplus` como raíz. `vercel.json` ya trae las rutas, las cabeceras de seguridad y el entorno de PHP.
2. **Variables de entorno** en Vercel:

   | Variable | Para qué |
   | --- | --- |
   | `UPSTASH_REDIS_REST_URL`, `UPSTASH_REDIS_REST_TOKEN` | Almacén Upstash Redis: registro de pasos de los buses, avisos de llegada y notificaciones. Sin él la app funciona, pero sin notificaciones. |
   | `BIDE_CRON_SECRET` | La clave de `/api/live/record` (cron). |
   | `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | Opcionales: las claves de las notificaciones. Si no están, se crean solas y se guardan en el almacén. |

3. **GitHub Actions**: `rebuild-schedule.yml` reconstruye cada día las bases de datos con el GTFS nuevo y despliega; `check-production.yml` comprueba la web publicada. Necesitan los secretos de Vercel, `NAP_API_KEY` si alguna red usa el NAP y `ENGINE_REPO_TOKEN` para traer el algoritmo. En `check-production.yml`, cambia las paradas de ejemplo por paradas de tu red.
4. **Cron cada minuto** (por ejemplo, en cron-job.org): `https://<tu-dominio>/api/live/record?key=<BIDE_CRON_SECRET>`. Apunta por dónde pasa cada autobús de todas las redes con motor (una llamada para todas), manda los avisos de llegada y, cada 5 minutos, las notificaciones de incidencias.

## 8. El directo y el algoritmo

- **Autobuses con GPS (SIRI Vehicle Monitoring)**: el algoritmo de `engine/<motor>/` empareja cada bus con su viaje del horario y calcula la llegada a cada parada con lo que tarda de verdad cada tramo. Cómo funciona y cuánto acierta: `docs/algoritmo.html`, en el repositorio `bideplus-engine`. Para darle el motor a una red con SIRI (`'live' => ['vehicle-monitoring']` y su bloque `siri`, con un feed y su reserva o uno por operador en `vehicle_monitoring_urls`):

  ```bash
  php engine/bizkaibus/tools/new-engine.php <red>
  ```

  Crea `engine/<motor>/` (sus cinco clases, que heredan las de Bizkaibus, y su carpeta de datos aprendidos) y pone `'engine'` en la ficha. Lo demás va solo: la captura (`pace-capture.yml`) en cuanto el feed trae vehículos, el recalibrado diario, y las horas estimadas en los horarios sin conexión y en el mapa de la red en cuanto ha aprendido algo. `tests/engine-test.php` comprueba que cada red con motor tiene sus clases, sus datos en su carpeta y su nombre en el emparejador.
- **Horas previstas (GTFS-Realtime)**: con `matching => 'trip'`, `Services/TripDelayMatcher.php` usa el viaje del horario que trae cada vehículo y su retraso (también si solo lo da por parada, como Tuvisa o Dbus); con `'stops'`, `Services/TripUpdatesMatcher.php` lo empareja por paradas y hora. Los dos viven en la app: no aprenden nada. Sirve para cualquier operador que publique Trip Updates.
- **Posiciones (GTFS-Realtime)**: `Services/VehiclePositions.php` pone cada vehículo en el mapa de su línea.
- **Incidencias (GTFS-Realtime)**: `GtfsRealtimeClient::fetchAlerts` lee `gtfs_rt.alerts_url` y da las vigentes.
- **Sin directo**: el horario oficial, marcado «Solo horario».
- **Guardar el directo para aprender de él** (`'capture' => 'gtfs-rt'`, como Renfe): la captura guarda su GTFS-Realtime, recortado a los viajes de la red, sin que la app lo use todavía.

## Lista de comprobación

- [ ] `php scripts/new-network.php …` y textos revisados en los tres idiomas.
- [ ] Atribución y aviso de «no oficial» correctos.
- [ ] Iconos de verdad en `icons-<red>/`.
- [ ] `build-database.php`, `build-sitemap.php`, `build-network-map.php` y `build-offline-data.php`.
- [ ] Con SIRI de vehículos: `php engine/bizkaibus/tools/new-engine.php <red>`.
- [ ] `python3 tests/static-checks.py` en verde.
- [ ] Probada en local: buscar una parada, abrir una línea, el mapa, Ajustes y Avisos.
- [ ] Si es una app propia: `site.php`, textos de Bide+, aviso legal y marca.
- [ ] Variables de entorno, secretos de GitHub y cron.
