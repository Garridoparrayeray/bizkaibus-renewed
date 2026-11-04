# Cómo hacer cada cosa

Recetas cortas, con las órdenes, para lo que se hace a menudo. Cada una enlaza la guía que lo explica a fondo. Las
órdenes se lanzan desde `bideplus/`, salvo las que empiezan por `.github/`.

Todo cambio sigue el mismo camino: rama `centro-de-pruebas` → *pull request* a `master` → pruebas en verde (CI) →
fusionar. **Fusionar es publicar**: el flujo «Rebuild schedule data» se lanza solo con cada cambio en `bideplus/` y
despliega en Vercel. Si el cambio toca `engine/`, primero la *pull request* del repositorio del algoritmo.

## Lo que va solo, sin tocar nada

| Qué | Cuándo | Quién lo hace |
| --- | --- | --- |
| Horarios de todas las redes (GTFS oficial) | cada día a las 03:00 UTC, y con cada cambio fusionado | `rebuild-schedule.yml`: base de datos, mapa de la red, horarios sin conexión, *sitemap* y despliegue |
| «Ver horario oficial» por periodos | con los horarios | Bizkaibus, el texto del operador; las demás, sacado de su GTFS |
| Directo (retrasos, vehículos en el mapa) | en cada consulta; un feed vacío se vuelve a mirar a los 10 minutos | la app, con la caché de cada red |
| Incidencias y notificaciones | cada minuto | el cron (`/api/live/record`) |
| Captura y aprendizaje del motor | sin parar mientras el feed trae vehículos; recalibrado a las 04:00 UTC | `pace-capture.yml`, para cada red con `engine` (y Renfe, que solo guarda) |
| Horas estimadas sin conexión y en el mapa | con los horarios, en cuanto el motor de la red ha aprendido algo | `build-offline-data.php` y `build-network-map.php` |
| Aviso de versión nueva | al publicar una novedad nueva | el cron, una vez por móvil |
| Aviso a los buscadores (IndexNow) | con cada despliegue | `scripts/indexnow.php`: el inicio, el mapa y cada app en los tres idiomas |

Una red nueva entra en todo eso por estar en `api/Networks/`: no hay listas de redes en ningún otro sitio.

## Añadir una red con GTFS (una orden)

```bash
php scripts/new-network.php --slug=<red> --name="<Nombre>+" --color=#RRGGBB --gtfs=<url del GTFS> --kind=bus --city="<Ciudad>" --operator="<Operador>"
python3 tests/static-checks.py
```

La primera orden lo deja todo listo: su ficha (con «Nuevo» unos días), un icono provisional con su inicial y su «+»
sobre su color, su base de datos, el mapa de la red, los horarios sin conexión (ya sale en Ajustes para descargarla) y
el *sitemap*. Con sus feeds GTFS-Realtime (`--trip-updates=`, `--vehicle-positions=`, `--alerts=`) sale ya en directo.
Después: revisar los textos en los tres idiomas de `api/Networks/<red>.php` y probarla en local
(`php -S 127.0.0.1:8011 dev-router.php` y abrir `/?red=<red>`). Si el GTFS no trae un destino útil en los viajes, pon
`features.headsign_from_last_stop => true`. Desde ahí, sus horarios se actualizan solos cada día. A fondo:
[nueva-red.md](nueva-red.md).

## «Nuevo» en una red o una función nueva

Nada que hacer: `new-network.php` pone en la ficha `'added'` (el día que llega) y su ficha del menú lleva «Nuevo» los
días de `new_days` (`api/Config/site.php`, 30). Para una función nueva (un botón, una tarjeta), añade
`data-new-since="AAAA-MM-DD"` a su elemento: lleva «Nuevo» los mismos días (`js/new-badge.js`) y se quita sola.

## Darle tiempo real (GTFS-Realtime)

En su ficha, `'live' => ['trip-updates', 'vehicle-positions']` y el bloque `gtfs_rt` con `trip_updates_url`,
`vehicle_positions_url` y, si publica incidencias, `alerts_url`. Con `'matching' => 'trip'` si los viajes del feed son
los del GTFS (Tuvisa, Dbus, Álavabus, Renfe); si no, `'stops'` (por paradas y hora, como Metro). Para comprobarlo, una
parada con salidas próximas debe dar alguna con `"status":"live"`:

