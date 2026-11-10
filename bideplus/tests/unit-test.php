<?php
// Pruebas unitarias de la app, sin servidor, sin red y sin el algoritmo (engine/): registro de redes, GTFS-Realtime,
// horas previstas, Renfe, vehículos en el mapa, variantes de un viaje y salidas de una parada.
// Uso: php tests/unit-test.php

require __DIR__ . '/unit-harness.php';

use Core\TripKey;
use Models\ServiceJourney;
use Services\Calendar;
use Services\CancelledTrains;
use Services\FeedStopOrder;
use Services\GtfsRealtimeClient;
use Services\RenfeAlertsClient;
use Services\ScheduleRange;
use Services\TripDelayMatcher;
use Services\TripUpdatesMatcher;
$Section('Sin motor: las salidas llevan los campos del horario');
$aOnly = (new \Services\ScheduleOnlyMatcher())->enrich([['line_id' => '1', 'trip_number' => 'a', 'first_departure_seconds' => 100, 'arrival_seconds' => 460]]);
$Expect('sin vehículos, cada salida sale como scheduled con su hora', $aOnly[0]['status'] === 'scheduled' && $aOnly[0]['etaSeconds'] === 460 && $aOnly[0]['delaySeconds'] === 0 && array_key_exists('vehicleRef', $aOnly[0]));

