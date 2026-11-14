# Bide+

Horarios, llegadas y avisos del transporte público de Euskadi en una sola aplicación web, sin vueltas. Hecha por [Yeray Garrido](https://www.yeraygarrido.dev/).

**Pruébala:** <https://www.bideplus.eus>

| App | Qué muestra |
|---|---|
| **Bizkaibus+** | Horarios, llegadas calculadas con la posición GPS de cada bus, mapa en vivo y avisos |
| **Metro+** | Horarios, tiempo real y avisos de Metro Bilbao |
| **Euskotren+** | Horarios y avisos de Euskotren |
| **Tranvía Bilbao+** y **Tranvía Vitoria+** | Horarios, tiempo real y avisos de los tranvías |
| **Renfe Cercanías+** | Horarios, retrasos en directo y avisos de Cercanías en Bilbao y Donostia/Irun |
| **Lurraldebus+** | Horarios y avisos de los autobuses de Gipuzkoa |
| **Bilbobus+** | Horarios de los autobuses urbanos de Bilbao |
| **Tuvisa+** | Horarios, tiempo real y avisos de los autobuses de Vitoria-Gasteiz |
| **Dbus+** | Horarios y tiempo real de los autobuses de Donostia |
| **Álavabus+** | Horarios y tiempo real de los autobuses interurbanos de Álava |
| **La Unión+** | Horarios de los autobuses de La Unión entre Bizkaia y Vitoria-Gasteiz |

Es una aplicación web instalable (PWA) en castellano, euskera e inglés, que funciona en el móvil y en el ordenador. Avisa con una notificación antes de que llegue tu bus o tu tren y de las incidencias nuevas de tus favoritos, aunque la app esté cerrada, y guarda en el dispositivo el horario de todas las redes: sin conexión se puede buscar y ver las paradas, las líneas con su horario y las paradas de cada viaje.

## Cómo funciona

- Los horarios salen del GTFS oficial de cada operador y se reconstruyen cada día.
- En Bizkaibus, el tiempo de llegada se calcula situando el GPS de cada bus sobre el trazado real de su ruta y sumando lo que tardan de verdad los buses en cada tramo. En 2,37 millones de llegadas de 17 días, acierta con menos de 2 minutos de error en el 96 % de los casos cuando faltan menos de 3 minutos (el horario oficial, en el 24 %).
- Metro Bilbao, los tranvías, Tuvisa, Dbus y Álavabus llevan el tiempo real que publica el operador; Renfe, el retraso de cada tren y los cancelados. Si un directo es una copia del horario (Euskotren, a ratos) o sus horas no cuadran con el GPS (Álavabus), la app lo detecta y enseña el horario o se fía del GPS. Lurraldebus y Bilbobus tienen el mismo algoritmo que Bizkaibus preparado sobre los feeds de sus operadores, que llegan vacíos a ratos; mientras tanto, y siempre en La Unión, se muestra el horario programado: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el propio operador.
- De madrugada salen también los viajes del día anterior que pasan de las 24:00 (el horario los anota a las 25:00, 26:00…). El directo de Bizkaibus no se actualiza de 23:58 a 05:00, así que sus nocturnos salen con la hora estimada a partir del horario y de lo que tardan de verdad los tramos.
- Los datos de cada operador mantienen sus propias licencias y se citan en la aplicación.

## Ejecutarla en local

Necesitas PHP 8.2 o superior con las extensiones `sqlite3`, `pdo_sqlite`, `curl`, `mbstring` y `zip`.

```
cd bideplus
php scripts/fetch-databases.php
php -S localhost:8011 dev-router.php
```

`fetch-databases.php` baja las bases de las redes (no están en git) de la release `bases`, las mismas que producción, y comprueba su tamaño y su huella SHA-256; con `--red=metro` baja solo una. Después abre <http://localhost:8011>.

El planificador del inicio (la tarjeta «¿A dónde vas?», que carga sus módulos de `/planificador/`) necesita el motor en `bideplus/engine`: `php scripts/build-planner.php --out=. --databases=data --except=villavesas` hace los paquetes y los recorridos del mapa sin las redes de `--except` (las villavesas, fuera del planificador hasta que se resuelvan sus datos; en «Rebuild schedule data» es la línea `REDES_RESERVADAS`) y, la primera vez, `php scripts/build-planner.php --out=. --walk --osm=euskadi-latest.osm.pbf,… --near=cantabria-latest.osm.pbf,…` las tablas de andar, los lugares y las calles del mapa con los mapas de OpenStreetMap (los de `--near`, los de alrededor, solo dan lugares cerca de una parada). Las pruebas están en [bideplus/tests/README.md](bideplus/tests/README.md).

## Participar

- [Guía para contribuir](CONTRIBUTING.md)
- [Código de conducta](CODE_OF_CONDUCT.md)
- [Política de seguridad](SECURITY.md)
- [Declaración de accesibilidad](ACCESSIBILITY.md)
- [Hoja de ruta](ROADMAP.md)

Un error en un horario o en una llegada, una idea o una barrera de accesibilidad: abre una [issue](../../issues/new/choose).

## Licencia

© 2026 Yeray Garrido. **Todos los derechos reservados**, con estos permisos ([LICENSE](LICENSE)):

- **Cualquier persona, a título particular y sin fines comerciales**, puede ver y descargar el código, ejecutarlo en su propio equipo, estudiarlo e investigarlo (también modificándolo en privado) y publicar lo que aprenda citando al autor.
- **Universidades y centros de investigación** pueden estudiarlo, investigarlo y usarlo en la docencia, pero no ponerlo en funcionamiento para dar un servicio.
- **Sin una licencia del autor no se permite**: ningún uso comercial ni con ánimo de lucro, ningún uso por organizaciones públicas o privadas (administraciones, consorcios, empresas…), publicar una web o app basada en este código, distribuir copias o versiones modificadas, ni usar el nombre «Bide+», sus logos o bideplus.eus.

Cualquier otro uso necesita el permiso por escrito del autor, que puede darlo gratis (por ejemplo, a un proyecto social sin ánimo de lucro) o con una licencia: escríbele antes (contacto al final del archivo [LICENSE](LICENSE)). Lo publicado antes del 4 de noviembre de 2026 se distribuyó bajo CC BY-NC-SA 4.0.

Los nombres y logotipos de Bizkaibus, Metro Bilbao, Euskotren, Renfe y los demás operadores son de sus titulares. Este es un proyecto independiente y no tiene relación con ellos ni con las administraciones que publican los datos.
