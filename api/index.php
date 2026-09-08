<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');
// Esto es una API JSON: los errores de PHP no deben colarse en la respuesta.
// Siguen apareciendo en el log de errores normal (visible en la consola de `php -S`).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(function (string $class): void {
    // Los namespaces mapean 1:1 a api/<Namespace>/<Clase>.php, sin Composer.
    $path = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use Core\Config;
use Core\Request;
use Core\Response;
use Core\Router;
use Controllers\SearchController;
use Controllers\StopsController;
use Controllers\LinesController;
use Controllers\TimetableController;
use Controllers\RealtimeController;
use Controllers\AlertsController;

$request = new Request();

$network = 'bus';
if ($request->query('red') === 'metro') {
    $network = 'metro';
}
Config::set($network);

$router = new Router();

// Rutas comunes a ambas redes.
$search = new SearchController();
$router->get('/search', [$search, 'search']);

$stops = new StopsController();
$router->get('/stops/{id}', [$stops, 'show']);
$router->get('/stops/{id}/departures', [$stops, 'departures']);
$router->get('/trips/{tripKey}', [$stops, 'tripStops']);

$lines = new LinesController();
$router->get('/lines', [$lines, 'index']);
$router->get('/lines/{id}', [$lines, 'show']);

$timetable = new TimetableController();
$router->get('/lines/{id}/timetable', [$timetable, 'show']);

// Alertas: bus usa SIRI-SX, metro usa el JSON de avisos propio de su CMS.
// AlertsController ya decide internamente cuál según la red, así que la
// ruta se registra para las dos.
$alerts = new AlertsController();
$router->get('/alerts', [$alerts, 'index']);

// Metro Bilbao no tiene feed SIRI en vivo (posición de vehículos) ni el
// endpoint legado de horario en texto libre, así que esas rutas solo existen
// para la red bus.
if ($network === 'bus') {
    $router->get('/lines/{id}/schedule-text', [$lines, 'scheduleText']);

    $realtime = new RealtimeController();
    $router->get('/vehicles/{tripKey}', [$realtime, 'vehicle']);
    $router->get('/lines/{id}/live', [$realtime, 'lineLive']);
}

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    Response::error('Unhandled error: ' . $e->getMessage(), 500);
}
