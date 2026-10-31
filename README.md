# Bide+

Horarios, llegadas y avisos del transporte público de Euskadi en una sola aplicación web, sin vueltas. Hecha por [Yeray Garrido](https://www.yeraygarrido.dev/).

**Pruébala:** <https://bideplus.vercel.app>

| App | Qué muestra |
|---|---|
| **Bizkaibus+** | Horarios, llegadas calculadas con la posición GPS de cada bus, mapa en vivo y avisos |
| **Metro+** | Horarios, tiempo real y avisos de Metro Bilbao |
| **Euskotren+** | Horarios y avisos de Euskotren |
| **Tranvía Bilbao+** y **Tranvía Vitoria+** | Horarios y avisos de los tranvías |
| **Renfe Cercanías+** | Horarios y avisos de Cercanías en Bilbao y Donostia/Irun |
| **Lurraldebus+** | Horarios y avisos de los autobuses de Gipuzkoa |

Es una aplicación web instalable (PWA) en castellano, euskera e inglés, que funciona en el móvil y en el ordenador. Avisa con una notificación antes de que llegue tu bus o tu tren y de las incidencias nuevas de tus favoritos, aunque la app esté cerrada, y guarda en el dispositivo el horario de las redes que elijas para verlo sin conexión.

## Cómo funciona

- Los horarios salen del GTFS oficial de cada operador y se reconstruyen cada día.
- En Bizkaibus, el tiempo de llegada se calcula situando el GPS de cada bus sobre el trazado real de su ruta y sumando lo que tardan de verdad los buses en cada tramo. En 2,37 millones de llegadas de 17 días, acierta con menos de 2 minutos de error en el 96 % de los casos cuando faltan menos de 3 minutos (el horario oficial, en el 24 %). El método está explicado, sin matemáticas y en versión formal, en [bideplus/docs/algoritmo-explicado.md](bideplus/docs/algoritmo-explicado.md).
- Metro Bilbao lleva tiempo real. Euskotren, los tranvías, Renfe y Lurraldebus muestran el horario programado: no reflejan retrasos ni cancelaciones, salvo los avisos de incidencias que publique el propio operador.
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

El código, el diseño y los iconos se publican bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE): puedes copiarlos y adaptarlos citando al autor, sin fines comerciales y compartiendo las obras derivadas con la misma licencia. © 2026 Yeray Garrido.

Cualquier uso comercial necesita una licencia del autor: venderlo o revenderlo, cobrar por instalarlo, adaptarlo, mantenerlo o desplegarlo para terceros (también para administraciones públicas) o incluirlo en un producto o servicio de pago. El uso sin ánimo de lucro, también por administraciones y entidades, está permitido con la misma licencia. Para una licencia comercial, mira la cabecera del archivo [LICENSE](LICENSE).

Los nombres y logotipos de Bizkaibus, Metro Bilbao, Euskotren y Renfe son de sus titulares, menos los generados para usar la aplicación que están publicados bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE). Este es un proyecto independiente y no tiene relación con ellos ni con las administraciones que publican los datos.
