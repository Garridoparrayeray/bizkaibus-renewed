# Notificaciones

Bide+ manda dos tipos de notificaciones, las dos con la app cerrada: el **aviso de llegada** («A3514 llega en 5 min»)
y las **incidencias nuevas de los favoritos**. Las dos van por *web push*: el servidor cifra el mensaje para el navegador
que se suscribió y se lo entrega a su servicio de push (Google, Mozilla, Apple o Microsoft), que despierta el service
worker del móvil aunque la app no esté abierta.

En iPhone solo funcionan con Bide+ añadida a la pantalla de inicio (iOS 16.4 o posterior): Safari no deja pedir
permiso a una página suelta.

## Piezas

| Pieza | Archivo |
| --- | --- |
| Cifrado (RFC 8291, `aes128gcm`) y firma VAPID (RFC 8292), sin librerías | `api/Services/WebPush.php` |
| Claves VAPID, suscripciones válidas y la función que envía | `api/Services/Push.php` |
| Avisos de llegada (crear, cancelar, mandar) | `api/Services/ArrivalAlerts.php` |
| Incidencias de los favoritos (guardar, mirar, mandar) | `api/Services/IncidentAlerts.php` |
| Aviso de versión nueva (apuntar, mandar una vez por versión) | `api/Services/UpdateAlerts.php`, `api/Services/Changelog.php` |
| Rutas `/api/push/*` | `api/Controllers/PushController.php` |
| Quién lo dispara: el cron de cada minuto | `api/Controllers/LiveRecordController.php` |
| Suscripción en el navegador | `js/push.js` |
| Casilla de la ficha del viaje y lista de Ajustes | `js/app/arrival-alerts.js`, `js/arrival-alerts-store.js`, `js/settings.js` |
| Interruptor de incidencias | `js/incident-alerts.js` |
| «1» de Ajustes, novedades de la versión nueva y apuntarse a su aviso | `js/app-update.js` (`/api/app/version`) |
| Mostrar y tocar la notificación | `sw.js` (`push`, `notificationclick`) y `js/notification-open.js` |
| Comprobación sin push (navegadores sin push) | `js/alerts-store.js` y `periodicsync` en `sw.js` |

## Claves VAPID

Las claves que firman los mensajes se crean solas la primera vez y se guardan en el almacén (`bide:vapid`), salvo que
estén en las variables `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY`. **No hay que cambiarlas**: cada suscripción se hizo con
la clave pública de ese momento, y con otra clave el servicio de push rechaza los mensajes. Si alguna vez cambian, el
navegador se vuelve a suscribir solo la próxima vez que se abre la app (`pushSubscription` compara la clave).

Sin almacén, `GET /api/push/key` responde `publicKey: null` y la app no ofrece ni avisos ni incidencias por push.

## El cron

Un cron externo (cron-job.org) llama cada minuto a `GET /api/live/record` con la clave `BIDE_CRON_SECRET`. En esa
llamada, además de apuntar por dónde va cada bus (ver [registro de pasos](registro-de-pasos.md)):

- `ArrivalAlerts::process()` mira los avisos pendientes y manda los que ya tocan.
- `IncidentAlerts::process()` trabaja una vez cada 5 minutos (una marca `bide:incident:run` que dura 4 min y medio).
- `UpdateAlerts::process()` avisa de una versión nueva de la app (abajo). Mientras no la hay, cuesta un solo comando.

La respuesta dice cuánto se hizo: `arrivalAlerts: {pending, sent, dropped}`, `incidentAlerts: {subscribers, sent,
removed}` y `updateAlerts: {version, sent, pending}` (o `skipped` si no le tocaba). Un fallo en las notificaciones no estropea el registro de pasos.

## Avisos de llegada

Desde la ficha de un viaje abierta en una parada se elige 2, 5 o 10 minutos (`ArrivalAlerts::MINUTES`), en las siete
apps. El servidor busca ese viaje en las salidas de esa parada (`StopsController::departuresData`, las mismas cuentas
que la app) y guarda el aviso.

- **De una vez** (por defecto): se guarda 3 horas. Al llegar el momento se manda y se borra. Si el viaje pasa sin
  avisar (cancelado), se olvida.
- **Todos los días** (casilla «Avisarme todos los días»): se queda y avisa cada día que haya ese viaje, es decir, la
  misma clave de viaje (`línea-número-salida`) en esa parada. Una marca `bide:arrival:sent:<id>` de 12 horas evita
  repetirlo el mismo día. Fuera de ±1 h 30 min alrededor de su hora en el horario ni se mira, así que el resto del
  día no cuesta consultas. Dura dos meses desde la última vez que avisó: si el viaje deja de existir por un cambio de
  horario, se borra solo.
- **Envío una sola vez**: el aviso se quita de la lista (`SREM`) antes de mandarlo, así que dos llamadas del cron a la
  vez no lo duplican. Si el servicio de push falla, vuelve a la lista para el minuto siguiente. Un 404 o 410 significa
  que la suscripción ya no existe (app desinstalada o permiso quitado): se olvida.

En el móvil, la lista de avisos activos vive en `localStorage` (`bide-arrival-alerts`) para enseñarlos en la ficha y
en Ajustes y poder cancelarlos. Uno de una vez desaparece en cuanto avisa: el mensaje lleva `alertId` y `repeat`, el
service worker apunta el id en IndexedDB (`arrivalFired`), aunque la app esté cerrada, y avisa a las ventanas abiertas
(`bide:arrival-fired`).

## Incidencias de los favoritos

El interruptor está en Ajustes. Al activarlo, el navegador manda al servidor su suscripción, los favoritos de **todas**
las apps y el idioma (`POST /api/push/incidents`). Cada 5 minutos el servidor mira las incidencias de cada favorito a
la manera de su red (`features.alerts`):

