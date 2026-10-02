# Pruebas

Puerta de regresión: todo tiene que salir en verde antes de commitear. Lo más cómodo es un solo comando, que arranca el servidor local si no está en marcha:

```
python tests/run-all.py            # todo, con navegador (unos 6 min)
python tests/run-all.py --rapido   # sin navegador (unos 15 s): estáticas, unitarias, datos, API y contrato
```

O a mano, con `php -S localhost:8011 dev-router.php` en otra terminal:

```
python tests/static-checks.py && python tests/api-smoke.py && python tests/api-contract.py && node tests/ui-battery.mjs && node tests/ui-offline.mjs && node tests/ui-alerts.mjs && node tests/ui-nearby.mjs
```

| Script | Qué comprueba |
|---|---|
| `static-checks.py` | Sintaxis de PHP y JS, ids que usa `app.js` frente a `api/shell.php`, ids del CSS, archivos del precache del service worker, y lanza `geocache-test.php`, `unit-test.php` y `data-integrity.php` |
| `unit-test.php` | Lógica sin servidor ni red, sobre una base de datos en memoria: cálculo de llegadas de `RealtimeMatcher` (proyección sobre el trazado, `T_pos`, ETA con `k`, pasillo de 50 m, sin trazado, GPS viejo, viaje sin empezar, ventana de tramos, trazados que vuelven sobre sí mismos, `trip_aliases`, `enrich()`), `PaceFactors` (franjas, prioridad por línea, límites 0,6-1,2 y validez de `data/pace-factors.json`), parseo SIRI-VM, `TripKey` y `Calendar`. La lanza `static-checks.py` |
| `data-integrity.php` | Las seis bases `data/*.sqlite`: tablas, referencias entre paradas, viajes, líneas y calendarios, horas que no van hacia atrás, `first_departure_seconds`, coordenadas, `trip_aliases`, `dist_m` y trazados. La lanza `static-checks.py` y también el flujo diario antes de desplegar |
| `api-smoke.py` | Todas las rutas de la API en las tres redes (búsqueda, paradas, salidas, líneas, horarios, trenes, alertas, línea en vivo, 404) |
| `api-contract.py` | Forma del JSON de cada ruta en las seis redes: campos que usa `app.js`, tipos, horas `HH:MM` y estados conocidos (`scheduled`, `live`, `departed`, `finished`). Añadir campos está permitido; quitar o renombrar uno rompe la prueba |
| `ui-battery.mjs` | Navegador headless en móvil y ordenador: cargar, buscar, ficha, favoritos, menú, horario completo, modal del tren, aviso legal, selector de app, enlaces directos, menú Bide+, tema «mi amor» y 404 reales. Falla si hay errores de consola o de red |
| `ui-offline.mjs` | Que las cuatro páginas solo piden recursos propios (tipografías y Leaflet ya no vienen de Google ni unpkg), que las tipografías y el mapa cargan, y que con el servidor caído la app arranca desde el service worker y avisa de la falta de conexión |
| `ui-alerts.mjs` | Avisos de incidencias por línea favorita: línea base sin notificar, aviso nuevo una sola vez, tope de 3 notificaciones más un resumen, fallos de la API, interruptor del menú (permiso concedido y denegado) y notificación real del service worker con sincronización periódica |
| `ui-nearby.mjs` | Botón «Cerca de mí» con la ubicación simulada en Bizkaibus, Metro y Euskotren (parada más cercana, distancia, próxima salida, abrir ficha), ubicación fuera de la zona y permiso denegado |

Variables de entorno: `BASE_URL` (por defecto `http://localhost:8011`), `CHROME_PATH` (por defecto Edge de Windows), `CDP_PORT`, `PHP_PATH`. Solo para `ui-battery.mjs`: `APPS` (bus,metro,euskotren,tranvia-bilbao,tranvia-vitoria,renfe), `SIZES` (movil,pc) y `VERBOSE=1` para ir viendo cada comprobación.

Los mosaicos del mapa (OpenStreetMap) siguen necesitando internet: sin red se ven la ruta y los marcadores sobre fondo liso.

Las comprobaciones que dependen de internet (el feed del «horario oficial» de Bizkaibus) salen como `SKIP`, no como fallo.
El navegador de pruebas usa un perfil temporal propio y se cierra por PID: no toca otras ventanas del navegador.

## Al añadir algo nuevo

- **Lógica nueva en `api/Services` o `api/Core`:** añade sus casos a `unit-test.php`, con el resultado exacto esperado (horas, segundos, metros), no solo «no da error».
- **Una ruta o un campo nuevo en la API:** añádelo al esquema de `api-contract.py` y una llamada en `api-smoke.py`.
- **Un operador nuevo (por ejemplo Lurraldebus):** añade su base a `data-integrity.php`, su búsqueda y su ubicación a `api-smoke.py` y `api-contract.py`, y su app a `ui-battery.mjs`.
- **Cambios en el cálculo de llegadas:** además de `unit-test.php`, mide con `php scripts/realtime-backtest.php analyze` antes y después.
- Si una prueba falla con tu cambio, arregla el código, no la prueba, salvo que el comportamiento haya cambiado a propósito (y entonces dilo en la *pull request*).
