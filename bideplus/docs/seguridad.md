# Seguridad de Bide+

Resumen de cómo se protege la API y la web, y de las pruebas de ataque que se le han hecho. Pensado para una revisión
técnica (por ejemplo, la de un ayuntamiento que quiera la app) y para quien mantenga el código.

La idea de fondo: **la app solo lee datos públicos de horarios y no tiene cuentas de usuario**. No hay contraseñas, ni
datos personales guardados en un servidor, ni panel de administración. Eso quita de un plumazo las familias de fallos
más graves (robo de cuentas, fuga de datos personales). Lo que queda se protege así.

## Qué recibe del cliente y cómo se valida

| Entra por | Ejemplo | Validación |
| --- | --- | --- |
| Red (`?red=`) | `?red=metro` | Solo un identificador que exista en `api/Networks`; si no, la red por defecto. Nunca se usa para construir una ruta de fichero. |
| Id de parada o línea | `/api/stops/2627` | Va siempre como parámetro enlazado (`?`) en SQL. Si no existe la fila, «no encontrado». |
| Clave de viaje | `3514-12-27600` | Patrón fijo `línea-número-segundos`; si no encaja, 422. |
| Búsqueda (`q=`) | `?q=amorebieta` | Se parte en palabras (sin signos) y cada una va como `LIKE ?` enlazado. |
| Fecha y horas del horario | `?date=2026-10-29` | Patrón `AAAA-MM-DD` y `HH:MM`; si no, 422. |
| Coordenadas (`lat`, `lon`) | `?lat=43.2&lon=-2.7` | Se convierten a número; el radio está acotado. |
| Suscripción push (JSON) | `{endpoint, keys}` | Ver «Notificaciones push». |

Todas las consultas a la base de datos usan **parámetros enlazados** (PDO `prepare`/`execute`); no se concatena texto
del cliente dentro del SQL. Los únicos trozos de consulta que se arman en código son listas de interrogantes (`?, ?, ?`)
y nombres de columna fijos, nunca datos de quien llama.

La base de datos se abre **en modo de solo lectura** (`mode=ro`, `PRAGMA query_only`), así que aunque un día apareciera
un hueco, no se podría escribir ni borrar nada.

## Cabeceras de seguridad (vercel.json)

- `Content-Security-Policy` estricta: `script-src 'self'` (sin `unsafe-inline`), `object-src 'none'`,
  `base-uri 'self'`, `frame-ancestors 'none'`, `form-action 'self'`, `upgrade-insecure-requests`. Aunque alguien
  colara texto en una página, el navegador no ejecutaría ningún script que no venga del propio sitio.
- `Strict-Transport-Security` (HSTS, con `preload`): siempre HTTPS.
- `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy` (cámara,
  micrófono, pagos… desactivados; geolocalización solo para «paradas cerca»).

Los ficheros que no son web pública —la base de datos (`/data`), los scripts, las pruebas, el motor (`/engine`), las
clases PHP (`/api/Mayúscula/…`) y la configuración— están **bloqueados**: cualquier intento de descargarlos acaba en
«no encontrado».

## Notificaciones push: nada de SSRF

Las notificaciones con la app cerrada son el punto más delicado, porque el servidor hace una petición de salida a una
dirección que manda el navegador. Para que nadie use eso para que el servidor llame a una dirección interna
(SSRF), la dirección de la suscripción (`endpoint`) tiene que cumplir **todo** esto:

- empezar por `https://`;
- que el dominio sea de un servicio de push real (`fcm.googleapis.com`, `push.services.mozilla.com`,
  `notify.windows.com`, `push.apple.com`) o un subdominio suyo;
- sin usuario (`user@…`) ni puerto (`:22`) en la dirección;
- la clave pública del navegador tiene que ser un punto P-256 válido (65 bytes) y la clave de cifrado, 16 bytes.

Además, el envío **no sigue redirecciones**, así que no se puede saltar la lista por la puerta de atrás. El envío
comprueba la dirección otra vez antes de salir.

Para no llenar el almacén: como mucho 20 000 suscriptores, 60 favoritos por app y 150 en total por suscriptor, cada id
con forma de id. Cada vuelta del cron manda como mucho 300 notificaciones.

## El cron está cerrado

`/api/live/record` y `/api/live/history` los llama un cron cada minuto. Sin la clave secreta (`BIDE_CRON_SECRET`)
**responden como si la ruta no existiera** (404). La clave se compara con `hash_equals` (tiempo constante, sin pistas
por el tiempo de respuesta) y vale por la URL (`?key=`) o por la cabecera `Authorization: Bearer …`.

## Errores

Con un fallo inesperado, la API responde un `500` genérico (`{"error":"Internal error"}`): nunca enseña la traza, la
ruta del fichero ni la consulta. El detalle solo va al registro del servidor.

## Pruebas de ataque hechas

Se ha probado la API en marcha como lo haría quien quisiera reventarla:

- **Inyección SQL** en búsqueda, id de parada, id de línea y fecha (`' OR '1'='1`, `UNION SELECT`, `DROP TABLE`): el
  texto se trata como búsqueda o se rechaza; la tabla sigue intacta.
- **Path traversal** por `?red=../../etc/passwd` y por los ids (`..%2f..%2f`): no se lee ningún fichero; cae en la red
  por defecto o en «no encontrado».
- **SSRF** por el `endpoint` de push a `169.254.169.254` (metadatos), a `127.0.0.1`, a un dominio cualquiera, y con los
  trucos `fcm.googleapis.com@evil.com`, `fcm.googleapis.com:22`, `ftp://…` y `notfcm.googleapis.com.evil.com`: todos
  rechazados con 400.
- **XSS** en `/pantalla/<payload>`: el texto sale escapado (`&quot;`, `&#039;`) y la CSP bloquearía cualquier script.
- **Cron sin clave**: 404.
- **Entradas con bytes nulos y UTF-8 inválido** en las rutas: «no encontrado» limpio.
- **Payloads abusivos** (dirección de 50 000 caracteres, 5 000 favoritos): rechazados por los topes.
- **Descarga de la base de datos, la config, las clases y los scripts**: bloqueada (404).

**No se encontró ninguna vía explotable.**

### Arreglado en esta revisión

- El router **de desarrollo** (`dev-router.php`, solo para `php -S` en local) se caía con un `500` ante una ruta con un
  byte nulo, porque `realpath()` aborta con bytes nulos. Ahora esas rutas dan «no encontrado» antes de tocar el disco,
  igual que en producción. En producción (Vercel) ese caso ya daba «no encontrado» porque el id no casa con ninguna fila.

### Recomendado para más adelante (no urgente)

- **Límite por IP** en `/api/push/*` y `/api/search` (por ejemplo, con un contador en el almacén) para que nadie pueda
  intentar llenar los 20 000 huecos de suscriptores con suscripciones inventadas. Hoy el tope de 20 000 ya protege el
  almacén, y las suscripciones que el servicio de push da por muertas (404/410) se borran solas, así que el riesgo es
  bajo; un límite por IP lo cerraría del todo.
