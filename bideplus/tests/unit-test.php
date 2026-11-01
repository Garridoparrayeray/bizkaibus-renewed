<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

require __DIR__ . '/../api/autoload.php';

use Core\Config;
use Core\TripKey;
use Models\ServiceJourney;
use Services\Calendar;
use Services\ScheduleRange;
use Services\GtfsRealtimeClient;
use Engine\Bizkaibus\PaceFactors;
use Engine\Bizkaibus\PassageLog;
use Engine\Bizkaibus\RealtimeMatcher;
use Engine\Bizkaibus\SegmentTimes;
use Engine\Bizkaibus\SiriVehicleMonitoringClient;
use Engine\Bizkaibus\TripOffsets;
use Engine\Metro\TripUpdatesMatcher;

$aFailures = [];
$Expect = function (string $sCheck, bool $bOk, string $sDetail = '') use (&$aFailures): void {
    $sOutput = 'OK  ' . $sCheck;
    if (!$bOk) {
        $sOutput = 'MAL ' . $sCheck;
        if ($sDetail !== '') {
            $sOutput .= '  [' . $sDetail . ']';
        }
    }
    echo $sOutput . "\n";
    if (!$bOk) {
        $aFailures[] = $sCheck;
    }
};
$Section = function (string $sTitle): void {
    echo "\n== " . $sTitle . "\n";
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

// La hora del horario en la que se sitúa el bus, para el detalle de un fallo.
function positionText(array|null $aPos): string
{
    if ($aPos === null) {
        return 'null';
    }
    return hms($aPos['scheduledSeconds']);
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
    for ($iMeters = 0; $iMeters <= 4000; $iMeters += 250) {
        $aPoints[] = [LAT0 + $iMeters * M_LAT, LON0, (float)$iMeters];
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
    for ($iMeters = 0; $iMeters <= 1000; $iMeters += 250) {
        $aLoop[] = [LAT0 + $iMeters * M_LAT, LON0, (float)$iMeters];
    }
    for ($iMeters = 1250; $iMeters <= 2000; $iMeters += 250) {
        $aLoop[] = [LAT0 + (2000 - $iMeters) * M_LAT, LON0 + lonOffset($dReturnOffsetMeters), (float)$iMeters];
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
$Section('RealtimeMatcher: posición sobre el trazado');

$iGps = T0 + 240;
$aLive = live(1500, 0, 3, $iGps);
$M = matcher($aLive, $iGps + 30);
$aPos = $M->positionFor('J_LINE', $aLive);
$Expect('el GPS a mitad del tramo P2-P3 se sitúa con el trazado', $aPos !== null && $aPos['method'] === 'shape');
$Expect('T_pos se interpola por metros (08:04:30)', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270, positionText($aPos));
$Expect('el retraso es T_gps - T_pos (-30 s, adelantado)', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

[$iEta, $iDelay, $bPositioned] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('ETA = T_gps + (T_destino - T_pos) con k = 1 (08:11:30)', $iEta === T0 + 690 && $bPositioned, hms($iEta));
$Expect('etaForStop devuelve el mismo retraso', $iDelay === -30);
[$iEta] = $M->etaForStop('J_LINE', T0 + 60, $aLive);
$Expect('una parada ya pasada por el bus cae en el pasado (no sale como llegando ahora)', $iEta < $iGps - 120, hms($iEta));

$Pace = new PaceFactors(['global' => ['laborable.punta_manana' => 0.9]]);
$M = matcher($aLive, $iGps + 30, $Pace);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('k de la franja escala solo el horario restante (08:10:45)', $iEta === T0 + 645, hms($iEta));
$Expect('k no cambia el retraso mostrado', $M->currentDelaySeconds('J_LINE', $aLive) === -30);

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKEND);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('un factor de laborable no se aplica en fin de semana', $iEta === T0 + 690, hms($iEta));

/* Tramos aprendidos de las capturas (S1>S2, S2>S3); S3>S4 no tiene datos y usa el horario con k. */
$Segments = new SegmentTimes(['segments' => [
    'S1>S2' => ['n' => 5, 'all' => 60],
    'S2>S3' => ['n' => 5, 'all' => 100],
]]);
$M = matcher($aLive, $iGps + 30, null, 40.0, DATE_WEEKDAY, $Segments);
[$iEta, $iDelay] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('con tiempos por tramo: medio tramo de 60 s + 100 s + 180 s del horario (08:09:10)', $iEta === T0 + 550, hms($iEta));
$Expect('el retraso mostrado es la llegada estimada menos la hora publicada de esa parada (08:09:10 contra 08:12:00)', $iDelay === -170, (string)$iDelay);
// Cabecera y lista dicen lo mismo: el retraso que se enseña es el de la próxima parada, no el de la posición.
$Expect('sin tiempos aprendidos el retraso enseñado coincide con el de la posición (−30 s)', matcher($aLive, $iGps + 30)->displayDelaySeconds('J_LINE', $aLive) === -30);
[, $iDelayNext] = $M->etaForStop('J_LINE', T0 + 360, $aLive, 3);
$Expect('con tiempos aprendidos el retraso de la posición (−30 s) y el de la próxima parada (−90 s) son distintos', $M->currentDelaySeconds('J_LINE', $aLive) === -30 && $iDelayNext === -90, (string)$iDelayNext);
$Expect('el retraso enseñado es el de la próxima parada (−90 s), el mismo de la lista, no el de la posición', $M->displayDelaySeconds('J_LINE', $aLive) === -90, (string)$M->displayDelaySeconds('J_LINE', $aLive));
[$iEta] = $M->etaForStop('J_LINE', T0 + 360, $aLive);
$Expect('con tiempos por tramo solo se suman los tramos hasta la parada pedida (08:04:30)', $iEta === T0 + 270, hms($iEta));

$M = matcher($aLive, $iGps + 30, $Pace, 40.0, DATE_WEEKDAY, $Segments);
[$iEta] = $M->etaForStop('J_LINE', T0 + 720, $aLive);
$Expect('el factor k solo se aplica a los tramos sin datos (08:08:52)', $iEta === T0 + 532, hms($iEta));

$SegmentsBand = new SegmentTimes(['segments' => ['S1>S2' => ['n' => 9, 'all' => 60, 'laborable.punta_manana' => 120]]]);
$Expect('el tiempo de la franja manda sobre el general', $SegmentsBand->seconds('S1', 'S2', 'laborable.punta_manana') === 120.0 && $SegmentsBand->seconds('S1', 'S2', 'laborable.noche') === 60.0);
$Expect('un tramo sin datos no tiene tiempo aprendido', $SegmentsBand->seconds('S2', 'S1', 'laborable.noche') === null);

$aNear = live(1500, 30, 3, $iGps);
$Expect('a 30 m del trazado sigue en ruta', matcher($aNear, $iGps)->positionFor('J_LINE', $aNear) !== null);
$aFar = live(1500, 80, 3, $iGps);
$MFar = matcher($aFar, $iGps);
$Expect('a 80 m del trazado (> 50 m) queda fuera de ruta', $MFar->positionFor('J_LINE', $aFar) === null);
[$iEta, , $bPositioned] = $MFar->etaForStop('J_LINE', T0 + 720, $aFar);
$Expect('fuera de ruta la ETA es el horario + retraso del feed', $iEta === T0 + 720 && !$bPositioned, hms($iEta));
[$iEtaNext] = $MFar->etaForStop('J_LINE', T0 + 360, $aFar, 3);
$Expect('fuera de ruta el retraso mostrado sale de la parada registrada, igual que la ETA de la lista', $MFar->currentDelaySeconds('J_LINE', $aFar) === $iEtaNext - (T0 + 360), (string)$MFar->currentDelaySeconds('J_LINE', $aFar));

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: cuándo no se fía del GPS');

$aStale = live(1500, 0, 3, $iGps);
$Expect('un dato GPS de hace 9 min todavía vale', matcher($aStale, $iGps + 9 * 60)->positionFor('J_LINE', $aStale) !== null);
$Expect('un dato GPS de hace 11 min (> 10) se descarta', matcher($aStale, $iGps + 11 * 60)->positionFor('J_LINE', $aStale) === null);

$aEarly = live(0, 0, 1, T0 - 120);
$Expect('un bus asignado a un viaje que aún no ha salido (> 60 s antes) no se sitúa', matcher($aEarly, T0 - 100)->positionFor('J_LINE', $aEarly) === null);
$aJustBefore = live(0, 0, 1, T0 - 30);
$Expect('30 s antes de la salida sí se sitúa (margen de 60 s)', matcher($aJustBefore, T0)->positionFor('J_LINE', $aJustBefore) !== null);

$aBadOrder = live(1500, 0, 42, $iGps);
$Expect('una próxima parada que no existe en el viaje descarta el GPS', matcher($aBadOrder, $iGps)->positionFor('J_LINE', $aBadOrder) === null);

$aWindow = live(500, 0, 5, $iGps);
$Expect('fuera de la ventana (3 tramos atrás) no se proyecta aunque esté sobre la ruta', matcher($aWindow, $iGps)->positionFor('J_LINE', $aWindow) === null);

/* Bizkaibus: sin GPS utilizable (no lo manda o está fuera de la ruta) se muestra el horario oficial, sin estimar nada. */
$aNoGps = live(1500, 0, 3, $iGps);
$aNoGps['lat'] = null;
$aNoGps['lon'] = null;
$aNoGps['locationSeconds'] = null;
$aNoGps['delaySeconds'] = 60;
$MNoGps = matcher($aNoGps, T0 + 300);
$Expect('Bizkaibus sin GPS: no cuenta como en vivo', !$MNoGps->isLive('J_LINE', $aNoGps));
[$iEta, $iDelay, $bPositioned] = $MNoGps->etaForStop('J_LINE', T0 + 720, $aNoGps);
$Expect('Bizkaibus sin GPS: la ETA es la hora oficial y no hay retraso', $iEta === T0 + 720 && $iDelay === 0 && !$bPositioned, hms($iEta));
$Expect('Bizkaibus sin GPS: el retraso mostrado es 0 aunque el feed diga otra cosa', $MNoGps->currentDelaySeconds('J_LINE', $aNoGps) === 0);
$aRowOfficial = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720];
$aOfficial = $MNoGps->enrich([$aRowOfficial])[0];
$Expect('enrich: sin GPS la salida sale programada con su hora oficial', $aOfficial['status'] === 'scheduled' && $aOfficial['etaSeconds'] === T0 + 720 && $aOfficial['delaySeconds'] === 0, json_encode([$aOfficial['status'], $aOfficial['etaSeconds']]));
$aOffRouteBus = live(1500, 200, 3, $iGps);
$aOffRouteNoClock = $aOffRouteBus;
$aOffRouteNoClock['locationSeconds'] = null;
$Expect('Bizkaibus con GPS fuera de la ruta y sin hora del dato: horario oficial', !matcher($aOffRouteNoClock, $iGps + 30)->isLive('J_LINE', $aOffRouteNoClock) && matcher($aOffRouteNoClock, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'scheduled');
$Expect('Bizkaibus con GPS en ruta: en directo', matcher($aLive, $iGps + 30)->enrich([$aRowOfficial])[0]['status'] === 'live');

/* Si el cálculo con GPS falla por lo que sea (base de datos rota, dato imposible), la salida cae al horario oficial en vez de romper la parada. */
$PdoBroken = new PDO('sqlite::memory:');
$PdoBroken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoBroken->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$MBroken = new RealtimeMatcher(['3414|808' => [$aLive]], new ServiceJourney($PdoBroken), $iGps + 30, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aBrokenOut = $MBroken->enrich([$aRowOfficial])[0];
$Expect('si el cálculo con GPS falla, la salida sale con el horario oficial', $aBrokenOut['status'] === 'scheduled' && $aBrokenOut['etaSeconds'] === T0 + 720 && $aBrokenOut['delaySeconds'] === 0, json_encode([$aBrokenOut['status'], $aBrokenOut['etaSeconds']]));
$Expect('si el cálculo con GPS falla, ni la posición ni el retraso rompen nada', $MBroken->positionFor('J_LINE', $aLive) === null && $MBroken->currentDelaySeconds('J_LINE', $aLive) === 0 && $MBroken->etaForStop('J_LINE', T0 + 720, $aLive) === [T0 + 720, 0, false]);

/* Otras redes con SIRI (Euskotren, tranvías) no cambian: sin GPS siguen en directo con el retraso del feed. */
Config::set('euskotren');
$MOtherNetwork = matcher($aNoGps, T0 + 300);
$Expect('en otra red, sin GPS, sigue en directo', $MOtherNetwork->isLive('J_LINE', $aNoGps));
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aNoGps);
$Expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

$Expect('sin datos en vivo la ETA es el horario', matcher($aLive, $iGps)->etaForStop('J_LINE', T0 + 720, null) === [T0 + 720, 0, false]);

/* ------------------------------------------------------------------ */
$Section('Bizkaibus: fuente de los buses en directo');
$aBusConfig = \Core\Networks::get('bus');
$Expect('los buses en directo salen solo del feed GPS SIRI de Open Data Bizkaia (posicion y hora del dato), sin el feed de trip-updates', str_starts_with($aBusConfig['siri']['vehicle_monitoring_url'], 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions') && !isset($aBusConfig['siri']['vehicle_monitoring_fallback_url']));

$Section('Lurraldebus: lectura del feed en directo');
$sLurSiri = '<?xml version="1.0"?><Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery>'
    . '<VehicleActivity><RecordedAtTime>' . DATE_WEEKDAY . 'T08:30:10+02:00</RecordedAtTime><MonitoredVehicleJourney><LineRef>7</LineRef>'
    . '<FramedVehicleJourneyRef><DataFrameRef>' . DATE_WEEKDAY . '</DataFrameRef><DatedVehicleJourneyRef>7_702_LJ_29700</DatedVehicleJourneyRef></FramedVehicleJourneyRef>'
    . '<OriginAimedDepartureTime>' . DATE_WEEKDAY . 'T08:15:00+02:00</OriginAimedDepartureTime>'
    . '<VehicleLocation><Longitude>-1.98</Longitude><Latitude>43.31</Latitude></VehicleLocation><VehicleRef>412</VehicleRef>'
    . '<MonitoredCall><StopPointRef>130</StopPointRef><Order>5</Order></MonitoredCall></MonitoredVehicleJourney></VehicleActivity>'
    . '</VehicleMonitoringDelivery></ServiceDelivery></Siri>';
$aLur = \Engine\Lurraldebus\Live::parse($sLurSiri, 'pesa', DATE_WEEKDAY);
$aLurEntry = $aLur['pesa:7|pesa:7_702_LJ_29700'][0] ?? null;
$Expect('el viaje lleva el prefijo del operador, como en la base de datos', $aLurEntry !== null && $aLurEntry['tripRef'] === 'pesa:7_702_LJ_29700' && $aLurEntry['lineId'] === 'pesa:7');
$Expect('posición, parada, orden y hora del dato', $aLurEntry !== null && $aLurEntry['lat'] === 43.31 && $aLurEntry['currentStopId'] === '130' && $aLurEntry['order'] === 5 && $aLurEntry['locationSeconds'] === 8 * 3600 + 30 * 60 + 10);
$Expect('salida de cabecera desde OriginAimedDepartureTime', $aLurEntry !== null && $aLurEntry['departureSeconds'] === 8 * 3600 + 15 * 60);
$Expect('un feed vacío no trae buses', \Engine\Lurraldebus\Live::parse('<Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery/></ServiceDelivery></Siri>', 'pesa', DATE_WEEKDAY) === []);
$Expect('motores separados: Lurraldebus no lee los datos aprendidos de Bizkaibus', (new \ReflectionClassConstant(\Engine\Lurraldebus\SegmentTimes::class, 'FILE_PATH'))->getValue() !== (new \ReflectionClassConstant(\Engine\Bizkaibus\SegmentTimes::class, 'FILE_PATH'))->getValue());

$Section('Registro de redes (api/Networks)');
$aFeatureKeys = ['platforms', 'headsign_from_last_stop', 'stop_hints', 'line_map', 'schedule_text', 'trip_detail', 'alerts', 'alerts_detail'];
foreach (\Core\Networks::all() as $sSlug => $aNet) {
    $aProblems = [];
    foreach ($aFeatureKeys as $sKey) {
        if (!array_key_exists($sKey, $aNet['features'])) {
            $aProblems[] = 'falta features.' . $sKey;
        }
    }
    foreach ($aNet['live'] as $sSource) {
        if ($sSource === 'vehicle-monitoring' && empty($aNet['siri']['vehicle_monitoring_url']) && empty($aNet['siri']['vehicle_monitoring_urls'])) {
            $aProblems[] = 'vehicle-monitoring sin siri.vehicle_monitoring_url(s)';
        } elseif ($sSource === 'vehicle-monitoring' && !is_file(__DIR__ . '/../engine/' . ($aNet['engine'] ?? '-') . '/Live.php')) {
            $aProblems[] = 'vehicle-monitoring sin motor (engine/<engine>/Live.php)';
        } elseif ($sSource === 'trip-updates' && empty($aNet['gtfs_rt']['trip_updates_url'])) {
            $aProblems[] = 'trip-updates sin gtfs_rt.trip_updates_url';
        } elseif (!in_array($sSource, ['vehicle-monitoring', 'trip-updates'], true)) {
            $aProblems[] = 'fuente en directo desconocida ' . $sSource;
        }
    }
    if (!in_array($aNet['features']['alerts'], ['lines', 'stops', 'network'], true)) {
        $aProblems[] = 'features.alerts desconocido';
    }
    if (!in_array($aNet['features']['trip_detail'], ['vehicle', 'stops'], true)) {
        $aProblems[] = 'features.trip_detail desconocido';
    }
    if (!in_array($aNet['kind'], ['bus', 'metro', 'train', 'tram'], true)) {
        $aProblems[] = 'kind desconocido';
    }
    if (!in_array($aNet['gtfs']['stops'] ?? null, ['bus', 'metro', 'euskotren', 'renfe', 'generic'], true)) {
        $aProblems[] = 'gtfs.stops desconocido';
    }
    $Expect("red $sSlug: configuración completa", $aProblems === [], implode('; ', $aProblems));
}
$Expect('solo Bizkaibus y Metro tienen datos en directo; el resto, horario oficial', array_keys(array_filter(\Core\Networks::all(), fn($aNet) => !empty($aNet['live']))) === ['bus', 'metro']);
$Expect('?red= desconocida cae en la red por defecto', \Core\Networks::fromQuery('no-existe') === 'bus' && \Core\Networks::fromQuery(null) === 'bus' && \Core\Networks::fromQuery('renfe') === 'renfe');

$Section('RealtimeMatcher: capa 2, parada registrada sin GPS utilizable');

/* J_LINE: paradas seq 1..5 con llegadas T0, +180, +360, +540, +720. El feed da la parada 3 como próxima. */
$aRegistered = live(1500, 0, 3, $iGps);
$aRegistered['lat'] = null;
$aRegistered['lon'] = null;
$aRegistered['locationSeconds'] = null;
$aRegistered['recordedSeconds'] = T0 + 250;
$MReg = matcher($aRegistered, T0 + 300);
$Expect('sin GPS pero con parada y hora del dato, el bus cuenta como en vivo', $MReg->isLive('J_LINE', $aRegistered));
[$iEta, $iDelay, $bPositioned] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('la ETA parte de la hora del dato, a mitad del tramo hasta la próxima, más lo que queda (08:11:40)', $iEta === T0 + 700 && !$bPositioned, hms($iEta));
$Expect('el retraso mostrado es la ETA menos el horario de la parada', $iDelay === -20);
$Expect('el retraso del recorrido sale de la parada registrada y no del campo Delay del feed', $MReg->currentDelaySeconds('J_LINE', array_merge($aRegistered, ['delaySeconds' => 720])) === -20);
[$iEta] = $MReg->etaForStop('J_LINE', T0 + 360, $aRegistered, 3);
$Expect('para la propia próxima parada solo cuenta la mitad del tramo (08:05:40)', $iEta === T0 + 340, hms($iEta));
$MRegFast = matcher($aRegistered, T0 + 300, new PaceFactors(['global' => ['laborable.punta_manana' => 0.5]]));
[$iEtaFar, $iDelayFar] = $MRegFast->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('con parada registrada, el retraso mostrado de una parada es su llegada estimada menos su hora publicada', $iDelayFar === $iEtaFar - (T0 + 720), json_encode([$iEtaFar - (T0 + 720), $iDelayFar]));
$aRowFarEarly = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720 + 3 * 3600, 'stop_seq' => 5];
$aFarOut = $MRegFast->enrich([$aRowFarEarly])[0];
$Expect('si la llegada estimada difiere más de 45 min de la hora publicada, sale estimada con su llegada estimada y sin afirmar retraso', $aFarOut['status'] === 'estimated' && $aFarOut['delaySeconds'] === 0 && $aFarOut['etaSeconds'] < T0 + 720 + 3 * 3600, json_encode([$aFarOut['status'], $aFarOut['delaySeconds']]));
[$iEta, , ] = $MReg->etaForStop('J_LINE', T0 + 720, $aRegistered);
$Expect('sin número de parada no se estima: horario oficial', $iEta === T0 + 720, hms($iEta));

$aRowPassed = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowTarget = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$aEnriched = $MReg->enrich([$aRowPassed, $aRowTarget]);
$Expect('enrich: la fila de una parada ya pasada sale como salida, con la hora estimada en que el bus la pasó (la de la parada registrada)', $aEnriched[0]['status'] === 'departed' && $aEnriched[0]['etaSeconds'] === T0 + 250, json_encode($aEnriched[0]['etaSeconds']));
$Expect('enrich: sin GPS, la fila de una parada posterior sale estimada (no en directo) con la ETA de la parada registrada y sin adelanto ni retraso', $aEnriched[1]['status'] === 'estimated' && $aEnriched[1]['etaSeconds'] === T0 + 700 && $aEnriched[1]['delaySeconds'] === 0, json_encode([$aEnriched[1]['status'], $aEnriched[1]['etaSeconds']]));

$aWaiting = $aRegistered;
$aWaiting['recordedSeconds'] = T0 - 120;
$Expect('un bus que espera en cabecera (viaje sin empezar) no cuenta como en vivo', !matcher($aWaiting, T0 - 100)->isLive('J_LINE', $aWaiting));
$Expect('un dato de hace más de 10 min no sirve: horario oficial', !matcher($aRegistered, T0 + 250 + 11 * 60)->isLive('J_LINE', $aRegistered));
$aNoClock = $aRegistered;
$aNoClock['recordedSeconds'] = null;
$Expect('sin hora del dato no se estima nada: horario oficial', !matcher($aNoClock, T0 + 300)->isLive('J_LINE', $aNoClock));

$aOffRoute = live(1500, 200, 3, $iGps);
$aOffRoute['recordedSeconds'] = $iGps;
$MOff = matcher($aOffRoute, $iGps + 30);
$Expect('con GPS fuera de la ruta se usa la parada registrada', $MOff->isLive('J_LINE', $aOffRoute) && $MOff->enrich([$aRowPassed])[0]['status'] === 'departed');
[$iEta] = $MOff->etaForStop('J_LINE', T0 + 720, $aOffRoute, 5);
$Expect('con GPS fuera de la ruta la ETA sale de la parada registrada (08:12:00)', $iEta === $iGps + 90 + 360, hms($iEta));
$aOnRoute = live(1500, 0, 3, $iGps);
$Expect('con GPS en ruta manda el GPS y no se descarta nada por el orden del feed', matcher($aOnRoute, $iGps + 30)->enrich([$aRowTarget])[0]['status'] === 'live');

/* Con GPS: en cuanto el bus pasa una parada (el feed ya marca la siguiente), esa salida se retira al momento. */
$aGpsPassed = live(1500, 0, 3, $iGps);
$MGpsPassed = matcher($aGpsPassed, $iGps + 30);
$aRowStop2 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 180, 'stop_seq' => 2];
$aRowStop3 = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 360, 'stop_seq' => 3];
$aOutPassed = $MGpsPassed->enrich([$aRowStop2, $aRowStop3]);
$Expect('con GPS, la parada que el bus ya pasó sale como salida, con la hora en que el bus estuvo en ella (08:02:30)', $aOutPassed[0]['status'] === 'departed' && $aOutPassed[0]['etaSeconds'] === T0 + 150, json_encode([$aOutPassed[0]['status'], $aOutPassed[0]['etaSeconds']]));
$Expect('con GPS, la próxima parada sigue en directo', $aOutPassed[1]['status'] === 'live', $aOutPassed[1]['status']);

/* Tramo largo (cabecera, espera): la parada registrada no dice dónde va el bus, así que no se estima. */
$aLongSegment = $aRegistered;
$aLongSegment['order'] = 3;
$MLong = matcher($aLongSegment, T0 + 300);
$Expect('tramos de 3 min sí se estiman', $MLong->isLive('J_LINE', $aLongSegment));
$aBigGap = array_merge($aRegistered, ['order' => 5, 'recordedSeconds' => T0 + 600]);
$Expect('con tramo hasta la próxima parada de 3 min (orden 5) se estima', matcher($aBigGap, T0 + 620)->isLive('J_LINE', $aBigGap));

/* Capa 3: sin ningún dato del bus (ni GPS ni registro de pasos), la hora se estima con los tiempos aprendidos (salida de cabecera + lo que tardan
 * los buses) y sale marcada como estimada («estimated»): la app enseña al lado la hora oficial. */
$SegLearned = new SegmentTimes(['segments' => [
    'S0>S1' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S1>S2' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S2>S3' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
    'S3>S4' => ['n' => 5, 'all' => 100, 'laborable.punta_manana' => 100],
]]);
$aRowNoBus = ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 720, 'stop_seq' => 5];
$MNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$aNoBus = $MNoBus->enrich([$aRowNoBus])[0];
$Expect('sin datos del bus, la llegada es la salida de cabecera + tiempos aprendidos + 1 min (08:07:40), marcada como estimada', $aNoBus['status'] === 'estimated' && $aNoBus['etaSeconds'] === T0 + 400 + 60 && $aNoBus['delaySeconds'] === 0, json_encode([$aNoBus['status'], hms($aNoBus['etaSeconds'])]));
$MNoBusLate = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 500, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$aNoBusLate = $MNoBusLate->enrich([$aRowNoBus])[0];
$Expect('si lo estimado ya queda en el pasado, se deja el horario publicado (no se esconde ni se deja en 0 min)', $aNoBusLate['status'] === 'scheduled' && $aNoBusLate['etaSeconds'] === T0 + 720);
/* Horario publicado mucho más lento que lo que tardan los buses (A3514 por San Juan, Amorebieta: 59 min en horario, 28 de verdad).
 * J_SLOW hace el mismo recorrido con 15 min por tramo: con los tiempos aprendidos el bus ya pasó. No se esconde (es una estimación): sale como
 * «probablemente ya pasó» con su hora oficial, y la app lo pone al final y en gris. */
$PdoSlow = buildFixture(40);
$PdoSlow->exec("INSERT INTO service_journeys VALUES ('J_SLOW', '3414', 'P1', '808', 'C1', " . T0 . ", 'SH_LINE')");
for ($i = 0; $i < 5; $i++) {
    $PdoSlow->exec("INSERT INTO passing_times VALUES ('J_SLOW', " . ($i + 1) . ", 'S$i', " . (T0 + $i * 900) . ', ' . (T0 + $i * 900) . ', ' . ($i * 1000.0) . ')');
}
$aRowSlow = array_merge($aRowNoBus, ['service_journey_id' => 'J_SLOW', 'arrival_seconds' => T0 + 3600]);
$aSlowPassed = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 3000, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('horario mucho más lento que lo aprendido y el bus ya debió pasar: sale como «probablemente ya pasó», con la hora oficial', $aSlowPassed['status'] === 'likely_departed' && $aSlowPassed['etaSeconds'] === T0 + 3600, json_encode([$aSlowPassed['status'], hms($aSlowPassed['etaSeconds'])]));
$aSlowSoon = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 300, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('mismo viaje antes de la hora estimada: sale estimado, con la hora estimada', $aSlowSoon['status'] === 'estimated' && $aSlowSoon['etaSeconds'] === T0 + 460);
$aSlowJustPassed = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 1000, new PaceFactors(), DATE_WEEKDAY, $SegLearned))->enrich([$aRowSlow])[0];
$Expect('si la hora estimada pasó hace menos de 10 min (el bus puede ir con retraso), se deja el horario publicado', $aSlowJustPassed['status'] === 'scheduled' && $aSlowJustPassed['etaSeconds'] === T0 + 3600);
$aSlowUnlearned = (new RealtimeMatcher([], new ServiceJourney($PdoSlow), T0 + 3000, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => ['S0>S1' => ['n' => 5, 'all' => 100]], 'lengthRatios' => ['3' => ['all' => 0.1]]])))->enrich([$aRowSlow])[0];
$Expect('si casi ningún tramo tiene tiempo aprendido, no se esconde el viaje', $aSlowUnlearned['status'] === 'scheduled' && $aSlowUnlearned['etaSeconds'] === T0 + 3600, json_encode([$aSlowUnlearned['status'], hms($aSlowUnlearned['etaSeconds'])]));
$MNoLearned = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$Expect('sin tiempos aprendidos, se deja el horario publicado', $MNoLearned->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
/* Tramos sin tiempo aprendido: la proporción tiempo real / horario depende del largo del tramo (en la fixture, 1000 m por tramo y 180 s de horario). */
$SegOne = ['S0>S1' => ['n' => 5, 'all' => 100]];
$MNoRatio = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne]));
$Expect('sin proporciones por largo, un tramo sin aprender usa k x horario (08:11:40)', $MNoRatio->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180, (string)$MNoRatio->enrich([$aRowNoBus])[0]['etaSeconds']);
$MRatio = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['3' => ['all' => 0.5]]]));
$Expect('con proporciones por largo, el tramo sin aprender de 1000 m usa la de su cubo (0,5 x 180 s = 90 s)', $MRatio->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 90, (string)$MRatio->enrich([$aRowNoBus])[0]['etaSeconds']);
$MRatioOther = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['0' => ['all' => 0.5]]]));
$Expect('si el cubo de su largo no tiene proporción, se vuelve a k x horario', $MRatioOther->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180);
$MRatioNoShape = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes(['segments' => $SegOne, 'lengthRatios' => ['3' => ['all' => 0.5]]]));
$aRowNoShape = array_merge($aRowNoBus, ['service_journey_id' => 'J_NOSHAPE', 'trip_number' => '809']);
$Expect('sin largo del tramo (viaje sin dist_m) se usa k x horario', $MRatioNoShape->enrich([$aRowNoShape])[0]['etaSeconds'] === T0 + 60 + 100 + 3 * 180);
$Ratios = new SegmentTimes(['lengthRatios' => ['0' => ['all' => 1.1], '5' => ['all' => 0.5, 'laborable.noche' => 0.4]]]);
$Expect('cubos de largo: 299 m -> 0, 300 m -> 1, 2999 m -> 4, 3000 m -> 5', SegmentTimes::lengthBucket(299) === 0 && SegmentTimes::lengthBucket(300) === 1 && SegmentTimes::lengthBucket(2999) === 4 && SegmentTimes::lengthBucket(3000) === 5);
$Expect('la proporción de la franja manda sobre la del cubo', abs($Ratios->fallbackSeconds(100.0, 4000.0, 'laborable.noche') - 40.0) < 1e-9 && abs($Ratios->fallbackSeconds(100.0, 4000.0, 'laborable.manana') - 50.0) < 1e-9);
$Expect('sin largo, sin proporciones o con largo no positivo no hay estimación', $Ratios->fallbackSeconds(100.0, null, 'x') === null && (new SegmentTimes())->fallbackSeconds(100.0, 1000.0, 'x') === null && $Ratios->fallbackSeconds(100.0, 0.0, 'x') === null);
Config::set('euskotren');
$MOtherNoBus = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned);
$Expect('en otras redes se deja el horario publicado', $MOtherNoBus->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 720);
Config::set('bus');

