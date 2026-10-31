# Registro de pasos

Para no enseñar un bus que ya pasó, el servidor tiene que saber por dónde ha ido cada uno, aunque nadie esté mirando la
app en ese momento. Para eso hay un almacén compartido y un cron que apunta los pasos cada minuto.

## Por qué hace falta

Vercel ejecuta la API en funciones que no comparten memoria y se apagan entre peticiones. El feed de Bizkaibus dice
dónde está cada bus **ahora**, no por dónde ha pasado. Si un bus va muy adelantado y deja de mandar posición (llega a
cabecera y se apaga), solo con el feed la app volvería a enseñarlo con su hora del horario, como si aún no hubiera
pasado. Con el registro, la app sabe que ese viaje ya pasó por la parada y no lo enseña.

## Piezas

| Pieza | Dónde |
| --- | --- |
| Almacén (Upstash Redis por su API HTTPS) | `api/Core/Store.php` |
| Qué motor apunta y lee, según la red | `api/Core/Live.php` |
| Cómo se apunta y se lee | `engine/bizkaibus/PassageLog.php` (en el repositorio del algoritmo) |
| La llamada del cron | `api/Controllers/LiveRecordController.php` (`/api/live/record`) |
| Historial de una línea | `/api/live/history?line=<línea>&date=AAAA-MM-DD` |

## El almacén

`Core\Store` habla con Upstash por HTTPS, en una sola llamada para varios comandos (`pipeline`). Las credenciales van
en `UPSTASH_REDIS_REST_URL` y `UPSTASH_REDIS_REST_TOKEN`, o en las que pone Vercel al conectar Upstash
(`KV_REST_API_URL` y `KV_REST_API_TOKEN`).

- **Sin credenciales**, no hace nada y la app funciona igual, sin registro ni notificaciones push.
- **Si falla**, deja de llamarlo un minuto (`PAUSE_AFTER_FAILURE_SECONDS`) para no frenar las respuestas, y las lecturas
  tienen un tiempo máximo corto.

Claves que usa la app (todas empiezan por `bide:`):

| Clave | Qué guarda | Dura |
| --- | --- | --- |
| `bide:bus:pass:<fecha>` | por viaje, la parada por la que iba y la hora | 36 h |
| `bide:bus:stops:<fecha>` | por viaje y parada, la última hora en que se vio yendo hacia ella (el historial) | 3 días |
| `bide:vapid` | las claves que firman las notificaciones | siempre |
| `bide:arrival:*` | avisos de llegada pendientes ([notificaciones](notificaciones.md)) | 3 h o 2 meses |
| `bide:incident:*` | suscriptores de incidencias y lo ya avisado | 90 días sin abrir la app |

## El cron

Un cron externo (cron-job.org, cada minuto) llama a `/api/live/record` con la clave secreta `BIDE_CRON_SECRET`, en la
dirección (`?key=`) o en la cabecera `Authorization: Bearer`. Sin la clave, la ruta responde como si no existiera
(404). En cada llamada:

1. Apunta por dónde va cada bus del directo (`recorded`: cuántos).
2. Manda los avisos de llegada que tocan y, cada 5 minutos, las incidencias nuevas ([notificaciones](notificaciones.md)).

Un cron de GitHub no sirve para esto: los trabajos programados de GitHub se retrasan y no garantizan cada minuto.

Respuestas posibles:

| Respuesta | Qué significa |
| --- | --- |
| `200` con `recorded`, `arrivalAlerts` e `incidentAlerts` | todo bien |
| `503 store not configured` | faltan las credenciales de Upstash en Vercel |
| `502 store write failed` | Upstash no respondió al apuntar |
| `404` | la clave no coincide con `BIDE_CRON_SECRET` |

## Cómo lo usa la app

Al calcular las salidas de una parada, el motor junta el directo con lo apuntado ese día (`PassageLog::forJourneys`).
Un viaje que ya pasó por la parada sale como «ya pasó» y deja de enseñarse aunque el bus ya no mande posición. Lo
apuntado también alimenta el aprendizaje de los tiempos por tramo ([el algoritmo](algoritmo-explicado.md)).

`tests/store-test.php` comprueba el almacén (con un Upstash falso), la clave del cron y que un almacén caído no frena la
respuesta.
