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

Es una aplicación web instalable (PWA) en castellano, euskera e inglés, que funciona en el móvil y en el ordenador. Avisa con una notificación antes de que llegue tu bus o tu tren y de las incidencias nuevas de tus favoritos, aunque la app esté cerrada, y guarda en el dispositivo el horario de las redes que elijas para verlo sin conexión.

## Cómo funciona

- Los horarios salen del GTFS oficial de cada operador y se reconstruyen cada día.
- En Bizkaibus, el tiempo de llegada se calcula situando el GPS de cada bus sobre el trazado real de su ruta y sumando lo que tardan de verdad los buses en cada tramo. En 2,37 millones de llegadas de 17 días, acierta con menos de 2 minutos de error en el 96 % de los casos cuando faltan menos de 3 minutos (el horario oficial, en el 24 %). El método está explicado, sin matemáticas y en versión formal, en [bideplus/docs/algoritmo-explicado.md](bideplus/docs/algoritmo-explicado.md).
- Metro Bilbao, los tranvías, Tuvisa, Dbus y Álavabus llevan el tiempo real que publica el operador; Renfe, el retraso de cada tren y los cancelados. Euskotren publica un directo que es una copia exacta de su horario, así que sale el horario. Lurraldebus y Bilbobus tienen el mismo algoritmo que Bizkaibus preparado sobre los feeds de sus operadores, que de momento llegan vacíos; mientras tanto, y siempre en La Unión, se muestra el horario programado: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el propio operador.
- Los datos de cada operador mantienen sus propias licencias y se citan en la aplicación.

## Ejecutarla en local

Necesitas PHP 8.2 o superior con las extensiones `sqlite3`, `pdo_sqlite`, `curl`, `mbstring` y `zip`.

```
cd bideplus
php -S localhost:8011 dev-router.php
```

Después abre <http://localhost:8011>. Toda la documentación (manual de uso, arquitectura, cómo ampliarla, operación y el algoritmo) está en [bideplus/docs/](bideplus/docs/README.md), y las pruebas en [bideplus/tests/README.md](bideplus/tests/README.md).

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