/* GPS y parada registrada que no cuadran (más de 6 min): no se fía de ninguna, horario oficial. */
$aDisagree = live(1500, 0, 5, $iGps);
$MDisagree = matcher($aDisagree, $iGps + 30, new PaceFactors(['global' => ['laborable.punta_manana' => 0.0]]));
$aDisagreeOut = $MDisagree->enrich([$aRowTarget]);
$Expect('GPS y parada registrada discrepan más de 6 min: la salida sale con el horario oficial', $aDisagreeOut[0]['status'] === 'scheduled', $aDisagreeOut[0]['status']);
$aAgreeOut = matcher($aGpsPassed, $iGps + 30)->enrich([$aRowTarget]);
$Expect('GPS y parada registrada que cuadran: sigue en vivo', $aAgreeOut[0]['status'] === 'live', $aAgreeOut[0]['status']);

/* Un viaje del feed que no es el que hace el bus (50 min de diferencia con el horario): la llegada estimada sigue valiendo, el retraso no se afirma. */
$iFarGps = T0 + 3300;
$aFar = live(1500, 0, 3, $iFarGps);
$MFarDelay = matcher($aFar, $iFarGps + 30);
$aFarGpsOut = $MFarDelay->enrich([$aRowTarget])[0];
$Expect('con GPS, 50 min de diferencia con el horario: sigue en vivo y el retraso queda sin afirmar', $aFarGpsOut['status'] === 'live' && $aFarGpsOut['delaySeconds'] === null, json_encode([$aFarGpsOut['status'], $aFarGpsOut['delaySeconds']]));
$Expect('el retraso para mostrar es null si pasa de 45 min y un entero si no', $MFarDelay->displayDelayMinutes(50 * 60) === null && $MFarDelay->displayDelayMinutes(40 * 60) === 40 && $MFarDelay->displayDelayMinutes(null) === null);
$aNear = live(1500, 0, 3, T0 + 240 + 40 * 60);
$Expect('40 min de retraso sí se muestran', matcher($aNear, T0 + 240 + 40 * 60 + 30)->isLive('J_LINE', $aNear));

