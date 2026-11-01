# El algoritmo de Bizkaibus+, explicado

Cómo Bide+ convierte «el horario dice 9:00» en «llega de verdad en 6 minutos». Hay **dos versiones del mismo
contenido**: la primera sin una sola fórmula, para explicarlo a cualquiera; la segunda formal, con el modelo
estadístico, para alguien con formación matemática. La referencia técnica viva está en el motor
(`engine/docs/algoritmo.html`); esto es para **enseñarlo**.

---

# Parte A · Sin matemáticas (para cualquier público)

## El problema

El horario oficial dice que el bus pasa a las 9:00. Pero son las 9:00 y el bus aún no está: hay tráfico, iba lleno,
llueve. Lo que la gente quiere saber no es el horario de papel, sino **cuánto falta de verdad**. Eso es lo que calcula
Bide+, en tres pasos.

## Paso 1 · ¿Dónde está cada bus ahora?

Bizkaibus publica la posición GPS de sus autobuses. Cada minuto, un proceso de Bide+ mira esas posiciones y las **casa**
con los viajes del horario: «este punto en el mapa, a esta hora, es el viaje de las 8:40 de la línea A3338, y va por aquí».

No siempre es evidente (dos buses de la misma línea cerca, un GPS que se despista), así que el sistema es desconfiado:
si la posición no encaja con lo que debería, la descarta en vez de inventar. Y va **apuntando** por qué parada ha pasado
cada bus y a qué hora; ese registro sirve para dos cosas: enseñar el histórico y, sobre todo, **aprender** (paso 2).

## Paso 2 · ¿Cuánto se tarda de verdad entre dos paradas?

Un recorrido se parte en **tramos**: de una parada a la siguiente. El horario dice cuánto «debería» costar cada tramo,
pero la realidad cambia según la hora: el tramo del centro a las 8 de la mañana (hora punta) no es el mismo que a las 11
de la noche.

Así que Bide+ **observa durante semanas** cuánto tarda de verdad cada tramo, separando por franjas horarias (madrugada,
punta de la mañana, mañana, mediodía, punta de la tarde, noche) y por día (laborable o fin de semana). Con todo eso se
queda con **el tiempo típico** de cada tramo en cada franja.

Dos decisiones importantes, y el porqué:

- **Usamos el tiempo «típico» (la mediana), no el promedio.** Si un día un bus se queda clavado 20 minutos por un
  accidente, el promedio se dispara y mentiría; la mediana (el valor del medio) ni se entera. Es más robusta.
- **Cuando hay pocos datos de una franja, nos fiamos a medias.** Si de la «punta de la tarde» de un tramo solo hemos
  visto 2 buses, ese dato es flojo. Entonces lo **mezclamos** con el tiempo típico del tramo a todas horas, como si ese
  valora 5 buses más. Con pocos datos manda el valor general (más estable); según llegan más observaciones de esa
  franja, manda lo propio de la franja. Es el equilibrio entre «lo que sé de este tramo a esta hora» y «lo que sé de
  este tramo en general».

## Paso 3 · Juntar los tramos para decir cuánto falta

Para saber cuándo llega el bus a **tu** parada: se mira dónde está ahora y se van **sumando los tiempos típicos** de los
tramos que le quedan hasta ti. Del tramo que está haciendo ahora mismo solo se cuenta la parte que le falta (si va por la
mitad, medio tramo). Esa suma, a partir de la hora actual, es la hora de llegada estimada. El **retraso** es,
sencillamente, esa hora menos la del horario.

## La escalera de «si no sé, pregunto a lo siguiente»

¿Y si de un tramo no hemos visto nunca cuánto cuesta a esa hora? Hay una escalera, de lo más fino a lo más basto:

1. **Lo medido en esa franja** para ese tramo. Es lo mejor.
2. Si no, **lo medido en ese tramo a cualquier hora**.
3. Si tampoco, **tramos parecidos en longitud** (cortos, medios, largos): cuánto se suelen desviar del horario los tramos
   de ese tamaño a esa hora.
4. Si no hay nada de eso, el **ritmo general de esa línea** a esa hora: si esa línea suele ir, por ejemplo, un 10 % más
   lenta que el papel por la tarde, se estira el horario un 10 %.

