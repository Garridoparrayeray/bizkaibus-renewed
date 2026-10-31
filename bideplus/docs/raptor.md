# Plan: buscador de viajes con RAPTOR en C

Cómo añadir a Bide+ un **buscador de viajes puerta a puerta** («de esta parada a esta otra, saliendo ahora, ¿qué cojo y
dónde hago transbordo?») con el algoritmo **RAPTOR**, escrito en **C**, sobre los datos GTFS que ya tenemos.

Hoy Bide+ sabe los horarios de una parada, el horario de una línea y por dónde va cada bus, pero **no** sabe unir dos
paradas con uno o varios transbordos. Eso es lo que falta, y es justo lo que RAPTOR hace bien.

> Este documento es el plan: qué construir, en qué orden y por qué. No hay que implementarlo de golpe; los hitos del
> final se pueden hacer uno a uno.

---

## 1. Qué es RAPTOR y por qué, y no un grafo con Dijkstra

La forma «de libro» de buscar rutas es montar un grafo gigante (cada parada-en-cada-minuto es un nodo) y correr
Dijkstra o A\*. Funciona, pero el grafo es enorme, se pierde mucho tiempo en la cola de prioridad y cuesta sacar la
segunda mejor opción («llego 3 minutos más tarde pero sin transbordar»).

**RAPTOR** (*Round-bАsed Public Transit Optimized Router*, Delling, Pajor y Werneck, Microsoft Research, 2012) le da la
vuelta. No hay grafo ni cola de prioridad: solo recorre las líneas por **rondas**.

- **Ronda 0:** estás en el origen.
- **Ronda 1:** coges **un** vehículo. ¿A qué paradas llegas y a qué hora, cogiendo una sola línea?
- **Ronda 2:** haces **un** transbordo. Desde donde dejó la ronda 1, coges otro vehículo.
- **Ronda k:** `k-1` transbordos.

La clave: **el número de ronda es el número de vehículos cogidos**. Así RAPTOR saca de un tirón, para cada parada, lo
mejor con 0 transbordos, con 1, con 2… (el llamado *frente de Pareto* entre «llegar antes» y «transbordar menos»). Eso
es exactamente lo que la gente quiere ver: «el directo tarda más, pero sin cambiar».

Ventajas para nosotros:

- **Rápido de verdad.** Recorre arrays seguidos en memoria (muy amigable con la caché del procesador), sin cola de
  prioridad. Con nuestros tamaños (ver §3) una consulta son **microsegundos**.
- **Da varias opciones** (por número de transbordos) sin coste extra.
- **Fácil de meter el tiempo real**: los retrasos solo cambian los tiempos que lee; el algoritmo es el mismo (§9).
- **Sencillo de escribir en C**: son unos pocos arrays y dos bucles. No necesita librerías.

---

## 2. Qué resuelve, en una frase

> Dadas una parada (o punto) de origen, una de destino y una hora de salida, devolver las mejores formas de llegar —la
> más temprana, y las que llegan algo más tarde pero con menos transbordos— diciendo qué línea coger, dónde subir, dónde
> bajar y dónde transbordar.

Y, como las redes de Bizkaia comparten territorio, el mismo motor permite **combinar redes** (bus + metro + Renfe +
Euskotren + tranvías) en un solo viaje. Esa es una baza fuerte para un ayuntamiento (§10).

---

## 3. Los datos que necesita, y de dónde salen

RAPTOR trabaja con cuatro cosas. Las cuatro ya están en nuestras bases GTFS (`data/<red>.sqlite`); solo hay que
**reordenarlas** a una forma compacta. El vocabulario de RAPTOR y nuestras tablas:

| RAPTOR | Qué es | De dónde sale |
| --- | --- | --- |
| **Route** (ruta) | Un conjunto de viajes con **la misma secuencia de paradas** | `journey_patterns` — ¡ya están agrupados así! |
| **route_stops** | La secuencia de paradas de cada ruta | `journey_pattern_stops` (`seq_order`, `stop_id`) |
| **Trips** de una ruta | Los viajes concretos, ordenados por hora | `service_journeys` + `passing_times` |
| **stop_times** | La hora de paso por cada parada de cada viaje | `passing_times` (`arrival_seconds`, `departure_seconds`) |
| **stop_routes** | Para cada parada, qué rutas la tocan | Se deriva de `journey_pattern_stops` |
| **transfers** (a pie) | Pasos andando entre paradas cercanas | Se calcula con las coordenadas (`stops.lat/lon`) + `station_id` |
| Calendario | Qué viajes circulan ese día | `service_calendars` (`weekday_mask`, `from/to_date`) + `service_calendar_exceptions` |

