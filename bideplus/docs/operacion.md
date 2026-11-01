# Operación: guía personal

Lo necesario para tener Bide+ en marcha: dónde vive cada cosa, qué se ejecuta solo y qué hacer cuando algo falla. Aquí
no hay ninguna clave: solo sus nombres y dónde se configuran.

## Servicios y cuentas

| Servicio | Para qué | Dónde se configura |
| --- | --- | --- |
| **Vercel** (proyecto `bizkaibus-renewed`, carpeta raíz `bideplus`) | sirve la app y la API: `bideplus.vercel.app` y `bideplusmiamor.vercel.app` (tema «mi amor») | Project Settings → Environment Variables y Domains |
| **Upstash Redis** (conectado desde Vercel → Storage) | almacén compartido: registro de pasos, avisos de llegada, incidencias y claves de las notificaciones | panel de Upstash: uso, límites y explorador de datos |
| **cron-job.org** | llama cada minuto a `/api/live/record`: apunta los pasos y manda las notificaciones | la tarea de cron-job.org (URL con `?key=` y frecuencia) |
| **GitHub Actions** | pruebas, reconstrucción diaria y despliegue, captura del ritmo de los buses | Settings → Secrets and variables → Actions |
| **Repositorio del algoritmo** (`bideplus-engine`) | el cálculo de llegadas y lo aprendido; los flujos lo colocan en `bideplus/engine` | sus propios flujos y su `README.md` |

## Variables y secretos

**En Vercel** (Environment Variables, entorno *Production*):

| Nombre | Para qué | Si falta |
| --- | --- | --- |
| `KV_REST_API_URL` y `KV_REST_API_TOKEN` (o `UPSTASH_REDIS_REST_URL` y `UPSTASH_REDIS_REST_TOKEN`) | acceso a Upstash; las pone Vercel al conectar Upstash | sin registro de pasos ni notificaciones push; el resto funciona |
| `BIDE_CRON_SECRET` | la clave que debe traer el cron | `/api/live/record` responde 404 a todo |
| `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY` (opcionales) | claves de las notificaciones | se crean solas y se guardan en Upstash (`bide:vapid`) |

**En GitHub** (Actions secrets):

| Nombre | Para qué |
| --- | --- |
| `ENGINE_REPO_TOKEN` | leer el repositorio del algoritmo desde las pruebas, la reconstrucción y la captura |
| `NAP_API_KEY` | descargar los GTFS del Punto de Acceso Nacional (nap.transportes.gob.es), en la reconstrucción diaria y en el recalibrado |
| `VERCEL_TOKEN`, `VERCEL_ORG_ID`, `VERCEL_PROJECT_ID_BIDEPLUS` | desplegar en Vercel desde la reconstrucción diaria |

Las claves no se cambian sin motivo. Al cambiar `BIDE_CRON_SECRET` hay que cambiar también la URL de cron-job.org. Las
claves VAPID no se cambian nunca: las suscripciones de los móviles están hechas con la clave pública actual.

## Qué se ejecuta solo

| Qué | Cuándo (hora UTC) | Qué hace |
| --- | --- | --- |
| cron-job.org → `/api/live/record` | cada minuto | apunta por dónde va cada bus, manda los avisos de llegada que tocan, cada 5 min las incidencias nuevas y, cuando sale una versión nueva de la app, su aviso |
| `rebuild-schedule.yml` | cada día a las 03:00, en cada cambio que llega a `master` y a mano | reconstruye las bases desde los GTFS, genera el mapa y los horarios sin conexión, comprueba los datos y **despliega a producción** |
| `pace-capture.yml` | captura continua (cada trabajo dura unas 5 h 30 min y lanza el siguiente; el cron horario de respaldo es a los :17) y recalibrado a las 04:00 | captura el feed de Bizkaibus, mide la precisión de las llegadas y recalcula el ritmo (k), los tiempos por tramo y la corrección por viaje (con la base de Bizkaibus del día); las muestras van a la release `pace-data` y lo aprendido, al repositorio del algoritmo |
| `tests.yml` | en cada cambio y en cada *pull request* | toda la batería de pruebas |
| `check-production.yml`, `eta-historial.yml` | a mano | comprobar el sitio desplegado (SEO, 404) y recuperar el historial de precisión |