/* Otras redes con SIRI no usan la parada registrada: siguen con el cálculo de siempre. */
Config::set('euskotren');
$MOtherNetwork = matcher($aRegistered, T0 + 300);
$Expect('en otra red no se da ninguna fila por pasada con la parada registrada', $MOtherNetwork->enrich([$aRowPassed])[0]['status'] !== 'departed');
[$iEta] = $MOtherNetwork->etaForStop('J_LINE', T0 + 720, $aRegistered, 5);
$Expect('en otra red, sin GPS, la ETA es la de siempre (ahora + horario desde la próxima parada)', $iEta === T0 + 300 + 360, hms($iEta));
Config::set('bus');

/* Corrección por viaje: lo que suele apartarse cada viaje de la hora estimada sin datos del bus, por tercio del recorrido.
 * J_LINE (línea 3414, S0 a S4, 5 paradas, sale a las 08:00) llega a S4 a las 08:07:40 según los tramos aprendidos. */
if (class_exists(TripOffsets::class)) {
    $Section('TripOffsets: corrección por viaje');
    $sLineKey = TripOffsets::key('3414', 'S0', 'S4', T0, 5);
    $Expect('el viaje se reconoce por línea, primera y última parada, salida y número de paradas', $sLineKey === '3414|S0|S4|28800|5', $sLineKey);
    $Expect('tercios de un recorrido de 5 paradas: 0 y 1 en el primero, 2 y 3 en el segundo, 4 en el tercero', [TripOffsets::part(0, 5), TripOffsets::part(1, 5), TripOffsets::part(2, 5), TripOffsets::part(3, 5), TripOffsets::part(4, 5), TripOffsets::part(9, 5)] === [0, 0, 1, 1, 2, 2]);
    $Trips = new TripOffsets([
        'trips' => [$sLineKey => ['laborable' => [10, 20, 30]]],
        'lines' => ['3414' => ['laborable.punta_manana' => 50]],
        'global' => ['laborable.punta_manana' => 70, 'fin_semana.punta_manana' => 90],
    ]);
    $Expect('con datos del viaje se usa el de su tercio', $Trips->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 4, 5) === 30.0 && $Trips->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 2, 5) === 20.0);
    $Expect('sin datos del viaje, el de su línea y franja', $Trips->seconds('3414|S0|S9|28800|9', '3414', DATE_WEEKDAY, T0, 4, 9) === 50.0);
    $Expect('sin datos de la línea, el de la franja', $Trips->seconds('1|A|B|28800|3', '1', DATE_WEEKDAY, T0, 1, 3) === 70.0);
    $Expect('el viaje solo tiene datos de laborable: en fin de semana va a la franja de fin de semana', $Trips->seconds($sLineKey, '3414', DATE_WEEKEND, T0, 4, 5) === 90.0);
    $Expect('sin tabla, no se corrige nada', (new TripOffsets())->seconds($sLineKey, '3414', DATE_WEEKDAY, T0, 4, 5) === 0.0 && (new TripOffsets())->isEmpty());

    $MTrip = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned, $Trips);
    $aTrip = $MTrip->enrich([$aRowNoBus])[0];
    $Expect('sin datos del bus, a la hora estimada se suma la corrección del viaje en S4 (tercer tercio: 30 s, 08:08:10)', $aTrip['status'] === 'estimated' && $aTrip['etaSeconds'] === T0 + 460 + 30, hms($aTrip['etaSeconds']));
    $Expect('la hora estimada es lo aprendido más la corrección', $MTrip->learnedArrival('J_LINE', T0 + 720, '3414') === T0 + 460 && $MTrip->tripOffsetSeconds('J_LINE', 4, '3414') === 30);
    $aRowNoSeq = $aRowNoBus;
    unset($aRowNoSeq['stop_seq']);
    $aRowNoSeq['stop_id'] = 'S4';
    $Expect('sin el orden de la parada, se encuentra por parada y hora', $MTrip->enrich([$aRowNoSeq])[0]['etaSeconds'] === T0 + 460 + 30);
    $Expect('con tramos pasados a mano y sin tabla de viajes, no se lee la del fichero', $MNoBus->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 460);

    /* Festivo entre semana: Bizkaibus lo publica haciendo circular por excepción los calendarios de solo domingo. Ese día
     * la capa 3 calcula como en fin de semana: con sus tramos y su corrección (40 s en S4 en vez de 30). */
    $PdoHoliday = buildFixture(40);
    $PdoHoliday->exec("
        CREATE TABLE service_calendars (id TEXT PRIMARY KEY, from_date TEXT NOT NULL, to_date TEXT NOT NULL, weekday_mask INTEGER NOT NULL);
        CREATE TABLE service_calendar_exceptions (calendar_id TEXT NOT NULL, date TEXT NOT NULL, available INTEGER NOT NULL);
        INSERT INTO service_calendars VALUES ('C_DOM', '2026-01-01', '2026-12-31', 64), ('C_LAB', '2026-01-01', '2026-12-31', 31);
        INSERT INTO service_calendar_exceptions VALUES ('C_DOM', '" . DATE_WEEKDAY . "', 1), ('C_LAB', '" . DATE_WEEKDAY . "', 0), ('C_LAB', '2026-10-03', 1);
    ");
    $HolidayJourneys = new ServiceJourney($PdoHoliday);
    $Expect('festivo: el día que circulan por excepción los calendarios de solo domingo', $HolidayJourneys->isHoliday(DATE_WEEKDAY) && !$HolidayJourneys->isHoliday('2026-10-02') && !$HolidayJourneys->isHoliday('2026-10-03'));
    $Expect('festivo: sin calendarios en la base, ningún día lo es', !(new ServiceJourney(buildFixture(40)))->isHoliday(DATE_WEEKDAY));
    $Expect('festivo: la capa 3 calcula con el domingo de esa semana', TripOffsets::serviceDate(DATE_WEEKDAY, true) === DATE_WEEKEND && TripOffsets::serviceDate(DATE_WEEKDAY, false) === DATE_WEEKDAY);
    $TripsBoth = new TripOffsets(['trips' => [$sLineKey => ['laborable' => [10, 20, 30], 'fin_semana' => [10, 20, 40]]]]);
    $MHoliday = new RealtimeMatcher([], $HolidayJourneys, T0 - 600, new PaceFactors(), DATE_WEEKDAY, $SegLearned, $TripsBoth);
    $aHoliday = $MHoliday->enrich([$aRowNoBus])[0];
    $Expect('festivo entre semana: la hora estimada lleva la corrección de fin de semana (08:08:20)', $MHoliday->serviceDate() === DATE_WEEKEND && $aHoliday['etaSeconds'] === T0 + 460 + 40, hms($aHoliday['etaSeconds']));
    $MWorkday = new RealtimeMatcher([], $HolidayJourneys, T0 - 600, new PaceFactors(), '2026-10-02', $SegLearned, $TripsBoth);
    $Expect('el día siguiente, laborable, la de laborable (08:08:10)', $MWorkday->enrich([$aRowNoBus])[0]['etaSeconds'] === T0 + 460 + 30);
}

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: sin trazado (tramos entre paradas)');