> **El detalle que lo hace fácil:** en GTFS puro, una «route» no sirve para RAPTOR (mezcla recorridos distintos) y hay
> que agrupar los viajes por secuencia de paradas. **Nosotros ya tenemos ese grupo hecho: es `journey_patterns`.** Un
> patrón es, por definición, los viajes con la misma lista de paradas. Nos ahorramos el paso más pesado.

Tamaños hoy (red Bizkaibus), para que se vea lo pequeño que es:

| Tabla | Filas |
| --- | --- |
| stops | 2 346 |
| lines | 100 |
| journey_patterns (= rutas RAPTOR) | 483 |
| journey_pattern_stops | 12 186 |
| service_journeys (= viajes) | 6 097 |
| passing_times (= stop_times) | 138 513 |

Incluso **sumando todas las redes** son unos pocos cientos de miles de filas: cabe de sobra en memoria y en un fichero
de pocos MB (importa para la opción del navegador, §11).

---

## 4. Paso 1 — Precálculo: la «red temporal» binaria

RAPTOR no lee SQLite en caliente; lee arrays ya ordenados. Así que primero, **una vez por publicación de horarios** (en
el `build-database.php` o en un script aparte `scripts/build-raptor.php`), se vuelca la base a un **fichero binario
compacto** por red (o uno combinado para intermodal). Formato tipo **CSR** (filas comprimidas: un array de datos seguido
y un array de «dónde empieza cada grupo»), que es como RAPTOR quiere los datos.

Contenido del fichero (todo enteros, ids ya reindexados a 0..N):

1. **Paradas**: `lat`, `lon` (para footpaths y para el punto de origen/destino).
2. **Rutas** (patrones): para cada ruta, cuántas paradas tiene y cuántos viajes.
3. **route_stops**: la secuencia de paradas de cada ruta, una detrás de otra.
4. **stop_times**: para cada ruta, sus viajes **ordenados por hora de salida**, y para cada viaje los
   `arrival`/`departure` en cada parada (en segundos desde medianoche). Ordenar por hora es lo que permite el truco de
   RAPTOR de «coge el primer viaje que sale a tu hora o después».
5. **stop_routes**: para cada parada, la lista de `(ruta, posición en esa ruta)` que la tocan.
6. **transfers**: para cada parada, la lista de `(parada vecina, segundos a pie)`.

> Reindexar ids de texto (`"2627"`, `"3514-12-27600"`) a enteros 0..N es importante: el C trabaja con índices de array,
> no con cadenas. Se guarda también la tabla «índice → id de texto» para traducir el resultado de vuelta.

El calendario se resuelve **al construir** la consulta, no dentro del C: antes de llamar a RAPTOR se filtra a los viajes
que circulan ese día (o se construye un fichero por tipo de día: laborable, sábado, festivo…). Para la primera versión,
lo más simple: el precálculo saca **un fichero por tipo de día** y la API elige el del día pedido.

### Los transbordos a pie (footpaths)

RAPTOR necesita saber andar entre paradas cercanas (bajar del metro y andar 150 m a la parada del bus). Se generan en el
precálculo:

- Dos paradas con el **mismo `station_id`** (andenes de una estación): transbordo de ~0 s.
- Paradas a menos de, por ejemplo, **250 m**: tiempo a pie = distancia / velocidad (≈ 1,1 m/s) + un margen fijo.
- La distancia ya la sabemos calcular: la fórmula de Haversine está en `api/Models/Stop.php` (`distanceMeters`).

Importante: los footpaths deben ser **transitivos y cerrados** (si A→B y B→C, conviene A→C) para que RAPTOR no pierda
opciones; en la práctica, con un radio pequeño basta con incluir los pares directos.

---

## 5. Paso 2 — El algoritmo (pseudocódigo)

Dos arrays de tiempos (segundos desde medianoche; «infinito» = aún no se llega):

