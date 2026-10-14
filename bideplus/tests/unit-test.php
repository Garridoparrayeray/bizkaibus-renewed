<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

spl_autoload_register(function (string $sClass): void {
    $sPath = __DIR__ . '/../api/' . str_replace('\\', '/', $sClass) . '.php';
    if (is_file($sPath)) {
        require $sPath;
    }
});

use Core\Config;
use Core\TripKey;
use Models\ServiceJourney;
use Services\Calendar;
use Services\GtfsRealtimeClient;
use Services\PaceFactors;
use Services\RealtimeMatcher;
use Services\SegmentTimes;
use Services\SiriVehicleMonitoringClient;
use Services\TripUpdatesMatcher;

$failures = [];
$expect = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'OK  ' : 'MAL ') . $name . ($ok || $detail === '' ? '' : '  [' . $detail . ']') . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
};
$section = function (string $title): void {
    echo "\n== " . $title . "\n";
};

const LAT0 = 43.26;
const LON0 = -2.93;
const M_LAT = 1 / 111320;
const DATE_WEEKDAY = '2026-10-01';
const DATE_WEEKEND = '2026-10-04';
const T0 = 8 * 3600;

function lonOffset(float $dMeters): float
{
    return $dMeters / (cos(deg2rad(LAT0)) * 111320);
}

function hms(int $iSeconds): string
{
    return sprintf('%02d:%02d:%02d', intdiv($iSeconds, 3600), intdiv($iSeconds % 3600, 60), $iSeconds % 60);
}

/*
 * Base de datos de prueba en memoria con el esquema real (ver scripts/build-database.php).
 * - J_LINE: línea recta hacia el norte, 5 paradas cada 1000 m y 180 s, con trazado (shape) cada 250 m.
 * - J_NOSHAPE: el mismo recorrido sin trazado ni dist_m (método por tramos entre paradas).
 * - J_LOOP: ida y vuelta por la misma calle; la vuelta va desplazada al este (ver fixture).
 */
function buildFixture(float $dReturnOffsetMeters): PDO
{
    $Pdo = new PDO('sqlite::memory:');
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $Pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $Pdo->exec('
        CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT NOT NULL, name_normalized TEXT NOT NULL, area TEXT NOT NULL DEFAULT \'\', area_normalized TEXT NOT NULL DEFAULT \'\', lat REAL NOT NULL, lon REAL NOT NULL, station_id TEXT, platform_label TEXT);
        CREATE TABLE service_journeys (id TEXT PRIMARY KEY, line_id TEXT NOT NULL, journey_pattern_id TEXT NOT NULL, trip_number TEXT, calendar_id TEXT NOT NULL, first_departure_seconds INTEGER, shape_id TEXT);
        CREATE TABLE passing_times (service_journey_id TEXT NOT NULL, seq_order INTEGER NOT NULL, stop_id TEXT NOT NULL, arrival_seconds INTEGER, departure_seconds INTEGER, dist_m REAL);
        CREATE TABLE shapes (id TEXT PRIMARY KEY, points TEXT NOT NULL);
        CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT NOT NULL);
    ');
    $InsStop = $Pdo->prepare('INSERT INTO stops (id, name, name_normalized, lat, lon) VALUES (?, ?, ?, ?, ?)');
    $InsPt = $Pdo->prepare('INSERT INTO passing_times VALUES (?, ?, ?, ?, ?, ?)');
    $InsSj = $Pdo->prepare('INSERT INTO service_journeys VALUES (?, ?, ?, ?, ?, ?, ?)');

    $aPoints = [];
    for ($i = 0; $i <= 5; $i++) {
        $InsStop->execute(['S' . $i, 'Parada ' . $i, 'parada ' . $i, LAT0 + $i * 1000 * M_LAT, LON0]);
    }
    for ($d = 0; $d <= 4000; $d += 250) {
        $aPoints[] = [LAT0 + $d * M_LAT, LON0, (float)$d];
    }
    $Pdo->prepare('INSERT INTO shapes VALUES (?, ?)')->execute(['SH_LINE', json_encode($aPoints)]);
    $InsSj->execute(['J_LINE', '3414', 'P1', '808', 'C1', T0, 'SH_LINE']);
    $InsSj->execute(['J_NOSHAPE', '3414', 'P1', '809', 'C1', T0, null]);
    for ($i = 0; $i < 5; $i++) {
        $InsPt->execute(['J_LINE', $i + 1, 'S' . $i, T0 + $i * 180, T0 + $i * 180, $i * 1000.0]);
        $InsPt->execute(['J_NOSHAPE', $i + 1, 'S' . $i, T0 + $i * 180, T0 + $i * 180, null]);
    }

    $InsStop->execute(['R0', 'Vuelta 0', 'vuelta 0', LAT0, LON0 + lonOffset($dReturnOffsetMeters)]);
    $aLoop = [];
    for ($d = 0; $d <= 1000; $d += 250) {
        $aLoop[] = [LAT0 + $d * M_LAT, LON0, (float)$d];
    }
    for ($d = 1250; $d <= 2000; $d += 250) {
        $aLoop[] = [LAT0 + (2000 - $d) * M_LAT, LON0 + lonOffset($dReturnOffsetMeters), (float)$d];
    }
    $Pdo->prepare('INSERT INTO shapes VALUES (?, ?)')->execute(['SH_LOOP', json_encode($aLoop)]);
    $InsSj->execute(['J_LOOP', '3414', 'P2', '900', 'C1', T0, 'SH_LOOP']);
    $InsPt->execute(['J_LOOP', 1, 'S0', T0, T0, 0.0]);
    $InsPt->execute(['J_LOOP', 2, 'S1', T0 + 180, T0 + 180, 1000.0]);
    $InsPt->execute(['J_LOOP', 3, 'R0', T0 + 360, T0 + 360, 2000.0]);

    $Pdo->exec("INSERT INTO trip_aliases VALUES ('trp_A3414_808_OP9LSEPT_28800_x', 'J_LINE'), ('trp_A3414_808_OP9LJIN_28800_x', 'J_LINE')");
    return $Pdo;
}

function live(float $dNorthMeters, float $dEastMeters, int $iOrder, int $iLocationSeconds, string $sTripRef = 'trp_A3414_808_OP9LSEPT_28800_x'): array
{
    return [
        'tripRef' => $sTripRef,
        'lineId' => '3414',
        'departureSeconds' => T0,
        'delaySeconds' => 0,
        'vehicleRef' => 'BUS1',
        'currentStopId' => 'S' . ($iOrder - 1),
        'order' => $iOrder,
        'lat' => LAT0 + $dNorthMeters * M_LAT,
        'lon' => LON0 + lonOffset($dEastMeters),
        'locationSeconds' => $iLocationSeconds,
    ];
}

