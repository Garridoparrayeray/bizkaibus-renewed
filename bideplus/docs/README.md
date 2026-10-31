# Documentación de Bide+

Qué hay que leer según para qué la necesites.

## Para usar la app

- [Manual de uso](manual-de-uso.md): las siete apps, buscar, paradas y líneas, favoritos, avisos de llegada e
  incidencias, sin conexión, Ajustes, instalarla en Android y en iPhone, pantalla de parada y mapa de la red.

## Para mantenerla en marcha (guía personal)

- [Operación](operacion.md): servicios y cuentas (Vercel, Upstash, cron-job.org, GitHub), variables y secretos (solo
  sus nombres), qué se ejecuta solo y cuándo, cómo llega un cambio a producción, cómo comprobar que todo va bien y qué
  hacer cuando algo falla.

## Para entenderla y ampliarla (técnica)

- [Arquitectura](../README.md): las piezas, el registro de redes, los temas, el modelo de calendario y los datos en
  tiempo real.
- [Cómo ampliar Bide+](ampliar.md): un texto o un idioma, una ruta de la API, una pantalla, un tipo de notificación,
  una prueba, y las pautas de código que lo comprueban todo.
- [Añadir una red](nueva-red.md): de un GTFS a una app nueva, también para una versión propia.
- [Notificaciones](notificaciones.md): avisos de llegada (una vez o todos los días), incidencias de los favoritos,
  claves VAPID, el cron y el service worker.
- [Sin conexión](sin-conexion.md): qué se guarda en el móvil, el formato de los horarios guardados, la hora estimada sin
  datos del bus y las marcas de «ya pasó».
- [Registro de pasos](registro-de-pasos.md): el almacén compartido (Upstash), qué apunta el cron cada minuto y para qué
  sirve.
- [Seguridad](seguridad.md): cabeceras, CSP, qué se publica y qué no.
- [Pruebas](../tests/README.md): toda la batería, incluida la de limpieza del código, y qué ampliar según lo que añadas.
- [Planificador de rutas (propuesta)](raptor.md): diseño de un planificador con RAPTOR. Aún no está hecho.

## El algoritmo de llegadas

- [El algoritmo, explicado](algoritmo-explicado.md): primero sin matemáticas y luego en versión formal, con lo que ha
  cambiado y cuánto acierta.
- La referencia completa (la memoria técnica, el laboratorio y las páginas interactivas) está en el repositorio del
  algoritmo, que los flujos de trabajo colocan en `engine/`: `engine/README.md` y `engine/docs/README.md`.

## Documentos del proyecto original

`manual-BizkaiBus-Metro.pdf` y `javadoc-BizkaiBus-Metro.pdf`: el manual y la documentación del código de la primera
versión, de antes de Bide+. Se conservan como histórico.
