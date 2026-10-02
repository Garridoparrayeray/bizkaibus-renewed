# Cómo contribuir a Bide+

Gracias por querer ayudar. Este es un proyecto personal y las contribuciones se revisan cuando hay tiempo, así que unas pautas sencillas ayudan a que todo vaya rápido. Al participar aceptas el [código de conducta](CODE_OF_CONDUCT.md).

## Antes de empezar

- **Un error en un horario o en una llegada:** abre una [issue](../../issues/new/choose) con la app, la parada o línea, el día y la hora, y lo que ves frente a lo que esperabas. Si el dato viene mal del operador, no se puede arreglar aquí, pero sirve para saberlo.
- **Una vulnerabilidad:** no abras una issue pública. Sigue la [política de seguridad](SECURITY.md).
- **Un cambio grande o una función nueva:** abre antes una issue para comentarla. Evita que trabajes en algo que no encaje.
- **Una barrera de accesibilidad:** ver la [declaración de accesibilidad](ACCESSIBILITY.md).

## Preparar el entorno

Necesitas PHP 8.2 o superior con las extensiones `sqlite3`, `pdo_sqlite`, `curl`, `mbstring` y `zip`, Node 22 y Python 3.12.

```
cd bideplus
php -S localhost:8011 dev-router.php
```

La aplicación queda en <http://localhost:8011>. Para que una petición lenta (el feed en directo) no bloquee a las demás, arranca el servidor con varios procesos: `PHP_CLI_SERVER_WORKERS=4 php -S localhost:8011 dev-router.php`.

## Pruebas

Antes de abrir una *pull request*, toda la batería tiene que salir en verde. Desde la carpeta `bideplus` (arranca el servidor local si hace falta):

```
python tests/run-all.py
```

Mientras trabajas, `python tests/run-all.py --rapido` comprueba en unos segundos todo lo que no necesita navegador. El detalle de cada prueba está en [bideplus/tests/README.md](bideplus/tests/README.md), junto con qué prueba ampliar según lo que añadas. Si tu cambio toca el cálculo de llegadas, usa además `php scripts/realtime-backtest.php`, que está explicado en [bideplus/README.md](bideplus/README.md).

## Estilo del código

Sigue el estilo que ya hay alrededor de tu cambio:

- **PHP:** variables con prefijo de tipo (`$aLista`, `$sTexto`, `$iNumero`, `$dDecimal`, `$bBandera`; los objetos en PascalCase), `if/else` en lugar del operador ternario y sin comentarios innecesarios. No hay *framework* ni *autoloader* para el código de la aplicación.
- **JavaScript:** vanilla, sin dependencias nuevas ni herramientas de compilación.
- **Textos:** la interfaz está en castellano y euskera. Si añades o cambias un texto, hazlo en los dos idiomas (`bideplus/js/i18n.js`).
- Lo visible tiene que funcionar en móvil y en ordenador, y respetar el foco de teclado y la preferencia de movimiento reducido.

## Datos y archivos generados

- No incluyas en tu *pull request* las bases de datos `bideplus/data/*.sqlite` regeneradas, salvo que el cambio sea justo en cómo se generan. Un flujo diario de GitHub Actions las reconstruye desde los GTFS oficiales.
- No subas claves, tokens ni datos personales. Las claves se pasan por variables de entorno o secretos del repositorio.

## Commits y *pull requests*

- Mensajes cortos, en castellano, con el formato `tipo(ámbito): descripción`, por ejemplo `fix(bideplus): corrige el horario del primer tren`.
- Una *pull request* por cambio, con una descripción de qué cambia y cómo lo has probado. La plantilla te guía.

## Licencia de tus contribuciones

El proyecto se publica bajo [CC BY-NC-SA 4.0](LICENSE). Al enviar una contribución aceptas que se publique bajo esa misma licencia. Los datos de los operadores mantienen las suyas.