$Section('Caché de los feeds en directo');
$sFeedKey = 'prueba_feed_' . getmypid();
$sFeedFile = sys_get_temp_dir() . '/bizkaibusplus_' . $sFeedKey . '.json';
$iCalls = 0;
$Empty = function () use (&$iCalls) {
    $iCalls++;
    return [];
};
\Core\Cache::rememberFeed($sFeedKey, 25, $Empty);
touch($sFeedFile, time() - 120);
clearstatcache();
\Core\Cache::rememberFeed($sFeedKey, 25, $Empty);
$Expect('un feed vacío no se vuelve a pedir en 10 minutos (sí en remember normal)', $iCalls === 1, (string)$iCalls);
touch($sFeedFile, time() - 700);
clearstatcache();
\Core\Cache::rememberFeed($sFeedKey, 25, $Empty);
$Expect('pasados 10 minutos, el feed vacío se vuelve a pedir', $iCalls === 2, (string)$iCalls);
file_put_contents($sFeedFile, json_encode(['3514|1' => [['vehicleRef' => 'x']]]));
touch($sFeedFile, time() - 120);
clearstatcache();
$aFresh = \Core\Cache::rememberFeed($sFeedKey, 25, fn() => ['nuevo' => []]);
$Expect('un feed con vehículos caduca a su tiempo normal', array_keys($aFresh) === ['nuevo']);
@unlink($sFeedFile);

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
        } elseif ($sSource === 'vehicle-monitoring' && is_dir(__DIR__ . '/../engine') && !is_file(__DIR__ . '/../engine/' . ($aNet['engine'] ?? '-') . '/Live.php')) {
            $aProblems[] = 'vehicle-monitoring sin motor (engine/<engine>/Live.php)';
        } elseif ($sSource === 'trip-updates' && empty($aNet['gtfs_rt']['trip_updates_url'])) {
            $aProblems[] = 'trip-updates sin gtfs_rt.trip_updates_url';
        } elseif ($sSource === 'vehicle-positions' && empty($aNet['gtfs_rt']['vehicle_positions_url'])) {
            $aProblems[] = 'vehicle-positions sin gtfs_rt.vehicle_positions_url';
        } elseif (!in_array($sSource, ['vehicle-monitoring', 'trip-updates', 'vehicle-positions'], true)) {
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
$aWithoutFeed = array_keys(array_filter(\Core\Networks::all(), fn($aNet) => in_array('trip-updates', $aNet['live'], true) && (empty($aNet['gtfs_rt']['trip_updates_url']) || !isset($aNet['gtfs_rt']['max_age_seconds']) || (($aNet['gtfs_rt']['matching'] ?? 'stops') === 'stops' && !isset($aNet['gtfs_rt']['match_tolerance_seconds'])))));
$Expect('las redes con horas previstas (GTFS-Realtime) tienen su feed configurado', $aWithoutFeed === [], implode(', ', $aWithoutFeed));
$Expect('?red= desconocida cae en la red por defecto', \Core\Networks::fromQuery('no-existe') === 'bus' && \Core\Networks::fromQuery(null) === 'bus' && \Core\Networks::fromQuery('renfe') === 'renfe');

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

function pbFloat(int $iField, float $dValue): string
{
    return pbVarint(($iField << 3) | 5) . pack('g', $dValue);
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
$sWaiting = pbField(1, pbField(1, '2.0') . pbField(3, 1000))
    . pbField(2, pbField(1, 'e1') . pbField(3, pbField(1, pbField(1, 'T2')) . pbField(2, pbStopTime('1', 2000, 2900)) . pbField(2, pbStopTime('2', 3000, 3020))));
$aWaitingStops = GtfsRealtimeClient::parseTripUpdates($sWaiting)['trips'][0]['stops'];
$Expect('una parada con espera (sale un minuto o más después de llegar) guarda también cuándo sale', $aWaitingStops === [['1', 2000, 2900], ['2', 3000]], json_encode($aWaitingStops));
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

// El feed del CTB publica a veces mal el id de una parada (Urduliz, 29, sale como Gobela, 20): ese tren no se
// descarta, se corrige.
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

// Id mal publicado con una posición mayor que las siguientes: tren de bajada (4,3,2,1) con el «3» publicado como «1».
$aFeedBadDown = [['tripId' => 'g', 'stops' => [['4', T0 + 100], ['1', T0 + 200], ['2', T0 + 300]]]];
$aDownFixed = (new TripUpdatesMatcher($aFeedBadDown, new ServiceJourney($PdoRt), 600, 0))->enrich('3', [$ScheduledRow('down1', 'DOWN', T0 + 205)]);
$Expect('un id mal publicado más adelante en el patrón que las paradas siguientes también se corrige (3 publicada como 1)', $aDownFixed[0]['status'] === 'live' && $aDownFixed[0]['etaSeconds'] === T0 + 200, json_encode([$aDownFixed[0]['status'], $aDownFixed[0]['etaSeconds']]));

// Con dos correcciones posibles (a, e, c: el «e» puede ser un «b» mal publicado o el «c» una «f»), gana la que deja
// la parada pegada a sus dos vecinas.
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

// De madrugada, la ficha de un viaje de ayer que pasa de las 24:00 es la de ayer mientras no haya terminado; y el
// diagnóstico del directo cuenta los viajes en marcha según el horario (los de hoy y los de ayer pasadas las 24:00).
$iVarNow = Calendar::nowSecondsSinceMidnight();
$iTodayBit = Calendar::weekdayBitFor(Calendar::todayMadrid());
$iYesterdayBit = Calendar::weekdayBitFor(Calendar::yesterdayMadrid());
$PdoRun = new PDO('sqlite::memory:');
$PdoRun->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoRun->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoRun->exec('
    CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT, name TEXT);
    CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, headsign TEXT);
    CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT);
    CREATE TABLE service_calendars (id TEXT PRIMARY KEY, weekday_mask INTEGER, from_date TEXT, to_date TEXT);
    CREATE TABLE service_calendar_exceptions (calendar_id TEXT, date TEXT, available INTEGER);
    CREATE TABLE service_journeys (id TEXT PRIMARY KEY, line_id TEXT, journey_pattern_id TEXT, trip_number TEXT, calendar_id TEXT, first_departure_seconds INTEGER, shape_id TEXT);
    CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER, dist_m REAL);
    INSERT INTO lines VALUES (\'L1\', \'A1\', \'Línea 1\');
    INSERT INTO journey_patterns VALUES (\'P1\', \'Destino\');
    INSERT INTO stops VALUES (\'S0\', \'Parada 0\'), (\'S1\', \'Parada 1\');
');
$PdoRun->exec("INSERT INTO service_calendars VALUES ('HOY', $iTodayBit, NULL, NULL), ('AYER', $iYesterdayBit, NULL, NULL)");
$AddTrip = function (string $sId, string $sCalendar, int $iFrom, int $iTo) use ($PdoRun): void {
    $PdoRun->exec("INSERT INTO service_journeys VALUES ('$sId', 'L1', 'P1', '9', '$sCalendar', $iFrom, NULL)");
    $PdoRun->exec("INSERT INTO passing_times VALUES ('$sId', 1, 'S0', $iFrom, $iFrom, 0), ('$sId', 2, 'S1', $iTo, $iTo, 0)");
};
$AddTrip('EN_MARCHA', 'HOY', $iVarNow - 600, $iVarNow + 600);
$AddTrip('ACABADO', 'HOY', $iVarNow - 3600, $iVarNow - 1800);
$AddTrip('SIN_SALIR', 'HOY', $iVarNow + 600, $iVarNow + 1200);
$AddTrip('DE_AYER', 'AYER', $iVarNow + 86400 - 600, $iVarNow + 86400 + 600);
$AddTrip('DE_AYER_ACABADO', 'AYER', $iVarNow + 86400 - 3600, $iVarNow + 86400 - 1800);
$JourneyRun = new ServiceJourney($PdoRun);
$Expect('en marcha según el horario: el de hoy entre su salida y su llegada y el de ayer que pasa de las 24:00', $JourneyRun->runningCount() === 2, (string)$JourneyRun->runningCount());
$Expect('directo con hora de hoy: sí con un bus que la trae; no con el feed congelado de madrugada (ningún bus la trae)',
    Core\Live::isFresh(['1|2' => [['recordedSeconds' => null, 'locationSeconds' => null], ['recordedSeconds' => 300]]])
    && !Core\Live::isFresh(['1|2' => [['recordedSeconds' => null, 'locationSeconds' => null]]]) && !Core\Live::isFresh([]));
$aLateVariants = $JourneyRun->lateVariants('L1', '9', $iVarNow + 86400 - 600, Services\TripSheet::LATE_GRACE_SECONDS);
$Expect('la ficha de un viaje de ayer que aún va es la de ayer', array_column($aLateVariants, 'id') === ['DE_AYER'], json_encode(array_column($aLateVariants, 'id')));
$Expect('la de uno que terminó hace más de 10 min, o de uno de hoy, no', $JourneyRun->lateVariants('L1', '9', $iVarNow + 86400 - 3600, Services\TripSheet::LATE_GRACE_SECONDS) === []
    && $JourneyRun->lateVariants('L1', '9', $iVarNow - 600, Services\TripSheet::LATE_GRACE_SECONDS) === []);

$Section('Rango de fechas publicado');
$MakeRange = function (array $aMeta, array $aCalendars, array $aExceptions): PDO {
    $P = new PDO('sqlite::memory:');
    $P->exec('CREATE TABLE meta (key TEXT, value TEXT)');
    $P->exec('CREATE TABLE service_calendars (from_date TEXT, to_date TEXT)');
    $P->exec('CREATE TABLE service_calendar_exceptions (date TEXT, available INTEGER)');
    foreach ($aMeta as $k => $sValue) {
        $P->prepare('INSERT INTO meta VALUES (?, ?)')->execute([$k, $sValue]);
    }
    foreach ($aCalendars as $aC) {
        $P->prepare('INSERT INTO service_calendars VALUES (?, ?)')->execute($aC);
    }
    foreach ($aExceptions as $aE) {
        $P->prepare('INSERT INTO service_calendar_exceptions VALUES (?, ?)')->execute($aE);
    }
    return $P;
};
$Expect('rango: usa feed_start_date y feed_end_date si el operador los da', ScheduleRange::forDatabase($MakeRange(['feed_start_date' => '2026-10-28', 'feed_end_date' => '2026-11-28'], [['2026-01-01', '2026-12-31']], [])) === ['from' => '2026-10-28', 'to' => '2026-11-28']);
$Expect('rango: sin feed_info, sale de los calendarios', ScheduleRange::forDatabase($MakeRange([], [['2026-09-01', '2026-10-16'], ['2026-09-10', '2026-10-01']], [])) === ['from' => '2026-09-01', 'to' => '2026-10-16']);
$Expect('rango: sin calendarios, sale de las fechas con servicio', ScheduleRange::forDatabase($MakeRange([], [['', '']], [['2026-10-05', 1], ['2026-10-20', 1], ['2026-12-25', 0]])) === ['from' => '2026-10-05', 'to' => '2026-10-20']);
$Expect('rango: sin datos devuelve nulos', ScheduleRange::forDatabase($MakeRange([], [], [])) === ['from' => null, 'to' => null]);

$Section('TripKey y Calendar');
$Expect('un guion en el número de viaje (Tuvisa) va y vuelve', TripKey::parse(TripKey::build('1', 'L1S1_001-DOM', 32700)) === ['1', 'L1S1_001-DOM', 32700] && TripKey::build('1', 'L1S1_001-DOM', 32700) === '1-L1S1_001~DOM-32700');

$Expect('TripKey ida y vuelta', TripKey::parse(TripKey::build('3414', '808', 28800)) === ['3414', '808', 28800]);
$Expect('TripKey con guiones en la línea', TripKey::parse(TripKey::build('L-1', '12', 3600)) === ['L-1', '12', 3600]);
$Expect('TripKey rechaza claves mal formadas', TripKey::parse('sin-numero') === null && TripKey::parse('') === null);
$Expect('lunes = bit 1 y domingo = bit 64', Calendar::weekdayBitFor(new DateTime('2026-09-28')) === 1 && Calendar::weekdayBitFor(new DateTime(DATE_WEEKEND)) === 64);
$Expect('secondsToHm pasa la medianoche (25:05 -> 01:05)', Calendar::secondsToHm(25 * 3600 + 5 * 60) === '01:05');
$Expect('hmToSeconds: 07:45 son 27900 s', Calendar::hmToSeconds('07:45') === 27900);
$Expect('hmToSeconds: lo que no es HH:MM cuenta como 0', Calendar::hmToSeconds('7h45') === 0 && Calendar::hmToSeconds('') === 0);

// Ficha del viaje: sin datos en vivo no se afirma que el bus ya salió.
$aTripStops = [['arrival_seconds' => 8 * 3600 + 10 * 60], ['arrival_seconds' => 8 * 3600 + 40 * 60]];
$Expect('ficha del viaje: con datos en vivo sale live', Services\TripSheet::status(true, $aTripStops, 8 * 3600 + 20 * 60) === 'live');
$Expect('ficha del viaje: sin datos en vivo y a la hora de salida NO dice que ya salió (scheduled)', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 10 * 60 + 30) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y a mitad del viaje sigue en scheduled', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 25 * 60) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y pasada la última parada sale finished', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 41 * 60) === 'finished');
$Expect('ficha del viaje: sin paradas sale scheduled', Services\TripSheet::status(false, [], 8 * 3600) === 'scheduled');

$Section('GTFS-Realtime de Renfe: retraso de cada tren y cancelados (Renfe+)');

$sRenfeFeed = pbField(1, pbField(1, '2.0') . pbField(3, 5000))
    . pbField(2, pbField(1, 'u1') . pbField(3, pbField(1, pbField(1, 'R1')) . pbField(2, pbStopTime('A', 6000)) . pbField(5, 180)))
    . pbField(2, pbField(1, 'u2') . pbField(3, pbField(1, pbField(1, 'R2') . pbField(4, 3))))
    . pbField(2, pbField(1, 'u3') . pbField(3, pbField(1, pbField(1, 'R3')) . pbField(2, pbStopTime('A', 6000)) . pbField(5, -120)));
$aRenfeTrips = GtfsRealtimeClient::parseTripUpdates($sRenfeFeed)['trips'];
$Expect('lee el retraso de cada tren, también negativo', $aRenfeTrips[0]['delay'] === 180 && $aRenfeTrips[2]['delay'] === -120, json_encode($aRenfeTrips));
$Expect('un tren cancelado se lee aunque no traiga paradas', $aRenfeTrips[1] === ['tripId' => 'R2', 'stops' => [], 'canceled' => true], json_encode($aRenfeTrips[1]));
$Expect('los viajes de Metro no llevan retraso ni cancelación', !isset($aSynthetic['trips'][0]['delay']) && !isset($aSynthetic['trips'][0]['canceled']));

$PdoRenfe = new PDO('sqlite::memory:');
$PdoRenfe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoRenfe->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoRenfe->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT)');
$PdoRenfe->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoRenfe->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$PdoRenfe->exec("INSERT INTO stops VALUES ('A', 'Abando'), ('B', 'Ametzola'), ('C', 'Zabalburu')");
foreach (['J1', 'J2', 'J3', 'J4'] as $iIndex => $sJourney) {
    foreach (['A', 'B', 'C'] as $iSeq => $sStop) {
        $iArrival = T0 + $iIndex * 600 + $iSeq * 120;
        $PdoRenfe->exec("INSERT INTO passing_times VALUES ('$sJourney', $iSeq, '$sStop', $iArrival, $iArrival)");
    }
}
$PdoRenfe->exec("INSERT INTO trip_aliases VALUES ('6005L1C1', 'J1'), ('6006M1C1', 'J1'), ('6005L2C1', 'J2'), ('6005L3C1', 'J3'), ('6005L4C1', 'J4')");
$PdoRenfe->exec('CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT)');
$PdoRenfe->exec('CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, line_id TEXT)');
$PdoRenfe->exec('CREATE TABLE service_journeys (id TEXT PRIMARY KEY, journey_pattern_id TEXT)');
$PdoRenfe->exec("INSERT INTO lines VALUES ('C1-ida', 'C1')");
$PdoRenfe->exec("INSERT INTO journey_patterns VALUES ('P1', 'C1-ida')");
$PdoRenfe->exec("INSERT INTO service_journeys VALUES ('J1', 'P1'), ('J2', 'P1'), ('J3', 'P1'), ('J4', 'P1')");
$RenfeRow = fn(string $sJourney, int $iIndex) => ['service_journey_id' => $sJourney, 'trip_number' => $sJourney, 'stop_seq' => 1, 'arrival_seconds' => T0 + $iIndex * 600 + 120, 'status' => 'scheduled', 'etaSeconds' => T0 + $iIndex * 600 + 120, 'delaySeconds' => 0];
$aRenfeRows = [$RenfeRow('J1', 0), $RenfeRow('J2', 1), $RenfeRow('J3', 2), $RenfeRow('J4', 3)];
$aRenfeLive = [
    ['tripId' => '6005L1C1', 'stops' => [['A', 0]], 'delay' => 240],
    ['tripId' => '6005L2C1', 'stops' => [['C', 0]], 'delay' => 60],
    ['tripId' => '6005L3C1', 'stops' => [], 'canceled' => true],
    ['tripId' => 'de-otro-nucleo', 'stops' => [['X', 0]], 'delay' => 600],
];
$MRenfe = new TripDelayMatcher($aRenfeLive, new ServiceJourney($PdoRenfe), 0);
$aRenfeOut = array_column($MRenfe->enrich('B', $aRenfeRows), null, 'service_journey_id');
$Expect('el tren que va hacia Abando lleva su retraso a Ametzola: 4 min', $aRenfeOut['J1']['status'] === 'live' && $aRenfeOut['J1']['etaSeconds'] === T0 + 120 + 240 && $aRenfeOut['J1']['delaySeconds'] === 240, json_encode($aRenfeOut['J1'] ?? null));
$Expect('el que ya va hacia Zabalburu ha pasado por Ametzola y no sale', !isset($aRenfeOut['J2']));
$Expect('el cancelado sale como cancelado, a su hora', $aRenfeOut['J3']['status'] === 'cancelled' && $aRenfeOut['J3']['etaSeconds'] === T0 + 1200 + 120);
$Expect('el que no está en el directo sigue con el horario', $aRenfeOut['J4']['status'] === 'scheduled');
$Expect('isCancelled: el cancelado sí, los demás no', $MRenfe->isCancelled('J3') && !$MRenfe->isCancelled('J1') && !$MRenfe->isCancelled('J4'));
$Expect('liveTrip: las paradas que le quedan, desde la siguiente, con el retraso', $MRenfe->liveTrip('B', [], 'J1') === [['A', T0 + 240], ['B', T0 + 120 + 240], ['C', T0 + 240 + 240]], json_encode($MRenfe->liveTrip('B', [], 'J1')));
$Expect('liveTrip: un cancelado o sin directo no tiene horas en directo', $MRenfe->liveTrip('B', [], 'J3') === null && $MRenfe->liveTrip('B', [], 'J4') === null);
$aWaiting = [['tripId' => '6005L1C1', 'stops' => [['B', 0]], 'delay' => 120]];
$aRowAtOrigin = ['stop_seq' => 0, 'arrival_seconds' => T0, 'etaSeconds' => T0] + $RenfeRow('J1', 0);
$MBeforeDeparture = new TripDelayMatcher($aWaiting, new ServiceJourney($PdoRenfe), time() - (T0 - 300));
$aAtOrigin = $MBeforeDeparture->enrich('A', [$aRowAtOrigin]);
$Expect('un tren que espera en el origen con retraso (Renfe le da la segunda estación como siguiente) sale en directo', count($aAtOrigin) === 1 && $aAtOrigin[0]['status'] === 'live', json_encode($aAtOrigin));
$Expect('su ficha empieza en la estación de origen', ($MBeforeDeparture->liveTrip('A', [], 'J1')[0][0] ?? null) === 'A');
$MOnPaper = new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['B', 0]], 'delay' => 0]], new ServiceJourney($PdoRenfe), time() - (T0 - 300));
$Expect('uno que aún no ha salido y solo repite su horario (retraso 0) sale con el horario, no en directo', $MOnPaper->enrich('A', [$aRowAtOrigin])[0]['status'] === 'scheduled' && $MOnPaper->liveTrip('A', [], 'J1') === null);
$iPaperMidnight = time() - (T0 - 300);
$MOnPaperTimes = new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['A', $iPaperMidnight + T0], ['B', $iPaperMidnight + T0 + 120]]]], new ServiceJourney($PdoRenfe), $iPaperMidnight);
$Expect('Euskotren: uno que aún no ha salido con sus horas de papel, también con el horario', $MOnPaperTimes->enrich('A', [$aRowAtOrigin])[0]['status'] === 'scheduled');
$aLeft = [['tripId' => '6005L1C1', 'stops' => [['B', 0]], 'delay' => 0]];
$Expect('ya salido del origen, no sale en la estación de origen', (new TripDelayMatcher($aLeft, new ServiceJourney($PdoRenfe), time() - (T0 + 60)))->enrich('A', [$aRowAtOrigin]) === []);
$aLate = (new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['A', 0]], 'delay' => 45]], new ServiceJourney($PdoRenfe), 0))->enrich('B', [$RenfeRow('J1', 0)]);
$Expect('menos de un minuto de retraso cuenta como en hora, pero la hora prevista lo lleva', $aLate[0]['delaySeconds'] === 0 && $aLate[0]['etaSeconds'] === T0 + 120 + 45);
$Expect('sin directo, las salidas no cambian y hasData es falso', !(new TripDelayMatcher([], new ServiceJourney($PdoRenfe), 0))->hasData() && (new TripDelayMatcher([], new ServiceJourney($PdoRenfe), 0))->enrich('B', $aRenfeRows) === $aRenfeRows);

