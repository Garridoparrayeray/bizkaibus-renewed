<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/autoload.php';

use Core\CacheSeconds;
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
use Controllers\AppController;

$Req = new Request();

Config::set(Networks::fromQuery($Req->query('red')));

$Router = new Router();

$Search = new SearchController();
$Router->get('/search', [$Search, 'search'], CacheSeconds::STATIC);

$Stops = new StopsController();
$Router->get('/nearby', [$Stops, 'nearby']);
$Router->get('/stops/{id}', [$Stops, 'show'], CacheSeconds::STATIC);
$Router->get('/stops/{id}/departures', [$Stops, 'departures'], CacheSeconds::LIVE);
$Router->get('/trips/{tripKey}', [$Stops, 'tripStops'], CacheSeconds::MINUTE);

$Lines = new LinesController();
$Router->get('/lines', [$Lines, 'index'], CacheSeconds::STATIC);
$Router->get('/lines/{id}', [$Lines, 'show'], CacheSeconds::STATIC);

$Timetable = new TimetableController();
$Router->get('/lines/{id}/timetable', [$Timetable, 'show'], CacheSeconds::MINUTE);

$Alerts = new AlertsController();
$Router->get('/alerts', [$Alerts, 'index'], CacheSeconds::MINUTE);

// El horario oficial por periodos: el del operador o, si no publica uno en texto, el de su GTFS (todas las redes).
$Router->get('/lines/{id}/schedule-text', [$Lines, 'scheduleText'], CacheSeconds::STATIC);

$Realtime = new RealtimeController();
$Router->get('/vehicles/{tripKey}', [$Realtime, 'vehicle'], CacheSeconds::LIVE);
$Router->get('/lines/{id}/route', [$Lines, 'route'], CacheSeconds::STATIC);
$Router->get('/status', [$Realtime, 'status'], CacheSeconds::STATUS);
$LiveRecord = new LiveRecordController();
$Router->get('/live/record', [$LiveRecord, 'record']);
$Router->get('/live/history', [$LiveRecord, 'history']);

// «Avísame cuando llegue»: el cron (/live/record) es quien manda los avisos.
$Push = new PushController();
// La clave no cambia: un día en la caché de Vercel, para no consultar el almacén en cada carga de la app.
$Router->get('/push/key', [$Push, 'key'], CacheSeconds::DAY);
$Router->post('/push/arrival', [$Push, 'createArrival']);
$Router->post('/push/arrival/{id}/cancel', [$Push, 'cancelArrival']);
$Router->post('/push/incidents', [$Push, 'saveIncidents']);
$Router->post('/push/incidents/remove', [$Push, 'removeIncidents']);
$Router->post('/push/updates', [$Push, 'saveUpdates']);
$Router->post('/push/updates/remove', [$Push, 'removeUpdates']);

// La versión solo cambia al desplegar, y cada despliegue vacía la caché de Vercel: el aviso sigue llegando al momento.
$App = new AppController();
$Router->get('/app/version', [$App, 'version'], CacheSeconds::VERSION);

try {
    $Router->dispatch($Req);
} catch (\Throwable $Ex) {
    error_log('Bide+ API: ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
    Response::error('Internal error', 500);
}