```bash
curl -s "http://127.0.0.1:8011/api/stops/<parada>/departures?red=<red>"
```

## Darle el motor (feed SIRI de vehículos)

Con `'live' => ['vehicle-monitoring']` y su bloque `siri` en la ficha (un feed con su reserva, o uno por operador en
`vehicle_monitoring_urls`):

```bash
php engine/bizkaibus/tools/new-engine.php <red>
php tests/engine-test.php
```

Crea `engine/<motor>/` y pone `'engine'` en la ficha. Se sube `engine/<motor>/` al repositorio del algoritmo y la
ficha a la app. La captura, el recalibrado y las horas estimadas empiezan solos en cuanto el feed traiga vehículos.

## Guardar el directo de una red para aprender de él

Para medir antes de usar el motor (como Renfe): `'capture' => 'gtfs-rt'` en la ficha y su herramienta en
`engine/<red>/tools/capture.php` (la de Renfe sirve de modelo). Las capturas, recortadas a la red, van cada hora a las
releases `capturas-<red>-AAAA-MM` del repositorio del algoritmo. El plan de Renfe, paso a paso: `renfe/README.md` en el
repositorio del algoritmo.

## Incidencias

Según lo que publique el operador: `gtfs_rt.alerts_url` (GTFS-Realtime), `siri.alerts_url` o `siri.alerts_urls`
(SIRI Situation Exchange, una o varias). `features.alerts` dice cómo se piden: `network`, `lines` o `stops`. Las
notificaciones de los favoritos salen solas con el cron.

## Horario oficial por periodos

Nada que hacer: sale del GTFS de la red. Si el operador publica el suyo en texto (como Bizkaibus), `schedule_text.url`
y `features.schedule_text => true` en la ficha, y un lector como `Services/ScheduleTextClient.php`.

## Iconos y logo

Dibuja la marca en `icons-<red>/icon.svg` (512 × 512: el fondo de la red con esquinas de 112 y la marca en blanco con
su «+») y en `icons-<red>/glyph.svg` (la misma marca en negro sobre transparente, para la cabecera). Las de Bilbobus,
Tuvisa, Dbus, Álavabus y La Unión sirven de plantilla. Después:

```bash
node scripts/render-icons.mjs <red>
```

Saca `icon-512.png`, `icon-192.png`, `apple-touch-icon.png`, `icon-maskable-512.png` y `glyph.png` (con el navegador de
las pruebas: `CHROME_PATH`). En la ficha, `logomark` con `glyph.png` y `card_logomark` con `icon-192.png`.

## Imagen para compartir y cartel de parada

```bash
node scripts/render-images.mjs        # todo; «og», «cartel» o «apps» para solo una parte
```

- `icons-bide-rojo/og-image.png` (1200 × 630): la imagen que sale al compartir un enlace de Bide+, con todas las apps
  del registro en el orden del menú. `scripts/new-network.php` la vuelve a dibujar al añadir una red; si cambias el
  nombre o el icono de una, ejecútalo a mano.
- `carteles/cartel-parada-a5.png` (A5 a 300 ppp) y `cartel-parada-a5.pdf`: el cartel «¿Cansado de perder el bus? Mira
  desde aquí el horario de esta parada», en castellano y euskera, con el hueco del centro para pegar el QR de cada
  parada (la dirección de la parada es `https://www.bideplus.eus/stops/<número>`; en otra red, con `?red=<red>`).
  `carteles/` se genera en local: no se sube al repositorio (`.gitignore`) ni se publica en la web.
- `carteles/apps/cartel-<red>.png` y `.pdf`: un cartel A5 por app, con su color, su icono y el QR que la abre
  (`https://www.bideplus.eus/?red=<red>`). Bizkaibus, Metro y Renfe llevan su propio mensaje y lo que ofrecen
  (`FEATURED` en el script); las demás, uno según el tipo de transporte y el subtítulo de su ficha. El QR lo dibuja
  `scripts/qr.mjs`, sin dependencias; antes de imprimir muchos, escanea uno.

