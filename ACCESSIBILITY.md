# Declaración de accesibilidad

Última revisión: 1 de octubre de 2026.

## Nuestro compromiso

Bide+ debe poder usarse por cuantas más personas mejor, también con lector de pantalla, solo con teclado, con zoom o con poca visión. El objetivo es cumplir las pautas WCAG 2.2 de nivel AA.

**Estado real:** el proyecto todavía no ha pasado una auditoría de accesibilidad formal ni se ha probado de forma sistemática con lectores de pantalla. No se declara conformidad con WCAG. Lo que sigue es lo que sí se ha comprobado en el código y lo que se sabe que falla.

## Entornos compatibles

La aplicación está pensada para las versiones recientes de Chrome, Edge, Firefox y Safari, en móvil y en ordenador. Se puede instalar como aplicación (PWA). No se garantiza el funcionamiento en navegadores antiguos ni la compatibilidad con ninguna combinación concreta de lector de pantalla y navegador.

## Lo que ya se ha tenido en cuenta

- El idioma de la página se declara y se actualiza al cambiar entre castellano y euskera.
- Los cuadros de diálogo (menú, horarios, aviso legal) usan el elemento nativo `dialog`, que gestiona el foco y se cierra con la tecla Escape.
- Los botones y controles sin texto visible tienen etiqueta accesible, y las imágenes decorativas se ocultan a las tecnologías de apoyo.
- Hay indicador visible de foco con teclado.
- Se respeta la preferencia del sistema de reducir el movimiento.
- No se bloquea el zoom del navegador y la maquetación se adapta a pantallas pequeñas.
- Lo importante de las llegadas (minutos que faltan, estado y retraso) se muestra en texto en las listas de salidas y en el detalle del bus, no solo en el mapa.

## Limitaciones conocidas

- **Contraste de color insuficiente en los tranvías.** El texto blanco sobre el verde de cabecera de **Tranvía Bilbao+** (2,5:1) y de **Tranvía Vitoria+** (2,8:1) no alcanza el 4,5:1 que pide WCAG AA para texto normal. El resto de apps (Bizkaibus+, Metro+, Euskotren+ y Renfe Cercanías+) sí lo cumple en sus colores principales. Otros pares de color de la interfaz no se han medido todavía.
- **No hay modo oscuro.**
- **El mapa en vivo** (solo en Bizkaibus+) es una ayuda visual y no está pensado para usarse con lector de pantalla.
- **Sin auditoría ni pruebas con usuarios con discapacidad.** Puede haber problemas que aún no conocemos, por ejemplo en el orden de lectura, en los avisos dinámicos o en el uso solo con teclado.
- **Notificaciones:** dependen de lo que permita el navegador y no se han probado con tecnologías de apoyo.

## Cómo informar de una barrera

Si algo no puedes usarlo, cuéntalo. Es de las cosas más útiles que se pueden aportar.

- Abre una [issue de accesibilidad](../../issues/new?template=accessibility.yml).
- O escribe a través de <https://www.yeraygarrido.dev/>.

Indica qué app y qué pantalla, qué intentabas hacer, qué dispositivo, navegador y tecnología de apoyo usas (por ejemplo, el lector de pantalla), y qué ocurrió. Se intentará responder en unos 7 días y se priorizarán los problemas que impidan usar la aplicación.
