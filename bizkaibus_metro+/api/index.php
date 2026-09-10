<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(function (string $class): void {

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

$alerts = new AlertsController();
$router->get('/alerts', [$alerts, 'index']);

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