- `tau[k][p]` = hora más temprana a la que se llega a la parada `p` con **como mucho `k` vehículos**.
- `tau_estrella[p]` = la mejor hora a la que se llega a `p` con cualquier número de vehículos (para podar).

Marcamos paradas «tocadas» en la ronda anterior; solo esas pueden generar algo nuevo.

```
tau[*][*] = INFINITO
tau[0][origen] = hora_de_salida
tau_estrella[origen] = hora_de_salida
marcadas = { origen }

para k = 1, 2, 3, ... (hasta que no se marque nada; en la práctica 4–6 rondas):
    tau[k] = tau[k-1]                      # arrastra lo de la ronda anterior

    # (a) Reunir las rutas que tocan alguna parada marcada
    rutas_a_mirar = {}
    para cada parada p en marcadas:
        para cada (ruta r, posición i) que toque p:
            rutas_a_mirar[r] = min(posición ya anotada, i)   # la parada más temprana por la que entramos
    limpiar marcadas

    # (b) Recorrer cada ruta una vez, de delante hacia atrás
    para cada (ruta r, posición de entrada i0) en rutas_a_mirar:
        viaje_cogido = NINGUNO
        para cada parada p = route_stops[r] desde i0 hasta el final:
            si viaje_cogido != NINGUNO:
                # ya vamos montados: ¿mejora la llegada a p?
                llegada = arrival(viaje_cogido, p)
                si llegada < min(tau_estrella[p], tau_estrella[destino]):   # poda local y al destino
                    tau[k][p] = llegada
                    tau_estrella[p] = llegada
                    marcar p
                    guardar «se llegó a p con el viaje_cogido desde la parada de subida»  # para reconstruir
            # ¿podemos subirnos aquí a un viaje más temprano que el que llevamos?
            si tau[k-1][p] <= departure(primer_viaje_posible_en_p, p):
                viaje_cogido = primer viaje de r que sale de p a las tau[k-1][p] o después

    # (c) Transbordos a pie desde las paradas que mejoraron
    para cada parada p recién marcada:
        para cada (vecina q, pie) en transfers[p]:
            si tau[k][p] + pie < tau[k][q]:
                tau[k][q] = tau[k][p] + pie
                tau_estrella[q] = min(tau_estrella[q], tau[k][q])
                marcar q
```

Al final, `tau_estrella[destino]` es la llegada más temprana, y `tau[1][destino]`, `tau[2][destino]`… dan las opciones
con 0, 1, 2… transbordos. La reconstrucción del viaje (qué líneas, dónde subir y bajar) se hace siguiendo hacia atrás lo
que se guardó en «para reconstruir».

Las **podas** (`tau_estrella`) son lo que lo hace volar: en cuanto una llegada no mejora lo mejor conocido a esa parada
ni al destino, se descarta.

---

## 6. Paso 3 — La implementación en C

Estructuras en memoria (todo `int32`, arrays seguidos cargados del fichero con `mmap`, sin copiar):

```c
typedef struct {
    int32_t  n_stops, n_routes;

    // Rutas (CSR)
    int32_t *route_stop_off;   // [n_routes+1] dónde empieza cada ruta en route_stops
    int32_t *route_stops;      // paradas en orden, todas las rutas seguidas
    int32_t *route_trip_off;   // [n_routes+1] dónde empiezan los viajes de cada ruta
    int32_t *route_n_stops;    // [n_routes] nº de paradas de la ruta (ancho de la matriz de tiempos)
    int32_t *stop_times;       // arr/dep por (viaje, parada); viajes ordenados por salida

    // Para cada parada, qué rutas la tocan (CSR)
    int32_t *stop_route_off;   // [n_stops+1]
    int32_t *stop_routes;      // pares (ruta, posición) aplanados

    // Transbordos a pie (CSR)
    int32_t *transfer_off;     // [n_stops+1]
    int32_t *transfer_to;      // parada vecina
    int32_t *transfer_secs;    // segundos a pie

    float   *lat, *lon;        // [n_stops]
} Timetable;

#define MAX_ROUNDS 6
int32_t tau[MAX_ROUNDS+1][/*n_stops*/];   // reservado dinámicamente
int32_t tau_star[/*n_stops*/];
// y arrays «boarded_from»/«prev» para reconstruir
```