## Cambiar los colores de una red

En su ficha, `tile_color`, `swatch_color`, `theme_color` y `palette`. Después:

```bash
php scripts/build-networks-css.php
php scripts/build-networks-js.php
```

## Cambiar el nombre de una red

En su ficha, `texts.<idioma>.name`. Además de la app, el nombre sale en los enlaces de la página de error 404, que es
un fichero estático. Después:

```bash
php scripts/build-networks-js.php
php scripts/build-404.php
```

`tests/static-checks.py` falla si `404.html`, `js/networks.js` o `css/style-networks.css` no están al día.

## Textos e idiomas

Los textos de la app están en `js/i18n.js`, con las mismas claves en castellano, euskera e inglés (lo comprueba
`tests/code-quality.php`); los de cada red, en su ficha (`texts`). A fondo: [ampliar.md](ampliar.md).

Para los buscadores, cada página existe en los tres idiomas: `?lang=eu` y `?lang=en` la sirven ya traducida desde el
servidor (`api/Core/Lang.php`, con esos mismos textos), con su `lang`, su canónica y los enlaces `hreflang` a las otras
dos; el sitemap las lista todas. Un elemento nuevo con `data-i18n` o `data-i18n-attr` sale traducido solo. Los títulos
y descripciones de cada página, en `api/Views/menu.php`, `app-page.php` y `network-map.php`.

## Novedad de versión (avisa a quien tiene la app)

Una entrada nueva al principio de `api/Services/Changelog.php` (fecha, número siguiente, título y texto) y sus
traducciones en `js/i18n.js` (`bide.changelog.<número>.title` y `.text`, con el título distinto en cada idioma). Al
publicar, el cron avisa una vez a cada móvil y Ajustes enseña el «1» rojo.

## Lanzar a mano

| Qué | Cómo |
| --- | --- |
| Horarios y despliegue | GitHub → Actions → «Rebuild schedule data» → *Run workflow* |
| Captura de una red | Actions → «Ritmo de los buses (k)» → *Run workflow*, con `red` |
| Recalibrar el motor | el mismo, marcando «Recalibrar en vez de capturar» |
| Volver al despliegue anterior | Actions → «Rollback production» |

## Ver las capturas

| Qué | Dónde | Cada cuánto cambia |
| --- | --- | --- |
| La captura en marcha | GitHub → Actions → «Ritmo de los buses (k)» → el trabajo `capture (<red>, …)` que está corriendo → paso «Capturar y medir sin parar» | dice cada bloque que empieza, que se archiva y su medida, en directo |
| Lo que trae el feed ahora | `https://www.bideplus.eus/api/status?red=<red>` (vehículos SIRI) o la app | en cada consulta |
| Las copias en bruto | releases `capturas-AAAA-MM` (Bizkaibus) y `capturas-<red>-AAAA-MM` (las demás) del repositorio del algoritmo: un `vm-<día>-<hora>.tar.xz` por hora | cada hora |
| Las muestras acumuladas | release `pace-data` (Bizkaibus) o `pace-data-<red>` del repositorio de la app: `pace-samples.jsonl`, `segment-samples.jsonl`, `coverage-samples.jsonl`, `eta-eval.jsonl` | cada hora (se sustituyen) |
| Lo aprendido | `engine/<motor>/data/` del repositorio del algoritmo (`pace-factors.json`, `segment-times.json`, `trip-offsets.json`) | cada noche (04:00 UTC), con un commit «recalibra…» |

Para bajar un bloque desde la terminal: `gh api` con la lista de la release y `-H "Accept: application/octet-stream"`
(ver `renfe/README.md` del repositorio del algoritmo).

## Comprobar que una red funciona

```bash
curl -s "https://www.bideplus.eus/?red=<red>" -o /dev/null -w "%{http_code}\n"
curl -s "https://www.bideplus.eus/api/status?red=<red>"
python3 tests/run-all.py
```