$aMetroTrip = [['tripId' => '6005L1C1', 'stops' => [['B', T0 + 120 + 900], ['C', T0 + 240 + 960]]]];
$aMetro = (new TripDelayMatcher($aMetroTrip, new ServiceJourney($PdoRenfe), 0))->enrich('B', [$RenfeRow('J1', 0)]);
$Expect('Metro: la hora de la estación es la del feed y el retraso, lo que se aparta del horario (15 min)', $aMetro[0]['status'] === 'live' && $aMetro[0]['etaSeconds'] === T0 + 1020 && $aMetro[0]['delaySeconds'] === 900, json_encode($aMetro[0]));
$aMetroAhead = (new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['A', T0 + 300], ['C', T0 + 840]]]], new ServiceJourney($PdoRenfe), 0))->enrich('B', [$RenfeRow('J1', 0)]);
$Expect('Metro: si el feed se salta la estación, la hora de la siguiente menos lo que se tarda hasta ella', $aMetroAhead[0]['etaSeconds'] === T0 + 720, json_encode($aMetroAhead[0]));

// Metro Bilbao manda Urduliz con el id de Gobela («20.0» por «29.0») en los viajes del ramal de Plentzia: el 8 de
// noviembre de 2026, el tren de las 19:32 salía en Areeta «por llegar, +17 min» después de haber pasado en hora.
$aPlentzia = ['19', '20', '21', '22', '28', '29', '30'];
$Expect('FeedStopOrder: la última parada con el id de otra toma el del hueco que queda (Urduliz)', FeedStopOrder::align([['21', 1], ['22', 2], ['28', 3], ['20', 4]], $aPlentzia) === [['21', 1], ['22', 2], ['28', 3], ['29', 4]]);
$Expect('FeedStopOrder: hacia Etxebarri, la segunda', FeedStopOrder::align([['30', 1], ['20', 2], ['28', 3], ['22', 4]], array_reverse($aPlentzia)) === [['30', 1], ['29', 2], ['28', 3], ['22', 4]]);
$Expect('FeedStopOrder: con Gobela de verdad también en el feed, se arregla la que sobra', FeedStopOrder::align([['20', 1], ['21', 2], ['28', 3], ['20', 4]], $aPlentzia) === [['20', 1], ['21', 2], ['28', 3], ['29', 4]]);
$Expect('FeedStopOrder: con dos paradas no se adivina, se quita la que va hacia atrás', FeedStopOrder::fit([['28', 1], ['20', 2]], $aPlentzia) === [['28', 1]]);
$Expect('FeedStopOrder: en orden, todo igual', FeedStopOrder::fit([['21', 1], ['22', 2]], $aPlentzia) === [['21', 1], ['22', 2]]);

