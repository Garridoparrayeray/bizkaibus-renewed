<?php
// Registro de pasos contra un Upstash falso (tests/fixtures/fake-upstash.php): escribir, leer, clave mala, almacén caído
// y la clave secreta del endpoint del cron. Uso: php tests/store-test.php

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';

use Controllers\LiveRecordController;
use Core\Config;
use Core\Request;
use Core\Store;
use Engine\Bizkaibus\PassageLog;
use Models\ServiceJourney;

$aFailures = [];
$expect = function (string $sName, bool $bOk, string $sDetail = '') use (&$aFailures): void {
    echo ($bOk ? 'OK  ' : 'MAL ') . $sName . ($bOk || $sDetail === '' ? '' : '  [' . $sDetail . ']') . "\n";
    if (!$bOk) {
        $aFailures[] = $sName;
    }
};

$sMarker = sys_get_temp_dir() . '/bizkaibusplus_store.fail';
@unlink($sMarker);
$sDataFile = tempnam(sys_get_temp_dir(), 'fake-upstash');
$iPort = 18000 + random_int(0, 999);
$Server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $iPort, __DIR__ . '/fixtures/fake-upstash.php'],
    [1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
    $aPipes,
    null,
    ['FAKE_UPSTASH_FILE' => $sDataFile]
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $iPort) === false; $i++) {
    usleep(100000);
}

try {
    putenv('UPSTASH_REDIS_REST_URL');
    putenv('KV_REST_API_URL');
    $expect('sin credenciales el almacén está apagado', !Store::enabled() && Store::pipeline([['HMGET', 'k', 'f']]) === null);

    putenv('UPSTASH_REDIS_REST_URL=http://127.0.0.1:' . $iPort . '/');
    putenv('UPSTASH_REDIS_REST_TOKEN=token-de-prueba');
    $expect('con credenciales está encendido', Store::enabled());

    Config::set('bus');
    $Pdo = new PDO('sqlite::memory:');
    $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $Pdo->exec("CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT NOT NULL);
        INSERT INTO trip_aliases VALUES ('trp_A3514_909_LIN_51300_x', 'J1'), ('trp_A3514_909_LSEPT_51300_x', 'J1');");
    $Journeys = new ServiceJourney($Pdo);

    $aVm = ['3514|909' => [['tripRef' => 'trp_A3514_909_LIN_51300_x', 'order' => 5, 'recordedSeconds' => 52860]]];
    $expect('el cron apunta los buses del directo', PassageLog::record($aVm, 'bus', '2026-10-29') === 1);
    $aPassages = PassageLog::forJourneys($Journeys, ['J1', 'J2'], 'bus', '2026-10-29');
    $expect('al consultar la parada se recupera por el alias del viaje', $aPassages === ['J1' => ['order' => 5, 'seconds' => 52860]], json_encode($aPassages));
    $expect('otro día no mezcla', PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-30') === []);

    $aVm['3514|909'][0]['order'] = 8;
    PassageLog::record($aVm, 'bus', '2026-10-29');
    $expect('la siguiente lectura del directo actualiza por dónde va', PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-29')['J1']['order'] === 8);

    putenv('UPSTASH_REDIS_REST_TOKEN=token-malo');
    $expect('con la clave mala no se escribe', PassageLog::record($aVm, 'bus', '2026-10-29') === null);
    $expect('tras un fallo se deja de llamar un minuto', is_file($sMarker) && Store::pipeline([['HMGET', 'k', 'f']]) === null);
    @unlink($sMarker);

    putenv('UPSTASH_REDIS_REST_TOKEN=token-de-prueba');
    putenv('UPSTASH_REDIS_REST_URL=http://127.0.0.1:1');
    $fStart = microtime(true);
    $aDown = PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-29');
    $expect('almacén caído: no hay registro y no frena la respuesta (menos de 1 s)', $aDown === [] && microtime(true) - $fStart < 1.0, sprintf('%.2f s', microtime(true) - $fStart));
    @unlink($sMarker);

    putenv('BIDE_CRON_SECRET');
    $_GET = ['key' => 'secreto'];
    $expect('sin BIDE_CRON_SECRET el endpoint del cron no existe', !LiveRecordController::authorized(new Request()));
    putenv('BIDE_CRON_SECRET=secreto');
    $expect('con la clave en ?key= se acepta', LiveRecordController::authorized(new Request()));
    $_GET = ['key' => 'otra'];
    $expect('con otra clave no', !LiveRecordController::authorized(new Request()));
    $_GET = [];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secreto';
    $expect('con la clave en la cabecera Authorization se acepta', LiveRecordController::authorized(new Request()));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer';
    $expect('con una cabecera vacía no', !LiveRecordController::authorized(new Request()));
} finally {
    proc_terminate($Server);
    @unlink($sDataFile);
    @unlink($sMarker);
}

echo "\nRESULTADO almacén: " . (empty($aFailures) ? 'OK' : 'FALLA -> ' . implode('; ', $aFailures)) . "\n";
exit(empty($aFailures) ? 0 : 1);