El bucle principal es el pseudocódigo de §5 traducido casi literal. Buscar «el primer viaje que sale a mi hora o
después» es una **búsqueda binaria** sobre los viajes de la ruta (están ordenados por salida). La memoria por consulta es
`(MAX_ROUNDS+1) × n_stops × 4 bytes` ≈ unos pocos MB; se reserva una vez y se reutiliza.

Interfaz pública mínima:

```c
Timetable *tt_load(const char *path);                 // mmap del fichero binario
int  raptor_query(const Timetable *tt,
                  int32_t from_stop, int32_t to_stop,
                  int32_t departure_secs,
                  Journey *out, int max_journeys);     // devuelve nº de opciones
void tt_free(Timetable *tt);
```

Origen/destino como **punto** (lat/lon) en vez de parada: se resuelve a «paradas a pie del punto» antes de llamar
(varias paradas de origen con su tiempo a pie inicial, varias de destino). Eso es un RAPTOR con **multiorigen**: se
siembran todas las paradas de origen en la ronda 0.

---

## 7. Variantes (para más adelante)

- **rRAPTOR / consulta por franja**: en vez de «salgo a las 9:00», «¿qué sale entre las 9:00 y las 10:00?». Se corre
  RAPTOR hacia atrás sobre las salidas de esa franja; da el **perfil** de viajes de la próxima hora. Muy útil para
  enseñar «los próximos enlaces».
- **McRAPTOR (multicriterio)**: añadir criterios además de hora y transbordos (tiempo a pie, fiabilidad). Más complejo;
  no hace falta para la primera versión.
- **Hora de llegada objetivo** («quiero estar a las 9:00»): RAPTOR invertido.

---

## 8. Tiempo real: enganchar la predicción del motor

Esta es la parte bonita y donde Bide+ ya tiene ventaja. El motor (`engine/`) **predice** cuánto se desvía cada bus del
horario (los tiempos por tramo). Para que el buscador use el directo:

- Antes de la consulta, se ajustan los `departure`/`arrival` de los viajes que están en circulación con el retraso
  estimado (un *offset* en segundos). RAPTOR no cambia: solo lee tiempos distintos.
- Para los viajes futuros (aún no salidos), se usa el horario oficial, opcionalmente corregido por el patrón típico de
  esa franja que el motor ya calibra (`segment-times.json`).
- Como el fichero binario es de solo lectura (mmap compartido), los retrasos se pasan **aparte**, en un array pequeño
  `delay[route][trip]` que la consulta suma al vuelo. Así el horario base se comparte entre todas las consultas y solo
  cambia la capa de retrasos.

Resultado: «coge el de las 9:05, que hoy va con 4 minutos de retraso, así llegas al enlace de las 9:20».

---

## 9. Intermodal: varias redes en un viaje (la baza para un ayuntamiento)

Como las redes comparten territorio, se puede construir **un fichero binario combinado** con las paradas de todas las
redes y footpaths entre paradas de redes distintas que estén cerca (la estación de Renfe y la parada de bus de al lado).
RAPTOR no distingue redes: para él son todo rutas y paradas. El resultado es un viaje real puerta a puerta que mezcla bus,
metro, tren y tranvía.

Para un ayuntamiento que quiera su propia app, esto es vendible: «buscador de viajes de toda la comarca, combinando
todos los transportes, con tiempo real». Encaja con el kit de redes (`docs/nueva-red.md`): una red nueva entra en el
fichero combinado sin tocar el algoritmo.

---

## 10. Cómo encaja con PHP y Vercel (tres opciones)

El reto: Vercel corre PHP **sin estado** (una función por petición, sin procesos largos). Tres maneras de meter el C,
de menos a más recomendable para nuestro caso:

1. **Binario por consulta (subproceso).** Se compila el C a un ejecutable; PHP lo llama con `proc_open`, le pasa
   `from,to,at` y lee el JSON que devuelve. El binario hace `mmap` del fichero y responde en microsegundos.
   - ✅ Simple, aislado, fácil de depurar.
   - ⚠️ Hay que poder incluir y ejecutar un binario en el runtime de Vercel; arranque por consulta (mmap es barato, el
     coste es el `fork/exec`).

2. **PHP-FFI (una `.so`).** El C se compila a librería y PHP la carga con FFI, llamando a `raptor_query` directo.
   - ✅ Sin `fork`, misma memoria del proceso PHP.
   - ⚠️ FFI tiene que estar habilitado en el runtime; más acoplado.

