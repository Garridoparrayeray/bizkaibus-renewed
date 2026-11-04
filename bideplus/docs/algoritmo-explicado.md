# El algoritmo de Bizkaibus+, explicado

Cómo Bide+ convierte «el horario dice 9:00» en «llega de verdad en 6 minutos», sin una sola fórmula, para explicarlo
a cualquiera. La versión formal, con el modelo estadístico, y la referencia técnica están en el repositorio privado del
algoritmo.

---

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
  de 10 minutos y el horario va claramente más lento, sale al final y en gris como «Sin datos del bus»: no hay ningún dato del vehículo que lo confirme.
- **Un bus que se pierde un minuto no se olvida.** El feed de Bizkaibus deja fuera a veces, una o dos lecturas, a un bus
  que sigue en su viaje: pasa cientos de veces al día. Antes, ese minuto la parada pasaba a «Solo horario»; ahora se
  sigue usando la última posición del bus hasta 3 minutos, y la llegada se calcula desde la hora de esa posición. En
  esos minutos, las llegadas a menos de 5 minutos que aciertan con menos de 2 minutos de error pasan del 55 % al 78 %.
- **También sin conexión.** Los horarios que se guardan en el móvil llevan la hora estimada de cada viaje, calculada como
  en el servidor: sin cobertura se ve lo mismo que con cobertura cuando no hay datos del bus.
- **Cada viaje, con su costumbre.** La hora estimada sin datos del bus llegaba de media 2 minutos antes que el bus: sumar
  lo típico de cada tramo se queda corto, y hay viajes que salen tarde casi siempre. Ahora se aprende, de los pasos
  reales de los últimos días, cuánto suele apartarse cada viaje (el de las 8:10 de tal línea) al principio, a la mitad
  y al final de su recorrido, y se suma. Probado con días que no se usaron para aprender, el error medio baja de 3,5 a
  2,6 minutos, y las que aciertan con menos de 2 minutos de error pasan del 47 % al 54 %. Vale con conexión, para los
  buses que aún no han salido, y sin conexión.
- **Los festivos, como fin de semana.** El horario ya cambia solo: cada día circulan los viajes de su calendario
  (laborable, sábado, domingo, festivo, verano…). Lo aprendido distingue laborable y fin de semana, y un festivo entre
  semana cuenta como fin de semana; se sabe que es festivo porque ese día circulan los viajes de domingo. Se probó
  separar también viernes, sábado y domingo, y usar solo los últimos días: no mejora. La temporada del año (verano,
  vacaciones) todavía no se puede medir, porque las muestras son de octubre; lo aprendido se recalcula cada día con los
  últimos 120 días, así que se va adaptando.
- **Cuánto acierta, medido en serio.** En 2,37 millones de llegadas de 17 días, comparadas con el paso real del bus, la
  hora que enseña Bide+ acierta con menos de 2 minutos de error en el 96 % de los casos cuando faltan menos de 3 minutos,
  y en el 67 % cuando faltan entre 10 y 20. El horario oficial acierta así en el 24 % y el 25 %.
- **Comparado con métodos más complicados.** Se probaron, con los mismos días y las mismas reglas, un filtro de Kalman y
  una red neuronal que además usan lo que está pasando ese día. Mejoran un poco (entre un 4 y un 7 %), pero el método
  sencillo ya se queda con casi toda la mejora sobre el horario. De momento no se cambia: antes hay que medir la mejora
  sobre la llegada completa que ve la gente, no solo sobre la suma de tramos.