Siempre con topes de sentido común, para que una estimación rara no se vuelva disparatada.

## ¿Cómo sabemos que funciona?

No vale con que «parezca» bien. Se mide así: se **tapa un día entero** de datos, se aprende con los demás y se predice el
día tapado como si no lo conociéramos; luego se compara la predicción con lo que de verdad pasó. Es la única forma honesta:
se prueba con datos que el sistema **no** ha visto al aprender. Con eso se sabe en qué porcentaje de casos se acierta
dentro de 1 minuto, dentro de 2, etc. Y cada día el sistema se recalibra solo con lo nuevo.

## Lo que ha cambiado en las últimas versiones

- **Tres formas de decir la hora, y se dice cuál es.** Con la posición del bus, la llegada sale **en directo**. Si el bus
  no manda posición pero se sabe por qué parada pasó, o si no se sabe nada de él, la hora se calcula igual, sumando lo
  que tardan de verdad los tramos desde su salida, y sale como **«ESTIMADO»**, con la hora oficial debajo en gris para
  comparar. Solo si tampoco hay tramos aprendidos sale **«SOLO HORARIO»**.
- **Un bus que ya pasó no vuelve a salir.** Un proceso apunta cada minuto por qué parada va cada bus, aunque nadie esté
  mirando la app. Si un bus va muy adelantado y deja de mandar posición (llega a cabecera y se apaga), la app sabe que
  ya pasó por tu parada y no lo enseña con su hora de papel. Si no hay registro pero el cálculo dice que pasó hace más
  de 10 minutos y el horario va claramente más lento, sale al final y en gris como «probablemente ya pasó».
- **También sin conexión.** Los horarios que se guardan en el móvil llevan la hora estimada de cada viaje, calculada como
  en el servidor: sin cobertura se ve lo mismo que con cobertura cuando no hay datos del bus.
- **Cada viaje, con su costumbre.** La hora estimada sin datos del bus llegaba de media 2 minutos antes que el bus: sumar
  lo típico de cada tramo se queda corto, y hay viajes que salen tarde casi siempre. Ahora se aprende, de los pasos
  reales de los últimos días, cuánto suele apartarse cada viaje (el de las 8:10 de tal línea) al principio, a la mitad
  y al final de su recorrido, y se suma. Probado con días que no se usaron para aprender, el error medio baja de 3,5 a
  2,6 minutos, y las que aciertan con menos de 2 minutos de error pasan del 47 % al 54 %. Vale con conexión, para los
  buses que aún no han salido, y sin conexión.
- **Cuánto acierta, medido en serio.** En 2,37 millones de llegadas de 17 días, comparadas con el paso real del bus, la
  hora que enseña Bide+ acierta con menos de 2 minutos de error en el 96 % de los casos cuando faltan menos de 3 minutos,
  y en el 67 % cuando faltan entre 10 y 20. El horario oficial acierta así en el 24 % y el 25 %.
- **Comparado con métodos más complicados.** Se probaron, con los mismos días y las mismas reglas, un filtro de Kalman y
  una red neuronal que además usan lo que está pasando ese día. Mejoran un poco (entre un 4 y un 7 %), pero el método
  sencillo ya se queda con casi toda la mejora sobre el horario. De momento no se cambia: antes hay que medir la mejora
  sobre la llegada completa que ve la gente, no solo sobre la suma de tramos.

---

# Parte B · Versión formal (máster / doctorado)

## Planteamiento

Sea un viaje (service journey) una secuencia de paradas con horas programadas. Para un vehículo en circulación y una
parada objetivo, se quiere estimar la hora de llegada (ETA) y, de ahí, el retraso. El estimador se apoya en un modelo de
**tiempos de recorrido por tramo**, estratificado por franja horaria y tipo de día, aprendido de observaciones y
recalibrado a diario.

### Notación

- Tramo `s = (u → v)`: paso entre dos paradas consecutivas.
- Franja `b`: `tipo_día ∈ {laborable, fin_semana}` × `franja ∈ {madrugada[0–6), punta_mañana[6–9), mañana[9–13),
  mediodía[13–16), punta_tarde[16–20), noche[20–24)}`.