function matcher(array $aLive, int $iNow, PaceFactors|null $Pace = null, float $dReturnOffset = 40.0, string $sDate = DATE_WEEKDAY, SegmentTimes|null $Segments = null): RealtimeMatcher
{
    return new RealtimeMatcher(['3414|808' => [$aLive]], new ServiceJourney(buildFixture($dReturnOffset)), $iNow, $Pace ?? new PaceFactors(), $sDate, $Segments ?? new SegmentTimes());
}

/* ------------------------------------------------------------------ */
$section('RealtimeMatcher: posición sobre el trazado');

$iGps = T0 + 240;
$aLive = live(1500, 0, 3, $iGps);
$M = matcher($aLive, $iGps + 30);
$aPos = $M->positionFor('J_LINE', $aLive);
$expect('el GPS a mitad del tramo P2-P3 se sitúa con el trazado', $aPos !== null && $aPos['method'] === 'shape');
$expect('T_pos se interpola por metros (08:04:30)', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270, $aPos === null ? 'null' : hms($aPos['scheduledSeconds']));
$expect('el retraso es T_gps - T_pos (-30 s, adelantado)', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

[$iEta, $iDelay, $bPositioned] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$expect('ETA = T_gps + (T_destino - T_pos) con k = 1 (08:11:30)', $iEta === T0 + 690 && $bPositioned, hms($iEta));
$expect('etaForStop devuelve el mismo retraso', $iDelay === -30);
[$iEta] = $M->etaForStop('J_LINE', T0 + 60, $aLive);
$expect('una parada ya pasada por el bus cae en el pasado (no sale como llegando ahora)', $iEta < $iGps - 120, hms($iEta));

$Pace = new PaceFactors(['global' => ['laborable.punta_manana' => 0.9]]);
$M = matcher($aLive, $iGps + 30, $Pace);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$expect('k de la franja escala solo el horario restante (08:10:45)', $iEta === T0 + 645, hms($iEta));
$expect('k no cambia el retraso mostrado', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKEND);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$expect('un factor de laborable no se aplica en fin de semana', $iEta === T0 + 690, hms($iEta));

/* Tramos aprendidos de las capturas (S1>S2, S2>S3); S3>S4 no tiene datos y usa el horario con k. */
$Segments = new SegmentTimes(['segments' => [
    'S1>S2' => ['n' => 5, 'all' => 60],
    'S2>S3' => ['n' => 5, 'all' => 100],
]]);
$M = matcher($aLive, $iGps + 30, null, 40.0, DATE_WEEKDAY, $Segments);
[$iEta, $iDelay] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$expect('con tiempos por tramo: medio tramo de 60 s + 100 s + 180 s del horario (08:09:10)', $iEta === T0 + 550, hms($iEta));
$expect('el retraso mostrado es la llegada estimada menos la hora publicada de esa parada (08:09:10 contra 08:12:00)', $iDelay === -170, (string)$iDelay);
[$iEta] = $M->etaForStop('J_LINE', T0 + 360, $aLive);
$expect('con tiempos por tramo solo se suman los tramos hasta la parada pedida (08:04:30)', $iEta === T0 + 270, hms($iEta));

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKDAY, $Segments);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$expect('el factor k solo se aplica a los tramos sin datos (08:08:52)', $iEta === T0 + 532, hms($iEta));

$SegmentsBand = new SegmentTimes(['segments' => ['S1>S2' => ['n' => 9, 'all' => 60, 'laborable.punta_manana' => 120]]]);
$expect('el tiempo de la franja manda sobre el general', $SegmentsBand->seconds('S1', 'S2', 'laborable.punta_manana') === 120.0 && $SegmentsBand->seconds('S1', 'S2', 'laborable.noche') === 60.0);
$expect('un tramo sin datos no tiene tiempo aprendido', $SegmentsBand->seconds('S2', 'S1', 'laborable.noche') === null);

$aNear = live(1500, 30, 3, $iGps);
$expect('a 30 m del trazado sigue en ruta', matcher($aNear, $iGps)->positionFor('J_LINE', $aNear) !== null);
$aFar = live(1500, 80, 3, $iGps);
$MFar = matcher($aFar, $iGps);
$expect('a 80 m del trazado (> 50 m) queda fuera de ruta', $MFar->positionFor('J_LINE', $aFar) === null);
[$iEta, , $bPositioned] = $MFar->etaForStop('J_LINE', T0 + 720, $aFar);
$expect('fuera de ruta la ETA es el horario + retraso del feed', $iEta === T0 + 720 && !$bPositioned, hms($iEta));
[$iEtaNext] = $MFar->etaForStop('J_LINE', T0 + 360, $aFar, 3);
$expect('fuera de ruta el retraso mostrado sale de la parada registrada, igual que la ETA de la lista', $MFar->currentDelaySeconds('J_LINE', $aFar) === $iEtaNext - (T0 + 360), (string)$MFar->currentDelaySeconds('J_LINE', $aFar));

/* ------------------------------------------------------------------ */
$section('RealtimeMatcher: cuándo no se fía del GPS');

$aStale = live(1500, 0, 3, $iGps);
$expect('un dato GPS de hace 9 min todavía vale', matcher($aStale, $iGps + 9 * 60)->positionFor('J_LINE', $aStale) !== null);
$expect('un dato GPS de hace 11 min (> 10) se descarta', matcher($aStale, $iGps + 11 * 60)->positionFor('J_LINE', $aStale) === null);

$aEarly = live(0, 0, 1, T0 - 120);
$expect('un bus asignado a un viaje que aún no ha salido (> 60 s antes) no se sitúa', matcher($aEarly, T0 - 100)->positionFor('J_LINE', $aEarly) === null);
$aJustBefore = live(0, 0, 1, T0 - 30);
$expect('30 s antes de la salida sí se sitúa (margen de 60 s)', matcher($aJustBefore, T0)->positionFor('J_LINE', $aJustBefore) !== null);

$aBadOrder = live(1500, 0, 42, $iGps);
$expect('una próxima parada que no existe en el viaje descarta el GPS', matcher($aBadOrder, $iGps)->positionFor('J_LINE', $aBadOrder) === null);

$aWindow = live(500, 0, 5, $iGps);
$expect('fuera de la ventana (3 tramos atrás) no se proyecta aunque esté sobre la ruta', matcher($aWindow, $iGps)->positionFor('J_LINE', $aWindow) === null);

