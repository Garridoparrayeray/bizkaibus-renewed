# Cómo ampliar Bide+

Recetas para los cambios más comunes, con todos los sitios que hay que tocar. Antes de empezar, dos cosas que valen
para todo:

- **Las pautas del código** están en [CONTRIBUTING.md](../../CONTRIBUTING.md). En resumen:
  - sin operador ternario en ningún lenguaje (`if/else`);
  - en PHP, variables con prefijo de tipo (`$aLista`, `$sTexto`, `$iNumero`, `$dDecimal`, `$bBandera`); objetos y
    funciones guardadas en una variable, en PascalCase;
  - en JavaScript, `const`/`let` y `===`;
  - textos en castellano, euskera e inglés;
  - nada muerto (lo que deja de usarse se borra).
- **La batería de limpieza** lo comprueba en medio segundo y dice la línea exacta: `php tests/code-quality.php`. Antes
  de cada *pull request*, toda la batería (`python tests/run-all.py`) en verde.

## Un texto de la interfaz

1. Añade la clave en los **tres** bloques de `js/i18n.js` (`es`, `eu` y `en`), en el mismo sitio de cada uno.
2. Úsala:
   - en el HTML, con `data-i18n="clave"` (o `data-i18n-attr="aria-label:clave"` para un atributo) y el texto en
     castellano dentro, que es lo que ven los buscadores;
   - en JavaScript, con `I18n.t('clave', { variable })`.
3. Si un texto deja de usarse, bórralo de los tres idiomas. La batería avisa de claves que faltan, que sobran, que se
   repiten o que no usa nadie.

Los nombres y textos de cada red no van en `i18n.js`: están en su ficha (`api/Networks/<red>.php`, clave `texts`) y
llegan al navegador en `js/networks.js` (`php scripts/build-networks-js.php` lo regenera).

## Un idioma nuevo

Hay que tocar:

- `js/i18n.js`: un bloque nuevo con todas las claves y el idioma en `LANGS`.
- `api/Views/bide-components.php`: el botón del selector (`data-bide-lang`).
- `api/Networks/*.php`: los `texts` de cada red en ese idioma.
- Lo que manda el servidor sin pasar por `i18n.js`:
  - `Push::LANGS` (`api/Services/Push.php`);
  - los `TEXTS` de `ArrivalAlerts` e `IncidentAlerts`;
  - `MORE_TEXTS` de `js/alerts-store.js`.
- Las novedades (`bide.changelog.N.*`) y las preguntas frecuentes en ese idioma.

## Una ruta de la API

1. Regístrala en `api/index.php` (`$Router->get('/ruta/{id}', [$Controlador, 'metodo'], segundosDeCache)`).
2. El método del controlador recibe `Request $Req, array $aParams` y responde con `Response::json(...)` o
   `Response::error('texto', código)`.
3. Lo que sea consulta va en `api/Models/` y lo que llame a fuera en `api/Services/`.
4. Añade su forma a `tests/api-contract.py` y una llamada a `tests/api-smoke.py`.
5. Si la app la usa, añádela a `js/api.js`. Si es de datos en directo, el service worker no debe guardarla (`LIVE_API`
   en `sw.js`).

Una clase nueva va en su carpeta de `api/`, una por archivo y con el espacio de nombres de su carpeta (`api/autoload.php`
la carga sola).

## Una pestaña de Bide+ (como Favoritos o Ajustes)

- `api/Views/bide-components.php`: la sección (`data-bide-view="nombre"`) y su botón en la barra de pestañas y en la
  columna del ordenador.
- `js/bide.js`: el nombre en `VIEWS` y qué pintar al abrirla (`showView`).
- Se abre también con `#nombre` en la dirección (así las notificaciones de incidencias abren `#avisos`).

## Un tipo de notificación nuevo

Lee antes [notificaciones.md](notificaciones.md).

1. **El mensaje**: `['title', 'body', 'url', 'tag', 'icon']`. La dirección empieza por `/` y lleva `aviso=` distinto en
   cada envío, para que la app la abra una sola vez.
2. **El envío**: `Push::sender($iTtlSegundos)` da la función que manda. Trata 404 y 410 como «suscripción que ya no
   existe» y bórrala.
3. **Quién lo dispara**: si es periódico, colócalo en `LiveRecordController::record` dentro de `safely(...)`, para que un
   fallo no estropee lo demás.
4. **El service worker** ya enseña cualquier mensaje con esa forma: no hay que tocarlo, salvo que necesites algo al
   recibirlo (como `alertId` en los avisos de llegada).
5. **Pruebas**: en `tests/push-test.php` (con el Upstash falso y un `Sender` falso) y en `tests/ui-alerts.mjs` (el push
   con la app cerrada).

## Una red nueva

Todo está en [nueva-red.md](nueva-red.md): `php scripts/new-network.php` crea la ficha y los iconos provisionales,
`scripts/build-database.php` la base y, después, sus pruebas.

## Un cambio visible

- Sube `CACHE_VERSION` en `sw.js`, para que los móviles cojan los archivos nuevos.
- Si es algo que la gente nota, añade una novedad:
  - una fila nueva arriba en `Changelog::ENTRIES` (`api/Services/Changelog.php`), con el número siguiente: es la
    versión nueva de la app, y al desplegarla se avisa a todos (notificación o «1» en Ajustes, ver
    [notificaciones](notificaciones.md));
  - `bide.changelog.N.title` y `.text` en los tres idiomas;
  - el número de novedades que espera `tests/ui-bide.mjs`.
- Si una página nueva carga un script o una hoja de estilos, añádelo a `SHELL_FILES` en `sw.js` (la batería lo exige).

## Una prueba

Qué ampliar según lo que cambies está en [tests/README.md](../tests/README.md#al-añadir-algo-nuevo). Si una prueba
falla con tu cambio, arregla el código, no la prueba, salvo que el comportamiento haya cambiado a propósito.

## El algoritmo de llegadas

Vive en su propio repositorio, que los flujos de trabajo colocan en `engine/`. Su manual (dónde está cada cosa, cómo
medir una idea y cómo calibrar) está en `engine/docs/algoritmo.html`. Mide antes y después con
`php engine/bizkaibus/tools/realtime-backtest.php analyze <carpeta>`: una idea entra solo si la medida mejora.