- `track`: una ejecución concreta de un viaje (un bus en un día). Cada track aporta **una** observación por tramo (la
  duración real de ese tramo en ese track), para que un vehículo lento no cuente muchas veces.
- `D(s)`: horario programado del tramo `s` (segundos). `θ(s,b)`: tiempo real esperado del tramo `s` en la franja `b`.

## 1. Localización del vehículo

Las posiciones (SIRI *vehicle monitoring*) se emparejan con viajes del horario con tolerancias y filtros de
plausibilidad (distancia GPS-a-ruta ≤ 150 m, coherencia con la parada declarada, descarte de horarios equivocados,
antigüedad máxima de la posición). Además, del registro de pasos se obtiene una **«parada registrada»**: el último punto
confirmado por el que pasó el bus, que ancla la predicción cuando no hay GPS fino. El diseño es **conservador**: ante
ambigüedad o incoherencia, se marca el vehículo como no fiable y se cae al horario, en vez de arriesgar una posición
falsa.

## 2. Estimador del tiempo por tramo (lo que se calibra)

Para cada `(s,b)` con un número de tracks `n(s,b) ≥ n_min`, sea `m(s,b)` la **mediana muestral baja** (el valor central
sin promediar los dos centrales; elección deliberada por robustez y por reproducibilidad exacta entre PHP y Python). Sea
`m(s,·)` la mediana del tramo **a todas horas**.

El estimador publicado aplica **encogimiento (shrinkage) hacia la media del tramo** con una pseudocuenta `κ = 5`:

```
θ̂(s,b) = ( n(s,b) · m(s,b) + κ · m(s,·) ) / ( n(s,b) + κ )
```

Es un estimador tipo **Bayes empírico / contracción a la media**: con `n` pequeño domina el *prior* `m(s,·)` (estable,
mucha muestra), y según crece `n` converge a la mediana propia de la franja. Reduce la varianza del estimador a costa de
un sesgo controlado — justo el régimen útil cuando las franjas tienen pocas observaciones. Es la misma idea que un
James–Stein o un *partial pooling* jerárquico, aquí en su forma más simple (un solo nivel, peso fijo).

> `κ = 5` no es arbitrario: se eligió maximizando la precisión en validación (§5). El motor expone la constante
> (`SEGMENT_BAND_PRIOR_TRACKS`) y la herramienta `eval-segments.py` barre otros valores.

### Jerarquía de respaldo (cold-start)

Cuando `θ̂(s,b)` no existe (tramo/franja sin muestra suficiente), se desciende por una cascada, de menor a mayor sesgo:

1. `θ̂(s,b)` — mediana encogida de la franja.
2. `m(s,·)` — mediana del tramo a todas horas.
3. **Ratio por longitud**: `clip(r(bucket(s), b), 0.3, 1.5) · D(s)`, donde `bucket(s)` clasifica el tramo por metros
   (cortes en 300/600/1000/1500/3000 m) y `r` es el ratio típico real/programado de ese tamaño en esa franja.
4. **Factor de ritmo de línea**: `clip(f(línea, b), 0.6, 1.2) · D(s)`, el desvío típico de esa línea respecto al papel
   en esa franja (si no hay de la línea, el global).

Los recortes (`clip`) acotan cada nivel a un rango físicamente plausible.

## 3. Predicción de ETA (encadenado)

Dado un origen temporal `t₀` (hora de la posición, o de la parada registrada) con su hora programada asociada, el tiempo
restante hasta la parada objetivo con llegada programada `T` es la **suma por tramos**, repartiendo proporcionalmente los
tramos parcialmente recorridos:

```
remaining(t₀, T) = Σ_s  share_s · c(s,b)
```

donde `c(s,b)` es el tiempo del tramo según la cascada (§2), y para cada tramo `s = (u→v)` con horas programadas
`[D_u, D_v]`:

```
share_s = ( min(D_v, T) − max(D_u, t₀_prog) ) / ( D_v − D_u ),  acotado a [0, 1]
```

Es decir, se toma la fracción programada del tramo que cae dentro del intervalo `[t₀, T]` (**supuesto de ritmo constante
dentro del tramo**) y se escala el tiempo real estimado de ese tramo por esa fracción. La ETA es:

```
ETA = loc_seconds + remaining(scheduled_at_loc, T)
```

y el **retraso efectivo** `δ = ETA − T`, acotado a lo plausible (en bus, `|δ| ≤ 45 min`; fuera de rango se descarta y se
cae al horario). Si el vehículo ya pasó el objetivo, la ETA se deriva hacia atrás desde la posición. Para buses sin GPS
fino pero con parada registrada, se usa esa ancla: `ETA = ref + mitad_del_tramo_en_curso + remaining(next, T)`.

Cuando no hay ningún tramo aprendido (motor vacío), todo degenera limpiamente a `f(línea,b) · (T − t₀)`: el horario
corregido por el ritmo de la línea.

## 4. Dos propiedades del diseño

- **Robustez**: medianas en vez de medias en todos los agregados (tramos, ratios, factores); los percentiles se calculan
  igual en calibración (PHP) y evaluación (Python) para que los números cuadren.
- **No estacionariedad**: se absorbe con la estratificación por franja/tipo de día y con la **recalibración diaria**
  automática; el modelo no se congela.

## 5. Protocolo de evaluación

Dos medidas complementarias, ambas sobre datos no vistos al aprender:

1. **Validación por días (holdout temporal)**, `eval-segments.py`: se entrena con los días `< d` y se prueba el día `d`
   (los últimos N días). Para cada bus de prueba se **encadenan sus tramos reales consecutivos** (el destino de uno es el
   origen del siguiente) y se compara la suma de las medianas aprendidas con la duración real, en horizontes de 4–8,
   8–12 y 12–20 minutos. Métricas: `P(|error| ≤ 60 s)`, `P(|error| ≤ 120 s)` y error absoluto medio. Reproduce la
   predicción de producción (encadenado), no un tramo aislado.
2. **Validación cruzada por mitades de tracks** (en la propia herramienta de calibración): error absoluto total,
   comparando la regla anterior con la nueva.

### Resultado medido de la regla de encogimiento (`κ = 5`)

Sobre 193 864 muestras, 25 días, prueba sobre los últimos 7:

| Horizonte | `P(≤60 s)` | `P(≤120 s)` |
| --- | --- | --- |
| 4–8 min | 74,6 → 75,7 % | 95,6 → 95,9 % |
| 8–12 min | 56,7 → 57,9 % | 86,6 → 87,4 % |
| 12–20 min | 41,2 → 42,2 % | 72,0 → 73,3 % |

Mejora en los tres horizontes y en los 7 días de prueba. En la validación cruzada por mitades, el error absoluto total
baja de 65 854 a 63 204 minutos.

### Alternativas medidas y descartadas

- **Respaldo por tipo de día** (antes que la mediana a todas horas): empeora (19,5 → 20,0 s de error), porque fragmenta
  aún más la muestra.
- **Tope de muestras por franja**: todavía innecesario; en los tramos que alcanzarían el tope, las franjas ya están
  equilibradas, así que no aporta.

## 6. Supuestos y límites (honestos)

- **Independencia entre tramos**: se suman estimadores por tramo sin modelar su correlación; sesga poco en la práctica,
  pero una perturbación global (temporal, corte) no se propaga entre tramos más allá de lo que cada franja ya refleja.
- **Ritmo constante dentro del tramo**: el reparto proporcional (`share`) lo asume; es razonable a la escala de un tramo
  parada-a-parada.
- **Dependencia del emparejamiento GPS**: la calidad de la ETA hereda la de la localización; el diseño prefiere abstenerse
  (caer al horario) a arriesgar.
- **Cobertura**: la precisión alta exige haber observado el tramo/franja; la cascada de respaldo cubre el arranque en frío
  a costa de precisión.

## 7. Cambios recientes y resultados

### Capas y estados

La predicción se organiza en tres capas con reglas explícitas para abstenerse (memoria técnica, capítulo 6):

1. **Posición proyectada** sobre el recorrido (§1-3): estado `live`.
2. **Última parada registrada**, cuando el vehículo no tiene posición fina: `ETA = ref + mitad_del_tramo_en_curso +
   remaining(next, T)`. Sale como `estimated`.