$PdoMetroB = new PDO('sqlite::memory:');
$PdoMetroB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoMetroB->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoMetroB->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT)');
$PdoMetroB->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoMetroB->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$PdoMetroB->exec('CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT)');
$PdoMetroB->exec('CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, line_id TEXT)');
$PdoMetroB->exec('CREATE TABLE service_journeys (id TEXT PRIMARY KEY, journey_pattern_id TEXT)');
foreach ($aPlentzia as $iSeq => $sStop) {
    $PdoMetroB->exec("INSERT INTO stops VALUES ('$sStop', 'Estación $sStop')");
    $PdoMetroB->exec("INSERT INTO passing_times VALUES ('JP', $iSeq, '$sStop', " . (T0 + $iSeq * 120) . ', ' . (T0 + $iSeq * 120) . ')');
}
$PdoMetroB->exec("INSERT INTO trip_aliases VALUES ('903232', 'JP')");
$PdoMetroB->exec("INSERT INTO lines VALUES ('MB', 'MB')");
$PdoMetroB->exec("INSERT INTO journey_patterns VALUES ('PP', 'MB')");
$PdoMetroB->exec("INSERT INTO service_journeys VALUES ('JP', 'PP')");
$MetroRow = fn(string $sStop) => ['service_journey_id' => 'JP', 'trip_number' => '903232', 'stop_seq' => array_search($sStop, $aPlentzia, true), 'arrival_seconds' => T0 + array_search($sStop, $aPlentzia, true) * 120, 'status' => 'scheduled', 'etaSeconds' => T0 + array_search($sStop, $aPlentzia, true) * 120, 'delaySeconds' => 0];
// Ya ha pasado por 19, 20 y 21 con minuto y medio de retraso; el feed le quedan 22, 28 y «20» (que es Urduliz).
$iPassedMidnight = time() - (T0 + 330);
$aPassed = [['tripId' => '903232', 'stops' => [['22', $iPassedMidnight + T0 + 360 + 90], ['28', $iPassedMidnight + T0 + 480 + 90], ['20', $iPassedMidnight + T0 + 600 + 90]]]];
$Expect('Metro: un tren que ya pasó por la estación no vuelve a salir «por llegar» por la parada mal etiquetada', (new TripDelayMatcher($aPassed, new ServiceJourney($PdoMetroB), $iPassedMidnight))->enrich('19', [$MetroRow('19')]) === []);
// Aún va hacia Gobela (20): su hora en Gobela es la de Gobela, no la de Urduliz que llega con el mismo id.
$iComingMidnight = time() - (T0 + 60);
$aComing = [['tripId' => '903232', 'stops' => [['20', $iComingMidnight + T0 + 120 + 90], ['21', $iComingMidnight + T0 + 240 + 90], ['22', $iComingMidnight + T0 + 360 + 90], ['28', $iComingMidnight + T0 + 480 + 90], ['20', $iComingMidnight + T0 + 600 + 90]]]];
$aGobela = (new TripDelayMatcher($aComing, new ServiceJourney($PdoMetroB), $iComingMidnight))->enrich('20', [$MetroRow('20')]);
$Expect('Metro: en Gobela, la hora de Gobela (1,5 min de retraso), no la de Urduliz', ($aGobela[0]['etaSeconds'] ?? null) === T0 + 120 + 90 && $aGobela[0]['delaySeconds'] === 90, json_encode($aGobela));
// De madrugada, un tren del horario de ayer anotado a las 26:00 (las 02:00 de hoy) con 3 min de retraso.
$PdoNight = new PDO('sqlite::memory:');
$PdoNight->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoNight->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT)');
$PdoNight->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoNight->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$PdoNight->exec("INSERT INTO stops VALUES ('A', 'Abando'), ('B', 'Ametzola'), ('C', 'Zabalburu')");
foreach (['A', 'B', 'C'] as $iSeq => $sStop) {
    $iArrival = 26 * 3600 + $iSeq * 120;
    $PdoNight->exec("INSERT INTO passing_times VALUES ('N1', $iSeq, '$sStop', $iArrival, $iArrival)");
}
$PdoNight->exec("INSERT INTO trip_aliases VALUES ('noche1', 'N1')");
$iNightMidnight = time() - (2 * 3600 + 60);
$MNight = new TripDelayMatcher([['tripId' => 'noche1', 'stops' => [['B', $iNightMidnight + 2 * 3600 + 120 + 180], ['C', $iNightMidnight + 2 * 3600 + 240 + 180]]]], new ServiceJourney($PdoNight), $iNightMidnight);
$aNightRow = ['service_journey_id' => 'N1', 'trip_number' => 'N1', 'stop_seq' => 1, 'arrival_seconds' => 2 * 3600 + 120, 'status' => 'scheduled', 'etaSeconds' => 2 * 3600 + 120, 'delaySeconds' => 0];
$aNight = $MNight->enrich('B', [$aNightRow]);
$Expect('de madrugada, un tren de ayer anotado a las 26:00 lleva sus 3 min de retraso, no 24 h', ($aNight[0]['status'] ?? null) === 'live' && $aNight[0]['delaySeconds'] === 180 && $aNight[0]['etaSeconds'] === 2 * 3600 + 300, json_encode($aNight));
$Expect('de madrugada, el retraso que se cuenta para vigilar el directo es de 3 min', ($MNight->diagnostics()['delayMinutes']['median'] ?? null) === 3, json_encode($MNight->diagnostics()));
$Expect('de madrugada, la ficha del tren da la hora de hoy, no la de mañana', (array_column($MNight->liveTrip('B', [], 'N1') ?? [], 1, 0)['B'] ?? null) === $iNightMidnight + 2 * 3600 + 300, json_encode($MNight->liveTrip('B', [], 'N1')));
$aGpsOnly = [['tripId' => '6005L2C1', 'lat' => 43.26, 'lon' => -2.93, 'stopId' => 'B', 'stopStatus' => null, 'vehicleId' => '513']];
$MGps = new TripDelayMatcher([], new ServiceJourney($PdoRenfe), time() - (T0 + 720 + 300), $aGpsOnly);
$aGpsRow = $MGps->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 840, 'etaSeconds' => T0 + 840] + $RenfeRow('J2', 1)]);
$Expect('Metro: un tren con GPS y sin hora prevista va en directo, con lo que va tarde en su estación (5 min)', $aGpsRow[0]['status'] === 'live' && $aGpsRow[0]['etaSeconds'] === T0 + 1140 && $aGpsRow[0]['delaySeconds'] === 300, json_encode($aGpsRow[0]));
$aGpsPassed = $MGps->enrich('A', [['stop_seq' => 0, 'arrival_seconds' => T0 + 600, 'etaSeconds' => T0 + 600] + $RenfeRow('J2', 1)]);
$Expect('Metro: con GPS, una estación que ya ha dejado atrás no sale', $aGpsPassed === [], json_encode($aGpsPassed));
$MGpsEnd = new TripDelayMatcher([], new ServiceJourney($PdoRenfe), time() - (T0 + 840 + 1800), [['stopId' => 'C'] + $aGpsOnly[0]]);
$Expect('con GPS en la última parada de su viaje, ya ha terminado: no cuenta', !$MGpsEnd->hasData());
$MGpsParked = new TripDelayMatcher([], new ServiceJourney($PdoRenfe), time() - (T0 + 720 + 3000), $aGpsOnly);
$Expect('con GPS y más de 45 min de retraso (parado o con el viaje mal asignado), no cuenta', !$MGpsParked->hasData());
$iStuckMidnight = time() - (T0 + 150 + 300);
$MStuck = new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['B', $iStuckMidnight + T0 + 150], ['C', $iStuckMidnight + T0 + 270]]]], new ServiceJourney($PdoRenfe), $iStuckMidnight);
$aStuck = $MStuck->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 240, 'etaSeconds' => T0 + 240] + $RenfeRow('J1', 0)]);
$Expect('un tren que no ha llegado a su siguiente estación pasada su hora prevista va más tarde: se suma lo que lleva de más (5 min)', $aStuck[0]['etaSeconds'] === T0 + 570 && $aStuck[0]['delaySeconds'] === 330, json_encode($aStuck[0]));
$aStuckNext = $MStuck->enrich('B', [$RenfeRow('J1', 0)]);
$Expect('en la estación a la que va, llega ya (no desaparece)', count($aStuckNext) === 1 && $aStuckNext[0]['etaSeconds'] === T0 + 450, json_encode($aStuckNext));
$iCopyMidnight = time() - (T0 + 200);
$MCopy = new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['B', $iCopyMidnight + T0 + 120], ['C', $iCopyMidnight + T0 + 240]]]], new ServiceJourney($PdoRenfe), $iCopyMidnight);
$aCopy = $MCopy->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 240, 'etaSeconds' => T0 + 240] + $RenfeRow('J1', 0)]);
$Expect('Euskotren: un feed cuyas horas son las del horario al segundo no es directo: sale con el horario', $aCopy[0]['status'] === 'scheduled' && !$MCopy->hasData() && $MCopy->matchedJourneys() === 1, json_encode($aCopy[0]));
$aStuckRenfe = (new TripDelayMatcher([['tripId' => '6005L1C1', 'stops' => [['B', 0]], 'delay' => 60]], new ServiceJourney($PdoRenfe), time() - (T0 + 120 + 360)))->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 240, 'etaSeconds' => T0 + 240] + $RenfeRow('J1', 0)]);
$Expect('Renfe: con su retraso ya sobrepasado en la siguiente estación, también', $aStuckRenfe[0]['delaySeconds'] === 360, json_encode($aStuckRenfe[0]));
$iOffMidnight = time() - (T0 + 1020);
$aOffTrips = [];
$aOffVehicles = [];
foreach (['6005L1C1', '6005L2C1', '6005L4C1'] as $sOffTrip) {
    $aOffTrips[] = ['tripId' => $sOffTrip, 'stops' => [['B', $iOffMidnight + T0 + 1020 + 3600], ['C', $iOffMidnight + T0 + 1140 + 3600]]];
    $aOffVehicles[] = ['tripId' => $sOffTrip, 'lat' => 42.85, 'lon' => -2.67, 'stopId' => 'B', 'stopStatus' => 'arriving', 'vehicleId' => $sOffTrip];
}
$aOffRow = (new TripDelayMatcher($aOffTrips, new ServiceJourney($PdoRenfe), $iOffMidnight, $aOffVehicles))->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 840, 'etaSeconds' => T0 + 840] + $RenfeRow('J2', 1)]);
$Expect('Álavabus: si las horas del feed no cuadran con dónde están los autobuses (una hora de más), se usa el GPS', $aOffRow[0]['status'] === 'live' && $aOffRow[0]['delaySeconds'] === 300, json_encode($aOffRow[0]));
$aOnTrips = array_map(fn($aTrip) => ['tripId' => $aTrip['tripId'], 'stops' => [['B', $iOffMidnight + T0 + 1050], ['C', $iOffMidnight + T0 + 1170]]], $aOffTrips);
$aOnRow = (new TripDelayMatcher($aOnTrips, new ServiceJourney($PdoRenfe), $iOffMidnight, $aOffVehicles))->enrich('C', [['stop_seq' => 2, 'arrival_seconds' => T0 + 840, 'etaSeconds' => T0 + 840] + $RenfeRow('J2', 1)]);
$Expect('si cuadran, mandan las horas del feed', $aOnRow[0]['etaSeconds'] === T0 + 1170, json_encode($aOnRow[0]));
// Álavabus escribe la hora de aquí como si fuera UTC, pero no en todos sus viajes: con gtfs_rt.local_clock cada viaje
// se lee de las dos maneras y vale la que más se acerca a su horario.
$iZone = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->getOffset();
$aAheadTrip = [['tripId' => '6005L2C1', 'stops' => [['B', $iOffMidnight + T0 + 1050 + $iZone], ['C', $iOffMidnight + T0 + 1170 + $iZone]]]];
$aRowC = [['stop_seq' => 2, 'arrival_seconds' => T0 + 840, 'etaSeconds' => T0 + 840] + $RenfeRow('J2', 1)];
\Core\Config::set('alavabus');
$MClock = new TripDelayMatcher($aAheadTrip, new ServiceJourney($PdoRenfe), $iOffMidnight);
$Expect('Álavabus: sus horas adelantadas lo que Madrid se aparta de UTC se corrigen, con un solo autobús y sin GPS', $MClock->enrich('C', $aRowC)[0]['etaSeconds'] === T0 + 1170 && $MClock->diagnostics()['clockShifted'] === 1, json_encode($MClock->enrich('C', $aRowC)[0]));
$aRightRow = (new TripDelayMatcher([$aOnTrips[1]], new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
$Expect('Álavabus: un viaje cuyas horas ya cuadran con el horario no se corrige', $aRightRow[0]['etaSeconds'] === T0 + 1170, json_encode($aRightRow[0]));
$aYesterdayTrip = [['startDate' => date('Ymd', strtotime('-2 days'))] + $aOnTrips[1]];
$Expect('Álavabus: un viaje de otro día que sigue en el feed no vale (sale con el horario)', (new TripDelayMatcher($aYesterdayTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['status'] === 'scheduled');
$aTodayTrip = [['startDate' => date('Ymd', $iOffMidnight + 12 * 3600)] + $aOnTrips[1]];
$Expect('el viaje de hoy sí vale', (new TripDelayMatcher($aTodayTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['etaSeconds'] === T0 + 1170);
// Donde el autobús manda su posición manda el GPS, aunque el feed traiga horas: 30 min de más en el feed, el GPS
// dice 5.
$aBadTimes = [['tripId' => '6005L2C1', 'stops' => [['B', $iOffMidnight + T0 + 1020 + 1800], ['C', $iOffMidnight + T0 + 1140 + 1800]]]];
$aGpsRow = (new TripDelayMatcher($aBadTimes, new ServiceJourney($PdoRenfe), $iOffMidnight, [$aOffVehicles[1]]))->enrich('C', $aRowC);
$Expect('Álavabus: con la posición del autobús, su retraso sale del GPS y no de las horas del feed', $aGpsRow[0]['status'] === 'live' && $aGpsRow[0]['delaySeconds'] === 300, json_encode($aGpsRow[0]));
// Dónde está el autobús por su GPS: tres paradas en línea recta (A 08:00, B 08:10, C 08:20), cada una a 1 km.
$aLine = [['stop_id' => 'A', 'arrival_seconds' => 8 * 3600], ['stop_id' => 'B', 'arrival_seconds' => 8 * 3600 + 600], ['stop_id' => 'C', 'arrival_seconds' => 8 * 3600 + 1200]];
$aLineCoords = ['A' => [43.0, -2.9], 'B' => [43.009, -2.9], 'C' => [43.018, -2.9]];
$iPastB = Services\RoutePosition::scheduledSeconds($aLine, 1, $aLineCoords, 43.0135, -2.9);
$Expect('posición GPS: el feed da B pero el bus va por la mitad de B a C (B ya quedó atrás): 08:15', abs($iPastB - (8 * 3600 + 900)) <= 5, (string)$iPastB);
$iBeforeB = Services\RoutePosition::scheduledSeconds($aLine, 1, $aLineCoords, 43.0045, -2.9);
$Expect('posición GPS: el feed da B y el bus va por la mitad de A a B: 08:05', abs($iBeforeB - (8 * 3600 + 300)) <= 5, (string)$iBeforeB);
$Expect('posición GPS: sin coordenadas de las paradas, no hay posición', Services\RoutePosition::scheduledSeconds($aLine, 1, [], 43.0, -2.9) === null);
$aOtherTrip = [['tripId' => '6005L2C1', 'stops' => [['B', $iOffMidnight + T0 + 1050 + 5 * 3600], ['C', $iOffMidnight + T0 + 1170 + 5 * 3600]]]];
$Expect('Álavabus: unas horas a 5 h de su horario son de otro viaje: sale con el horario', (new TripDelayMatcher($aOtherTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['status'] === 'scheduled');
// Álavabus con las paradas en línea recta, a 1 km: A a T0, B a T0 + 10 min, C a T0 + 20 min.
$PdoAlava = new PDO('sqlite::memory:');
$PdoAlava->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoAlava->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT, lat REAL, lon REAL)');
$PdoAlava->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoAlava->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$PdoAlava->exec("INSERT INTO stops VALUES ('A', 'Aretxabaleta', 43.0, -2.9), ('B', 'Betoño', 43.009, -2.9), ('C', 'Castillo', 43.018, -2.9)");
foreach (['A', 'B', 'C'] as $iSeq => $sStop) {
    $iArrival = T0 + $iSeq * 600;
    $PdoAlava->exec("INSERT INTO passing_times VALUES ('JA', $iSeq, '$sStop', $iArrival, $iArrival)");
}
$PdoAlava->exec("INSERT INTO trip_aliases VALUES ('22123', 'JA')");
$AlavaRow = fn(string $sStop, int $iSeq) => ['service_journey_id' => 'JA', 'trip_number' => '22123', 'stop_seq' => $iSeq, 'arrival_seconds' => T0 + $iSeq * 600, 'status' => 'scheduled', 'etaSeconds' => T0 + $iSeq * 600, 'delaySeconds' => 0];
// El feed da B, la que acaba de dejar atrás, y el GPS lo pone a mitad de B a C (T0 + 15 min) 5 min tarde.
$iBeyondMidnight = time() - (T0 + 900 + 300);
$aBeyondBus = [['tripId' => '22123', 'lat' => 43.0135, 'lon' => -2.9, 'stopId' => 'B', 'stopStatus' => null, 'vehicleId' => '92']];
$MBeyond = new TripDelayMatcher([], new ServiceJourney($PdoAlava), $iBeyondMidnight, $aBeyondBus);
$aBeyondC = $MBeyond->enrich('C', [$AlavaRow('C', 2)]);
$Expect('Álavabus: con el GPS pasada la parada que da el feed, en la siguiente lleva sus 5 min, no lo que va desde la que dejó atrás', ($aBeyondC[0]['delaySeconds'] ?? null) === 300 && ($MBeyond->diagnostics()['delayMinutes']['max'] ?? null) === 5, json_encode([$aBeyondC, $MBeyond->diagnostics()]));
$Expect('Álavabus: y la parada que ya dejó atrás no sale', $MBeyond->enrich('B', [$AlavaRow('B', 1)]) === []);
// Un autobús que lleva el número de otro viaje: su GPS da 50 min; sus horas del feed, 20 min (40 con lo que lleva
// de más).
$iWrongMidnight = time() - (T0 + 600 + 3000);
$aWrongBus = [['tripId' => '22123', 'lat' => 43.009, 'lon' => -2.9, 'stopId' => 'B', 'stopStatus' => null, 'vehicleId' => '94']];
$aWrongTimes = [['tripId' => '22123', 'stops' => [['C', $iWrongMidnight + T0 + 1200 + 1200]]]];
$aWrongC = (new TripDelayMatcher($aWrongTimes, new ServiceJourney($PdoAlava), $iWrongMidnight, $aWrongBus))->enrich('C', [$AlavaRow('C', 2)]);
$Expect('Álavabus: si el GPS dice que el autobús es de otro viaje, tampoco valen sus horas del feed: sale con el horario', ($aWrongC[0]['status'] ?? null) === 'scheduled', json_encode($aWrongC));
$aRightC = (new TripDelayMatcher($aWrongTimes, new ServiceJourney($PdoAlava), $iWrongMidnight))->enrich('C', [$AlavaRow('C', 2)]);
$Expect('sin GPS, las mismas horas sí valen (40 min)', ($aRightC[0]['status'] ?? null) === 'live' && $aRightC[0]['delaySeconds'] === 2400, json_encode($aRightC));
// Sin GPS, el feed lo pone en B 25 min tarde y ya han pasado otros 25: se enseñarían 50 min, más del tope de 45.
$iStaleMidnight = time() - (T0 + 600 + 1500 + 1500);
$aStaleTimes = [['tripId' => '22123', 'stops' => [['B', $iStaleMidnight + T0 + 600 + 1500], ['C', $iStaleMidnight + T0 + 1200 + 1500]]]];
$MStale = new TripDelayMatcher($aStaleTimes, new ServiceJourney($PdoAlava), $iStaleMidnight);
$Expect('Álavabus: un retraso que con lo que lleva de más pasa de 45 min no vale: sale con el horario', !$MStale->hasData() && ($MStale->enrich('C', [$AlavaRow('C', 2)])[0]['status'] ?? null) === 'scheduled', json_encode($MStale->diagnostics()));
\Core\Config::set('renfe');
$Expect('en las demás redes no hay tope: un tren con más de 2 h de retraso sale con su retraso', (new TripDelayMatcher($aOtherTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['status'] === 'live');
$Expect('sin local_clock en su ficha, las horas del feed no se corrigen', (new TripDelayMatcher($aAheadTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['etaSeconds'] === T0 + 1170 + $iZone);
\Core\Config::set('metro');
// Las horas del feed, de verdad (marcas de tiempo del día de la prueba).
$MetroTripAt = fn(int $iMidnight) => [['tripId' => '6005L1C1', 'stops' => [['B', $iMidnight + T0 + 120 + 900], ['C', $iMidnight + T0 + 240 + 960]]]];
$MMetroNow = new TripDelayMatcher($MetroTripAt(time() - (T0 + 1950)), new ServiceJourney($PdoRenfe), time() - (T0 + 1950));
$Expect('Metro: un tren que ya debería estar en la estación y no está en el feed sale al final, sin datos', $MMetroNow->enrich('B', [$RenfeRow('J4', 3)])[0]['status'] === 'likely_departed');
$MMetroEarly = new TripDelayMatcher($MetroTripAt(time() - T0), new ServiceJourney($PdoRenfe), time() - T0);
$Expect('Metro: uno que aún no toca y no está en el feed sigue con el horario', $MMetroEarly->enrich('B', [$RenfeRow('J4', 3)])[0]['status'] === 'scheduled');
\Core\Config::set('bus');
$Expect('en los autobuses, el que no está en el directo sigue con el horario aunque toque ya', (new TripDelayMatcher($MetroTripAt(time() - (T0 + 1950)), new ServiceJourney($PdoRenfe), time() - (T0 + 1950)))->enrich('B', [$RenfeRow('J4', 3)])[0]['status'] === 'scheduled');

$aCancelAlerts = CancelledTrains::alerts($MRenfe, new ServiceJourney($PdoRenfe), T0 + 1200 + 60);
$Expect('un tren cancelado es una incidencia: línea, hora de salida, origen y destino', count($aCancelAlerts) === 1 && $aCancelAlerts[0]['summary'] === 'C1 · tren de las 08:20 cancelado' && $aCancelAlerts[0]['description'] === 'Renfe ha cancelado el tren de las 08:20 de Abando a Zabalburu.' && $aCancelAlerts[0]['lineRefs'] === ['C1-ida'], json_encode($aCancelAlerts));
$Expect('la incidencia sale en las estaciones por las que aún tenía que pasar', $aCancelAlerts[0]['stopRefs'] === ['B', 'C'] && $aCancelAlerts[0]['scope'] === 'stop', json_encode($aCancelAlerts[0]['stopRefs'] ?? null));
$Expect('pasada la hora de su última estación, no sale', CancelledTrains::alerts($MRenfe, new ServiceJourney($PdoRenfe), T0 + 1200 + 241) === []);
$Expect('sin cancelados no hay incidencias', CancelledTrains::alerts(new TripDelayMatcher([], new ServiceJourney($PdoRenfe), 0), new ServiceJourney($PdoRenfe), T0) === []);

$aRepeated = array_fill(0, 70, ['title' => 'ASCENSOR AVERIADO', 'description' => 'EXTERIOR:SANTUTXU', 'severity' => 'normal']);
$aRepeated[] = ['summary' => 'Desvío', 'description' => '', 'lineRefs' => ['A1']];
$aRepeated[] = ['summary' => 'Desvío', 'description' => '', 'lineRefs' => ['A2']];
$Expect('el mismo aviso repetido (SIRI de Metro) sale una vez; con otras líneas, no es el mismo', \Controllers\AlertsController::withoutRepeats($aRepeated) === [$aRepeated[0], $aRepeated[70], $aRepeated[71]]);

$AlertsFeed = fn(int $iGenerated) => json_encode(['header' => ['timestamp' => (string)$iGenerated], 'entity' => [['alert' => [
    'informedEntity' => [['stopId' => 'A']],
    'descriptionText' => ['translation' => [['language' => 'es', 'text' => 'ABANDO: Ascensor fuera de servicio.']]],
]]]]);
$Expect('avisos de Renfe recientes: salen', count(RenfeAlertsClient::parse($AlertsFeed(time() - 600), ['A' => 'Abando'], 2 * 86400)) === 1);
$Expect('avisos de Renfe de hace días (el fichero dejó de actualizarse): no salen', RenfeAlertsClient::parse($AlertsFeed(time() - 26 * 86400), ['A' => 'Abando'], 2 * 86400) === []);

/* ------------------------------------------------------------------ */
$Section('Posiciones GPS de Renfe y Metro');

$sVpFeed = pbField(1, pbField(1, '2.0') . pbField(3, 5000))
    . pbField(2, pbField(1, 'v1') . pbField(4, pbField(1, pbField(1, 'R1')) . pbField(2, pbFloat(1, 43.25) . pbFloat(2, -2.94)) . pbField(4, 1) . pbField(7, 'B') . pbField(8, pbField(1, '26175') . pbField(2, 'C1-26175'))))
    . pbField(2, pbField(1, 'v2') . pbField(4, pbField(1, pbField(1, '892031')) . pbField(2, pbFloat(1, 43.2543) . pbFloat(2, -2.9133)) . pbField(7, '5.0')))
    . pbField(2, pbField(1, 'v3') . pbField(4, pbField(1, pbField(1, 'sin-gps')) . pbField(7, 'B')));
$aVp = GtfsRealtimeClient::parseVehiclePositions($sVpFeed);
$aVp1 = $aVp['vehicles'][0] ?? [];
$Expect('posiciones: viaje, GPS, estación, si está parado en ella y número del tren', count($aVp['vehicles']) === 2 && $aVp1['tripId'] === 'R1' && abs($aVp1['lat'] - 43.25) < 0.0001 && abs($aVp1['lon'] + 2.94) < 0.0001 && $aVp1['stopStatus'] === 'at' && $aVp1['stopId'] === 'B' && $aVp1['vehicleId'] === '26175', json_encode($aVp));
$Expect('si no dice si está parado (Metro), no se inventa; «5.0» es la estación 5; sin GPS no sale', $aVp['vehicles'][1]['stopStatus'] === null && $aVp['vehicles'][1]['stopId'] === '5' && $aVp['generatedAt'] === 5000);

$PdoMap = new PDO('sqlite::memory:');
$PdoMap->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$PdoMap->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoMap->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT, lat REAL, lon REAL)');
$PdoMap->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoMap->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$PdoMap->exec('CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT)');
$PdoMap->exec('CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, line_id TEXT)');
$PdoMap->exec('CREATE TABLE service_journeys (id TEXT PRIMARY KEY, journey_pattern_id TEXT)');
$PdoMap->exec("INSERT INTO stops VALUES ('A', 'Abando', 43.26, -2.93), ('B', 'Ametzola', 43.25, -2.94), ('C', 'Santurtzi', 43.33, -3.03), ('5', 'Santutxu', 43.254, -2.913), ('6', 'Basauri', 43.236, -2.886)");
foreach ([['J1', ['A', 'B', 'C']], ['J2', ['C', 'B', 'A']]] as [$sJourney, $aJourneyStops]) {
    foreach ($aJourneyStops as $iSeq => $sStop) {
        $PdoMap->exec("INSERT INTO passing_times VALUES ('$sJourney', $iSeq, '$sStop', " . (T0 + $iSeq * 120) . ', ' . (T0 + $iSeq * 120) . ')');
    }
}
$PdoMap->exec("INSERT INTO trip_aliases VALUES ('R1', 'J1'), ('R2', 'J2')");
$PdoMap->exec("INSERT INTO lines VALUES ('C1-ida', 'C1'), ('C1-vuelta', 'C1')");
$PdoMap->exec("INSERT INTO journey_patterns VALUES ('P1', 'C1-ida'), ('P2', 'C1-vuelta')");
$PdoMap->exec("INSERT INTO service_journeys VALUES ('J1', 'P1'), ('J2', 'P2')");

/* ------------------------------------------------------------------ */
$Section('Directo por viaje con retraso por parada (Tuvisa, Dbus) e incidencias GTFS-Realtime');

$PbDelayStop = fn(string $sStopId, int $iDelay) => pbField(2, pbField(4, $sStopId) . pbField(2, pbField(1, $iDelay)));
$sDelayFeed = pbField(1, pbField(1, '2.0') . pbField(3, 5000))
    . pbField(2, pbField(1, 't1') . pbField(3, pbField(1, pbField(1, 'R1')) . $PbDelayStop('B', 90) . $PbDelayStop('C', 150)))
    . pbField(2, pbField(1, 't2') . pbField(3, pbField(1, pbField(1, 'R2')) . pbField(2, pbField(4, 'A'))));
$aDelayTrips = GtfsRealtimeClient::parseTripUpdates($sDelayFeed)['trips'];
$Expect('parada solo con retraso: el de la primera vale para el viaje y es la parada a la que va', ($aDelayTrips[0] ?? null) === ['tripId' => 'R1', 'stops' => [], 'delay' => 90, 'nextStopId' => 'B'], json_encode($aDelayTrips));
$Expect('una parada sin hora ni retraso no cuenta', count($aDelayTrips) === 1);
$aDelayOut = (new TripDelayMatcher($aDelayTrips, new ServiceJourney($PdoMap), 0))->enrich('C', [$RenfeRow('J1', 0)]);
$Expect('el retraso de la parada se suma en las que le quedan al autobús', $aDelayOut[0]['status'] === 'live' && $aDelayOut[0]['delaySeconds'] === 90 && $aDelayOut[0]['etaSeconds'] === T0 + 120 + 90, json_encode($aDelayOut));
$aRowAtA = ['stop_seq' => 0, 'arrival_seconds' => T0, 'etaSeconds' => T0] + $RenfeRow('J1', 0);
$Expect('con la parada siguiente después de la nuestra, ya ha pasado y no sale', (new TripDelayMatcher($aDelayTrips, new ServiceJourney($PdoMap), 0))->enrich('A', [$aRowAtA]) === []);

$PbText = fn(string $sText, string $sLanguage) => pbField(1, pbField(1, $sText) . pbField(2, $sLanguage));
$sAlertsPb = pbField(1, pbField(1, '2.0') . pbField(3, 5000))
    . pbField(2, pbField(1, 'a1') . pbField(5, pbField(1, pbField(1, 1000)) . pbField(5, pbField(5, '1325')) . pbField(10, $PbText('Geltokia mugituta', 'eu') . $PbText('Parada trasladada', 'es-ES')) . pbField(11, $PbText('Por obras', 'es-ES'))))
    . pbField(2, pbField(1, 'a2') . pbField(5, pbField(1, pbField(1, 1000) . pbField(2, 2000)) . pbField(5, pbField(2, 'L3')) . pbField(10, $PbText('Desvío', 'es'))))
    . pbField(2, pbField(1, 'a3') . pbField(5, pbField(10, $PbText('', 'es'))));
$aParsedAlerts = GtfsRealtimeClient::parseAlerts($sAlertsPb)['alerts'];
$Expect('incidencias: el titular y el texto en castellano, y la parada o la línea a la que afectan', count($aParsedAlerts) === 2 && $aParsedAlerts[0]['summary'] === 'Parada trasladada' && $aParsedAlerts[0]['description'] === 'Por obras' && $aParsedAlerts[0]['stopRefs'] === ['1325'] && $aParsedAlerts[1]['lineRefs'] === ['L3'], json_encode($aParsedAlerts));
$Expect('solo las vigentes: la que ya acabó no sale', array_column(GtfsRealtimeClient::activeAlerts($aParsedAlerts, 3000), 'summary') === ['Parada trasladada'] && count(GtfsRealtimeClient::activeAlerts($aParsedAlerts, 1500)) === 2);

$Stale = new ReflectionMethod(GtfsRealtimeClient::class, 'stale');
$aOldFeed = ['generatedAt' => time() - 3600];
$Expect('un feed con la hora atrasada una hora es viejo sin desfase', $Stale->invoke(null, $aOldFeed, ['max_age_seconds' => 300]) === true);
$Expect('con clock_offset_seconds la hora corregida lo da por fresco', $Stale->invoke(null, $aOldFeed, ['max_age_seconds' => 300, 'clock_offset_seconds' => 3600]) === false);
$Expect('con clock_offset_seconds un feed realmente viejo sigue siéndolo', $Stale->invoke(null, ['generatedAt' => time() - 9000], ['max_age_seconds' => 300, 'clock_offset_seconds' => 3600]) === true);

$sToday = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
$sOldDay = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->modify('-3 day')->format('Y-m-d');
$aDated = [['tripId' => '123' . $sToday], ['tripId' => '123' . $sOldDay], ['tripId' => 'sinFecha']];
$OfServiceDay = new ReflectionMethod(GtfsRealtimeClient::class, 'ofServiceDay');
$WithSuffix = new GtfsRealtimeClient(['gtfs_rt' => ['trip_date_suffix' => true]]);
$Expect('trip_date_suffix deja los viajes de hoy y los sin fecha', array_column($OfServiceDay->invoke($WithSuffix, $aDated), 'tripId') === ['123' . $sToday, 'sinFecha']);
$Expect('sin trip_date_suffix no se filtra nada', count($OfServiceDay->invoke(new GtfsRealtimeClient(['gtfs_rt' => []]), $aDated)) === 3);

// gtfs_rt.keeps_passed_stops (Txik Txak): el feed sigue dando las paradas ya pasadas.
$NextFeedStop = new ReflectionMethod(\Services\TripDelayMatcher::class, 'nextFeedStop');
$Finished = new ReflectionMethod(\Services\TripDelayMatcher::class, 'finished');
$MatcherShell = (new ReflectionClass(\Services\TripDelayMatcher::class))->newInstanceWithoutConstructor();
$iShellMidnight = (new DateTime('today', new DateTimeZone('Europe/Madrid')))->getTimestamp();
(new ReflectionProperty(\Services\TripDelayMatcher::class, 'iMidnight'))->setValue($MatcherShell, $iShellMidnight);
$iShellNow = time() - $iShellMidnight;
$aEnRoute = [['A', time() - 900], ['B', time() - 60], ['C', time() + 240], ['D', time() + 600]];
$aLiveEnRoute = ['times' => ['A' => $iShellNow - 900, 'C' => $iShellNow + 240], 'shift' => 0];
$aLiveEnded = ['times' => ['A' => $iShellNow - 1800, 'B' => $iShellNow - 900], 'shift' => 0];
\Core\Config::set('txiktxak');
$Expect('keeps_passed_stops: la siguiente parada es la primera con hora por venir', $NextFeedStop->invoke(null, $aEnRoute) === 'C');
$Expect('keeps_passed_stops: un viaje con todas sus horas pasadas ha terminado', $Finished->invoke($MatcherShell, $aLiveEnded) && !$Finished->invoke($MatcherShell, $aLiveEnRoute));
\Core\Config::set('metro');
$Expect('sin keeps_passed_stops la siguiente parada es la primera del feed y ningún viaje se descarta', $NextFeedStop->invoke(null, $aEnRoute) === 'A' && !$Finished->invoke($MatcherShell, $aLiveEnded));
\Core\Config::set('bus');


/* ------------------------------------------------------------------ */
$Section('Salidas de una parada: orden y filas repetidas');
// Orden de las salidas: un bus que probablemente ya pasó (estimación) va al final aunque su hora oficial sea anterior.
$BuildItems = new ReflectionMethod(Controllers\StopsController::class, 'departureItems');
$iNowItems = Calendar::nowSecondsSinceMidnight();
$MakeItem = fn(string $sTrip, string $sStatus, int $iEta) => ['line_id' => '3514', 'line_code' => 'A3514', 'line_name' => 'x', 'headsign' => 'GERNIKA', 'last_stop_name' => null,
    'trip_number' => $sTrip, 'first_departure_seconds' => 0, 'arrival_seconds' => $iEta, 'etaSeconds' => $iEta, 'status' => $sStatus, 'delaySeconds' => 0];
$aItems = $BuildItems->invoke(null, [$MakeItem('A', 'likely_departed', $iNowItems + 600), $MakeItem('B', 'estimated', $iNowItems + 1200), $MakeItem('C', 'live', $iNowItems + 900)], false);
$Expect('el que probablemente ya pasó va el último; los demás por hora', array_column($aItems, 'status') === ['live', 'estimated', 'likely_departed'], json_encode(array_column($aItems, 'status')));
$aPassedItem = $BuildItems->invoke(null, [['likelyPassedSeconds' => 11 * 3600 + 58 * 60] + $MakeItem('A', 'likely_departed', $iNowItems + 600)], false)[0];
$Expect('el que puede haber pasado lleva la hora a la que el algoritmo calcula que pasó; los demás, ninguna', $aPassedItem['passedTime'] === '11:58' && $aItems[0]['passedTime'] === null, (string)$aPassedItem['passedTime']);
$Limited = new ReflectionMethod(Controllers\StopsController::class, 'limited');
$aMany = [];
foreach (range(1, 6) as $iItem) {
    $aMany[] = $MakeItem('L' . $iItem, 'live', $iNowItems + $iItem * 300);
}
$aMany[] = $MakeItem('P1', 'likely_departed', $iNowItems + 60);
$aMany[] = $MakeItem('P2', 'likely_departed', $iNowItems + 90);
$aMany[] = $MakeItem('P3', 'likely_departed', $iNowItems + 120);
$aShown = array_column($Limited->invoke(null, $BuildItems->invoke(null, $aMany, false), 5), 'status');
$Expect('el límite no deja fuera a los que pueden haber pasado: las 5 que vienen y, detrás, dos de ellos', $aShown === ['live', 'live', 'live', 'live', 'live', 'likely_departed', 'likely_departed'], json_encode($aShown));

// Filas repetidas: mismo número de viaje en dos calendarios se junta; dos viajes distintos a la vez por la parada, no.
$Dedupe = new ReflectionMethod(ServiceJourney::class, 'dedupeByTrip');
$aDedupeRows = [
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60180, 'first_departure_seconds' => 58500],
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60180, 'first_departure_seconds' => 58500],
    ['line_id' => '3514', 'trip_number' => '909', 'departure_seconds' => 60249, 'first_departure_seconds' => 56700],
];
$aDeduped = $Dedupe->invoke(new ServiceJourney(buildFixture(40)), $aDedupeRows, 10);
$Expect('el mismo viaje en dos calendarios sale una vez; otro viaje del mismo número un minuto después también sale', \count($aDeduped) === 2 && $aDeduped[1]['first_departure_seconds'] === 56700, (string)\count($aDeduped));

// De madrugada, las salidas de ayer (26:00, en la lista como 02:00) pasan por el emparejador de ayer en las horas de su
// horario y vuelven en las de hoy; las de hoy, por el de hoy. Sin salidas de ayer, el de ayer ni se crea.
$WithEngine = new ReflectionMethod(Controllers\StopsController::class, 'withEngine');
$FakeMatcher = fn(string $sWho) => new class ($sWho) {
    public array $aSeen = [];
    public function __construct(private string $sWho)
    {
    }
    public function enrich(array $aRows): array
    {
        $this->aSeen = array_merge($this->aSeen, array_column($aRows, 'arrival_seconds'));
        return array_map(fn(array $aRow) => $aRow + ['etaSeconds' => (int)$aRow['arrival_seconds'] + 60, 'who' => $this->sWho], $aRows);
    }
};
$TodayFake = $FakeMatcher('hoy');
$YesterdayFake = $FakeMatcher('ayer');
$iYesterdayCalls = 0;
$Yesterday = function () use ($YesterdayFake, &$iYesterdayCalls) {
    $iYesterdayCalls++;
    return $YesterdayFake;
};
$aMixed = $WithEngine->invoke(null, [$TodayFake, $Yesterday], [
    ['trip_number' => 'N', 'yesterday' => true, 'arrival_seconds' => 7200, 'departure_seconds' => 7200],
    ['trip_number' => 'D', 'arrival_seconds' => 7500, 'departure_seconds' => 7500],
]);
$Expect('de madrugada, la de ayer va con el de ayer a las 26:00 y vuelve a las 02:00; la de hoy, con el de hoy y en orden',
    array_column($aMixed, 'who') === ['ayer', 'hoy'] && $YesterdayFake->aSeen === [7200 + 86400] && $TodayFake->aSeen === [7500]
    && $aMixed[0]['arrival_seconds'] === 7200 && $aMixed[0]['etaSeconds'] === 7260 && $aMixed[0]['departure_seconds'] === 7200,
    json_encode($aMixed));
$aDayRows = [['trip_number' => 'D', 'arrival_seconds' => 50000, 'departure_seconds' => 50000]];
$iYesterdayCalls = 0;
$Expect('de día, todo con el de hoy y sin crear el de ayer', $WithEngine->invoke(null, [$TodayFake, $Yesterday], $aDayRows) === $TodayFake->enrich($aDayRows) && $iYesterdayCalls === 0);

/* ------------------------------------------------------------------ */
$Section('NewBadge: «Nuevo» unos días (new_days de Config/site.php)');

$iDays = Core\NewBadge::days();
$Since = new DateTime('2026-11-02');
$Expect('el primer día, nueva', Core\NewBadge::isNew('2026-11-02', clone $Since));
$Expect("el día $iDays, todavía nueva", Core\NewBadge::isNew('2026-11-02', (clone $Since)->modify('+' . ($iDays - 1) . ' days')));
$Expect('el día siguiente, ya no', !Core\NewBadge::isNew('2026-11-02', (clone $Since)->modify("+$iDays days")));
$Expect('sin fecha o con una que no es fecha, no', !Core\NewBadge::isNew(null) && !Core\NewBadge::isNew('pronto'));
$Expect('una red sin «added» no es nueva', !Core\Networks::isNew(['slug' => 'x']));

$aUncited = array_keys(array_filter(\Core\Networks::all(), fn($aNet) => in_array('trip-updates', $aNet['live'], true) && empty($aNet['texts']['es']['liveSource'])));
$Expect('cada red con horas previstas en directo cita a quien las publica (texts.liveSource)', empty($aUncited));

$Finish('unitarias');
