<?php
// Registro de pasos contra un Upstash falso (tests/fixtures/fake-upstash.php): escribir, leer, clave mala, almacén
// caído y la clave secreta del endpoint del cron. Uso: php tests/store-test.php

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';

use Controllers\LiveRecordController;
use Core\Config;
use Core\Request;
use Core\Store;
use Engine\Bizkaibus\PassageLog;
use Models\ServiceJourney;
use Services\Calendar;

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

    // El registro de pasos es del algoritmo (engine/, repositorio aparte): sin él, esta parte no se prueba.
    if (class_exists(PassageLog::class)) {
        Config::set('bus');
        $Pdo = new PDO('sqlite::memory:');
        $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $Pdo->exec("CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT NOT NULL);
            INSERT INTO trip_aliases VALUES ('trp_A3514_909_LIN_51300_x', 'J1'), ('trp_A3514_909_LSEPT_51300_x', 'J1');");
        $Journeys = new ServiceJourney($Pdo);

        $aVm = ['3514|909' => [['tripRef' => 'trp_A3514_909_LIN_51300_x', 'order' => 5, 'recordedSeconds' => 52860]]];
        $sExpiryMarker = PassageLog::expiryMarker('bus', '2026-10-29');
        @unlink($sExpiryMarker);
        $Expect('el cron apunta los buses del directo', PassageLog::record($aVm, 'bus', '2026-10-29') === 1);
        $iDayEnd = (new DateTimeImmutable('2026-10-30', new DateTimeZone('Europe/Madrid')))->getTimestamp();
        $aExpiry = Store::pipeline([['GET', 'expireat:bide:bus:pass:2026-10-29'], ['GET', 'expireat:bide:bus:stops:2026-10-29']]);
        $Expect('el registro del día caduca 36 h después de acabar el día, y su historial a los 3 días', $aExpiry === [(string)($iDayEnd + 36 * 3600), (string)($iDayEnd + 3 * 86400)], json_encode($aExpiry));
        Store::pipeline([['DEL', 'expireat:bide:bus:pass:2026-10-29']]);
        PassageLog::record($aVm, 'bus', '2026-10-29');
        $Expect('la caducidad se pone una vez por servidor y día, no en cada minuto', Store::pipeline([['GET', 'expireat:bide:bus:pass:2026-10-29']]) === [null]);
        @unlink($sExpiryMarker);
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

        // De madrugada, el bus de un viaje de ayer (salió a las 23:30) se apunta en el registro de ayer, en horas de ayer;
        // el de hoy, en el de hoy. Así el de las 23:30 de hoy no sale esta noche por pasado antes de salir.
        $aNightVm = ['3514|909' => [['tripRef' => 'trp_A3514_909_LIN_51300_x', 'departureSeconds' => 84600, 'order' => 12, 'recordedSeconds' => 1800]],
            '3515|914' => [['tripRef' => 'trp_A3515_914_LIN_55800_z', 'departureSeconds' => 1500, 'order' => 2, 'recordedSeconds' => 1800]]];
        $sToday = Calendar::todayMadrid()->format('Y-m-d');
        $sYesterday = Calendar::yesterdayMadrid()->format('Y-m-d');
        $Expect('de madrugada se apuntan los dos', Engine\Bizkaibus\Live::recordVehicles($aNightVm, 'noche', 1800) === 2);
        $Expect('el de ayer, en el registro de ayer y a las 24:30', PassageLog::forJourneys($Journeys, ['J1'], 'noche', $sYesterday) === ['J1' => ['order' => 12, 'seconds' => 1800 + 86400]]
            && PassageLog::forJourneys($Journeys, ['J1'], 'noche', $sToday) === []);
        $Expect('el de hoy, en el de hoy', Store::pipeline([['HMGET', PassageLog::key('noche', $sToday), 'trp_A3515_914_LIN_55800_z']]) === [['2:1800']]);
        @unlink(PassageLog::expiryMarker('noche', $sToday));
        @unlink(PassageLog::expiryMarker('noche', $sYesterday));

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
    } else {
        echo "SKIP registro de pasos: sin engine/\n";
    }

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