`rebuild-schedule-gasolinera.yml` es de otro proyecto (Gasolinera+), cuya carpeta ya no está en este repositorio. No se
ejecuta desde septiembre.

## Cómo llega un cambio a producción

1. El trabajo va en una rama y se abre una *pull request* a `master`. Vercel publica una vista previa de la rama y
   `tests.yml` pasa toda la batería.
2. Al fusionar en `master`, `rebuild-schedule.yml` reconstruye las bases y despliega. Vercel **no** despliega `master`
   por su cuenta (`vercel.json`): si lo hiciera, desplegaría las bases viejas del repositorio y pisaría las del día.
3. Para volver atrás, se revierte el cambio en `master` (el flujo vuelve a desplegar) o, en Vercel, se promueve un
   despliegue anterior.
4. Si el cambio trae una novedad nueva (`api/Services/Changelog.php`), es una versión nueva: al minuto del despliegue el
   cron avisa a quien tiene las notificaciones, una sola vez, y a los demás les sale el «1» rojo en Ajustes.

## Comprobar que todo va bien

| Qué mirar | Bien si… |
| --- | --- |
| `https://bideplus.vercel.app/api/status?red=bus` | `ok: true` y `vehicles` mayor que 0 en horario de servicio |
| `https://bideplus.vercel.app/api/push/key` | `publicKey` no es `null` (Upstash y claves de notificación bien) |
| El historial de cron-job.org | respuestas 200 con `recorded`, `arrivalAlerts`, `incidentAlerts` y `updateAlerts` |
| GitHub → Actions | la última «Rebuild schedule data» y la captura en verde |
| Ajustes de la app | la versión es la de la última novedad, y la fecha de los horarios guardados es la de hoy |

## Qué hacer cuando algo falla

| Síntoma | Causa probable | Qué hacer |
| --- | --- | --- |
| El cron responde 404 | la clave de la URL no es `BIDE_CRON_SECRET` | corregir la URL de cron-job.org (`?key=`) |
| El cron responde 503 `store not configured` | faltan las variables de Upstash en Vercel | conectar Upstash en Vercel → Storage y redesplegar |
| El cron responde 502 `store write failed` | Upstash no responde o se pasó de su límite | mirar el uso en el panel de Upstash |
| No llegan notificaciones a nadie | el cron no llama, o `/api/push/key` da `null` | mirar el historial del cron y las variables |
| No le llegan a una persona | permiso quitado, ahorro de batería del móvil o, en iPhone, Bide+ sin añadir a la pantalla de inicio | que active las notificaciones de Bide+ y quite el ahorro de batería para el navegador |
| Horarios de ayer o antiguos | falló la reconstrucción (GTFS caído o cambiado) | ver el log de «Rebuild schedule data»; se puede relanzar a mano |
| Las llegadas de Bizkaibus salen todas «solo horario» | el feed en directo no responde | `/api/status?red=bus`; si `ok` es `false`, es del operador |
| Las pruebas fallan en `engine/` | `ENGINE_REPO_TOKEN` caducado | crear un token nuevo con lectura del repositorio del algoritmo y guardarlo con el mismo nombre |
| El despliegue falla en Vercel | `VERCEL_TOKEN` caducado | crear uno nuevo en Vercel y guardarlo en GitHub |

## Ramas y commits

- El trabajo va en la rama `centro-de-pruebas` y entra a `master` por *pull request* (`.claude/CLAUDE.md` lo fija para
  quien trabaje con Claude).
- Los commits van a nombre de Garridoparrayeray.
- Mensajes en castellano, con el formato `tipo(ámbito): descripción`.
