# Pruebas

Puerta de regresión: los tres scripts tienen que salir en verde antes de commitear.

```
php -S localhost:8011 dev-router.php        # en otra terminal, desde esta carpeta
python tests/static-checks.py && python tests/api-smoke.py && node tests/ui-battery.mjs
```

| Script | Qué comprueba |
|---|---|
| `static-checks.py` | Sintaxis de PHP y JS, ids que usa `app.js` frente a `api/shell.php`, ids del CSS y archivos del precache del service worker |
| `api-smoke.py` | Todas las rutas de la API en las tres redes (búsqueda, paradas, salidas, líneas, horarios, trenes, alertas, línea en vivo, 404) |
| `ui-battery.mjs` | Navegador headless en móvil y ordenador: cargar, buscar, ficha, favoritos, menú, horario completo, modal del tren, aviso legal, selector de app, enlaces directos, menú Bide+, tema «mi amor» y 404 reales. Falla si hay errores de consola o de red |

Variables de entorno: `BASE_URL` (por defecto `http://localhost:8011`), `CHROME_PATH` (por defecto Edge de Windows), `CDP_PORT` (por defecto 9362), `PHP_PATH`.

Las comprobaciones que dependen de internet (el feed del «horario oficial» de Bizkaibus) salen como `SKIP`, no como fallo.
El navegador de pruebas usa un perfil temporal propio y se cierra por PID: no toca otras ventanas del navegador.