$aSeg = live(1500, 100, 3, $iGps, 'trp_A3414_809_X_28800_x');
$MSeg = new RealtimeMatcher(['3414|809' => [$aSeg]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aPos = $MSeg->positionFor('J_NOSHAPE', $aSeg);
$Expect('sin shape se proyecta sobre la recta entre paradas', $aPos !== null && $aPos['method'] === 'segment');
$Expect('sin shape el pasillo es max(150 m, 15 %): 100 m vale', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 270);
$aSegFar = live(1500, 200, 3, $iGps, 'trp_A3414_809_X_28800_x');
$Expect('sin shape a 200 m queda fuera de ruta', $MSeg->positionFor('J_NOSHAPE', $aSegFar) === null);

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: trazados que vuelven sobre sí mismos');

$aOut = live(250, 0, 2, T0 + 60);
$aPos = matcher($aOut, T0 + 60)->positionFor('J_LOOP', $aOut);
$Expect('en la ida (próxima = P2) se queda en la ida', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, positionText($aPos));

$aBack = live(250, 40, 2, T0 + 300);
$aPos = matcher($aBack, T0 + 300, null, 40.0)->positionFor('J_LOOP', $aBack);
$Expect('en la vuelta, 40 m más cerca, gana la vuelta aunque el feed diga P2', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 315, positionText($aPos));

$aClose = live(250, 10, 2, T0 + 300);
$aPos = matcher($aClose, T0 + 300, null, 10.0)->positionFor('J_LOOP', $aClose);
$Expect('si la vuelta está a menos de 20 m de diferencia, manda la próxima parada del feed', $aPos !== null && $aPos['scheduledSeconds'] === T0 + 45, positionText($aPos));

/* ------------------------------------------------------------------ */
$Section('RealtimeMatcher: emparejar el feed con el horario y enrich()');

$M = matcher($aLive, $iGps + 30);
$Expect('el VehicleJourneyRef se empareja por trip_aliases', $M->lookup('3414', '808', T0, 'J_LINE') === $aLive);
$aVariant = live(1500, 0, 3, $iGps, 'trp_A3414_808_OP9LJIN_28800_x');
$MVariant = new RealtimeMatcher(['3414|808' => [$aVariant]], new ServiceJourney(buildFixture(40)), $iGps, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$Expect('una variante de calendario fusionada (OP9LJIN) apunta al mismo viaje', $MVariant->journeyIdFor($aVariant) === 'J_LINE' && $MVariant->lookup('3414', '808', T0, 'J_LINE') === $aVariant);

$aOld = ['3414|808' => [
    ['departureSeconds' => T0 + 200, 'tag' => 'lejos'],
    ['departureSeconds' => T0 + 60, 'tag' => 'cerca'],
]];
$MOld = new RealtimeMatcher($aOld, null, T0, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$aHit = $MOld->lookup('3414', '808', T0);
$Expect('sin alias, gana la salida más cercana dentro de 180 s', $aHit !== null && $aHit['tag'] === 'cerca');
$Expect('sin alias, más de 180 s de diferencia no empareja', $MOld->lookup('3414', '808', T0 + 600) === null);
$Expect('una línea sin buses en el feed no empareja', $MOld->lookup('9999', '808', T0) === null);

$aRow = ['service_journey_id' => 'J_LINE', 'line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'arrival_seconds' => T0 + 720];
$aOut = $M->enrich([$aRow])[0];
$Expect('enrich marca la salida como live con la ETA calculada', $aOut['status'] === 'live' && $aOut['etaSeconds'] === T0 + 690 && $aOut['vehicleRef'] === 'BUS1');
$Expect('enrich conserva los campos originales de la fila', $aOut['arrival_seconds'] === T0 + 720 && $aOut['trip_number'] === '808');

$aPassed = ['arrival_seconds' => T0] + $aRow;
$aOut = $M->enrich([$aPassed])[0];
$Expect('una parada que el bus ya dejó atrás sale como departed', $aOut['status'] === 'departed');

$aOther = ['service_journey_id' => 'J_NOSHAPE', 'trip_number' => '777'] + $aRow;
$aOut = $M->enrich([$aOther])[0];
$Expect('una salida sin bus en el feed queda programada', $aOut['status'] === 'scheduled' && $aOut['etaSeconds'] === T0 + 720 && $aOut['delaySeconds'] === 0);

$aOut = $MFar->enrich([$aRow])[0];
$Expect('un bus fuera de ruta deja la salida como programada', $aOut['status'] === 'scheduled');

/* ------------------------------------------------------------------ */
$Section('PaceFactors');

$Expect('jueves 07:30 es laborable.punta_manana', PaceFactors::bandFor(DATE_WEEKDAY, 7 * 3600 + 1800) === 'laborable.punta_manana');
$Expect('domingo 10:00 es fin_semana.manana', PaceFactors::bandFor(DATE_WEEKEND, 10 * 3600) === 'fin_semana.manana');
$Expect('16:00 en punto ya es punta_tarde', PaceFactors::bandFor(DATE_WEEKDAY, 16 * 3600) === 'laborable.punta_tarde');
$Expect('pasada la medianoche (25:10 GTFS) es madrugada', PaceFactors::bandFor(DATE_WEEKDAY, 25 * 3600 + 600) === 'laborable.madrugada');
$P = new PaceFactors(['global' => ['laborable.manana' => 0.9, 'laborable.noche' => 0.1, 'laborable.mediodia' => 5], 'lines' => ['A1' => ['laborable.manana' => 0.8]]]);
$Expect('el factor por línea tiene prioridad sobre el global', $P->factor('A1', DATE_WEEKDAY, 10 * 3600) === 0.8);
$Expect('otra línea usa el global', $P->factor('B2', DATE_WEEKDAY, 10 * 3600) === 0.9);
$Expect('sin valor para la franja, k = 1', $P->factor('B2', DATE_WEEKEND, 10 * 3600) === 1.0);
$Expect('k se limita por abajo a 0,6', $P->factor(null, DATE_WEEKDAY, 21 * 3600) === PaceFactors::MIN_FACTOR);
$Expect('k se limita por arriba a 1,2', $P->factor(null, DATE_WEEKDAY, 14 * 3600) === PaceFactors::MAX_FACTOR);
$Expect('un fichero que no existe da k = 1', PaceFactors::fromFile('/no/existe.json')->factor(null, DATE_WEEKDAY, 10 * 3600) === 1.0);

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
$Expect('engine/bizkaibus/data/pace-factors.json es válido (franjas conocidas, k entre 0,6 y 1,2)', $bValid && empty($aBad), implode(', ', $aBad));

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
$Expect('engine/bizkaibus/data/segment-times.json es válido si existe (tramos desde>hasta, segundos entre 1 y 3 h)', empty($aSegmentsBad), implode(', ', array_slice($aSegmentsBad, 0, 5)));

/* ------------------------------------------------------------------ */
$Section('SIRI-VM (parseo del feed)');

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
$Expect('agrupa por línea|número de viaje y descarta referencias que no encajan', array_keys($aMap) === ['3414|808'] && \count($aMap['3414|808']) === 2);
$Expect('lee la salida del VehicleJourneyRef (28800 s)', $aFirst !== null && $aFirst['departureSeconds'] === 28800);
$Expect('Delay PT1M30S = 90 s y -PT45S = -45 s', $aFirst !== null && $aSecond !== null && $aFirst['delaySeconds'] === 90 && $aSecond['delaySeconds'] === -45);
$Expect('lee GPS, hora del dato (08:04:00), parada y VisitNumber', $aFirst !== null && $aFirst['lat'] === 43.27 && $aFirst['locationSeconds'] === T0 + 240 && $aFirst['currentStopId'] === '0123' && $aFirst['order'] === 3);
$Expect('Order tiene prioridad y un GPS de otro día se descarta', $aSecond !== null && $aSecond['order'] === 7 && $aSecond['lat'] === null && $aSecond['locationSeconds'] === null);
$Expect('guarda la hora del dato (RecordedAtTime) aunque no haya GPS', $aFirst !== null && $aFirst['recordedSeconds'] === T0 + 240 && $aSecond !== null && $aSecond['recordedSeconds'] === null);
$Expect('un XML roto devuelve un mapa vacío', SiriVehicleMonitoringClient::parse('<Siri><roto', DATE_WEEKDAY) === []);

/* ------------------------------------------------------------------ */
$Section('GTFS-Realtime: lectura del feed de Metro Bilbao (CTB)');

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
$Expect('feed real: 84 viajes y 661 previsiones (igual que la librería oficial de Google)', count($aReal['trips']) === 84 && $iRealStops === 661, count($aReal['trips']) . ' / ' . $iRealStops);
$Expect('feed real: fecha de generación y primer viaje 899112 en la parada 22 (sin el ".0")', $aReal['generatedAt'] === 1791180803 && $aReal['trips'][0]['tripId'] === '899112' && $aReal['trips'][0]['stops'][0] === ['22', 1791180816]);

$sSynthetic = pbField(1, pbField(1, '2.0') . pbField(3, 1000))
    . pbField(2, pbField(1, 'e1') . pbField(3, pbField(1, pbField(1, 'T1')) . pbField(2, pbStopTime('7.0', 2000)) . pbField(2, pbStopTime('8.0', null, 2100)) . pbField(2, pbStopTime('9.0', 2200, null, 1))))
    . pbField(2, pbField(1, 'e2') . pbField(4, pbField(1, 'solo-posicion')));
$aSynthetic = GtfsRealtimeClient::parseTripUpdates($sSynthetic);
$Expect('lee la hora de generación de la cabecera', $aSynthetic['generatedAt'] === 1000);
$Expect('usa la salida si no hay llegada y descarta las paradas saltadas', $aSynthetic['trips'] === [['tripId' => 'T1', 'stops' => [['7', 2000], ['8', 2100]]]], json_encode($aSynthetic['trips']));
$Expect('ignora entidades sin previsiones (solo posición) y lee retrasos negativos sin romperse', count($aSynthetic['trips']) === 1);
$bTruncated = false;
try {
    GtfsRealtimeClient::parseTripUpdates(substr($sSynthetic, 0, 20));
} catch (RuntimeException $Ex) {
    $bTruncated = true;
}
$Expect('un feed cortado lanza un error (la caché sigue con el último bueno)', $bTruncated);

/* ------------------------------------------------------------------ */
$Section('GTFS-Realtime: asociación de previsiones al horario (Metro+)');

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
$ScheduledRow = fn(string $sTrip, string $sPattern, int $iArrival) => [
    'trip_number' => $sTrip, 'journey_pattern_id' => $sPattern, 'arrival_seconds' => $iArrival,
    'status' => 'scheduled', 'etaSeconds' => $iArrival, 'delaySeconds' => 0,
];
$aRows = [$ScheduledRow('up1', 'UP', T0 + 100), $ScheduledRow('down1', 'DOWN', T0 + 120), $ScheduledRow('up2', 'UP', T0 + 400)];
$aFeedTrips = [
    ['tripId' => 'a', 'stops' => [['2', T0 + 150], ['3', T0 + 250]]],
    ['tripId' => 'b', 'stops' => [['3', T0 + 200], ['2', T0 + 300]]],
    ['tripId' => 'c', 'stops' => [['2', T0 + 110]]],
    ['tripId' => 'd', 'stops' => [['2', T0 + 2000], ['3', T0 + 2100]]],
];
$aOut = (new TripUpdatesMatcher($aFeedTrips, new ServiceJourney($PdoRt), 600, 0))->enrich('2', $aRows);
$aByTrip = array_column($aOut, null, 'trip_number');
$Expect('el tren de subida recibe su previsión (50 s cuentan como en hora)', $aByTrip['up1']['status'] === 'live' && $aByTrip['up1']['etaSeconds'] === T0 + 150 && $aByTrip['up1']['delaySeconds'] === 0);
$Expect('el de bajada recibe la del tren que va en su sentido: 3 min de retraso', $aByTrip['down1']['status'] === 'live' && $aByTrip['down1']['delaySeconds'] === 180);
$Expect('una previsión de una sola parada o a más de 10 min no se asocia', $aByTrip['up2']['status'] === 'scheduled' && $aByTrip['up2']['etaSeconds'] === T0 + 400);
$Expect('las salidas quedan ordenadas por la hora prevista', array_column($aOut, 'trip_number') === ['up1', 'down1', 'up2']);
$Expect('sin feed, el horario no cambia', (new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->enrich('2', $aRows) === $aRows);
$MDeparted = new TripUpdatesMatcher($aFeedTrips, new ServiceJourney($PdoRt), 600, 0);
$aGone = [
    ['status' => 'live', 'etaSeconds' => 1000 - 30],
    ['status' => 'live', 'etaSeconds' => 1000 - 60],
    ['status' => 'scheduled', 'etaSeconds' => 1000 - 50],
    ['status' => 'scheduled', 'etaSeconds' => 1000 - 90],
    ['status' => 'scheduled', 'etaSeconds' => 1000 + 120],
];
$aKept = array_column($MDeparted->withoutDeparted($aGone, 1000), 'etaSeconds');
$Expect('con el feed activo, un tren en vivo que pasó hace 30 s se mantiene y hace 60 s ya ha salido', $aKept === [970, 950, 1120], json_encode($aKept));
$Expect('con el feed activo, un tren sin datos en vivo pasado hace 90 s ya ha salido', !in_array(910, $aKept, true));
$Expect('hasData: con trenes en el feed, sí; con el feed vacío, no', $MDeparted->hasData() && !(new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->hasData());

/* El feed del CTB publica a veces mal el id de una parada (Urduliz, 29, sale como Gobela, 20): ese tren no se descarta, se corrige. */
$aFeedBadId = [['tripId' => 'e', 'stops' => [['2', T0 + 150], ['3', T0 + 250], ['1', T0 + 350]]]];
$MBadId = new TripUpdatesMatcher($aFeedBadId, new ServiceJourney($PdoRt), 600, 0);
$aAtFour = $MBadId->enrich('4', [$ScheduledRow('up1', 'UP', T0 + 340)]);
$Expect('un tren con el id de su última parada mal publicado sigue en directo en la parada corregida', $aAtFour[0]['status'] === 'live' && $aAtFour[0]['etaSeconds'] === T0 + 350, json_encode([$aAtFour[0]['status'], $aAtFour[0]['etaSeconds']]));
$aAtOne = $MBadId->enrich('1', [$ScheduledRow('up9', 'UP', T0 + 345)]);
$Expect('el id mal publicado no se usa como previsión de la parada que dice (otro tren a esa hora)', $aAtOne[0]['status'] === 'scheduled' && $aAtOne[0]['etaSeconds'] === T0 + 345, json_encode([$aAtOne[0]['status'], $aAtOne[0]['etaSeconds']]));
$aRowsBad = [['service_journey_id' => 'J_UP1', 'trip_number' => 'up1', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 100, 'status' => 'scheduled']];
$Expect('liveTrip devuelve las paradas con el id corregido', $MBadId->liveTrip('2', $aRowsBad, 'J_UP1') === [['2', T0 + 150], ['3', T0 + 250], ['4', T0 + 350]], json_encode($MBadId->liveTrip('2', $aRowsBad, 'J_UP1')));
$aTwoBad = [['tripId' => 'f', 'stops' => [['2', T0 + 150], ['1', T0 + 250], ['1', T0 + 350]]]];
$Expect('un tren con dos ids que no encajan no se asocia', (new TripUpdatesMatcher($aTwoBad, new ServiceJourney($PdoRt), 600, 0))->enrich('2', [$ScheduledRow('up1', 'UP', T0 + 150)])[0]['status'] === 'scheduled');

/* Id mal publicado con una posición mayor que las siguientes: tren de bajada (4,3,2,1) con el «3» publicado como «1». */
$aFeedBadDown = [['tripId' => 'g', 'stops' => [['4', T0 + 100], ['1', T0 + 200], ['2', T0 + 300]]]];
$aDownFixed = (new TripUpdatesMatcher($aFeedBadDown, new ServiceJourney($PdoRt), 600, 0))->enrich('3', [$ScheduledRow('down1', 'DOWN', T0 + 205)]);
$Expect('un id mal publicado más adelante en el patrón que las paradas siguientes también se corrige (3 publicada como 1)', $aDownFixed[0]['status'] === 'live' && $aDownFixed[0]['etaSeconds'] === T0 + 200, json_encode([$aDownFixed[0]['status'], $aDownFixed[0]['etaSeconds']]));

/* Con dos correcciones posibles (a, e, c: el «e» puede ser un «b» mal publicado o el «c» una «f»), gana la que deja la parada pegada a sus dos vecinas. */
$aFeedAmbiguous = [['tripId' => 'i', 'stops' => [['a', T0 + 100], ['e', T0 + 200], ['c', T0 + 300]]]];
$aAmbiguous = (new TripUpdatesMatcher($aFeedAmbiguous, new ServiceJourney($PdoRt), 600, 0))->enrich('b', [$ScheduledRow('long1', 'LONG', T0 + 205)]);
$Expect('si hay varias correcciones posibles gana la que deja la parada pegada a sus dos vecinas (e era b)', $aAmbiguous[0]['status'] === 'live' && $aAmbiguous[0]['etaSeconds'] === T0 + 200, json_encode([$aAmbiguous[0]['status'], $aAmbiguous[0]['etaSeconds']]));

/* El recorrido en vivo de un tren: se empareja igual que en las salidas y devuelve sus paradas previstas. */
$aRowsJourney = [
    ['service_journey_id' => 'J_UP1', 'trip_number' => 'up1', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 100, 'status' => 'scheduled'],
    ['service_journey_id' => 'J_DOWN1', 'trip_number' => 'down1', 'journey_pattern_id' => 'DOWN', 'arrival_seconds' => T0 + 120, 'status' => 'scheduled'],
    ['service_journey_id' => 'J_UP2', 'trip_number' => 'up2', 'journey_pattern_id' => 'UP', 'arrival_seconds' => T0 + 400, 'status' => 'scheduled'],
];
$aLiveUp = $MDeparted->liveTrip('2', $aRowsJourney, 'J_UP1');
$Expect('liveTrip: el viaje de subida recibe las paradas previstas de su tren', $aLiveUp === [['2', T0 + 150], ['3', T0 + 250]], json_encode($aLiveUp));
$aLiveDown = $MDeparted->liveTrip('2', $aRowsJourney, 'J_DOWN1');
$Expect('liveTrip: el de bajada recibe las del tren que va en su sentido', $aLiveDown === [['3', T0 + 200], ['2', T0 + 300]], json_encode($aLiveDown));
$Expect('liveTrip: un viaje que el feed no trae (previsión a más de 10 min) devuelve null', $MDeparted->liveTrip('2', $aRowsJourney, 'J_UP2') === null);
$Expect('liveTrip: sin feed devuelve null', (new TripUpdatesMatcher([], new ServiceJourney($PdoRt), 600, 0))->liveTrip('2', $aRowsJourney, 'J_UP1') === null);

/* ------------------------------------------------------------------ */
$Section('Variantes de un viaje en varios calendarios');
$PdoVar = new PDO('sqlite::memory:');
$PdoVar->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoVar->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoVar->exec('
    CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT);
    CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT, name TEXT);
    CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, headsign TEXT);
    CREATE TABLE service_calendars (id TEXT PRIMARY KEY, weekday_mask INTEGER, from_date TEXT, to_date TEXT);
    CREATE TABLE service_calendar_exceptions (calendar_id TEXT, date TEXT, available INTEGER);
    CREATE TABLE service_journeys (id TEXT PRIMARY KEY, line_id TEXT, journey_pattern_id TEXT, trip_number TEXT, calendar_id TEXT, first_departure_seconds INTEGER, shape_id TEXT);
    CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER, dist_m REAL);
    INSERT INTO stops VALUES (\'S0\', \'Parada 0\');
    INSERT INTO lines VALUES (\'L1\', \'A1\', \'Línea 1\');
    INSERT INTO journey_patterns VALUES (\'P1\', \'Destino\');
    INSERT INTO service_calendars VALUES (\'C_NEVER\', 0, NULL, NULL), (\'C_ALWAYS\', 127, NULL, NULL);
    INSERT INTO service_journeys VALUES (\'A_OTHER_DAY\', \'L1\', \'P1\', \'721\', \'C_NEVER\', 79200, NULL), (\'B_TODAY\', \'L1\', \'P1\', \'721\', \'C_ALWAYS\', 79200, NULL);
    INSERT INTO passing_times VALUES (\'A_OTHER_DAY\', 1, \'S0\', 79200, 79200, 0), (\'B_TODAY\', 1, \'S0\', 79200, 79200, 0);
');
$JourneyVar = new ServiceJourney($PdoVar);
$aVariants = $JourneyVar->variantsByLineAndTrip('L1', '721', 79200);
$Expect('un viaje en dos calendarios devuelve las dos variantes', count($aVariants) === 2);
$Expect('la variante que circula hoy va la primera, aunque su id sea posterior', $aVariants[0]['id'] === 'B_TODAY' && (int)$aVariants[0]['runs_today'] === 1 && (int)$aVariants[1]['runs_today'] === 0);
$Expect('findByLineAndTrip da la variante de hoy', $JourneyVar->findByLineAndTrip('L1', '721', 79200)['id'] === 'B_TODAY');
$Expect('un viaje que no existe da null', $JourneyVar->findByLineAndTrip('L1', '999', 79200) === null && $JourneyVar->variantsByLineAndTrip('L1', '999', 79200) === []);

$Section('Rango de fechas publicado');
$MakeRange = function (array $aMeta, array $aCalendars, array $aExceptions): PDO {
    $P = new PDO('sqlite::memory:');
    $P->exec('CREATE TABLE meta (key TEXT, value TEXT)');
    $P->exec('CREATE TABLE service_calendars (from_date TEXT, to_date TEXT)');
    $P->exec('CREATE TABLE service_calendar_exceptions (date TEXT, available INTEGER)');
    foreach ($aMeta as $k => $sValue) { $P->prepare('INSERT INTO meta VALUES (?, ?)')->execute([$k, $sValue]); }
    foreach ($aCalendars as $aC) { $P->prepare('INSERT INTO service_calendars VALUES (?, ?)')->execute($aC); }
    foreach ($aExceptions as $aE) { $P->prepare('INSERT INTO service_calendar_exceptions VALUES (?, ?)')->execute($aE); }
    return $P;
};
$Expect('rango: usa feed_start_date y feed_end_date si el operador los da', ScheduleRange::forDatabase($MakeRange(['feed_start_date' => '2026-10-28', 'feed_end_date' => '2026-11-28'], [['2026-01-01', '2026-12-31']], [])) === ['from' => '2026-10-28', 'to' => '2026-11-28']);
$Expect('rango: sin feed_info, sale de los calendarios', ScheduleRange::forDatabase($MakeRange([], [['2026-09-01', '2026-10-16'], ['2026-09-10', '2026-10-01']], [])) === ['from' => '2026-09-01', 'to' => '2026-10-16']);
$Expect('rango: sin calendarios, sale de las fechas con servicio', ScheduleRange::forDatabase($MakeRange([], [['', '']], [['2026-10-05', 1], ['2026-10-20', 1], ['2026-12-25', 0]])) === ['from' => '2026-10-05', 'to' => '2026-10-20']);
$Expect('rango: sin datos devuelve nulos', ScheduleRange::forDatabase($MakeRange([], [], [])) === ['from' => null, 'to' => null]);

$Section('TripKey y Calendar');

$Expect('TripKey ida y vuelta', TripKey::parse(TripKey::build('3414', '808', 28800)) === ['3414', '808', 28800]);
$Expect('TripKey con guiones en la línea', TripKey::parse(TripKey::build('L-1', '12', 3600)) === ['L-1', '12', 3600]);
$Expect('TripKey rechaza claves mal formadas', TripKey::parse('sin-numero') === null && TripKey::parse('') === null);
$Expect('lunes = bit 1 y domingo = bit 64', Calendar::weekdayBitFor(new DateTime('2026-09-28')) === 1 && Calendar::weekdayBitFor(new DateTime(DATE_WEEKEND)) === 64);
$Expect('secondsToHm pasa la medianoche (25:05 -> 01:05)', Calendar::secondsToHm(25 * 3600 + 5 * 60) === '01:05');
$Expect('hmToSeconds: 07:45 son 27900 s', Calendar::hmToSeconds('07:45') === 27900);
$Expect('hmToSeconds: lo que no es HH:MM cuenta como 0', Calendar::hmToSeconds('7h45') === 0 && Calendar::hmToSeconds('') === 0);

// Ficha del viaje: sin datos en vivo no se afirma que el bus ya salió.
$aTripStops = [['arrival_seconds' => 8 * 3600 + 10 * 60], ['arrival_seconds' => 8 * 3600 + 40 * 60]];
$Expect('ficha del viaje: con datos en vivo sale live', Controllers\RealtimeController::tripStatus(true, $aTripStops, 8 * 3600 + 20 * 60) === 'live');
$Expect('ficha del viaje: sin datos en vivo y a la hora de salida NO dice que ya salió (scheduled)', Controllers\RealtimeController::tripStatus(false, $aTripStops, 8 * 3600 + 10 * 60 + 30) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y a mitad del viaje sigue en scheduled', Controllers\RealtimeController::tripStatus(false, $aTripStops, 8 * 3600 + 25 * 60) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y pasada la última parada sale finished', Controllers\RealtimeController::tripStatus(false, $aTripStops, 8 * 3600 + 41 * 60) === 'finished');
$Expect('ficha del viaje: sin paradas sale scheduled', Controllers\RealtimeController::tripStatus(false, [], 8 * 3600) === 'scheduled');

$Section('Registro de pasos (buses que ya pasaron aunque hayan salido del directo)');
$aVmPass = ['3414|808' => [
    ['tripRef' => 'trp_A', 'order' => 4, 'recordedSeconds' => T0 + 500, 'locationSeconds' => T0 + 490],
    ['tripRef' => 'trp_B', 'order' => null, 'recordedSeconds' => T0 + 500],
    ['tripRef' => 'trp_C', 'order' => 2, 'recordedSeconds' => null, 'locationSeconds' => T0 + 300],
    ['tripRef' => 'trp_D', 'order' => 3],
]];
$Expect('se apunta «parada a la que va:hora» de cada viaje con parada y hora', PassageLog::fields($aVmPass) === ['trp_A' => '4:' . (T0 + 500), 'trp_C' => '2:' . (T0 + 300)], json_encode(PassageLog::fields($aVmPass)));
$Expect('valores mal formados no cuentan', PassageLog::decode('x') === null && PassageLog::decode(null) === null && PassageLog::decode('4:100') === ['order' => 4, 'seconds' => 100]);
$Expect('de los alias de un viaje vale el que llegó más lejos', PassageLog::merge(['J1', 'J1', 'J2'], ['2:100', '5:200', null]) === ['J1' => ['order' => 5, 'seconds' => 200]]);
$Expect('la clave es por red y por día', PassageLog::key('bus', '2026-10-29') === 'bide:bus:pass:2026-10-29');
$Expect('historial: una entrada por viaje y parada, con la hora', PassageLog::stopFields($aVmPass) === ['trp_A#4' => (string)(T0 + 500), 'trp_C#2' => (string)(T0 + 300)], json_encode(PassageLog::stopFields($aVmPass)));
$aHistory = PassageLog::stopsFromHash(['trp_X#5' => '200', 'trp_X#3' => '100', 'trp_Y#1' => '50', 'mal' => '1', 'trp_Z#2' => 'x']);
$Expect('historial: se agrupa por viaje y se ordena por parada', $aHistory === ['trp_X' => [3 => 100, 5 => 200], 'trp_Y' => [1 => 50]], json_encode($aHistory));
$Expect('sin almacén configurado no se consulta nada', PassageLog::forJourneys(new ServiceJourney(buildFixture(40)), ['J_LINE'], 'bus', DATE_WEEKDAY) === []);
$Expect('nombres de un viaje en el directo: su id y sus alias', (new ServiceJourney(buildFixture(40)))->tripRefsForJourneys(['J_LINE'])['J_LINE'] === ['J_LINE', 'trp_A3414_808_OP9LJIN_28800_x', 'trp_A3414_808_OP9LSEPT_28800_x'] || (new ServiceJourney(buildFixture(40)))->tripRefsForJourneys(['J_LINE'])['J_LINE'] === ['J_LINE', 'trp_A3414_808_OP9LSEPT_28800_x', 'trp_A3414_808_OP9LJIN_28800_x']);

$aAsked = [];
$MPass = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$MPass->setPassageLoader(function (array $aIds) use (&$aAsked) {
    $aAsked = array_merge($aAsked, $aIds);
    return ['J_LINE' => ['order' => 4, 'seconds' => T0 + 500]];
});
$aPassRows = [
    ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 360, 'stop_seq' => 3],
    ['line_id' => '3414', 'trip_number' => '808', 'first_departure_seconds' => T0, 'service_journey_id' => 'J_LINE', 'arrival_seconds' => T0 + 540, 'stop_seq' => 4],
];
$aPassOut = $MPass->enrich($aPassRows);
$Expect('el registro dice que el bus ya va hacia la parada 4: la 3 sale como pasada, con la hora del registro', $aPassOut[0]['status'] === 'departed' && $aPassOut[0]['etaSeconds'] === T0 + 500, json_encode([$aPassOut[0]['status'], $aPassOut[0]['etaSeconds']]));
$Expect('la parada a la que va todavía no ha pasado', $aPassOut[1]['status'] === 'scheduled', $aPassOut[1]['status']);
$Expect('el registro se pide una vez por consulta, sin repetir viajes', $aAsked === ['J_LINE'], json_encode($aAsked));
$MPass->enrich($aPassRows);
$Expect('lo ya leído no se vuelve a pedir', $aAsked === ['J_LINE'], json_encode($aAsked));

$aAskedLive = [];
$MPassLive = matcher(live(1500, 0, 3, T0 + 270), T0 + 300);
$MPassLive->setPassageLoader(function (array $aIds) use (&$aAskedLive) {
    $aAskedLive = array_merge($aAskedLive, $aIds);
    return [];
});
$MPassLive->enrich([$aPassRows[1]]);
$Expect('los viajes que están en el directo no se buscan en el registro', $aAskedLive === [], json_encode($aAskedLive));
$MPassBroken = new RealtimeMatcher([], new ServiceJourney(buildFixture(40)), T0 + 600, new PaceFactors(), DATE_WEEKDAY, new SegmentTimes());
$MPassBroken->setPassageLoader(function (array $aIds) {
    throw new RuntimeException('almacén caído');
});
$Expect('si el registro falla, se sigue con el horario', @$MPassBroken->enrich([$aPassRows[0]])[0]['status'] === 'scheduled');

// Orden de las salidas: un bus que probablemente ya pasó (estimación) va al final aunque su hora oficial sea anterior.
$BuildItems = new ReflectionMethod(Controllers\StopsController::class, 'departureItems');
$iNowItems = Calendar::nowSecondsSinceMidnight();
$MakeItem = fn(string $sTrip, string $sStatus, int $iEta) => ['line_id' => '3514', 'line_code' => 'A3514', 'line_name' => 'x', 'headsign' => 'GERNIKA', 'last_stop_name' => null,
    'trip_number' => $sTrip, 'first_departure_seconds' => 0, 'arrival_seconds' => $iEta, 'etaSeconds' => $iEta, 'status' => $sStatus, 'delaySeconds' => 0];
$aItems = $BuildItems->invoke(null, [$MakeItem('A', 'likely_departed', $iNowItems + 600), $MakeItem('B', 'estimated', $iNowItems + 1200), $MakeItem('C', 'live', $iNowItems + 900)], false);
$Expect('el que probablemente ya pasó va el último; los demás por hora', array_column($aItems, 'status') === ['live', 'estimated', 'likely_departed'], json_encode(array_column($aItems, 'status')));

// Filas repetidas: mismo número de viaje en dos calendarios se junta; dos viajes distintos a la vez por la parada, no.
$Dedupe = new ReflectionMethod(ServiceJourney::class, 'dedupeByTrip');
$aDedupeRows = [
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60180, 'first_departure_seconds' => 58500],
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60180, 'first_departure_seconds' => 58500],
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60249, 'first_departure_seconds' => 56700],
];
$aDeduped = $Dedupe->invoke(new ServiceJourney(buildFixture(40)), $aDedupeRows, 10);
$Expect('el mismo viaje en dos calendarios sale una vez; otro viaje del mismo número un minuto después también sale', \count($aDeduped) === 2 && $aDeduped[1]['first_departure_seconds'] === 56700, (string)\count($aDeduped));

if (!empty($aFailures)) {
    echo "\nRESULTADO unitarias: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO unitarias: OK\n";
exit(0);
