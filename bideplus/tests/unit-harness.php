<?php
// Lo que comparten las pruebas unitarias de la app (unit-test.php) y del algoritmo (engine-test.php): el informe de
// cada comprobación, la base de datos de prueba en memoria y los datos de un bus en directo.

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

require __DIR__ . '/../api/autoload.php';

use Engine\Bizkaibus\PaceFactors;
use Engine\Bizkaibus\RealtimeMatcher;
use Engine\Bizkaibus\SegmentTimes;
use Models\ServiceJourney;

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

$Finish = function (string $sName) use (&$aFailures): void {
    if (!empty($aFailures)) {
        echo "\nRESULTADO " . $sName . ": FALLA -> " . implode('; ', $aFailures) . "\n";
        exit(1);
    }
    echo "\nRESULTADO " . $sName . ": OK\n";
    exit(0);
};
