<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/autoload.php';

use Core\Config;
use Core\Networks;
use Core\Request;
use Core\Response;
use Core\Router;
use Controllers\SearchController;
use Controllers\StopsController;
use Controllers\LinesController;
use Controllers\TimetableController;
use Controllers\RealtimeController;
use Controllers\AlertsController;
use Controllers\LiveRecordController;
use Controllers\PushController;

$Req = new Request();

Config::set(Networks::fromQuery($Req->query('red')));

$Router = new Router();

$Search = new SearchController();
$Router->get('/search', [$Search, 'search'], 3600);

$Stops = new StopsController();
$Router->get('/nearby', [$Stops, 'nearby']);
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

if (Config::feature('schedule_text')) {
    $Router->get('/lines/{id}/schedule-text', [$Lines, 'scheduleText'], 3600);
}

$Realtime = new RealtimeController();
$Router->get('/vehicles/{tripKey}', [$Realtime, 'vehicle'], 10);
$Router->get('/lines/{id}/live', [$Realtime, 'lineLive'], 10);
$Router->get('/status', [$Realtime, 'status'], 30);
$LiveRecord = new LiveRecordController();
$Router->get('/live/record', [$LiveRecord, 'record']);
$Router->get('/live/history', [$LiveRecord, 'history']);

// «Avísame cuando llegue»: el cron (/live/record) es quien manda los avisos.
$Push = new PushController();
// La clave no cambia: un día en la caché de Vercel, para no consultar el almacén en cada carga de la app.
$Router->get('/push/key', [$Push, 'key'], 86400);
$Router->post('/push/arrival', [$Push, 'createArrival']);
$Router->post('/push/arrival/{id}/cancel', [$Push, 'cancelArrival']);
$Router->post('/push/incidents', [$Push, 'saveIncidents']);
$Router->post('/push/incidents/remove', [$Push, 'removeIncidents']);

try {
    $Router->dispatch($Req);
} catch (\Throwable $Ex) {
    error_log('Bide+ API: ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
    Response::error('Internal error', 500);
}