3. **WebAssembly en el navegador (recomendada para la app pública).** El mismo C se compila a **WASM**
   (Emscripten/`clang --target=wasm32`), y la búsqueda corre **en el móvil**, no en el servidor. El fichero binario
   (pocos MB) se descarga una vez y se cachea en el service worker (Bide+ ya funciona sin conexión).
   - ✅ **Coste de servidor cero** y escala infinito: cada móvil calcula su propio viaje.
   - ✅ Funciona **sin conexión**, como el resto de la app offline.
   - ✅ Instantáneo: sin ida y vuelta al servidor.
   - ⚠️ Hay que bajar el fichero (se puede partir por red / por tipo de día y bajar solo lo que haga falta).

**Recomendación:** empezar por la **opción 1** (binario + API) para validar el algoritmo y los datos contra casos
reales, y después portar a **WASM** (opción 3) para la app pública, que es donde brilla (gratis, offline, instantáneo).
El mismo código C vale para las tres; solo cambia cómo se le llama.

### API nueva (opción 1)

```
GET /api/journey?from=2627&to=3940&at=2026-11-03T09:00&red=bus
# o por punto:
GET /api/journey?fromLat=..&fromLon=..&toLat=..&toLon=..&at=..
```

Respuesta: lista de opciones, cada una con sus tramos (línea, parada de subida y hora, parada de bajada y hora,
transbordos a pie). Validación de entrada **igual de estricta** que el resto (ver `docs/seguridad.md`): ids con forma de
id, fecha con patrón, coordenadas a número y acotadas.

### Front

Una pantalla «Cómo llegar»: dos campos (origen/destino, reutilizando el buscador de paradas que ya existe), hora, y la
lista de opciones con el frente de Pareto («más rápido» / «menos transbordos»). Los tramos se pueden pintar en el mapa de
línea que ya tenemos.

---

## 11. Pruebas y validación

- **Correción**: comparar RAPTOR con una búsqueda ingenua (Dijkstra sobre grafo tiempo-expandido) en un conjunto de
  pares origen-destino; las horas de llegada tienen que coincidir.
- **Casos límite**: origen = destino; sin viaje posible; último servicio del día; viajes que cruzan medianoche
  (segundos > 86400).
- **Rendimiento**: medir p50/p99 de la consulta; objetivo < 5 ms server-side, < 50 ms en WASM en un móvil normalito.
- **Datos**: un test que reconstruya el fichero binario desde SQLite y verifique que cada ruta/viaje casa con
  `passing_times` (como hace `tests/static-checks.py` con lo demás).
- **Reglas del repo**: sin ternarios, C claro y comentado, y el script de precálculo documentado en `docs/`.

---

## 12. Hitos

1. **Precálculo** (`scripts/build-raptor.php`): de SQLite al fichero binario CSR, con footpaths y un fichero por tipo de
   día. Test de integridad.
2. **Núcleo RAPTOR en C**: `tt_load` + `raptor_query` (earliest arrival + transbordos), reconstrucción del viaje.
   Pruebas de corrección contra la búsqueda ingenua.
3. **API `/api/journey`** (opción 1, subproceso) + validación + pantalla «Cómo llegar» básica.
4. **Tiempo real**: capa de retrasos del motor.
5. **Intermodal**: fichero combinado multired + footpaths entre redes.
6. **WASM**: portar el C al navegador y servirlo offline; quitar la dependencia del servidor para la app pública.
7. **Extras**: consulta por franja (rRAPTOR), hora de llegada objetivo.

## 13. Riesgos y decisiones abiertas

- **Calendario**: lo más simple es un fichero por tipo de día; si hay muchos calendarios raros (líneas escolares,
  refuerzos), habrá que resolver el calendario dentro de la consulta en vez de por fichero.
- **Medianoche**: los viajes que pasan de las 24:00 llevan segundos > 86400 en GTFS; hay que respetarlo en el binario.
- **Footpaths**: elegir bien el radio (250 m) y la velocidad a pie; demasiados footpaths ralentizan, muy pocos pierden
  enlaces.
- **Runtime de Vercel** para la opción 1/2: confirmar que se puede ejecutar binario o FFI; si no, ir directo a WASM.
- **Tamaño del binario en WASM**: partir por red/tipo de día y bajar bajo demanda.