| Red | Qué mira |
| --- | --- |
| Bizkaibus | cada línea guardada |
| Renfe | cada estación y cada línea guardadas |
| Metro, Euskotren, tranvías y Lurraldebus | toda la red, si hay algún favorito suyo |

- La primera vez que mira una fuente **solo apunta** lo que ya hay: no avisa de lo viejo. Un favorito nuevo empieza
  igual.
- Manda como mucho tres incidencias sueltas y, si hay más, un resumen («Más incidencias en tus favoritos»), en el idioma
  del suscriptor.
- Si el servicio de push falla, lo que no se mandó se reintenta en la siguiente vuelta. Una fuente que falla no hace
  que lo de antes parezca nuevo.
- El suscriptor caduca a los 90 días sin abrir la app. La app lo renueva al abrirse si cambian los favoritos o el
  idioma, y como mucho cada semana.

Si el navegador no tiene push, la app mira al abrirse y, donde se puede (Chrome instalado), en segundo plano cada 12
horas como mucho (`periodicsync`). Esas notificaciones salen igual que las del servidor.

## Versión nueva de la app

La versión de la app es el número de su última novedad (`Services\Changelog::ENTRIES`, la primera fila). Cuando sube
(se publica una novedad nueva y llega a producción):

- **Con las notificaciones permitidas:** el cron manda a cada móvil apuntado una notificación, una sola vez por versión:
  «Bide+ se ha actualizado» con los títulos de lo nuevo, en su idioma. Están apuntados los que tienen activados los
  avisos de incidencias y los que, con el permiso ya dado, abren la app (`js/app-update.js` manda su suscripción y su
  idioma a `POST /api/push/updates`, como mucho una vez al día). Se avisa como mucho a 300 por minuto; lo que falla
  por el servicio de push se reintenta y una suscripción que ya no existe se borra. En el almacén:
  `bide:update:subs` y `bide:update:sub:<id>`, los ya avisados de la versión (`bide:update:sent:<versión>`, 30 días) y la
  última versión avisada a todos (`bide:update:done`).
- **Sin notificaciones:** la app compara la versión de su código (la última novedad que trae `js/i18n.js`) con la del
  servidor (`GET /api/app/version?desde=<la del móvil>&lang=<idioma>`, que nunca sale de la caché). Si va por detrás, la
  rueda de Ajustes lleva un «1» en rojo (en la barra de pestañas, la columna del ordenador, el pie y el menú de cada
  app) y Ajustes enseña «Nueva actualización de la app» con lo que trae cada versión, en su idioma, encima de «Vaciar
  caché y actualizar», que también lleva el «1». Al vaciar la caché se carga el código nuevo y el «1» desaparece.

Los textos en euskera e inglés de cada novedad salen de `js/i18n.js` en el servidor: el móvil con la versión vieja aún
no los tiene.

## Al tocar una notificación

- **Llegada**: abre la parada con la ficha del viaje (`/stops/<id>?red=…&viaje=…`).
- **Incidencia**: abre la pestaña Avisos de su app (`/?red=…#avisos`).
- **Versión nueva**: abre Ajustes en el inicio de Bide+ (`/#ajustes`), directo a «Vaciar caché y actualizar».

`openFromNotification` (en `sw.js`) usa la ventana de la app que ya haya: la trae al frente y la lleva a la dirección.
Si no hay ninguna, abre una nueva. En iPhone, con la app cerrada, el sistema abre la app por el inicio y esa ventana
aún no se deja llevar («navigate failed», un fallo conocido de WebKit). Entonces el service worker se lo pide a la
página por mensaje (`bide:open`) y la página va ella (`js/notification-open.js`).

Cada dirección lleva `aviso=`, distinto en cada envío. La app lo apunta al abrirla (`AlertsStore.firstOpening`,
`bide-opened-alerts`, 7 días; en las apps lo hace `js/app/navigation.js` y en el inicio `js/bide.js`) y lo quita de la
dirección. Así, si Android vuelve a cargar la dirección con la que se abrió la app (pasa al reabrirla), la
ficha o la pestaña no vuelven a salir: la app se queda en su inicio.

Todas las notificaciones llevan el icono de su app y, para la barra de estado de Android, la silueta de Bide+ en blanco
sobre transparente (`icons-bide-rojo/badge-96.png`). Aunque un mensaje llegue roto, se enseña algo: en iPhone, un push
sin notificación hace que el sistema acabe quitando el permiso.

## Pruebas

- `tests/push-test.php`: cifrado contra el vector del RFC 8291, firma, direcciones permitidas, avisos de una vez y de
  todos los días, incidencias (línea base, solo lo nuevo, resumen, reintento, cada 5 minutos, baja), las siete redes y
  el aviso de versión nueva (una vez por versión, también a quien solo tiene incidencias, en su idioma, reintento y
  baja), contra un Upstash falso (`tests/fixtures/fake-upstash.php`).
- `tests/ui-bide.mjs`: con una versión más nueva en el servidor, el «1» en cada botón de Ajustes, el aviso con sus
  novedades, ir directo a actualizar y que la notificación abre Ajustes una sola vez; al día, sin «1».
- `tests/ui-alerts.mjs`: con Chromium, el push con la app cerrada y el service worker parado (llegada, incidencia y
  mensaje roto), qué pasa al tocarla con y sin ventana, que la lista se limpia sola y que la página no va a direcciones
  de fuera.
- `tests/ui-bide.mjs`: la casilla de la ficha en las siete apps, Ajustes y que la ficha de una notificación no vuelve a
  salir al recargar.
