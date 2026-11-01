# Sin conexión

Bide+ sigue funcionando sin cobertura de dos formas: lo que ya se abrió se guarda solo, y el horario completo de las
redes que se elijan se puede guardar en el móvil.

## Lo que se guarda solo (service worker)

`sw.js` guarda tres cosas, cada una con su estrategia:

| Qué | Estrategia | Caché |
| --- | --- | --- |
| La «carcasa»: estilos, scripts, tipografías, iconos (`SHELL_FILES`) | se guardan al instalar; después, de la caché al instante y se renuevan en segundo plano | `bizkaibus-shell-<versión>` |
| Páginas (una app, una parada, una línea) | la red manda, pero si tarda más de 1,5 s y hay copia, se usa la copia | la misma |
| Respuestas de la API que no son en directo (paradas, líneas, horarios) | la red y, si falla, la última copia | `bizkaibus-api-<versión>` |

Las rutas en directo (`departures`, `live`, `vehicles`, `alerts`, `nearby`) y la versión de la app (`app/version`) no se
guardan: una hora de llegada vieja engaña más que ninguna, y una versión vieja ocultaría la nueva. Sin conexión, una
parada o una línea que nunca se abrió se sirve con cualquier página guardada de su app (`appPageFallback`): la página
lee la dirección y la pinta con el horario guardado.

**`CACHE_VERSION`** (al principio de `sw.js`) se sube en cada cambio de la parte visible: así los móviles descartan la
carcasa vieja. La batería de limpieza comprueba que todo lo que cargan las páginas esté en `SHELL_FILES`.

## El horario completo en el móvil

En Ajustes, «Horarios sin conexión» guarda el horario oficial de cada red (o de todas a la vez) en una caché aparte,
`bide-offline`, que no depende de la versión: actualizar la app no lo borra. Los archivos los genera
`scripts/build-offline-data.php` en cada reconstrucción diaria y van en `offline-data/<red>.json`, con un índice
`offline-data/index.json` (tamaño y fecha de cada red; la app avisa si lo guardado es de otro día).

Lo guardado se actualiza solo: al abrir la app, al volver a ella y al recuperar la conexión, como mucho cada 6 horas,
`BideOffline.refresh()` mira el índice y vuelve a bajar las redes guardadas con un horario más nuevo. Si una descarga
falla, lo intenta otra vez la próxima vez.

### Formato (`format: 2`)

Cada cosa se guarda una vez y los viajes la nombran por su posición, para que el archivo sea pequeño:

| Campo | Qué es |
| --- | --- |
| `lines` | `[id, código, nombre]` |
| `calendars` | `[máscara de días (lunes = bit 0), días que se añaden, días que se quitan]`, como en la API |
| `patterns` | `[línea, destino, [paradas en orden]]` |
| `timings` | segundos desde la primera parada a cada una |
| `trips` | `[recorrido, horas, primera hora, calendario, número de viaje, salida de cabecera, estimada laborable, estimada fin de semana y festivo]` |
| `estimates`, `estimateTrusted` | segundos estimados desde la salida a cada parada (ya con la corrección del viaje), y por cada parada un `1` si lo aprendido hasta ella basta para decir que un bus ya pasó (como el servidor) |
| `stops` | `[nombre]`, o `[nombre, estación, andén]` en las redes con andenes |
| `directionReference`, `lon` | lo que Metro necesita para repartir las salidas en dos columnas por sentido |

`js/offline.js` calcula con eso las salidas de una parada con la misma forma que las de la API (`departures`), así que
el resto de la app no distingue si vienen del servidor o del móvil. `tests/offline-schedule.mjs` comprueba en las siete
redes que salen las mismas que da la API.

### La hora estimada sin datos del bus

Donde la red usa el algoritmo (Bizkaibus), el archivo lleva para cada viaje su hora estimada con lo aprendido, para un
día laborable y para uno de fin de semana. Es la misma cuenta que hace el servidor cuando no tiene la posición del bus:
salida publicada + 1 minuto + lo que tardan de verdad los buses en cada tramo, con la franja horaria y el ritmo del
punto medio, + lo que suele apartarse ese viaje en ese tercio del recorrido (`engine/bizkaibus/TripOffsets.php`).
Un festivo entre semana usa la estimación de fin de semana, como el servidor: `js/offline.js` sabe que es festivo
porque ese día lo añaden los calendarios de solo domingo. Comparada con el servidor en 1.200 paradas al azar de un
laborable, un sábado y un festivo, la diferencia máxima es de 6 s.

La corrección por viaje no ocupa sitio aparte: va sumada a cada hora estimada. Por eso hay más horas distintas que antes
(9.196 frente a 5.339) y el archivo de Bizkaibus pasa de 1,1 a 1,5 MB (0,5 MB comprimido). A cambio, contra el paso real
de los buses en 7 días que no se usaron para aprender, el error medio baja de 210 a 158 s y las que aciertan con ±2 min
pasan del 47 % al 54 % (ver `algoritmo-explicado.md`, «Corrección por viaje»).

Sin conexión, cada salida se enseña como con conexión y sin datos del bus:

| Caso | Se enseña |
| --- | --- |
| El cálculo dice que aún viene | **ESTIMADO**, con la hora oficial debajo en gris |
| La estimación pasó hace más de 10 min, el horario va 10 min o más por detrás y los tramos aprendidos cubren el 80 % del recorrido | al final y en gris, «prob. ya pasó · hora oficial» |
| Estimación pasada hace menos de 10 min, o sin estimación | la hora oficial, **SOLO HORARIO** |

### Marcas de «ya pasó» y «no ha pasado»

Solo sin conexión, cada salida tiene un botón para marcarla:

- «Ya pasó» la esconde en esa parada y en las anteriores de su recorrido.
- «No ha pasado» (en las que salen como probablemente pasadas) la devuelve con su hora oficial ahí y en las siguientes.

Se guardan en el móvil por día de viaje (`localStorage`, `bide-offline-marks`). Se borran solas las de antes de ayer.
«Mostrar los marcados como pasados (n)» las deshace. Con conexión no hay botones y las marcas no se usan: manda el
directo.

## Pruebas

- `tests/offline-schedule.mjs`: las salidas guardadas son las de la API en las siete redes, y los casos de estimación y
  de marcas.
- `tests/ui-offline.mjs`: que las páginas solo piden recursos propios y que con el servidor caído la app arranca desde
  el service worker, enseña la estimación y deja marcar y deshacer.
