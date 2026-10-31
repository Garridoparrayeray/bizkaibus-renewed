# Montar Bide+ para otra red o para un ayuntamiento

Esta guía explica, paso a paso, cómo añadir una red de transporte a Bide+ o cómo sacar una versión propia para una ciudad («Getxo+», por ejemplo). Todo sale del mismo código: una red es **un archivo de configuración y sus iconos**.

## Qué hace falta

| Dato | ¿Obligatorio? | Para qué |
| --- | --- | --- |
| **GTFS** (un ZIP con paradas, líneas y horarios) | Sí | Horarios, paradas, buscador, mapa de la red y horarios sin conexión. Casi todos los operadores lo publican; en España, en el [Punto de Acceso Nacional](https://nap.transportes.gob.es/) (NAP). |
| **Directo de vehículos** (SIRI Vehicle Monitoring) | No | Llegadas en tiempo real con la posición de cada autobús. Usa el algoritmo de `engine/` (repositorio privado `bideplus-engine`). |
| **Horas previstas** (GTFS-Realtime Trip Updates) | No | Llegadas en tiempo real cuando el operador ya publica la hora prevista de cada vehículo (como Metro Bilbao). |
| **Incidencias** (SIRI Situation Exchange) | No | La pestaña Avisos y las notificaciones de incidencias. |

Sin directo, la app enseña el horario oficial y lo marca como «Solo horario».

## 1. Crear la red

```bash
cd bideplus
php scripts/new-network.php --slug=getxo --name="Getxo Bus+" --color=#E30613 \
    --gtfs=https://…/gtfs.zip --kind=bus --city=Getxo --operator="Getxoko Udala"
```

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

## 2. Revisar la configuración

Abre `api/Networks/<red>.php`. Lo que más conviene revisar:

- **Textos** (`texts`): el euskera y el inglés salen de una plantilla; corrígelos. `disclaimer` es el aviso de que el proyecto no es oficial.
- **`attribution`**: la frase de atribución que pide la licencia de los datos (por ejemplo, «Datos: Getxoko Udala (CC-BY 4.0)»). Sale bajo cada pantalla y en Info › Fuentes de datos.
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
| `texts.<idioma>` | `name`, `tile`, `switcher`, `subtitle`, `liveEmpty`, `disclaimer`; opcionales `tile_title` y `tile_city` (ficha en dos líneas). Si falta un texto en euskera o inglés, sale el castellano. |
| `seo` | Título y descripción para Google. |
| `features.platforms` | Estaciones con andenes: las salidas se separan por andén (Metro, Euskotren). |
| `features.headsign_from_last_stop` | El destino de cada viaje es su última parada, no el rótulo del GTFS. |
| `features.stop_hints` | En el buscador, cada parada dice hacia dónde van sus autobuses («hacia Gernika»), para distinguir las dos aceras. |
| `features.line_map` | Mapa de la línea en «Consultar horarios». |
| `features.schedule_text` | Botón «Ver horario oficial» con el horario en texto del operador (necesita `schedule_text.url`). |
| `features.trip_detail` | `vehicle`: ficha del vehículo en directo; `stops`: lista de paradas del viaje. |
| `features.alerts` | Cómo se piden las incidencias: `lines` (por línea favorita), `stops` (por estación, como Renfe) o `network` (todas las de la red). |
| `features.alerts_detail` | Las incidencias del menú lateral enseñan también su texto largo. |
| `live` | Fuentes en directo: `['vehicle-monitoring']` (SIRI con el algoritmo), `['trip-updates']` (GTFS-RT) o `[]` (horario oficial). |
| `engine` | Con `vehicle-monitoring`: el motor de `engine/` que se usa (`bizkaibus`, `lurraldebus`). |
| `siri` | Direcciones de los feeds SIRI: `vehicle_monitoring_url`, `alerts_url` (o `alerts_urls`, varias) y tiempos de caché. |
| `gtfs_rt` | Dirección del feed GTFS-Realtime y su tolerancia al emparejar viajes. |
| `direction_reference_stop_id` | Una parada central: cada salida se marca «hacia» o «desde» ella (los dos sentidos de Metro). |
| `hidden_line_codes` | Líneas que no se enseñan aunque estén en el GTFS. |
| `gtfs.source`, `gtfs.fallback_source` | De dónde se descarga el GTFS (y de dónde, si falla). `gtfs.sources`: varios GTFS que se juntan (Lurraldebus). |
| `gtfs.agency_id`, `gtfs.route_id_prefixes` | Para quedarse con un operador o unas líneas de un GTFS que trae más. |
| `gtfs.stops` | Cómo se leen las paradas: `generic` (cualquier GTFS), o los ajustes de `bus`, `metro`, `euskotren` y `renfe`. |
| `gtfs.skip_geocode` | No buscar el barrio de cada parada en OpenStreetMap. |
| `gtfs.required` | Si su reconstrucción diaria falla, se para el despliegue (para las redes principales). |
| `gtfs.feed_window`, `gtfs.merge_calendars` | Ajustes para GTFS con calendarios raros; normalmente no hacen falta. |

## 3. Iconos

Cambia los provisionales de `icons-<red>/` por los de la red:

- `icon-192.png` y `icon-512.png`, cuadrados;
- `apple-touch-icon.png`, de 180 × 180 y sin transparencia.

Si el logotipo de la cabecera es otra imagen (por ejemplo `glyph.png`), ponla en la misma carpeta y cambia `brand.logomark`.

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
   - `gsc_verification`, la verificación de Google Search Console (o vacío).
3. **Textos de Bide+** en `js/i18n.js` (castellano, euskera e inglés):
   - `bide.info.aboutText` (Sobre Bide+);
   - `menu.footer` y `bide.footer.text` (pie);
   - las preguntas frecuentes `bide.faq.*` (la versión en castellano está también en `api/Views/bide-components.php`, `BIDE_FAQ`);
   - las novedades: `BIDE_CHANGELOG` en `bide-components.php` y `bide.changelog.*`.
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
4. **Cron cada minuto** (por ejemplo, en cron-job.org): `https://<tu-dominio>/api/live/record?key=<BIDE_CRON_SECRET>`. Apunta por dónde pasa cada autobús, manda los avisos de llegada y, cada 5 minutos, las notificaciones de incidencias.

## 8. El directo y el algoritmo

- **Autobuses con GPS (SIRI Vehicle Monitoring)**: el algoritmo de `engine/<motor>/` empareja cada bus con su viaje del horario y calcula la llegada a cada parada con lo que tarda de verdad cada tramo. Cómo funciona y cuánto acierta: `docs/algoritmo.html`, en el repositorio `bideplus-engine`. Para una red nueva con SIRI se parte de un motor que ya existe, como se hizo con Lurraldebus a partir del de Bizkaibus: hay que adaptar cómo se reconoce cada viaje en el feed, y la captura (`pace-capture.yml`) para que aprenda sus tiempos por tramo.
- **Horas previstas (GTFS-Realtime)**: `engine/metro/TripUpdatesMatcher.php` empareja el feed con el horario. Sirve para cualquier operador que publique Trip Updates.
- **Sin directo**: el horario oficial, marcado «Solo horario».

## Lista de comprobación

- [ ] `php scripts/new-network.php …` y textos revisados en los tres idiomas.
- [ ] Atribución y aviso de «no oficial» correctos.
- [ ] Iconos de verdad en `icons-<red>/`.
- [ ] `build-database.php`, `build-sitemap.php`, `build-network-map.php` y `build-offline-data.php`.
- [ ] `python3 tests/static-checks.py` en verde.
- [ ] Probada en local: buscar una parada, abrir una línea, el mapa, Ajustes y Avisos.
- [ ] Si es una app propia: `site.php`, textos de Bide+, aviso legal y marca.
- [ ] Variables de entorno, secretos de GitHub y cron.
