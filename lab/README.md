# Laboratorio de Bide+

Una aplicación local para ver cómo aprende el algoritmo y compararlo con las fuentes oficiales.

```
php -S localhost:8090        # desde la raíz del repositorio
# abrir http://localhost:8090/lab/
```

Necesita PHP 8.3 con SQLite. La pestaña **En vivo** además necesita salida a internet (lee el feed de Open Data Bizkaia).

| Pestaña | Qué enseña |
|---|---|
| Resumen | Las líneas se parecen a sí mismas (r = 0,74) y los buses no (r ≈ 0); el rango del factor k, que se puede cambiar en vivo |
| Líneas | Cómo se forma el k de una línea viaje a viaje, con reproductor, por franja y con sus buses |
| Buses | Qué se aprende (y qué no) de un bus concreto |
| En vivo | El algoritmo real de la app (`RealtimeMatcher`) frente al horario oficial y al retraso que da el feed; graba lecturas y mide quién acierta cuando el bus pasa por la parada |
| Aprendizaje por días | `docs/aprendizaje.html`: cómo se rellena la tabla de tramos día a día |
| Red neuronal | `docs/red-neuronal.html`: la red aprendiendo segundo a segundo, con el horario oficial como referencia |

## Datos

- Resumen, Líneas y Buses leen `lab/data/lab-data.js`, generado con Python desde los viajes de la captura horaria:
  `python3 bideplus/scripts/lab-data.py pace-samples.jsonl` (el fichero está en la release `pace-data`).
- En vivo graba en `lab/data/live-log.jsonl` (ignorado por git). «Borrar lo grabado» lo vacía.

## Cómo se mide en «En vivo»

Cada minuto se lee el feed y, para la próxima parada de cada bus y dos más adelante, se guarda lo que dice el horario, lo que dice
el campo Delay del feed y lo que calcula Bide+. Cuando el VisitNumber del bus pasa de esa parada, el paso real es el punto medio
entre las dos lecturas (resolución de ±30 s). Es la misma medida que `bideplus/scripts/eval-eta.php`.