3. **Horario corregido** sin datos del vehículo: salida publicada de cabecera + 60 s + `remaining(0, T)` con la franja y
   el ritmo del punto medio entre la salida y la parada, + `Δ_v` (la corrección del viaje, abajo). Sale como `estimated`
   si hay tramos aprendidos; si no, como `scheduled` («solo horario»).

Un viaje que el **registro de pasos** (`PassageLog`, en Upstash, escrito cada minuto por un cron externo) ya vio pasar
por la parada sale como `departed` y no se enseña. Sin registro, la capa 3 decide con la hora corregida `C`:

- `C` aún no ha llegado: `estimated`.
- `C` pasó hace más de 10 min, el horario va al menos 10 min por detrás de `C` y los tramos aprendidos cubren al menos
  el 80 % del tiempo programado hasta la parada: `likely_departed`, al final de la lista y en gris.
- En otro caso (pasó hace menos de 10 min, o con poco aprendido): `scheduled`. No se arriesga a esconder un bus que
  quizá aún no ha llegado.

Las constantes son `SCHEDULE_ORIGIN_OFFSET_SECONDS = 60`, `WRONG_SCHEDULE_PASSED_SECONDS = 600`,
`WRONG_SCHEDULE_GAP_SECONDS = 600` y `WRONG_SCHEDULE_LEARNED_SHARE = 0.8` (`engine/bizkaibus/RealtimeMatcher.php`).

Los horarios sin conexión llevan la capa 3 precalculada, con la corrección del viaje, para un laborable y un fin de
semana (`scripts/build-offline-data.php`). Contra el servidor, en 800 paradas al azar de un laborable y un sábado, la
diferencia máxima es de 6 s.

### Corrección por viaje (`Δ_v`)

La capa 3 sin corregir llegaba de media 131 s antes que el bus: la suma de medianas de tramos con colas a la derecha
queda por debajo de la media del recorrido, y algunos viajes salen tarde de cabecera de forma sistemática. Con las
muestras de tramo se reconstruye el paso real `R` de cada viaje por cada parada y se calcula el residuo
`e = R − C₀`, con `C₀` la capa 3 sin corregir (`RealtimeMatcher::learnedArrival`), recortado a ±900 s. Después, de lo más
general a lo más fino:

```
g(b)        = media de e en la franja b (tipo de día + hora de salida)
l(línea,b)  = (Σ e + 20 · g(b)) / (n + 20)
Δ(v,d,p)    = (Σ_días ē_día + 1 · l(línea,b)) / (días + 1)
```

donde `v` es el viaje (línea, primera y última parada, salida y número de paradas: no cambian aunque el operador renueve
los identificadores), `d` el tipo de día, `p` el tercio del recorrido de la parada y `ē_día` la media del residuo de ese
viaje y tercio ese día. En la predicción, `Δ_v` es `Δ(v,d,p)` si existe; si no, `l(línea,b)`; si no, `g(b)`.

Protocolo por días: aprender con los 20 días anteriores y probar en los 7 últimos (26 oct.-1 nov. 2026, 87 333 pasos).

| Hora sin datos del bus | Error medio | ±60 s | ±120 s | ±300 s | Sesgo |
| --- | --- | --- | --- | --- | --- |
| Horario publicado | 438 s | 13,1 % | 24,4 % | 49,8 % | −263 s |
| Capa 3 sin corregir | 210 s | 27,0 % | 46,6 % | 76,3 % | +131 s |
| Capa 3 + corrección de la línea | 178 s | 25,6 % | 47,3 % | 83,0 % | — |
| **Capa 3 + corrección por viaje y tercio** | **158 s** | **30,3 %** | **53,9 %** | **86,1 %** | **+17 s** |

Con un solo valor por viaje sale 164 s; por quintos, 159 s; por parada, 161 s. Los tiempos por tramo se aprendieron con
todos los días, también los de prueba, así que la ventaja de la capa 3 sin corregir está inflada y la mejora real es
algo mayor. La tabla (`engine/bizkaibus/data/trip-offsets.json`, unos 290 KB) se recalcula cada noche después de los
tramos y solo se guarda si mejora en los días de prueba. En los horarios sin conexión no ocupa sitio aparte: va sumada a
cada hora estimada (el fichero de Bizkaibus pasa de 1,1 a 1,5 MB, 0,5 MB comprimido).

