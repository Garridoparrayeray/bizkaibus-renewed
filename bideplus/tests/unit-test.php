<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

spl_autoload_register(function (string $sClass): void {
    $sPath = __DIR__ . '/../api/' . str_replace('\\', '/', $sClass) . '.php';
    if (is_file($sPath)) {
        require $sPath;
    }
});

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
$expect('los tiempos por tramo no cambian el retraso mostrado', $iDelay === -30);
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
$expect('fuera de ruta el retraso mostrado es 0', $MFar->currentDelaySeconds('J_LINE', $aFar) === 0);

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

$aNoGps = live(1500, 0, 3, $iGps);
$aNoGps['lat'] = null;
$aNoGps['lon'] = null;
$aNoGps['locationSeconds'] = null;
$aNoGps['delaySeconds'] = 60;
$MNoGps = matcher($aNoGps, T0 + 300);
$expect('sin GPS el bus sigue contando como en vivo', $MNoGps->isLive('J_LINE', $aNoGps));
[$iEta, , $bPositioned] = $MNoGps->etaForStop('J_LINE', T0 + 720, $aNoGps);
$expect('sin GPS usa ahora + horario restante desde la parada del feed', $iEta === T0 + 300 + (720 - 360) && !$bPositioned, hms($iEta));

$expect('sin datos en vivo la ETA es el horario', matcher($aLive, $iGps)->etaForStop('J_LINE', T0 + 720, null) === [T0 + 720, 0, false]);

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
foreach (['UP' => ['1', '2', '3', '4'], 'DOWN' => ['4', '3', '2', '1']] as $sPattern => $aStops) {
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

/* ------------------------------------------------------------------ */
$section('TripKey y Calendar');

$expect('TripKey ida y vuelta', TripKey::parse(TripKey::build('3414', '808', 28800)) === ['3414', '808', 28800]);
$expect('TripKey con guiones en la línea', TripKey::parse(TripKey::build('L-1', '12', 3600)) === ['L-1', '12', 3600]);
$expect('TripKey rechaza claves mal formadas', TripKey::parse('sin-numero') === null && TripKey::parse('') === null);
$expect('lunes = bit 1 y domingo = bit 64', Calendar::weekdayBitFor(new DateTime('2026-09-28')) === 1 && Calendar::weekdayBitFor(new DateTime(DATE_WEEKEND)) === 64);
$expect('secondsToHm pasa la medianoche (25:05 -> 01:05)', Calendar::secondsToHm(25 * 3600 + 5 * 60) === '01:05');

echo "\nRESULTADO unitarias: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
