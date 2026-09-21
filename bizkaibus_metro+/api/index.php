<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(function (string $sClass): void {

    $sPath = __DIR__ . '/' . str_replace('\\', '/', $sClass) . '.php';
    if (is_file($sPath)) {
        require $sPath;
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

$Req = new Request();

$sNetwork = in_array($Req->query('red'), ['metro', 'euskotren'], true) ? $Req->query('red') : 'bus';
Config::set($sNetwork);

$Router = new Router();

$Search = new SearchController();
$Router->get('/search', [$Search, 'search'], 3600);

$Stops = new StopsController();
$Router->get('/stops/{id}', [$Stops, 'show'], 3600);
$Router->get('/stops/{id}/departures', [$Stops, 'departures'], 10);
$Router->get('/trips/{tripKey}', [$Stops, 'tripStops'], 60);

$Lines = new LinesController();
$Router->get('/lines', [$Lines, 'index'], 3600);
$Router->get('/lines/{id}', [$Lines, 'show'], 3600);

$Timetable = new TimetableController();
$Router->get('/lines/{id}/timetable', [$Timetable, 'show'], 60);

$Alerts = new AlertsController();
$Router->get('/alerts', [$Alerts, 'index'], 60);

if ($sNetwork === 'bus') {
    $Router->get('/lines/{id}/schedule-text', [$Lines, 'scheduleText'], 3600);
}

$Realtime = new RealtimeController();
$Router->get('/vehicles/{tripKey}', [$Realtime, 'vehicle'], 10);
$Router->get('/lines/{id}/live', [$Realtime, 'lineLive'], 10);

try {
    $Router->dispatch($Req);
} catch (\Throwable $Ex) {
    Response::error('Unhandled error: ' . $Ex->getMessage(), 500);
}
