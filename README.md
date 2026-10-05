# Bide+

Horarios, llegadas y avisos del transporte público de Euskadi en una sola aplicación web, sin vueltas. Hecha por [Yeray Garrido](https://www.yeraygarrido.dev/).

**Pruébala:** <https://bideplus.vercel.app>

| App | Qué muestra |
|---|---|
| **Bizkaibus+** | Horarios, llegadas calculadas con la posición GPS de cada bus, mapa en vivo y avisos |
| **Metro+** | Horarios, tiempo real y avisos de Metro Bilbao |
| **Euskotren+** | Horarios de Euskotren |
| **Tranvía Bilbao+** y **Tranvía Vitoria+** | Horarios de los tranvías |
| **Renfe Cercanías+** | Horarios y avisos de Cercanías en Bilbao y Donostia/Irun |

Es una aplicación web instalable (PWA) en castellano y euskera, que funciona en el móvil y en el ordenador y guarda en el dispositivo lo último que consultaste para poder verlo sin conexión.

## Cómo funciona

- Los horarios salen del GTFS oficial de cada operador y se reconstruyen cada día.
- En Bizkaibus, el tiempo de llegada se calcula situando el GPS de cada bus sobre el trazado real de su ruta. Frente a mostrar solo el horario, el error típico baja de unos 5 min a menos de 1,5 min. El método, con esquemas, ejemplos y resultados, está explicado en [bideplus/README.md](bideplus/README.md).
- Euskotren, los tranvías y Renfe muestran el horario programado: no reflejan retrasos ni cancelaciones, salvo los avisos de incidencias que publique el propio operador.
- Los datos de cada operador mantienen sus propias licencias y se citan en la aplicación.

## Ejecutarla en local

Necesitas PHP 8.2 o superior con las extensiones `sqlite3`, `pdo_sqlite`, `curl`, `mbstring` y `zip`.

```
cd bideplus
php -S localhost:8011 dev-router.php
```

Después abre <http://localhost:8011>. Las pruebas y la documentación técnica están en [bideplus/tests/README.md](bideplus/tests/README.md) y [bideplus/README.md](bideplus/README.md).

## Participar

- [Guía para contribuir](CONTRIBUTING.md)
- [Código de conducta](CODE_OF_CONDUCT.md)
- [Política de seguridad](SECURITY.md)
- [Declaración de accesibilidad](ACCESSIBILITY.md)
- [Hoja de ruta](ROADMAP.md)

Un error en un horario o en una llegada, una idea o una barrera de accesibilidad: abre una [issue](../../issues/new/choose).

## Licencia

El código, el diseño y los iconos se publican bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE): puedes copiarlos y adaptarlos citando al autor, sin fines comerciales y compartiendo las obras derivadas con la misma licencia. © 2026 Yeray Garrido.

El uso comercial, la reventa y los despliegues institucionales (administraciones, entidades públicas o privadas) por parte de terceros requieren autorización del titular. Para una licencia comercial, mira la cabecera del archivo [LICENSE](LICENSE).

Los nombres y logotipos de Bizkaibus, Metro Bilbao, Euskotren y Renfe son de sus titulares, menos los generados para usar la aplicación que están publicados bajo [Creative Commons Reconocimiento-NoComercial-CompartirIgual 4.0 (CC BY-NC-SA 4.0)](LICENSE). Este es un proyecto independiente y no tiene relación con ellos ni con las administraciones que publican los datos.