### Precisión de punta a punta

En 2,37 millones de predicciones de 17 días contrastadas con el paso real, la llegada mostrada cae dentro de ±2 min en
el 96 % de los casos a menos de 3 min y en el 67 % entre 10 y 20 min, frente al 24 % y al 25 % del horario publicado. La
captura guarda desde octubre la precisión de cada bloque en histogramas (`eta-eval.jsonl`, release `pace-data`), así
que la serie se puede seguir día a día.

### Comparación con otros métodos

Protocolo por días (§5), mismos siete días de prueba (25-31 oct. 2026) y mismos recorridos encadenados. Los rivales usan
además lo que pasa ese día (lo terminado al menos 60 s antes del instante de predicción).

| Método | Error medio 4–8 min | 8–12 min | 12–20 min | ±120 s a 12–20 min |
| --- | --- | --- | --- | --- |
| Horario | 153,9 s | 233,5 s | 329,7 s | 24,0 % |
| **Método actual** (medianas contraídas, `κ = 5`) | 42,0 s | 61,7 s | 88,2 s | 74,1 % |
| Buses previos en el tramo | 41,6 s | 60,7 s | 86,5 s | 75,0 % |
| Kalman por tramo | 41,7 s | 60,7 s | 86,4 s | 75,0 % |
| Kalman del ritmo del propio autobús | 40,4 s | 59,3 s | 84,6 s | 76,0 % |
| Red neuronal (28 variables, 32 y 16 neuronas) | 39,5 s | 57,5 s | 81,7 s | 77,4 % |

El método actual se queda con casi toda la mejora sobre el horario. La red neuronal mejora entre un 6 y un 7 % todos
los días en los tres horizontes, y el Kalman del propio autobús algo más de la mitad de eso con dos parámetros. Las
diferencias son reales (intervalos de confianza del 95 % por *bootstrap* que no incluyen el cero), pero se midieron
sobre sumas de tramos. Antes de adoptar el filtro hay que medirlo sobre la llegada completa que ve el usuario, con la
misma regla de siempre: solo entra lo que mejora la medida. Los detalles (rejilla de parámetros, semillas, ideas
descartadas) están en la memoria técnica del repositorio del algoritmo (`engine/docs/tesis/`).

### Recalibrado

Los tiempos por tramo, el ritmo de cada línea y la corrección por viaje se recalculan cada día a las 04:00 UTC
(`pace-capture.yml`) con todo lo capturado y la base de Bizkaibus del día; el 31 de octubre se recalibraron con la regla
de contracción y las muestras nuevas de ese día.

## 8. Mapa de ficheros (para seguir el código)

| Pieza | Fichero |
| --- | --- |
| Localización y emparejamiento GPS↔horario | `engine/bizkaibus/RealtimeMatcher.php` |
| ETA por encadenado de tramos (`expectedSeconds`) | `engine/bizkaibus/RealtimeMatcher.php` |
| Tiempos por tramo + respaldo por longitud | `engine/bizkaibus/SegmentTimes.php`, `engine/bizkaibus/data/segment-times.json` |
| Factores de ritmo por línea/franja | `engine/bizkaibus/PaceFactors.php`, `engine/bizkaibus/data/pace-factors.json` |
| Corrección por viaje de la capa 3 | `engine/bizkaibus/TripOffsets.php`, `engine/bizkaibus/data/trip-offsets.json` |
| Registro de pasos (aprendizaje y directo) | `engine/bizkaibus/PassageLog.php`, `api/Core/Live.php`, `api/Core/Store.php` |
| Hora estimada sin conexión (capa 3 precalculada) | `scripts/build-offline-data.php`, `js/offline.js` |
| Calibración (aprende la regla de encogimiento y la corrección por viaje) | `engine/bizkaibus/tools/realtime-backtest.php` |
| Evaluación por días encadenada | `engine/bizkaibus/tools/eval-segments.py` |