`/api/status` dice cuántos vehículos trae un feed SIRI ahora mismo. Qué mirar cada día y qué hacer si algo falla:
[operacion.md](operacion.md).

## Consultar una parada desde la terminal

Para contestar «¿cuándo pasa el bus por tal parada?» sin abrir la app. Bizkaibus va sin `red=`; las demás, con el
`red=` de la tabla. Las órdenes son de PowerShell (`irm`); con `curl` valen las mismas direcciones.

```powershell
# 1. El número de la parada
(irm "https://www.bideplus.eus/api/search?red=metro&q=bidezabal").stops | ft id,name,area
# 2. Sus próximas salidas
(irm "https://www.bideplus.eus/api/stops/24/departures?red=metro&limit=10").departures | ft lineCode,headsign,scheduledTime,etaMinutes,status,delayMinutes,tripKey
# 3. Un viaje: qué vehículo es, su retraso y por qué parada va
$v = irm "https://www.bideplus.eus/api/vehicles/<tripKey>?red=metro"; $v | select lineCode,status,vehicleRef,delayMinutes; $v.stops | ft scheduledTime,name,etaMinutes,isPast,isCurrent
```

O todo de una vez, con una función que se pega una vez en PowerShell:

```powershell
function Bide($red, $parada) {
    $r = ''; if ($red -ne 'bus') { $r = "red=$red&" }
    $s = (irm "https://www.bideplus.eus/api/search?${r}q=$([uri]::EscapeDataString($parada))").stops | select -First 1
    "$($s.name) · $($s.area) · $($s.id)"
    (irm "https://www.bideplus.eus/api/stops/$($s.id)/departures?${r}limit=10").departures | ft lineCode,headsign,scheduledTime,etaMinutes,status,delayMinutes
}
Bide bus 'amorebieta san juan'
```

| Red | `red=` | Ejemplo de búsqueda | Parada | Directo |
| --- | --- | --- | --- | --- |
| Bizkaibus+ | (nada) | `amorebieta san juan` | 3388 | GPS de cada bus |
| Metro+ | `metro` | `bidezabal` | 24 | retraso del operador |
| Euskotren+ | `euskotren` | `zazpikaleak` | `ES:Euskotren:StopPlace:2577:` | retraso del operador |
| Renfe Cercanías+ | `renfe` | `santurtzi` | 13405 | retraso del operador |
| Tranvía Bilbao+ | `tranvia-bilbao` | `guggenheim` | `ES:Euskotren:StopPlace:1476:` | retraso del operador |
| Tranvía Vitoria+ | `tranvia-vitoria` | `angulema` | `ES:Euskotren:StopPlace:1573:` | retraso del operador |
| Lurraldebus+ | `lurraldebus` | `arrasate ulgor` | 5702 | solo horario |
| Bilbobus+ | `bilbobus` | `arenal arriaga` | 140 | solo horario |
| Tuvisa+ | `tuvisa` | `adriano` | 1178 | retraso del operador |
| Dbus+ | `dbus` | `vasconia` | 105 | retraso del operador |
| Álavabus+ | `alavabus` | `laudio` | 26 | retraso del operador |
| La Unión+ | `launion` | `intermodal` | 17 | solo horario |

Cómo leer cada salida:

- `etaMinutes`: los minutos que faltan; `scheduledTime`, la hora oficial.
- `status`: `live` (en directo), `estimated` (sin vehículo, con lo que tarda de verdad ese recorrido), `scheduled`
  (solo la hora oficial, «SOLO HORARIO»), `likely_departed` («Sin datos del bus»), `departed` (ya ha pasado),
  `cancelled` (suprimido).
- `delayMinutes`: el retraso frente al horario oficial; negativo, adelanto. Un adelanto de muchos minutos en Bizkaibus
  suele ser un horario oficial mal hecho, no un bus que va antes: la hora buena es `etaMinutes`.
- `tomorrow`: hoy ya no sale ninguna más y es la primera de mañana.

Si en Bizkaibus no sale ningún bus en directo, `irm "https://www.bideplus.eus/api/status"` dice cuántos trae ahora el
feed GPS: con 0 o `ok: false`, el problema es el feed, no la app.
