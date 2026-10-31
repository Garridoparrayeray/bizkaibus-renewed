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
$Expect = function (string $sName, bool $bOk, string $sDetail = '') use (&$aFailures): void {
    $sLine = 'OK  ' . $sName;
    if (!$bOk) {
        $sLine = 'MAL ' . $sName;
        if ($sDetail !== '') {
            $sLine .= '  [' . $sDetail . ']';
        }
    }
    echo $sLine . "\n";
    if (!$bOk) {
        $aFailures[] = $sName;
    }
};

$sMarker = sys_get_temp_dir() . '/bizkaibusplus_store.fail';
@unlink($sMarker);
$sDataFile = tempnam(sys_get_temp_dir(), 'fake-upstash');
$iPort = 18000 + random_int(0, 999);
$sNull = '/dev/null';
if (PHP_OS_FAMILY === 'Windows') {
    $sNull = 'NUL';
}
$Server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $iPort, __DIR__ . '/fixtures/fake-upstash.php'],
    [1 => ['file', $sNull, 'w'], 2 => ['file', $sNull, 'w']],
    $aPipes,
    null,
    array_filter(array_merge($_SERVER, $_ENV), 'is_string') + ['FAKE_UPSTASH_FILE' => $sDataFile]
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $iPort) === false; $i++) {
    usleep(100000);
}

try {
    putenv('UPSTASH_REDIS_REST_URL');
    putenv('KV_REST_API_URL');
    $Expect('sin credenciales el almacén está apagado', !Store::enabled() && Store::pipeline([['HMGET', 'k', 'f']]) === null);

    putenv('UPSTASH_REDIS_REST_URL=http://127.0.0.1:' . $iPort . '/');
    putenv('UPSTASH_REDIS_REST_TOKEN=token-de-prueba');
    $Expect('con credenciales está encendido', Store::enabled());

    Config::set('bus');
    $Pdo = new PDO('sqlite::memory:');
    $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $Pdo->exec("CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT NOT NULL);
        INSERT INTO trip_aliases VALUES ('trp_A3514_909_LIN_51300_x', 'J1'), ('trp_A3514_909_LSEPT_51300_x', 'J1');");
    $Journeys = new ServiceJourney($Pdo);

    $aVm = ['3514|909' => [['tripRef' => 'trp_A3514_909_LIN_51300_x', 'order' => 5, 'recordedSeconds' => 52860]]];
    $Expect('el cron apunta los buses del directo', PassageLog::record($aVm, 'bus', '2026-10-29') === 1);
    $aPassages = PassageLog::forJourneys($Journeys, ['J1', 'J2'], 'bus', '2026-10-29');
    $Expect('al consultar la parada se recupera por el alias del viaje', $aPassages === ['J1' => ['order' => 5, 'seconds' => 52860]], json_encode($aPassages));
    $Expect('otro día no mezcla', PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-30') === []);

    $aVm['3514|909'][0]['order'] = 8;
    PassageLog::record($aVm, 'bus', '2026-10-29');
    $Expect('la siguiente lectura del directo actualiza por dónde va', PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-29')['J1']['order'] === 8);

    $aVm['3514|909'][] = ['tripRef' => 'trp_A3514_909_LIN_53100_y', 'order' => 2, 'recordedSeconds' => 52900];
    $aVm['3515|914'] = [['tripRef' => 'trp_A3515_914_LIN_55800_z', 'order' => 4, 'recordedSeconds' => 52900]];
    PassageLog::record($aVm, 'bus', '2026-10-29');
    $aVm['3514|909'][0] = ['tripRef' => 'trp_A3514_909_LIN_51300_x', 'order' => 9, 'recordedSeconds' => 52960];
    PassageLog::record($aVm, 'bus', '2026-10-29');
    $aHistory = PassageLog::history('bus', '2026-10-29', '3514');
    $Expect('historial de una línea: cada parada con su hora, sin otras líneas', $aHistory === [
        'trp_A3514_909_LIN_51300_x' => [5 => 52860, 8 => 52860, 9 => 52960],
        'trp_A3514_909_LIN_53100_y' => [2 => 52900],
    ], json_encode($aHistory));
    $Expect('historial: línea con caracteres raros no se consulta', PassageLog::history('bus', '2026-10-29', '35*') === null);

    putenv('UPSTASH_REDIS_REST_TOKEN=token-malo');
    $Expect('con la clave mala no se escribe', PassageLog::record($aVm, 'bus', '2026-10-29') === null);
    $Expect('tras un fallo se deja de llamar un minuto', is_file($sMarker) && Store::pipeline([['HMGET', 'k', 'f']]) === null);
    @unlink($sMarker);

    putenv('UPSTASH_REDIS_REST_TOKEN=token-de-prueba');
    putenv('UPSTASH_REDIS_REST_URL=http://127.0.0.1:1');
    $dStart = microtime(true);
    $aDown = PassageLog::forJourneys($Journeys, ['J1'], 'bus', '2026-10-29');
    $Expect('almacén caído: no hay registro y no frena la respuesta (menos de 1 s)', $aDown === [] && microtime(true) - $dStart < 1.0, sprintf('%.2f s', microtime(true) - $dStart));
    @unlink($sMarker);

    putenv('BIDE_CRON_SECRET');
    $_GET = ['key' => 'secreto'];
    $Expect('sin BIDE_CRON_SECRET el endpoint del cron no existe', !LiveRecordController::authorized(new Request()));
    putenv('BIDE_CRON_SECRET=secreto');
    $Expect('con la clave en ?key= se acepta', LiveRecordController::authorized(new Request()));
    $_GET = ['key' => 'otra'];
    $Expect('con otra clave no', !LiveRecordController::authorized(new Request()));
    $_GET = [];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secreto';
    $Expect('con la clave en la cabecera Authorization se acepta', LiveRecordController::authorized(new Request()));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer';
    $Expect('con una cabecera vacía no', !LiveRecordController::authorized(new Request()));
    $_GET = ['key' => 'secreto'];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secreto-mal-copiado';
    $Expect('una cabecera equivocada no anula la clave buena de la URL', LiveRecordController::authorized(new Request()));
    $_GET = ['key' => 'otra'];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer secreto ';
    $Expect('con la cabecera buena (aunque lleve un espacio al final) y otra clave en la URL, se acepta', LiveRecordController::authorized(new Request()));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer otra';
    $Expect('con las dos equivocadas, no', !LiveRecordController::authorized(new Request()));
    unset($_SERVER['HTTP_AUTHORIZATION']);
    $_GET = [];
} finally {
    proc_terminate($Server);
    @unlink($sDataFile);
    @unlink($sMarker);
}

if (!empty($aFailures)) {
    echo "\nRESULTADO almacén: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO almacén: OK\n";
exit(0);
