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

use Core\Request;
use Core\Response;
use Core\Router;
use Controllers\StationsController;

$request = new Request();

$router = new Router();

$stations = new StationsController();
$router->get('/stations/near', [$stations, 'near']);
$router->get('/stations/search', [$stations, 'search']);
$router->get('/stations/bbox', [$stations, 'bbox']);
$router->get('/stats/national', [$stations, 'nationalStats']);
$router->get('/stats/by-fuel', [$stations, 'statsByFuel']);
$router->get('/stats/by-province', [$stations, 'statsByProvince']);
$router->get('/stats/price-distribution', [$stations, 'priceDistribution']);
$router->get('/stations/{ideess}', [$stations, 'show']);
$router->get('/stations/{ideess}/history', [$stations, 'history']);
$router->get('/stations/{ideess}/zone-comparison', [$stations, 'zoneComparison']);

try {
    $router->dispatch($request);
} catch (\Throwable $e) {
    Response::error('Unhandled error: ' . $e->getMessage(), 500);
}