/* Bizkaibus: sin GPS utilizable (no lo manda o está fuera de la ruta) se muestra el horario oficial, sin estimar nada. */
$aNoGps = live(1500, 0, 3, $iGps);
$aNoGps['lat'] = null;
$aNoGps['lon'] = null;
$aNoGps['locationSeconds'] = null;
$aNoGps['delaySeconds'] = 60;
$MNoGps = matcher($aNoGps, T0 + 300);
$expect('Bizkaibus sin GPS: no cuenta como en vivo', !$MNoGps->isLive('J_LINE', $aNoGps));
[$iEta, $iDelay, $bPositioned] = $MNoGps->etaForStop('J_LINE', T0 + 720, $aNoGps);
$expect('Bizkaibus sin GPS: la ETA es la hora oficial y no hay retraso', $iEta === T0 + 720 && $iDelay === 0 && !$bPositioned, hms($iEta));
$expect('Bizkaibus sin GPS: el retraso mostrado es 0 aunque el feed diga otra cosa', $MNoGps->currentDelaySeconds('J_LINE', $aNoGps) === 0);
$aRowOfficial = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720];
$aOfficial = $MNoGps->enrich([$aRowOfficial])[0];
$expect('enrich: sin GPS la salida sale programada con su hora oficial', $aOfficial['status'] === 'scheduled' && $aOfficial['etaSeconds'] === T0 + 720 && $aOfficial['delaySeconds'] === 0, json_encode([$aOfficial['status'], $aOfficial['etaSeconds']]));
$aOffRouteBus = live(1500, 200, 3, $iGps);
$aOffRouteNoClock = $aOffRouteBus;
$aOffRouteNoClock['locationSeconds'] = null;
$expect('Bizkaibus con GPS fuera de la ruta y sin hora del dato: horario oficial', !matcher($aOffRouteNoClock, $iGps + 30)->isLive('J_LINE', $aOffRouteNoClock) && matcher($aOffRouteNoClock, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'scheduled');
$expect('Bizkaibus con GPS en ruta: en directo', matcher($aLive, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'live');

/* Si el cálculo con GPS falla por lo que sea (base de datos rota, dato imposible), la salida cae al horario oficial en vez de romper la parada. */
$PdoBroken = new PDO('sqlite::memory:');
$PdoBroken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoBroken->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$MBroken = new RealtimeMatcher(['3414|808' => [$aLive]], new ServiceJourney($PdoBroken), $iGps + 30, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aBrokenOut = $MBroken->enrich([$aRowOfficial])[0];
$expect('si el cálculo con GPS falla, la salida sale con el horario oficial', $aBrokenOut['status'] === 'scheduled' && $aBrokenOut['etaSeconds'] === T0 + 720 && $aBrokenOut['delaySeconds'] === 0, json_encode([$aBrokenOut['status'], $aBrokenOut['etaSeconds']]));
$expect('si el cálculo con GPS falla, ni la posición ni el retraso rompen nada', $MBroken->positionFor('J_LINE', $aLive) === null && $MBroken->currentDelaySeconds('J_LINE', $aLive) === 0 && $MBroken->etaForStop('J_LINE', T0 + 720, $aLive) === [T0 + 720, 0, false]);

/* Otras redes con SIRI (Euskotren, tranvías) no cambian: sin GPS siguen en directo con el retraso del feed. */
Config::set('euskotren');
$MOtherNetwork = matcher($aNoGps, T0 + 300);
$expect('en otra red, sin GPS, sigue en directo', $MOtherNetwork->isLive('J_LINE', $aNoGps));
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aNoGps);
$expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

$expect('sin datos en vivo la ETA es el horario', matcher($aLive, $iGps)->etaForStop('J_LINE', T0 + 720, null) === [T0 + 720, 0, false]);

/* ------------------------------------------------------------------ */
$section('Bizkaibus: fuente de los buses en directo');
$aBusConfig = require __DIR__ . '/../api/Config/config.php';
$expect('los buses en directo salen solo del feed GPS SIRI de Open Data Bizkaia (posicion y hora del dato), sin el feed de trip-updates', str_starts_with($aBusConfig['siri']['vehicle_monitoring_url'], 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions') && !isset($aBusConfig['siri']['vehicle_monitoring_fallback_url']));

$section('RealtimeMatcher: capa 2, parada registrada sin GPS utilizable');

/* J_LINE: paradas seq 1..5 con llegadas T0, +180, +360, +540, +720. El feed da la parada 3 como próxima. */
$aRegistered = live(1500, 0, 3, $iGps);
$aRegistered['lat'] = null;
$aRegistered['lon'] = null;
$aRegistered['locationSeconds'] = null;
$aRegistered['recordedSeconds'] = T0 + 250;
$MReg = matcher($aRegistered, T0 + 300);
$expect('sin GPS pero con parada y hora del dato, el bus cuenta como en vivo', $MReg->isLive('J_LINE', $aRegistered));
[$iEta, $iDelay, $bPositioned] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$expect('la ETA parte de la hora del dato, a mitad del tramo hasta la próxima, más lo que queda (08:11:40)', $iEta === T0 + 700 && !$bPositioned, hms($iEta));
$expect('el retraso mostrado es la ETA menos el horario de la parada', $iDelay === -20);
$expect('el retraso del recorrido sale de la parada registrada y no del campo Delay del feed', $MReg->currentDelaySeconds('J_LINE', array_merge($aRegistered, ['delaySeconds' => 720])) === -20);
[$iEta] = $MReg->etaForStop('J_LINE', T0 + 360, $aRegistered, 3);
$expect('para la propia próxima parada solo cuenta la mitad del tramo (08:05:40)', $iEta === T0 + 340, hms($iEta));
$MRegFast = matcher($aRegistered, T0 + 300, new PaceFactors(['global' => ['laborable.punta_manana' => 0.5]]));
[$iEtaFar, $iDelayFar] = $MRegFast->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$expect('con parada registrada, el retraso mostrado de una parada es su llegada estimada menos su hora publicada', $iDelayFar === $iEtaFar - (T0 + 720), json_encode([$iEtaFar - (T0 + 720), $iDelayFar]));
$aRowFarEarly = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720 + 3 * 3600, 'stop_seq' => 5];
$aFarOut = $MRegFast->enrich([$aRowFarEarly])[0];
$expect('si la llegada estimada difiere más de 45 min de la hora publicada, sigue en vivo con su llegada estimada pero sin afirmar el retraso', $aFarOut['status'] === 'live' && $aFarOut['delaySeconds'] === null && $aFarOut['etaSeconds'] < T0 + 720 + 3 * 3600, json_encode([$aFarOut['status'], $aFarOut['delaySeconds']]));
[$iEta, , ] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered);
$expect('sin número de parada no se estima: horario oficial', $iEta === T0 + 720, hms($iEta));

$aRowPassed = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowTarget = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$aEnriched = $MReg->enrich([$aRowPassed, $aRowTarget]);
$expect('enrich: la fila de una parada ya pasada sale como salida, con la hora estimada en que el bus la pasó (la de la parada registrada)', $aEnriched[0]['status'] === 'departed' && $aEnriched[0]['etaSeconds'] === T0 + 250, json_encode($aEnriched[0]['etaSeconds']));
$expect('enrich: la fila de una parada posterior sale en directo con la ETA de la parada registrada', $aEnriched[1]['status'] === 'live' && $aEnriched[1]['etaSeconds'] === T0 + 700, json_encode([$aEnriched[1]['status'], $aEnriched[1]['etaSeconds']]));

$aWaiting = $aRegistered;
$aWaiting['recordedSeconds'] = T0 - 120;
$expect('un bus que espera en cabecera (viaje sin empezar) no cuenta como en vivo', !matcher($aWaiting, T0 - 100)->isLive('J_LINE', $aWaiting));
$expect('un dato de hace más de 10 min no sirve: horario oficial', !matcher($aRegistered, T0 + 250 + 11 * 60)->isLive('J_LINE', $aRegistered));
$aNoClock = $aRegistered;
$aNoClock['recordedSeconds'] = null;
$expect('sin hora del dato no se estima nada: horario oficial', !matcher($aNoClock, T0 + 300)->isLive('J_LINE', $aNoClock));

$aOffRoute = live(1500, 200, 3, $iGps);
$aOffRoute['recordedSeconds'] = $iGps;
$MOff = matcher($aOffRoute, $iGps + 30);
$expect('con GPS fuera de la ruta se usa la parada registrada', $MOff->isLive('J_LINE', $aOffRoute) && $MOff->enrich([$aRowPassed])[0]['status'] === 'departed');
[$iEta] = $MOff->etaForStop('J_LINE', T0 + 720, $aOffRoute, 5);
$expect('con GPS fuera de la ruta la ETA sale de la parada registrada (08:12:00)', $iEta === $iGps + 90 + 360, hms($iEta));
$aOnRoute = live(1500, 0, 3, $iGps);
$expect('con GPS en ruta manda el GPS y no se descarta nada por el orden del feed', matcher($aOnRoute, $iGps + 30)->enrich([$aRowTarget])[0]['status'] === 'live');

/* Con GPS: en cuanto el bus pasa una parada (el feed ya marca la siguiente), esa salida se retira al momento. */
$aGpsPassed = live(1500, 0, 3, $iGps);
$MGpsPassed = matcher($aGpsPassed, $iGps + 30);
$aRowStop2 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowStop3 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 360, 'stop_seq' => 3];
$aOutPassed = $MGpsPassed->enrich([$aRowStop2, $aRowStop3]);
$expect('con GPS, la parada que el bus ya pasó sale como salida, con la hora en que el bus estuvo en ella (08:02:30)', $aOutPassed[0]['status'] === 'departed' && $aOutPassed[0]['etaSeconds'] === T0 + 150, json_encode([$aOutPassed[0]['status'], $aOutPassed[0]['etaSeconds']]));
$expect('con GPS, la próxima parada sigue en directo', $aOutPassed[1]['status'] === 'live', $aOutPassed[1]['status']);

/* Tramo largo (cabecera, espera): la parada registrada no dice dónde va el bus, así que no se estima. */
$aLongSegment = $aRegistered;
$aLongSegment['order'] = 3;
$MLong = matcher($aLongSegment, T0 + 300);
$expect('tramos de 3 min sí se estiman', $MLong->isLive('J_LINE', $aLongSegment));
$aBigGap = array_merge($aRegistered, ['order' => 5, 'recordedSeconds' => T0 + 600]);
$expect('con tramo hasta la próxima parada de 3 min (orden 5) se estima', matcher($aBigGap, T0 + 620)->isLive('J_LINE', $aBigGap));

/* Capa 3: sin ningún dato del bus, el horario publicado se corrige con los tiempos aprendidos (salida de cabecera + lo que tardan los buses). */
$SegLearned = new SegmentTimes(['segments' => [
    'S0>S1' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S1>S2' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S2>S3' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S3>S4' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
]]);
$aRowNoBus = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$MNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$aNoBus = $MNoBus->enrich([$aRowNoBus])[0];
$expect('sin datos del bus, la llegada es la salida de cabecera + tiempos aprendidos + 1 min (08:07:40)', $aNoBus['status'] === 'scheduled' && $aNoBus['etaSeconds'] === T0 + 400 + 60 && $aNoBus['delaySeconds'] === 0, json_encode([$aNoBus['status'], hms($aNoBus['etaSeconds'])]));
$MNoBusLate = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 500, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$expect('si lo estimado ya queda en el pasado, se deja el horario publicado (no se esconde ni se deja en 0 min)', $MNoBusLate->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
$MNoLearned = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$expect('sin tiempos aprendidos, se deja el horario publicado', $MNoLearned->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
Config::set('euskotren');
$MOtherNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$expect('en otras redes se deja el horario publicado', $MOtherNoBus->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
Config::set('bus');

/* GPS y parada registrada que no cuadran (más de 6 min): no se fía de ninguna, horario oficial. */
$aDisagree = live(1500, 0, 5, $iGps);
$MDisagree = matcher($aDisagree, $iGps + 30, new PaceFactors(['global' => ['laborable.punta_manana' => 0.0]]));
$aDisagreeOut = $MDisagree->enrich([$aRowTarget]);
$expect('GPS y parada registrada discrepan más de 6 min: la salida sale con el horario oficial', $aDisagreeOut[0]['status'] === 'scheduled', $aDisagreeOut[0]['status']);
$aAgreeOut = matcher($aGpsPassed, $iGps + 30)->enrich([$aRowTarget]);
$expect('GPS y parada registrada que cuadran: sigue en vivo', $aAgreeOut[0]['status'] === 'live', $aAgreeOut[0]['status']);

/* Un viaje del feed que no es el que hace el bus (50 min de diferencia con el horario): la llegada estimada sigue valiendo, el retraso no se afirma. */
$iFarGps = T0 + 3300;
$aFar = live(1500, 0, 3, $iFarGps);
$MFarDelay = matcher($aFar, $iFarGps + 30);
$aFarGpsOut = $MFarDelay->enrich([$aRowTarget])[0];
$expect('con GPS, 50 min de diferencia con el horario: sigue en vivo y el retraso queda sin afirmar', $aFarGpsOut['status'] === 'live' && $aFarGpsOut['delaySeconds'] === null, json_encode([$aFarGpsOut['status'], $aFarGpsOut['delaySeconds']]));
$expect('el retraso para mostrar es null si pasa de 45 min y un entero si no', $MFarDelay->displayDelayMinutes(50 * 60) === null && $MFarDelay->displayDelayMinutes(40 * 60) === 40 && $MFarDelay->displayDelayMinutes(null) === null);
$aNear = live(1500, 0, 3, T0 + 240 + 40 * 60);
$expect('40 min de retraso sí se muestran', matcher($aNear, T0 + 240 + 40 * 60 + 30)->isLive('J_LINE', $aNear));

/* Otras redes con SIRI no usan la parada registrada: siguen con el cálculo de siempre. */
Config::set('euskotren');
$MOtherNetwork = matcher($aRegistered, T0 + 300);
$expect('en otra red no se da ninguna fila por pasada con la parada registrada', $MOtherNetwork->enrich([$aRowPassed])[0]['status'] !== 'departed');
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

/* ------------------------------------------------------------------ */
$section('RealtimeMatcher: sin trazado (tramos entre paradas)');

$aSeg = live(1500, 100, 3, $iGps, 'trp_A3414_809_X_28800_x');
$MSeg = new RealtimeMatcher(['3414|809' => [$aSeg]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aPos = $MSeg->positionFor('J_NOSHAPE', $aSeg);
$expect('sin shape se proyecta sobre la recta entre paradas', $aPos !== null && $aPos['method'] === 'segment');
$expect('sin shape el pasillo es max(150 m, 15 %): 100 m vale', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270);
$aSegFar = live(1500, 200, 3, $iGps, 'trp_A3414_809_X_28800_x');
$expect('sin shape a 200 m queda fuera de ruta', $MSeg->positionFor('J_NOSHAPE', $aSegFar) === null);

/* ------------------------------------------------------------------ */
$section('RealtimeMatcher: trazados que vuelven sobre sí mismos');

$aOut = live(250, 0, 2, T0 + 60);
$aPos = matcher($aOut, T0 + 60)->positionFor('J_LOOP', $aOut);
$expect('en la ida (próxima = P2) se queda en la ida', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, $aPos === null ? 'null' : hms($aPos['scheduledSeconds']));

$aBack = live(250, 40, 2, T0 + 300);
$aPos = matcher($aBack, T0 + 300, null, 40.0)->positionFor('J_LOOP', $aBack);
$expect('en la vuelta, 40 m más cerca, gana la vuelta aunque el feed diga P2', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 315, $aPos === null ? 'null' : hms($aPos['scheduledSeconds']));

$aClose = live(250, 10, 2, T0 + 300);
$aPos = matcher($aClose, T0 + 300, null, 10.0)->positionFor('J_LOOP', $aClose);
$expect('si la vuelta está a menos de 20 m de diferencia, manda la próxima parada del feed', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, $aPos === null ? 'null' : hms($aPos['scheduledSeconds']));

/* ------------------------------------------------------------------ */
$section('RealtimeMatcher: emparejar el feed con el horario y enrich()');

$M = matcher($aLive, $iGps + 30);
$expect('el VehicleJourneyRef se empareja por trip_aliases', $M->lookup('3414', '808', T0, 'J_LINE') === $aLive);
$aVariant = live(1500, 0, 3, $iGps, 'trp_A3414_808_OP9LJIN_28800_x');
$MVariant = new RealtimeMatcher(['3414|808' => [$aVariant]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$expect('una variante de calendario fusionada (OP9LJIN) apunta al mismo viaje', $MVariant->journeyIdFor($aVariant) === 'J_LINE' && $MVariant->lookup('3414', '808', T0, 'J_LINE') === $aVariant);

$aOld = ['3414|808' => [
    ['departureSeconds' => T0 + 200, 'tag' => 'lejos'],
    ['departureSeconds' => T0 + 60, 'tag' => 'cerca'],
]];
$MOld = new RealtimeMatcher($aOld, null, T0, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aHit = $MOld->lookup('3414', '808', T0);
$expect('sin alias, gana la salida más cercana dentro de 180 s', $aHit !== null && $aHit['tag'] === 'cerca');
$expect('sin alias, más de 180 s de diferencia no empareja', $MOld->lookup('3414', '808', T0 + 600) === null);
$expect('una línea sin buses en el feed no empareja', $MOld->lookup('9999', '808', T0) === null);

$aRow = ['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 720];
$aOut = $M->enrich([$aRow])[0];
$expect('enrich marca la salida como live con la ETA calculada', $aOut['status'] === 'live' && $aOut['etaSeconds'] === T0 + 690 && $aOut['vehicleRef'] === 'BUS1');
$expect('enrich conserva los campos originales de la fila', $aOut['arrival_seconds'] === T0 + 720 && $aOut['trip_number'] === '808');

$aPassed = ['arrival_seconds' => T0] + $aRow;
$aOut = $M->enrich([$aPassed])[0];
$expect('una parada que el bus ya dejó atrás sale como departed', $aOut['status'] === 'departed');

$aOther = ['service_journey_id' => 'J_NOSHAPE', 'trip_number' => '777'] + $aRow;
$aOut = $M->enrich([$aOther])[0];
$expect('una salida sin bus en el feed queda programada', $aOut['status'] === 'scheduled' && $aOut['etaSeconds'] === T0 + 720 && $aOut['delaySeconds'] === 0);

$aOut = $MFar->enrich([$aRow])[0];
$expect('un bus fuera de ruta deja la salida como programada', $aOut['status'] === 'scheduled');

/* ------------------------------------------------------------------ */
$section('PaceFactors');

$expect('jueves 07:30 es laborable.punta_manana', PaceFactors::bandFor(DATE_WEEKDAY, 7 * 3600 + 1800) === 'laborable.punta_manana');
$expect('domingo 10:00 es fin_semana.manana', PaceFactors::bandFor(DATE_WEEKEND, 10 * 3600) === 'fin_semana.manana');
$expect('16:00 en punto ya es punta_tarde', PaceFactors::bandFor(DATE_WEEKDAY, 16 * 3600) === 'laborable.punta_tarde');
$expect('pasada la medianoche (25:10 GTFS) es madrugada', PaceFactors::bandFor(DATE_WEEKDAY, 25 * 3600 + 600) === 'laborable.madrugada');
$P = new PaceFactors(['global' => ['laborable.manana' => 0.9, 'laborable.noche' => 0.1, 'laborable.mediodia' => 5], 'lines' => ['A1' => ['laborable.manana' => 0.8]]]);
$expect('el factor por línea tiene prioridad sobre el global', $P->factor('A1', DATE_WEEKDAY, 10 * 3600) === 0.8);
$expect('otra línea usa el global', $P->factor('B2', DATE_WEEKDAY, 10 * 3600) === 0.9);
$expect('sin valor para la franja, k = 1', $P->factor('B2', DATE_WEEKEND, 10 * 3600) === 1.0);
$expect('k se limita por abajo a 0,6', $P->factor(null, DATE_WEEKDAY, 21 * 3600) === PaceFactors::MIN_FACTOR);
$expect('k se limita por arriba a 1,2', $P->factor(null, DATE_WEEKDAY, 14 * 3600) === PaceFactors::MAX_FACTOR);
$expect('un fichero que no existe da k = 1', PaceFactors::fromFile('/no/existe.json')->factor(null, DATE_WEEKDAY, 10 * 3600) === 1.0);

$aData = json_decode((string)file_get_contents(PaceFactors::defaultPath()), true);
$aBands = [];
foreach (['laborable', 'fin_semana'] as $sDay) {
    foreach (['madrugada', 'punta_manana', 'manana', 'mediodia', 'punta_tarde', 'noche'] as $sBand) {
        $aBands[] = $sDay . '.' . $sBand;
    }
}
$bValid = \is_array($aData) && isset($aData['global']) && \is_array($aData['global']);
$aBad = [];
if ($bValid) {
    $aAll = ['global' => $aData['global']];
    foreach ($aData['lines'] ?? [] as $sLine => $aLineBands) {
        $aAll['línea ' . $sLine] = $aLineBands;
    }
    foreach ($aAll as $sWhere => $aValues) {
        foreach ($aValues as $sBand => $dValue) {
            if (!\in_array($sBand, $aBands, true) || !is_numeric($dValue) || $dValue < PaceFactors::MIN_FACTOR || $dValue > PaceFactors::MAX_FACTOR) {
                $aBad[] = $sWhere . ':' . $sBand . '=' . json_encode($dValue);
            }
        }
    }
}
$expect('data/pace-factors.json es válido (franjas conocidas, k entre 0,6 y 1,2)', $bValid && empty($aBad), implode(', ', $aBad));

$aSegmentsBad = [];
$sSegmentsPath = SegmentTimes::defaultPath();
if (is_file($sSegmentsPath)) {
    $aSegmentsFile = json_decode((string)file_get_contents($sSegmentsPath), true);
    if (!is_array($aSegmentsFile) || !is_array($aSegmentsFile['segments'] ?? null)) {
        $aSegmentsBad[] = 'formato';
    } else {
        foreach ($aSegmentsFile['segments'] as $sKey => $aSegment) {
            foreach ($aSegment as $sGroup => $Value) {
                $bGroupOk = $sGroup === 'n' || $sGroup === 'all' || \in_array($sGroup, $aBands, true);
                if (!preg_match('/^[^>]+>[^>]+$/', (string)$sKey) || !$bGroupOk || !is_int($Value) || $Value <= 0 || $Value > 3 * 3600) {
                    $aSegmentsBad[] = $sKey . ':' . $sGroup . '=' . json_encode($Value);
                }
            }
        }
    }
}
$expect('data/segment-times.json es válido si existe (tramos desde>hasta, segundos entre 1 y 3 h)', empty($aSegmentsBad), implode(', ', array_slice($aSegmentsBad, 0, 5)));

/* ------------------------------------------------------------------ */
$section('SIRI-VM (parseo del feed)');

$sXml = <<<XML
<Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery>
<VehicleActivity><RecordedAtTime>2026-10-01T08:04:00+02:00</RecordedAtTime><MonitoredVehicleJourney>
  <VehicleJourneyRef>trp_A3414_808_OP9LSEPT_28800_abc</VehicleJourneyRef><Delay>PT1M30S</Delay><VehicleRef>4521</VehicleRef>
  <VehicleLocation><Longitude>-2.93</Longitude><Latitude>43.27</Latitude></VehicleLocation>
  <MonitoredCall><StopPointRef>0123</StopPointRef><VisitNumber>3</VisitNumber></MonitoredCall>
</MonitoredVehicleJourney></VehicleActivity>
<VehicleActivity><MonitoredVehicleJourney>
  <VehicleJourneyRef>trp_A3414_808_OP9LJIN_30000_abc</VehicleJourneyRef><Delay>-PT45S</Delay>
  <LocationRecordedAtTime>2026-09-30T23:59:00+02:00</LocationRecordedAtTime>
  <VehicleLocation><Longitude>-2.9</Longitude><Latitude>43.2</Latitude></VehicleLocation>
  <MonitoredCall><Order>7</Order></MonitoredCall>
</MonitoredVehicleJourney></VehicleActivity>
<VehicleActivity><MonitoredVehicleJourney><VehicleJourneyRef>referencia-rara</VehicleJourneyRef></MonitoredVehicleJourney></VehicleActivity>
</VehicleMonitoringDelivery></ServiceDelivery></Siri>
XML;
$aMap = SiriVehicleMonitoringClient::parse($sXml, DATE_WEEKDAY);
$aFirst = $aMap['3414|808'][0] ?? null;
$aSecond = $aMap['3414|808'][1] ?? null;
$expect('agrupa por línea|número de viaje y descarta referencias que no encajan', array_keys($aMap) === ['3414|808'] && \count($aMap['3414|808']) === 2);
$expect('lee la salida del VehicleJourneyRef (28800 s)', $aFirst !== null && $aFirst['departureSeconds'] === 28800);
$expect('Delay PT1M30S = 90 s y -PT45S = -45 s', $aFirst !== null && $aSecond !== null && $aFirst['delaySeconds'] === 90 && $aSecond['delaySeconds'] === -45);
$expect('lee GPS, hora del dato (08:04:00), parada y VisitNumber', $aFirst !== null && $aFirst['lat'] === 43.27 && $aFirst['locationSeconds'] === T0 + 240 && $aFirst['currentStopId'] === '0123' && $aFirst['order'] === 3);
$expect('Order tiene prioridad y un GPS de otro día se descarta', $aSecond !== null && $aSecond['order'] === 7 && $aSecond['lat'] === null && $aSecond['locationSeconds'] === null);
$expect('guarda la hora del dato (RecordedAtTime) aunque no haya GPS', $aFirst !== null && $aFirst['recordedSeconds'] === T0 + 240 && $aSecond !== null && $aSecond['recordedSeconds'] === null);
$expect('un XML roto devuelve un mapa vacío', SiriVehicleMonitoringClient::parse('<Siri><roto', DATE_WEEKDAY) === []);

/* ------------------------------------------------------------------ */
$section('GTFS-Realtime: lectura del feed de Metro Bilbao (CTB)');

function pbVarint(int $iValue): string
{
    $sOut = '';
    do {
        $iByte = $iValue & 0x7f;
        $iValue = ($iValue >> 7) & (PHP_INT_MAX >> 6);
        if ($iValue !== 0) {
            $iByte |= 0x80;
        }
        $sOut .= chr($iByte);
    } while ($iValue !== 0);
    return $sOut;
}

function pbField(int $iField, int|string $Value): string
{
    if (is_int($Value)) {
        return pbVarint($iField << 3) . pbVarint($Value);
    }
    return pbVarint(($iField << 3) | 2) . pbVarint(strlen($Value)) . $Value;
}

function pbStopTime(string $sStopId, int|null $iArrival, int|null $iDeparture = null, int $iRelationship = 0): string
{
    $sOut = '';
    if ($iArrival !== null) {
        $sOut .= pbField(2, pbField(1, -60) . pbField(2, $iArrival));
    }
    if ($iDeparture !== null) {
        $sOut .= pbField(3, pbField(2, $iDeparture));
    }
    $sOut .= pbField(4, $sStopId);
    if ($iRelationship !== 0) {
        $sOut .= pbField(5, $iRelationship);
    }
    return $sOut;
}

$aReal = GtfsRealtimeClient::parseTripUpdates((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-trip-updates.pb'));
$iRealStops = 0;
foreach ($aReal['trips'] as $aTrip) {
    $iRealStops += count($aTrip['stops']);
}
$expect('feed real: 84 viajes y 661 previsiones (igual que la librería oficial de Google)', count($aReal['trips']) === 84 && $iRealStops === 661, count($aReal['trips']) . ' / ' . $iRealStops);
$expect('feed real: fecha de generación y primer viaje 899112 en la parada 22 (sin el ".0")', $aReal['generatedAt'] === 1791180803 && $aReal['trips'][0]['tripId'] === '899112' && $aReal['trips'][0]['stops'][0] === ['22', 1791180816]);

$sSynthetic = pbField(1, pbField(1, '2.0') . pbField(3, 1000))
    . pbField(2, pbField(1, 'e1') . pbField(3, pbField(1, pbField(1, 'T1')) . pbField(2, pbStopTime('7.0', 2000)) . pbField(2, pbStopTime('8.0', null, 2100)) . pbField(2, pbStopTime('9.0', 2200, null, 1))))
    . pbField(2, pbField(1, 'e2') . pbField(4, pbField(1, 'solo-posicion')));
$aSynthetic = GtfsRealtimeClient::parseTripUpdates($sSynthetic);
$expect('lee la hora de generación de la cabecera', $aSynthetic['generatedAt'] === 1000);
$expect('usa la salida si no hay llegada y descarta las paradas saltadas', $aSynthetic['trips'] === [['tripId' => 'T1', 'stops' => [['7', 2000], ['8', 2100]]]], json_encode($aSynthetic['trips']));
$expect('ignora entidades sin previsiones (solo posición) y lee retrasos negativos sin romperse', count($aSynthetic['trips']) === 1);
$bTruncated = false;
try {
    GtfsRealtimeClient::parseTripUpdates(substr($sSynthetic, 0, 20));
} catch (RuntimeException $Ex) {
    $bTruncated = true;
}
$expect('un feed cortado lanza un error (la caché sigue con el último bueno)', $bTruncated);

/* ------------------------------------------------------------------ */
$section('GTFS-Realtime: asociación de previsiones al horario (Metro+)');

$PdoRt = new PDO('sqlite::memory:');
$PdoRt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoRt->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoRt->exec('CREATE TABLE journey_pattern_stops (journey_pattern_id TEXT NOT NULL, seq_order INTEGER NOT NULL, stop_id TEXT NOT NULL)');
$InsJps = $PdoRt->prepare('INSERT INTO journey_pattern_stops VALUES (?, ?, ?)');
foreach (['UP' => ['1', '2', '3', '4'], 'DOWN' => ['4', '3', '2', '1'], 'LONG' => ['a', 'b', 'c', 'd', 'e', 'f']] as $sPattern => $aStops) {
    foreach ($aStops as $iSeq => $sStop) {
        $InsJps->execute([$sPattern, $iSeq, $sStop]);
    }
}
$scheduledRow = fn(string $sTrip, string $sPattern, int $iArrival) => [
    'trip_number' => $sTrip, 'journey_pattern_id' => $sPattern, 'arrival_seconds' => $iArrival,
    'status' => 'scheduled', 'etaSeconds' => $iArrival, 'delaySeconds' => 0,
];
$aRows = [$scheduledRow('up1', 'UP', T0 + 100), $scheduledRow('down1', 'DOWN', T0 + 120), $scheduledRow('up2', 'UP', T0 + 400)];
$aFeedTrips = [
    ['tripId' => 'a', 'stops' => [['2', T0 + 150], ['3', T0 + 250]]],
    ['tripId' => 'b', 'stops' => [['3', T0 + 200], ['2', T0 + 300]]],
    ['tripId' => 'c', 'stops' => [['2', T0 + 110]]],
    ['tripId' => 'd', 'stops' => [['2', T0 + 2000], ['3', T0 + 2100]]],
];
$aOut = (new TripUpdatesMatcher($aFeedTrips, new ServiceJourney($PdoRt), 600, 0))->enrich('2', $aRows);
$aByTrip = array_column($aOut, null, 'trip_number');
$expect('el tren de subida recibe su previsión (50 s cuentan como en hora)', $aByTrip['up1']['status'] === 'live' && $aByTrip['up1']['etaSeconds'] === T0 + 150 && $aByTrip['up1']['delaySeconds'] === 0);
$expect('el de bajada recibe la del tren que va en su sentido: 3 min de retraso', $aByTrip['down1']['status'] === 'live' && $aByTrip['down1']['delaySeconds'] === 180);
$expect('una previsión de una sola parada o a más de 10 min no se asocia', $aByTrip['up2']['status'] === 'scheduled' && $aByTrip['up2']['etaSeconds'] === T0 + 400);
$expect('las salidas quedan ordenadas por la hora prevista', array_column($aOut, 'trip_number') === ['up1', 'down1', 'up2']);
$expect('sin feed, el horario no cambia', (new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->enrich('2', $aRows) === $aRows);
$MDeparted = new TripUpdatesMatcher($aFeedTrips, new ServiceJourney($PdoRt), 600, 0);
$aGone = [
    ['status' => 'live', 'etaSeconds' => 1000 - 30],
    ['status' => 'live', 'etaSeconds' => 1000 - 60],
    ['status' => 'scheduled', 'etaSeconds' => 1000 - 50],
    ['status' => 'scheduled', 'etaSeconds' => 1000 - 90],
    ['status' => 'scheduled', 'etaSeconds' => 1000 + 120],
];
$aKept = array_column($MDeparted->withoutDeparted($aGone, 1000), 'etaSeconds');
$expect('con el feed activo, un tren en vivo que pasó hace 30 s se mantiene y hace 60 s ya ha salido', $aKept === [970, 950, 1120], json_encode($aKept));
$expect('con el feed activo, un tren sin datos en vivo pasado hace 90 s ya ha salido', !in_array(910, $aKept, true));
$expect('hasData: con trenes en el feed, sí; con el feed vacío, no', $MDeparted->hasData() && !(new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->hasData());

/* El feed del CTB publica a veces mal el id de una parada (Urduliz, 29, sale como Gobela, 20): ese tren no se descarta, se corrige. */
$aFeedBadId = [['tripId' => 'e', 'stops' => [['2', T0 + 150], ['3', T0 + 250], ['1', T0 + 350]]]];
$MBadId = new TripUpdatesMatcher($aFeedBadId, new ServiceJourney($PdoRt), 600, 0);
$aAtFour = $MBadId->enrich('4', [$scheduledRow('up1', 'UP', T0 + 340)]);
$expect('un tren con el id de su última parada mal publicado sigue en directo en la parada corregida', $aAtFour[0]['status'] === 'live' && $aAtFour[0]['etaSeconds'] === T0 + 350, json_encode([$aAtFour[0]['status'], $aAtFour[0]['etaSeconds']]));
$aAtOne = $MBadId->enrich('1', [$scheduledRow('up9', 'UP', T0 + 345)]);
$expect('el id mal publicado no se usa como previsión de la parada que dice (otro tren a esa hora)', $aAtOne[0]['status'] === 'scheduled' && $aAtOne[0]['etaSeconds'] === T0 + 345, json_encode([$aAtOne[0]['status'], $aAtOne[0]['etaSeconds']]));
$aRowsBad = [['service_journey_id' => 'J_UP1', 'trip_number' => 'up1', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 100, 'status' => 'scheduled']];
$expect('liveTrip devuelve las paradas con el id corregido', $MBadId->liveTrip('2', $aRowsBad, 'J_UP1') === [['2', T0 + 150], ['3', T0 + 250], ['4', T0 + 350]], json_encode($MBadId->liveTrip('2', $aRowsBad, 'J_UP1')));
$aTwoBad = [['tripId' => 'f', 'stops' => [['2', T0 + 150], ['1', T0 + 250], ['1', T0 + 350]]]];
$expect('un tren con dos ids que no encajan no se asocia', (new TripUpdatesMatcher($aTwoBad, new ServiceJourney($PdoRt), 600, 0))->enrich('2', [$scheduledRow('up1', 'UP', T0 + 150)])[0]['status'] === 'scheduled');

/* Id mal publicado con una posición mayor que las siguientes: tren de bajada (4,3,2,1) con el «3» publicado como «1». */
$aFeedBadDown = [['tripId' => 'g', 'stops' => [['4', T0 + 100], ['1', T0 + 200], ['2', T0 + 300]]]];
$aDownFixed = (new TripUpdatesMatcher($aFeedBadDown, new ServiceJourney($PdoRt), 600, 0))->enrich('3', [$scheduledRow('down1', 'DOWN', T0 + 205)]);
$expect('un id mal publicado más adelante en el patrón que las paradas siguientes también se corrige (3 publicada como 1)', $aDownFixed[0]['status'] === 'live' && $aDownFixed[0]['etaSeconds'] === T0 + 200, json_encode([$aDownFixed[0]['status'], $aDownFixed[0]['etaSeconds']]));

/* Con dos correcciones posibles (a, e, c: el «e» puede ser un «b» mal publicado o el «c» una «f»), gana la que deja la parada pegada a sus dos vecinas. */
$aFeedAmbiguous = [['tripId' => 'i', 'stops' => [['a', T0 + 100], ['e', T0 + 200], ['c', T0 + 300]]]];
$aAmbiguous = (new TripUpdatesMatcher($aFeedAmbiguous, new ServiceJourney($PdoRt), 600, 0))->enrich('b', [$scheduledRow('long1', 'LONG', T0 + 205)]);
$expect('si hay varias correcciones posibles gana la que deja la parada pegada a sus dos vecinas (e era b)', $aAmbiguous[0]['status'] === 'live' && $aAmbiguous[0]['etaSeconds'] === T0 + 200, json_encode([$aAmbiguous[0]['status'], $aAmbiguous[0]['etaSeconds']]));

/* El recorrido en vivo de un tren: se empareja igual que en las salidas y devuelve sus paradas previstas. */
$aRowsJourney = [
    ['service_journey_id' => 'J_UP1', 'trip_number' => 'up1', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 100, 'status' => 'scheduled'],
    ['service_journey_id' => 'J_DOWN1', 'trip_number' => 'down1', 'journey_pattern_id' => 'DOWN', 'arrival_seconds' => T0 + 120, 'status' => 'scheduled'],
    ['service_journey_id' => 'J_UP2', 'trip_number' => 'up2', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 400, 'status' => 'scheduled'],
];
$aLiveUp = $MDeparted->liveTrip('2', $aRowsJourney, 'J_UP1');
$expect('liveTrip: el viaje de subida recibe las paradas previstas de su tren', $aLiveUp === [['2', T0 + 150], ['3', T0 + 250]], json_encode($aLiveUp));
$aLiveDown = $MDeparted->liveTrip('2', $aRowsJourney, 'J_DOWN1');
$expect('liveTrip: el de bajada recibe las del tren que va en su sentido', $aLiveDown === [['3', T0 + 200], ['2', T0 + 300]], json_encode($aLiveDown));
$expect('liveTrip: un viaje que el feed no trae (previsión a más de 10 min) devuelve null', $MDeparted->liveTrip('2', $aRowsJourney, 'J_UP2') === null);
$expect('liveTrip: sin feed devuelve null', (new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->liveTrip('2', $aRowsJourney, 'J_UP1') === null);

/* ------------------------------------------------------------------ */
$section('TripKey y Calendar');

$expect('TripKey ida y vuelta', TripKey::parse(TripKey::build('3414', '808', 28800)) === ['3414', '808', 28800]);
$expect('TripKey con guiones en la línea', TripKey::parse(TripKey::build('L-1', '12', 3600)) === ['L-1', '12', 3600]);
$expect('TripKey rechaza claves mal formadas', TripKey::parse('sin-numero') === null && TripKey::parse('') === null);
$expect('lunes = bit 1 y domingo = bit 64', Calendar::weekdayBitFor(new DateTime('2026-09-28')) === 1 && Calendar::weekdayBitFor(new DateTime(DATE_WEEKEND)) === 64);
$expect('secondsToHm pasa la medianoche (25:05 -> 01:05)', Calendar::secondsToHm(25 * 3600 + 5 * 60) === '01:05');

echo "\nRESULTADO unitarias: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
