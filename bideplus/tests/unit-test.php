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
// La accesibilidad de cada red (accessibility), con sus valores; build-packs del motor la aplica a su paquete.
$aAccessibilityValues = ['fleet' => ['all', 'some', 'unknown'], 'stops' => ['all', 'some', 'unknown'],
    'gtfs' => ['trust', 'ignore'], 'unknown_stops_with_accessible_fleet' => ['yes', 'no']];
$aBadAccessibility = array_keys(array_filter(\Core\Networks::all(), fn($aNet) => array_filter($aAccessibilityValues,
    fn($aAllowed, $sKey) => !in_array($aNet['accessibility'][$sKey] ?? null, $aAllowed, true), ARRAY_FILTER_USE_BOTH) !== []
    || !array_key_exists('source', $aNet['accessibility']) || !is_string($aNet['accessibility']['note'] ?? null)));
$Expect('cada red dice su accesibilidad (flota, paradas, qué hacer con el GTFS, fuente y nota)', $aBadAccessibility === [],
    implode(', ', $aBadAccessibility));
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
$Expect('de unos viajes, circulan hoy los de un calendario de hoy', $JourneyVar->runningOf(['A_OTHER_DAY', 'B_TODAY'], Calendar::todayMadrid()) === ['B_TODAY']);
// El feed de Metro trae a veces ids que en el horario son de otro calendario: no se casan con ese viaje.
$PdoVar->exec("CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT); INSERT INTO trip_aliases VALUES ('A_OTHER_DAY', 'A_OTHER_DAY'), ('B_TODAY', 'B_TODAY')");
$MatcherVar = new TripDelayMatcher([['tripId' => 'A_OTHER_DAY', 'stops' => [], 'delay' => 120], ['tripId' => 'B_TODAY', 'stops' => [], 'delay' => 120]], $JourneyVar);
$Expect('un viaje del feed que según el horario no circula hoy no se casa', $MatcherVar->diagnostics()['matched'] === 1, json_encode($MatcherVar->diagnostics()));

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
$Expect('secondsToHm quita los segundos (08:10:59 -> 08:10), como hm() de js/offline.js', Calendar::secondsToHm(8 * 3600 + 10 * 60 + 59) === '08:10');
$Expect('hmToSeconds: 07:45 son 27900 s', Calendar::hmToSeconds('07:45') === 27900);
$Expect('hmToSeconds: lo que no es HH:MM cuenta como 0', Calendar::hmToSeconds('7h45') === 0 && Calendar::hmToSeconds('') === 0);

// Ficha del viaje: sin datos en vivo no se afirma que el bus ya salió.
$aTripStops = [['arrival_seconds' => 8 * 3600 + 10 * 60], ['arrival_seconds' => 8 * 3600 + 40 * 60]];
$Expect('ficha del viaje: con datos en vivo sale live', Services\TripSheet::status(true, $aTripStops, 8 * 3600 + 20 * 60) === 'live');
$Expect('ficha del viaje: sin datos en vivo y a la hora de salida NO dice que ya salió (scheduled)', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 10 * 60 + 30) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y a mitad del viaje sigue en scheduled', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 25 * 60) === 'scheduled');
$Expect('ficha del viaje: sin datos en vivo y pasada la última parada sale finished', Services\TripSheet::status(false, $aTripStops, 8 * 3600 + 41 * 60) === 'finished');
$Expect('ficha del viaje: sin paradas sale scheduled', Services\TripSheet::status(false, [], 8 * 3600) === 'scheduled');
// SNCF pone en el headsign el número del tren: la ficha da como destino la última parada, como las salidas.
$aSncfJourney = ['line_code' => 'TER', 'line_name' => 'TER', 'headsign' => '867432'];
$aSncfStops = [['name' => 'Hendaye'], ['name' => 'Bordeaux Saint-Jean']];
\Core\Config::set('sncf');
$sSncfHeadsign = Services\TripSheet::sheet($aSncfJourney, 'scheduled', null, null, $aSncfStops)['headsign'];
\Core\Config::set(\Core\Networks::defaultSlug());
$Expect('ficha del tren de SNCF: el destino es la última parada, no el número del tren', $sSncfHeadsign === 'Bordeaux Saint-Jean');
$Expect('ficha del viaje en las demás redes: el headsign del GTFS', Services\TripSheet::sheet($aSncfJourney, 'scheduled', null, null, $aSncfStops)['headsign'] === '867432');

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
// Con el tope de su ficha (max_feed_gap_seconds, 90 min en bus urbano), un retraso de 2 h sin el día del viaje puede
// ser de otro viaje: sale sin dato, no a su hora. Uno de 10 min sí vale, y con el día de hoy, también los de 40 a 120.
$TripLate = fn(int $iLate, array $aDay = []) => [$aDay + ['tripId' => '6005L2C1', 'stops' => [['B', $iOffMidnight + T0 + 1020 + $iLate], ['C', $iOffMidnight + T0 + 1140 + $iLate]]]];
$aToday = ['startDate' => date('Ymd', $iOffMidnight + 12 * 3600)];
foreach (['dbus', 'txiktxak'] as $sCapped) {
    \Core\Config::set($sCapped);
    $aFarRow = (new TripDelayMatcher($TripLate(7200), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
    $aNearRow = (new TripDelayMatcher($TripLate(600), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
    $Expect("{$sCapped}: sin el día del viaje, un retraso de 2 h sale sin dato y uno de 10 min vale", $aFarRow[0]['status'] === 'likely_departed' && $aNearRow[0]['status'] === 'live', json_encode([$aFarRow[0], $aNearRow[0]]));
    // La fila de C lleva ya su retraso sin el del feed: se cuenta lo que añade cada uno.
    $iBase = (new TripDelayMatcher($TripLate(0, $aToday), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['delaySeconds'];
    $aLate = [];
    foreach ([40, 46, 80] as $iMinutes) {
        $aLateRow = (new TripDelayMatcher($TripLate($iMinutes * 60, $aToday), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
        $aLate[$iMinutes] = ($aLateRow[0]['delaySeconds'] - $iBase) / 60;
    }
    $Expect("{$sCapped}: con el día de hoy, 40, 46 y 80 min salen como retraso", $aLate === [40 => 40, 46 => 46, 80 => 80], json_encode($aLate));
    $aAbsurd = (new TripDelayMatcher($TripLate(150 * 60, $aToday), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
    $Expect("{$sCapped}: con el día de hoy, 2 h 30 min no es creíble: sin dato", $aAbsurd[0]['status'] === 'likely_departed', json_encode($aAbsurd[0]));
    $aUndated = (new TripDelayMatcher($TripLate(80 * 60), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
    $Expect("{$sCapped}: sin el día del viaje, 80 min caben en el tope de 90", $aUndated[0]['delaySeconds'] === 80 * 60 + $iBase, json_encode($aUndated[0]));
    $aOtherDay = ['startDate' => date('Ymd', $iOffMidnight - 2 * 86400 + 12 * 3600)];
    $aOld = (new TripDelayMatcher($TripLate(46 * 60, $aOtherDay), new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC);
    $Expect("{$sCapped}: un viaje de otro día no da retraso", $aOld[0]['status'] === 'scheduled', json_encode($aOld[0]));
}
\Core\Config::set('alavabus');
// Dónde está el autobús por su GPS: tres paradas en línea recta (A 08:00, B 08:10, C 08:20), cada una a 1 km.
$aLine = [['stop_id' => 'A', 'arrival_seconds' => 8 * 3600], ['stop_id' => 'B', 'arrival_seconds' => 8 * 3600 + 600], ['stop_id' => 'C', 'arrival_seconds' => 8 * 3600 + 1200]];
$aLineCoords = ['A' => [43.0, -2.9], 'B' => [43.009, -2.9], 'C' => [43.018, -2.9]];
$iPastB = Services\RoutePosition::scheduledSeconds($aLine, 1, $aLineCoords, 43.0135, -2.9);
$Expect('posición GPS: el feed da B pero el bus va por la mitad de B a C (B ya quedó atrás): 08:15', abs($iPastB - (8 * 3600 + 900)) <= 5, (string)$iPastB);
$iBeforeB = Services\RoutePosition::scheduledSeconds($aLine, 1, $aLineCoords, 43.0045, -2.9);
$Expect('posición GPS: el feed da B y el bus va por la mitad de A a B: 08:05', abs($iBeforeB - (8 * 3600 + 300)) <= 5, (string)$iBeforeB);
$Expect('posición GPS: sin coordenadas de las paradas, no hay posición', Services\RoutePosition::scheduledSeconds($aLine, 1, [], 43.0, -2.9) === null);
$aOtherTrip = [['tripId' => '6005L2C1', 'stops' => [['B', $iOffMidnight + T0 + 1050 + 5 * 3600], ['C', $iOffMidnight + T0 + 1170 + 5 * 3600]]]];
$Expect('Álavabus: unas horas a 5 h de su horario son de otro viaje: sale sin dato', (new TripDelayMatcher($aOtherTrip, new ServiceJourney($PdoRenfe), $iOffMidnight))->enrich('C', $aRowC)[0]['status'] === 'likely_departed');
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
$Expect('Álavabus: si el GPS dice que el autobús es de otro viaje, tampoco valen sus horas del feed: sale sin dato', ($aWrongC[0]['status'] ?? null) === 'likely_departed', json_encode($aWrongC));
// En una red donde no manda el GPS (Renfe LD), ese mismo vehículo de 50 min sin hora prevista no deja el viaje sin
// dato: sigue con su horario, como en master.
\Core\Config::set('renfe-ld');
$aLdC = (new TripDelayMatcher([], new ServiceJourney($PdoAlava), $iWrongMidnight, $aWrongBus))->enrich('C', [$AlavaRow('C', 2)]);
$Expect('Renfe LD: un GPS de más de 45 min sin hora prevista no deja el viaje sin dato', ($aLdC[0]['status'] ?? null) === 'scheduled', json_encode($aLdC));
\Core\Config::set('alavabus');
$aRightC = (new TripDelayMatcher($aWrongTimes, new ServiceJourney($PdoAlava), $iWrongMidnight))->enrich('C', [$AlavaRow('C', 2)]);
$Expect('sin GPS, las mismas horas sí valen (40 min)', ($aRightC[0]['status'] ?? null) === 'live' && $aRightC[0]['delaySeconds'] === 2400, json_encode($aRightC));
// Sin GPS, el feed lo pone en B 25 min tarde y ya han pasado otros 25: se enseñarían 50 min, más del tope de 45.
$iStaleMidnight = time() - (T0 + 600 + 1500 + 1500);
$aStaleTimes = [['tripId' => '22123', 'stops' => [['B', $iStaleMidnight + T0 + 600 + 1500], ['C', $iStaleMidnight + T0 + 1200 + 1500]]]];
$MStale = new TripDelayMatcher($aStaleTimes, new ServiceJourney($PdoAlava), $iStaleMidnight);
$Expect('Álavabus: un retraso que con lo que lleva de más pasa de 45 min no vale: sale sin dato', !$MStale->hasData() && ($MStale->enrich('C', [$AlavaRow('C', 2)])[0]['status'] ?? null) === 'likely_departed', json_encode($MStale->diagnostics()));
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
$aMetroAbsurd = [['tripId' => '6005L1C1', 'stops' => [['B', time() + 5 * 3600], ['C', time() + 5 * 3600 + 120]]]];
$Expect('Metro: un retraso de horas en el GTFS-Realtime (otro viaje con ese id) no vale: sale con el horario', !(new TripDelayMatcher($aMetroAbsurd, new ServiceJourney($PdoRenfe), time() - T0))->hasData());
\Core\Config::set('bus');
$Expect('en los autobuses, el que no está en el directo sigue con el horario aunque toque ya', (new TripDelayMatcher($MetroTripAt(time() - (T0 + 1950)), new ServiceJourney($PdoRenfe), time() - (T0 + 1950)))->enrich('B', [$RenfeRow('J4', 3)])[0]['status'] === 'scheduled');

$Section('Álavabus con su feed real (fixtures/alavabus-*-20261115.pb, del 15-11-2026 a las 17:49)');
// Su base, con todos los calendarios en marcha, y el feed movido a hoy a la misma hora: lo que sale tiene que ser lo
// de master con ese feed. El viaje 20925 trae horas de hace 4,5 h con el día de hoy (antes daba un p95 de 194 min).
$sAlavaDb = __DIR__ . '/../data/alavabus.sqlite';
if (is_file($sAlavaDb)) {
    \Core\Config::set('alavabus');
    $sAlavaCopy = tempnam(sys_get_temp_dir(), 'bide-alava-');
    copy($sAlavaDb, $sAlavaCopy);
    $PdoReal = new PDO('sqlite:' . $sAlavaCopy);
    $PdoReal->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $PdoReal->exec("UPDATE service_calendars SET from_date = '2000-01-01', to_date = '2099-12-31', weekday_mask = 127");
    $PdoReal->exec('DELETE FROM service_calendar_exceptions');
    $aRealFeed = GtfsRealtimeClient::parseTripUpdates((string)file_get_contents(__DIR__ . '/fixtures/alavabus-trip-updates-20261115.pb'));
    $aRealPos = GtfsRealtimeClient::parseVehiclePositions((string)file_get_contents(__DIR__ . '/fixtures/alavabus-vehicle-positions-20261115.pb'));
    $iFixtureMidnight = (new DateTime('2026-11-15', new DateTimeZone(Calendar::TIME_ZONE)))->getTimestamp();
    $iRealMidnight = time() - ($aRealFeed['generatedAt'] - $iFixtureMidnight);
    $iMoved = $iRealMidnight - $iFixtureMidnight;
    $sRealDay = (new DateTime('@' . ($iRealMidnight + 12 * 3600)))->setTimezone(new DateTimeZone(Calendar::TIME_ZONE))->format('Ymd');
    $aRealTrips = [];
    foreach ($aRealFeed['trips'] as $aTrip) {
        foreach ($aTrip['stops'] as $iStop => $aStop) {
            $aTrip['stops'][$iStop][1] += $iMoved;
        }
        $aTrip['startDate'] = $sRealDay;
        $aRealTrips[] = $aTrip;
    }
    $aRealVehicles = [];
    foreach ($aRealPos['vehicles'] as $aVehicle) {
        $aVehicle['timestamp'] += $iMoved;
        $aRealVehicles[] = $aVehicle;
    }
    $MReal = new TripDelayMatcher($aRealTrips, new ServiceJourney($PdoReal), $iRealMidnight, $aRealVehicles);
    $aRealLive = [];
    foreach ((new ReflectionProperty($MReal, 'aByJourney'))->getValue($MReal) as $sJourney => $aLive) {
        $aRealLive[(string)$sJourney] = [(int)round($aLive['delay'] / 60), (string)$aLive['nextStopId']];
    }
    ksort($aRealLive);
    // Lo que da master con el mismo feed: viaje => [retraso en min, parada a la que va].
    $aMasterLive = ['15911' => [0, '1'], '20882' => [3, '412'], '21994' => [4, '71'], '22038' => [0, '142'],
        '22046' => [0, '79'], '22151' => [4, '10'], '22195' => [0, '4'], '25426' => [0, '399'], '25902' => [0, '1'],
        '25962' => [0, '135']];
    $Expect('Álavabus, feed real: los mismos viajes en directo y con el mismo retraso que en master', $aRealLive === $aMasterLive, json_encode($aRealLive));
    $aRealDiag = $MReal->diagnostics();
    $Expect('Álavabus, feed real: sin retrasos absurdos (p95 de 11 min, como en master)', ($aRealDiag['delayMinutes']['p95'] ?? null) === 11, json_encode($aRealDiag));
    $aStale = $MReal->enrich('828', [['service_journey_id' => '20925', 'trip_number' => '20925', 'stop_seq' => 1, 'arrival_seconds' => 0, 'status' => 'scheduled', 'etaSeconds' => 0, 'delaySeconds' => 0]]);
    $Expect('Álavabus, feed real: el viaje con horas de hace 4,5 h sale sin dato', ($aStale[0]['status'] ?? null) === 'likely_departed', json_encode($aStale));
    unlink($sAlavaCopy);
}

$Section('Directo con solo posiciones (vehicle positions sin trip updates)');
// Una red de la que solo se publica dónde va cada vehículo: su ficha con live = ['vehicle-positions'].
$aOnlyPositions = \Core\Networks::get('alavabus');
$aOnlyPositions['live'] = ['vehicle-positions'];
unset($aOnlyPositions['gtfs_rt']['trip_updates_url'], $aOnlyPositions['gtfs_rt']['prefer_vehicles']);
(new ReflectionProperty(\Core\Config::class, 'aCurrent'))->setValue(null, $aOnlyPositions);
$PdoPos = new PDO('sqlite::memory:');
$PdoPos->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoPos->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT, lat REAL, lon REAL)');
$PdoPos->exec('CREATE TABLE service_journeys (id TEXT PRIMARY KEY, line_id TEXT, first_departure_seconds INTEGER)');
$PdoPos->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT,
    arrival_seconds INTEGER, departure_seconds INTEGER)');
$PdoPos->exec('CREATE TABLE trip_aliases (trip_id TEXT PRIMARY KEY, service_journey_id TEXT)');
$InsPosStop = $PdoPos->prepare('INSERT INTO stops VALUES (?, ?, ?, ?)');
foreach (['A' => 43.0, 'B' => 43.009, 'C' => 43.018] as $sStop => $dLat) {
    $InsPosStop->execute([$sStop, $sStop, $dLat, -2.9]);
    $InsPosStop->execute([$sStop . '2', $sStop . ' vuelta', $dLat, -2.9 + lonOffset(20)]);
}
// J1 y J2, de A a C con 30 min entre uno y otro; J3, la vuelta por la acera de enfrente.
$aPosJourneys = ['J1' => [['A', 'B', 'C'], T0], 'J2' => [['A', 'B', 'C'], T0 + 1800],
    'J3' => [['C2', 'B2', 'A2'], T0 + 1300]];
foreach ($aPosJourneys as $sJourney => [$aStopIds, $iStart]) {
    $PdoPos->exec("INSERT INTO service_journeys VALUES ('$sJourney', 'L1', $iStart)");
    foreach ($aStopIds as $iSeq => $sStop) {
        $iArrival = $iStart + $iSeq * 600;
        $PdoPos->exec("INSERT INTO passing_times VALUES ('$sJourney', $iSeq, '$sStop', $iArrival, $iArrival)");
    }
}
$PdoPos->exec("INSERT INTO trip_aliases VALUES ('T1', 'J1'), ('T2', 'J2'), ('T3', 'J3')");
$sPosFixture = (string)file_get_contents(__DIR__ . '/fixtures/vehicle-positions-sin-horas.pb');
$aPosFeed = GtfsRealtimeClient::parseVehiclePositions($sPosFixture);
$aNoTrip = $aPosFeed['vehicles'][1] ?? [];
$bNoTrip = $aNoTrip['tripId'] === '' && $aNoTrip['routeId'] === 'L1' && $aNoTrip['directionId'] === 0;
$Expect('feed guardado: un vehículo sin viaje entra con su línea, su sentido y su rumbo',
    count($aPosFeed['vehicles']) === 3 && $bNoTrip && $aNoTrip['bearing'] === 2.0, json_encode($aPosFeed['vehicles']));
$iPosMidnight = time() - (T0 + 2340);
$MPos = new TripDelayMatcher([], new ServiceJourney($PdoPos), $iPosMidnight, $aPosFeed['vehicles']);
$PosRow = function (string $sJourney, int $iSeq) use ($aPosJourneys): array {
    $iArrival = $aPosJourneys[$sJourney][1] + $iSeq * 600;
    return ['service_journey_id' => $sJourney, 'trip_number' => $sJourney, 'stop_seq' => $iSeq,
        'arrival_seconds' => $iArrival, 'status' => 'scheduled', 'etaSeconds' => $iArrival, 'delaySeconds' => 0];
};
$IsLive = fn(array $aRows, int $iDelay) => ($aRows[0]['status'] ?? null) === 'live'
    && $aRows[0]['delaySeconds'] === $iDelay;
$aPosC = $MPos->enrich('C', [$PosRow('J1', 2)]);
$Expect('con su viaje y sin parada: el GPS a mitad de B a C (24 min tarde) da su retraso en C', $IsLive($aPosC, 1440),
    json_encode($aPosC));
$aPosLine = $MPos->enrich('B', [$PosRow('J2', 1)]);
$Expect('sin viaje: el de su línea y su rumbo de hora más cercana (J2, 4 min tarde), ni el de antes ni la vuelta',
    $IsLive($aPosLine, 240), json_encode($aPosLine));
$aPosBack = $MPos->enrich('B2', [$PosRow('J3', 1)]);
$Expect('con un viaje desconocido, la parada del feed da el sentido: la vuelta, a mitad de C a B (12 min)',
    $IsLive($aPosBack, 740), json_encode($aPosBack));
$aPosTrip = $MPos->liveTrip('B', [], 'J2');
$Expect('la ficha del viaje sale con esas horas, desde la parada a la que va',
    ($aPosTrip[0] ?? null) === ['B', $iPosMidnight + T0 + 2400 + 240], json_encode($aPosTrip));
// Con su viaje pero sin su día (el feed de posiciones no lo trae), un GPS que da 1 h tarde pasa del tope: sin dato.
$aLateBus = [['tripId' => 'T1', 'routeId' => 'L1', 'lat' => 43.0005, 'lon' => -2.9, 'stopId' => '', 'vehicleId' => '9']];
$MLate = new TripDelayMatcher([], new ServiceJourney($PdoPos), time() - (T0 + 3600), $aLateBus);
$aLateA = $MLate->enrich('A', [$PosRow('J1', 0)]);
$Expect('solo posiciones: un GPS de más del tope y sin el día del viaje sale sin dato, no a su hora',
    ($aLateA[0]['status'] ?? null) === 'likely_departed', json_encode($aLateA));
$aLoopStops = [];
foreach (['A', 'B', 'C', 'B', 'A'] as $iSeq => $sStop) {
    $aLoopStops[] = ['stop_id' => $sStop, 'arrival_seconds' => T0 + $iSeq * 600];
}
$aLoopCoords = ['A' => [43.0, -2.9], 'B' => [43.009, -2.9], 'C' => [43.018, -2.9]];
$aGoing = Services\RoutePosition::locate($aLoopStops, $aLoopCoords, 43.0045, -2.9, T0 + 420);
$aComing = Services\RoutePosition::locate($aLoopStops, $aLoopCoords, 43.0045, -2.9, T0 + 2160);
$bGoing = ($aGoing['index'] ?? null) === 1 && $aGoing['seconds'] === T0 + 300;
$bComing = ($aComing['index'] ?? null) === 4 && $aComing['seconds'] === T0 + 2100;
$Expect('ida y vuelta por la misma calle: el tramo con la hora más cercana a la de ahora', $bGoing && $bComing,
    json_encode([$aGoing, $aComing]));
$aAway = Services\RoutePosition::locate($aLoopStops, $aLoopCoords, 43.0045, -2.85, T0);
$Expect('lejos del recorrido no se sitúa', $aAway === null);
\Core\Config::set('bus');

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

// Cerca de medianoche, las 00:xx que el GTFS pone en el día siguiente (no como 24:xx) salen con las de hoy dentro de
// las 4 horas, en horas de hoy y sin «mañana»; la que el horario de hoy ya trae como 24:xx no se repite; las de más
// allá de la ventana, cuando hoy ya no queda ninguna, siguen como «mañana».
$NextDayModel = new class (buildFixture(40)) extends ServiceJourney {
    public array $aDays = [];
    public int $iFakeNow = 0;
    public function upcomingAtStop(int|string $iStopId, int $iLimit = 8, int $iWindowSeconds = 4 * 3600, int $iDaysAhead = 0, int|null $iPastSeconds = null): array
    {
        $iShift = $iDaysAhead * 86400;
        $iNow = $this->iFakeNow - $iShift;
        $aOut = [];
        foreach ($this->aDays[$iDaysAhead] ?? [] as [$sLine, $iDeparture]) {
            if ($iDeparture >= $iNow - 900 && $iDeparture <= $iNow + $iWindowSeconds) {
                $aOut[] = ['line_code' => $sLine, 'departure_seconds' => $iDeparture + $iShift, 'arrival_seconds' => $iDeparture + $iShift];
            }
        }
        return $aOut;
    }
    public function nextDepartureSeconds(int|string $iStopId): int|null
    {
        return null;
    }
};
$RowsToday = new ReflectionMethod(Controllers\StopsController::class, 'upcomingRowsToday');
$NextDayModel->aDays = [0 => [['L3', 85680], ['L3', 86400]], 1 => [['L3', 0], ['L3', 180], ['L3', 1080], ['L3', 30000]]];
$NextDayModel->iFakeNow = 85200;
$aNear = $RowsToday->invoke(null, $NextDayModel, '1', 4, null, 85200);
$Expect('a las 23:40, detrás de la de las 23:48 y la de las 24:00, las de las 00:03 y 00:18 del día siguiente, en horas de hoy y sin «mañana»',
    array_map(fn(array $aRow) => [(int)$aRow['departure_seconds'], !empty($aRow['nextDay']), !empty($aRow['tomorrow'])], $aNear) === [[85680, false, false], [86400, false, false], [86580, true, false], [87480, true, false]],
    json_encode($aNear));
$NextDayModel->aDays[0] = [['L3', 84000]];
$NextDayModel->iFakeNow = 86280;
$aLate = $RowsToday->invoke(null, $NextDayModel, '1', 4, null, 86280);
$Expect('a las 23:58 sin más salidas hoy: las de las 00:03 y 00:18 van como las de hoy; la de las 08:20 de mañana, como «mañana»',
    array_map(fn(array $aRow) => [(int)$aRow['departure_seconds'], !empty($aRow['nextDay']), !empty($aRow['tomorrow'])], $aLate) === [[86400, true, false], [86580, true, false], [87480, true, false], [116400, false, true]],
    json_encode($aLate));
$WithLiveRows = new ReflectionMethod(Controllers\StopsController::class, 'withLive');
$aLateLive = $WithLiveRows->invoke(null, [new \Services\ScheduleOnlyMatcher()], null, '1', $aLate);
$Expect('las del día siguiente dentro de la ventana van con su horario, sin pasar por el directo de hoy', array_unique(array_column($aLateLive, 'status')) === ['scheduled'], json_encode(array_column($aLateLive, 'status')));

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

$Uncited = fn($aNet) => \Core\Networks::hasFeedLive($aNet) && empty($aNet['texts']['es']['liveSource']);
$aUncited = array_keys(array_filter(\Core\Networks::all(), $Uncited));
$Expect('cada red con directo del GTFS-Realtime (horas o posiciones) cita de dónde salen sus horas (liveSource)',
    empty($aUncited));

$Section('Aviso general (Services\\Notice)');
$iNow = 1800000000;
$aGood = ['id' => '20261110T203000Z', 'text' => ['es' => '  Huelga mañana  ', 'eu' => 'Greba bihar', 'en' => ''], 'url' => 'https://www.bizkaia.eus/avisos', 'expiresAt' => $iNow + 3600, 'notify' => true];
$aNotice = \Services\Notice::fromArray($aGood, $iNow);
$Expect('un aviso bien escrito sale con su texto sin espacios de más', $aNotice !== null && $aNotice['text']['es'] === 'Huelga mañana' && $aNotice['id'] === '20261110T203000Z', json_encode($aNotice));
$Expect('los idiomas sin texto no salen: caen en castellano', array_keys($aNotice['text']) === ['es', 'eu']);
$Expect('trae su enlace y si hay que notificar', $aNotice['url'] === 'https://www.bizkaia.eus/avisos' && $aNotice['notify'] === true);
$Expect('pasada su hora de fin ya no sale', \Services\Notice::fromArray(['expiresAt' => $iNow] + $aGood, $iNow) === null && \Services\Notice::fromArray(['expiresAt' => $iNow - 1] + $aGood, $iNow) === null);
$Expect('sin texto en castellano no sale', \Services\Notice::fromArray(['text' => ['eu' => 'Greba']] + $aGood, $iNow) === null && \Services\Notice::fromArray(['text' => 'Huelga'] + $aGood, $iNow) === null);
$Expect('sin identificador válido no sale', \Services\Notice::fromArray(['id' => 'a b'] + $aGood, $iNow) === null && \Services\Notice::fromArray(['id' => ''] + $aGood, $iNow) === null && \Services\Notice::fromArray(['id' => str_repeat('a', 41)] + $aGood, $iNow) === null);
$Expect('la hora de fin tiene que ser un número entero', \Services\Notice::fromArray(['expiresAt' => '1800003600'] + $aGood, $iNow) === null);
$Expect('vacío (aviso quitado), sin aviso', \Services\Notice::fromArray([], $iNow) === null);
$Expect('un enlace que no es http o https se descarta, pero el aviso sale', \Services\Notice::fromArray(['url' => 'javascript:alert(1)'] + $aGood, $iNow)['url'] === null && \Services\Notice::fromArray(['url' => 'no es un enlace'] + $aGood, $iNow)['url'] === null);
$Expect('«notificar» solo vale con true', \Services\Notice::fromArray(['notify' => 'true'] + $aGood, $iNow)['notify'] === false && \Services\Notice::fromArray(['notify' => null] + $aGood, $iNow)['notify'] === false);
$Expect('el texto se corta a 400 caracteres', mb_strlen(\Services\Notice::fromArray(['text' => ['es' => str_repeat('á', 900)]] + $aGood, $iNow)['text']['es']) === 400);

$Section('Trenes del SIRI de llegadas: cada fila lleva el número de su tren');
$sSiri = '<?xml version="1.0"?><Siri xmlns="http://www.siri.org.uk/siri"><ServiceDelivery><VehicleMonitoringDelivery>'
    . '<VehicleActivity><RecordedAtTime>2026-11-11T19:55:00+01:00</RecordedAtTime><MonitoredVehicleJourney><DirectionRef>1</DirectionRef>'
    . '<DestinationRef>1.0</DestinationRef><DestinationAimedArrivalTime>2026-11-11T20:00:00+01:00</DestinationAimedArrivalTime>'
    . '<VehicleJourneyRef>903106</VehicleJourneyRef></MonitoredVehicleJourney></VehicleActivity>'
    . '<VehicleActivity><RecordedAtTime>2026-11-11T19:55:00+01:00</RecordedAtTime><MonitoredVehicleJourney><DirectionRef>1</DirectionRef>'
    . '<DestinationRef>2.0</DestinationRef><DestinationAimedArrivalTime>2026-11-11T20:02:00+01:00</DestinationAimedArrivalTime>'
    . '<VehicleJourneyRef>903106</VehicleJourneyRef></MonitoredVehicleJourney></VehicleActivity>'
    . '</VehicleMonitoringDelivery></ServiceDelivery></Siri>';
$aParsed = \Services\StopArrivalTrips::parseArrivals($sSiri);
$Expect('lee la parada (sin «.0») y la hora de cada llegada', \count($aParsed) === 2 && $aParsed[0]['stop'] === '1' && $aParsed[1]['time'] - $aParsed[0]['time'] === 120, json_encode($aParsed));
$Expect('un XML roto no da llegadas', \Services\StopArrivalTrips::parseArrivals('<Siri') === []);
$Expect('un feed parado (lecturas antiguas) no vale', \Services\StopArrivalTrips::recent($aParsed, $aParsed[0]['recorded'] + 1) === [] && \count(\Services\StopArrivalTrips::recent($aParsed, $aParsed[0]['recorded'] - 1)) === 2);

$aGrouped = \Services\StopArrivalTrips::grouped([$aParsed[1], $aParsed[0], ['stop' => '5', 'time' => 10, 'recorded' => 0, 'train' => '']]);
$Expect('un tren son sus llegadas, por hora; las filas sin número de tren no valen', $aGrouped === [['tripId' => '903106', 'stops' => [['1', $aParsed[0]['time']], ['2', $aParsed[1]['time']]]]], json_encode($aGrouped));
$aSiriReal = \Services\StopArrivalTrips::grouped(\Services\StopArrivalTrips::parseArrivals((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-siri-vm-20261114.xml')));
$Expect('con el SIRI real, los mismos números de tren que el GTFS-Realtime (16 trenes por Moyua)', \count($aSiriReal) === 16 && \in_array('903106', array_column($aSiriReal, 'tripId'), true));

$PdoSeg = new PDO('sqlite::memory:');
$PdoSeg->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoSeg->exec('CREATE TABLE passing_times (service_journey_id TEXT, seq_order INT, stop_id TEXT, arrival_seconds INT, departure_seconds INT)');
$PdoSeg->exec("INSERT INTO passing_times VALUES ('a',1,'1',0,0),('a',2,'2',100,110),('b',1,'1',500,500),('b',2,'2',640,650)");
$Expect('lo que tarda un tren entre paradas es la media del horario', (new ServiceJourney($PdoSeg))->segmentSeconds() === ['1|2' => 120]);

$Section('Respaldo con el SIRI: solo si la ficha lo pide');
$Expect('sin siri_fallback en la ficha no se usa', !(new \Services\StopArrivalTrips(['gtfs_rt' => [], 'siri' => ['vehicle_monitoring_url' => 'https://x']], new ServiceJourney($PdoSeg)))->enabled());
$Expect('con siri_fallback pero sin SIRI de directo tampoco', !(new \Services\StopArrivalTrips(['gtfs_rt' => ['siri_fallback' => true], 'siri' => []], new ServiceJourney($PdoSeg)))->enabled());
$Expect('con las dos cosas sí', (new \Services\StopArrivalTrips(['gtfs_rt' => ['siri_fallback' => true], 'siri' => ['vehicle_monitoring_url' => 'https://x']], new ServiceJourney($PdoSeg)))->enabled());
foreach (\Core\Networks::all() as $sSlug => $aNet) {
    if (!empty($aNet['gtfs_rt']['siri_fallback'])) {
        $Expect("$sSlug: siri_fallback pide también el SIRI de directo y emparejar por viaje", !empty($aNet['siri']['vehicle_monitoring_url']) && ($aNet['gtfs_rt']['matching'] ?? '') === 'trip');
    }
}

$Section('Recorridos de una parada');
$PdoX = new PDO('sqlite::memory:');
$PdoX->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$PdoX->exec("
    CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT);
    CREATE TABLE lines (id TEXT PRIMARY KEY, code TEXT, name TEXT);
    CREATE TABLE journey_patterns (id TEXT PRIMARY KEY, line_id TEXT, headsign TEXT);
    CREATE TABLE journey_pattern_stops (journey_pattern_id TEXT, seq_order INTEGER, stop_id TEXT);
    CREATE TABLE service_journeys (id TEXT PRIMARY KEY, journey_pattern_id TEXT);
    INSERT INTO stops VALUES ('A', 'Aldea'), ('B', 'Bosque'), ('C', 'Costa');
    INSERT INTO lines VALUES ('L', 'MB', 'Metro');
    INSERT INTO journey_patterns VALUES ('LARGO', 'L', 'Costa'), ('CORTO', 'L', 'Bosque');
    INSERT INTO journey_pattern_stops VALUES ('LARGO', 1, 'A'), ('LARGO', 2, 'B'), ('LARGO', 3, 'C'), ('CORTO', 1, 'A'), ('CORTO', 2, 'B');
");
for ($iTrip = 1; $iTrip <= 8; $iTrip++) {
    $PdoX->exec("INSERT INTO service_journeys VALUES ('l$iTrip', 'LARGO')");
}
$PdoX->exec("INSERT INTO service_journeys VALUES ('c1', 'CORTO')");
$JourneyX = new ServiceJourney($PdoX);
$aPatternsX = $JourneyX->patternsAtStop('A');
$Expect('los recorridos que pasan por una parada sin acabar en ella', array_column($aPatternsX, 'journey_pattern_id') === ['LARGO', 'CORTO'] && \count($JourneyX->patternsAtStop('C')) === 0, json_encode($aPatternsX));
$Section('Ficha de un refuerzo');
$Expect('la clave de un refuerzo es su parada, su andén y su hora, y se reconoce (también sin andén)', \Core\TripKey::buildExtra('8', 1794410000, '7') === 'extra-8@7-1794410000' && \Core\TripKey::parseExtra('extra-8@7-1794410000') === ['8', 1794410000, '7'] && \Core\TripKey::parseExtra('extra-8-1794410000') === ['8', 1794410000, null] && \Core\TripKey::parseExtra('3514-12-27600') === null);
$aKnownTimes = [['B', 1000]];
$aAlong = \Services\ExtraSheet::timesAlong(['A', 'B', 'C'], $aKnownTimes, ['A|B' => 60, 'B|C' => 120]);
$Expect('las paradas de antes se calculan restando lo que tarda el tren y las de después, sumándolo', $aAlong === ['B' => 1000, 'A' => 940, 'C' => 1120], json_encode($aAlong));
$aCut = \Services\ExtraSheet::timesAlong(['A', 'B', 'C'], $aKnownTimes, ['B|C' => 120]);
$Expect('si no se sabe lo que tarda un tramo, no se inventa la parada de más allá', array_keys($aCut) === ['B', 'C'], json_encode($aCut));
$Expect('sin ninguna parada con hora no hay recorrido', \Services\ExtraSheet::timesAlong(['A', 'B'], [], ['A|B' => 60]) === []);

$Section('Directo por andén con los trenes del feed abierto (Metro Bilbao)');
$aMetroNet = \Core\Networks::all()['metro'] ?? null;
$Expect('la ficha de Metro: casado por andén con sus umbrales con nombre y tope de retraso del GTFS-Realtime', $aMetroNet === null || (isset($aMetroNet['gtfs_rt']['platform_matching']['max_delay_seconds'], $aMetroNet['gtfs_rt']['platform_matching']['covered_seconds']) && $aMetroNet['gtfs_rt']['max_feed_gap_seconds'] === 3600));
$Expect('el andén de cada recorrido es su parada siguiente; donde acaba, ninguno', \Services\PlatformMatcher::nextStops('A', ['P1' => ['A', 'B', 'Z'], 'P3' => ['X', 'A', 'C'], 'P9' => ['X', 'A']]) === ['P1' => 'B', 'P3' => 'C']);

$iMidPm = time() - 500;
$aPmSettings = ['max_delay_seconds' => 600, 'max_early_seconds' => 180, 'covered_seconds' => 1800, 'truncated_after_seconds' => 1500, 'omits_terminal' => true, 'min_travel_share' => 0.5, 'usual_pattern_share' => 4];
$PmPattern = fn(string $sId, string $sLast, int $iTrips) => ['journey_pattern_id' => $sId, 'headsign' => $sLast, 'line_id' => 'MB', 'line_code' => 'MB', 'line_name' => 'Metro', 'trips' => $iTrips, 'last_stop_name' => $sLast];
$aPatternsPm = [$PmPattern('P1', 'Zeta', 10), $PmPattern('P2', 'Uve', 5), $PmPattern('P3', 'Ce', 8)];
$aSequencesPm = ['P1' => ['X', 'A', 'B', 'Z'], 'P2' => ['X', 'A', 'B', 'Y', 'W'], 'P3' => ['Q', 'A', 'C']];
$PmRow = fn(string $sJourney, string $sPattern, int $iArrival) => [
    'service_journey_id' => $sJourney, 'trip_number' => $sJourney, 'line_id' => 'MB', 'line_code' => 'MB', 'journey_pattern_id' => $sPattern,
    'arrival_seconds' => $iArrival, 'departure_seconds' => $iArrival, 'status' => 'scheduled', 'etaSeconds' => $iArrival,
];
$aRowsPm = [$PmRow('r1', 'P1', 1000), $PmRow('r2', 'P2', 1100), $PmRow('r5', 'P3', 1200), $PmRow('r3', 'P1', 1600), $PmRow('r6', 'P1', 2250), $PmRow('r4', 'P1', 3000)];
$JourneyPm = new class ($aPatternsPm, $aSequencesPm, $aRowsPm) extends ServiceJourney {
    public function __construct(private array $aPatterns, private array $aSequences, private array $aRows)
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function patternsAtStop(int|string $iStopId): array
    {
        return $this->aPatterns;
    }

    public function stopSequencesForPatterns(array $aPatternIds): array
    {
        return array_intersect_key($this->aSequences, array_flip($aPatternIds));
    }

    public function upcomingAtStop(int|string $iStopId, int $iLimit = 8, int $iWindowSeconds = 4 * 3600, int $iDaysAhead = 0, int|null $iPastSeconds = null): array
    {
        if ($iDaysAhead !== 0) {
            return [];
        }
        return $this->aRows;
    }
};
$aTrainsPm = [
    ['tripId' => 't1', 'stops' => [['A', $iMidPm + 1090], ['B', $iMidPm + 1150]]],
    ['tripId' => 't2', 'stops' => [['A', $iMidPm + 1100], ['B', $iMidPm + 1160], ['Y', $iMidPm + 1200]]],
    ['tripId' => 't3', 'stops' => [['A', $iMidPm + 2000], ['B', $iMidPm + 2060], ['Y', $iMidPm + 2100]]],
    ['tripId' => 't4', 'stops' => [['A', $iMidPm + 2200], ['B', $iMidPm + 2260]]],
    ['tripId' => 'suelto', 'stops' => [['A', $iMidPm + 1300]]],
];
$aArrivalsPm = array_column(\Services\PlatformMatcher::arrivalsAt('A', $aTrainsPm, $aSequencesPm, array_column($aPatternsPm, 'trips', 'journey_pattern_id'), [], time(), $aPmSettings), null, 'train');
$Expect('el destino sale de la secuencia: el feed no da la terminal, así que va a la que sigue a su última parada', array_keys($aArrivalsPm[0]['fits']) === ['Z'] && array_keys($aArrivalsPm[1]['fits']) === ['W'] && $aArrivalsPm[0]['next'] === 'B', json_encode($aArrivalsPm[0]));
$Expect('una lista que acaba lejos puede estar cortada: valen todos sus destinos; un tren de una sola parada no dice su andén', array_keys($aArrivalsPm[3]['fits']) === ['Z', 'W'] && !isset($aArrivalsPm[4]), json_encode(array_keys($aArrivalsPm)));
$MatcherPm = new \Services\PlatformMatcher($aTrainsPm, \Services\PlatformMatcher::SOURCE_TRIP_UPDATES, $JourneyPm, $iMidPm, $aPmSettings, []);
$aPm = array_column($MatcherPm->enrich('A', $aRowsPm), null, 'trip_number');
$Expect('cada tren va a la salida de su andén y su destino: hora del feed y su retraso', $aPm['r1']['status'] === 'live' && $aPm['r1']['etaSeconds'] === 1090 && $aPm['r1']['delaySeconds'] === 90 && $aPm['r2']['etaSeconds'] === 1100 && $aPm['r2']['delaySeconds'] === 0, json_encode($aPm['r1'] ?? null));
$Expect('un tren con varios destinos posibles va a la salida que le queda de uno de ellos', $aPm['r6']['status'] === 'live' && $aPm['r6']['etaSeconds'] === 2200 && $aPm['r6']['delaySeconds'] === 0);
$Expect('una salida sin tren dentro de la ventana del feed no sale (fantasma); más allá, con su horario', !isset($aPm['r3']) && $aPm['r4']['status'] === 'scheduled', json_encode(array_keys($aPm)));
$aNotStarted = array_column($MatcherPm->enrich('A', array_merge($aRowsPm, [$PmRow('r7', 'P1', 1700) + ['first_departure_seconds' => 1650]])), null, 'trip_number');
$Expect('una salida sin tren cuyo viaje aún no ha salido de cabecera se queda con su horario (puede no estar en el feed)', ($aNotStarted['r7']['status'] ?? null) === 'scheduled' && !isset($aNotStarted['r3']));
$Expect('en un andén sin trenes en el feed manda el horario', $aPm['r5']['status'] === 'scheduled' && $aPm['r5']['etaSeconds'] === 1200);
$aExtraPm = array_values(array_filter($aPm, fn($aRow) => !empty($aRow['unscheduled'])));
$Expect('un tren sin salida sale sin horario, con el destino y la línea del recorrido de su andén', \count($aExtraPm) === 1 && $aExtraPm[0]['headsign'] === 'Uve' && $aExtraPm[0]['etaSeconds'] === 2000 && $aExtraPm[0]['journey_pattern_id'] === 'P2' && $aExtraPm[0]['stop_id'] === 'A', json_encode($aExtraPm));
$Expect('liveTrip: las paradas que le quedan al tren casado con el viaje', $MatcherPm->liveTrip('A', $aRowsPm, 'r1') === [['A', $iMidPm + 1090], ['B', $iMidPm + 1150]], json_encode($MatcherPm->liveTrip('A', $aRowsPm, 'r1')));
$aExtraTrainPm = $MatcherPm->unscheduledTrain('A', $iMidPm + 2030, 'B');
$Expect('el tren sin horario se vuelve a encontrar por su clave, con su recorrido', $aExtraTrainPm !== null && \count($aExtraTrainPm['stops']) === 3 && $aExtraTrainPm['sequence'] === $aSequencesPm['P2'] && $aExtraTrainPm['headsign'] === 'Uve', json_encode($aExtraTrainPm));
$Expect('el tren sin horario trae su id del feed (lo usa el planificador para los refuerzos)', ($aExtraTrainPm['trip_id'] ?? null) === 't3', json_encode($aExtraTrainPm));
$Expect('la clave de un tren sin horario lleva su andén: con otro andén no es él', $aExtraPm[0]['platform_stop_id'] === 'B' && $MatcherPm->unscheduledTrain('A', $iMidPm + 2030, 'C') === null);
$aTwoExtras = [
    ['tripId' => 'e1', 'stops' => [['A', $iMidPm + 1900], ['B', $iMidPm + 1960], ['Y', $iMidPm + 2000]]],
    ['tripId' => 'e3', 'stops' => [['A', $iMidPm + 2010], ['B', $iMidPm + 2070], ['Y', $iMidPm + 2110]]],
];
$MatcherTwo = new \Services\PlatformMatcher($aTwoExtras, \Services\PlatformMatcher::SOURCE_TRIP_UPDATES, $JourneyPm, $iMidPm, $aPmSettings, []);
$Expect('de varios trenes sin horario cerca de la hora de la clave, el más cercano de su andén', $MatcherTwo->unscheduledTrain('A', $iMidPm + 2000, 'B')['stops'][0][1] === $iMidPm + 2010 && $MatcherTwo->unscheduledTrain('A', $iMidPm + 1950, 'B')['stops'][0][1] === $iMidPm + 1900);
$aShareConfig = ['gtfs_rt' => ['platform_matching' => ['min_trip_share' => 0.8]]];
$Expect('Metro: con unos pocos ids del feed en el horario (21 de 83) se casa por andén; con casi todos, por id',
    !Core\Live::tripIdsCover($aShareConfig, 21, 83) && Core\Live::tripIdsCover($aShareConfig, 70, 83));
$Expect('sin platform_matching.min_trip_share, cualquier viaje casado por id vale (como antes)',
    Core\Live::tripIdsCover(['gtfs_rt' => ['matching' => 'trip']], 1, 83));
$Expect('cuántos trenes trae el feed (los de una sola parada no cuentan)', $MatcherPm->trainCount() === 4 && $MatcherPm->diagnostics()['trainSource'] === \Services\PlatformMatcher::SOURCE_TRIP_UPDATES);
$aNoFeedPm = (new \Services\PlatformMatcher([], \Services\PlatformMatcher::SOURCE_TRIP_UPDATES, $JourneyPm, $iMidPm, $aPmSettings, []))->enrich('A', $aRowsPm);
$Expect('sin trenes en el feed, el horario tal cual (sin errores)', array_column($aNoFeedPm, 'trip_number') === ['r1', 'r2', 'r5', 'r3', 'r6', 'r4'] && array_unique(array_column($aNoFeedPm, 'status')) === ['scheduled']);
$Fit = fn(string $sTerminal, int $iTrips, bool $bExact) => ['terminal' => $sTerminal, 'trips' => $iTrips, 'exact' => $bExact];
$Terminals = fn(array $aFits) => array_column($aFits, 'terminal');
$Expect('destino exacto y usual (Etxebarri): solo ese', $Terminals(\Services\PlatformMatcher::likely([$Fit('E', 600, true), $Fit('B', 1400, false)], false, 4)) === ['E']);
$Expect('destino exacto pero raro (Bidezabal, Urduliz): ese y los usuales, y decide el horario', $Terminals(\Services\PlatformMatcher::likely([$Fit('BID', 80, true), $Fit('PLE', 630, false), $Fit('SOP', 90, false)], false, 4)) === ['BID', 'PLE']);
$Expect('con la lista cortada, o sin destino exacto, todos los que encajan', $Terminals(\Services\PlatformMatcher::likely([$Fit('BID', 80, true), $Fit('PLE', 630, false), $Fit('SOP', 90, false)], true, 4)) === ['BID', 'PLE', 'SOP'] && $Terminals(\Services\PlatformMatcher::likely([$Fit('PLE', 630, false), $Fit('URD', 25, false)], false, 4)) === ['PLE', 'URD']);
$PmTrain = fn(int $iSeconds, array $aTerminals) => ['seconds' => $iSeconds, 'terminals' => $aTerminals];
$PmSlot = fn(int $iArrival, string $sTerminal) => ['arrival_seconds' => $iArrival, 'terminal' => $sTerminal];
$aOrderPm = \Services\PlatformMatcher::inOrder([$PmTrain(1458, ['K']), $PmTrain(1729, ['K']), $PmTrain(1966, ['K']), $PmTrain(2362, ['K'])], [$PmSlot(960, 'K'), $PmSlot(1380, 'K'), $PmSlot(1740, 'K'), $PmSlot(2160, 'K'), $PmSlot(2580, 'K')], 180, 600);
$Expect('en orden: un tren no adelanta a otro (el de 32:46 no es el de las 16:00 con 17 min de retraso; sale sin horario)', $aOrderPm === [3 => 3, 1 => 2, 0 => 1], json_encode($aOrderPm));
$Expect('un retraso de más del tope o un adelanto de más de 3 min no casan', \Services\PlatformMatcher::inOrder([$PmTrain(1300, ['K']), $PmTrain(2800, ['K'])], [$PmSlot(0, 'K'), $PmSlot(3000, 'K')], 180, 600) === []);
$Expect('en el tronco (dos destinos por el mismo andén), cada tren con una salida de su destino', \Services\PlatformMatcher::inOrder([$PmTrain(1000, ['E']), $PmTrain(1010, ['B'])], [$PmSlot(1000, 'B'), $PmSlot(1005, 'E')], 180, 600) === [0 => 1]);
$aHeldPm = \Services\PlatformMatcher::assign([['train' => 0, 'time' => 970, 'next' => 'B', 'fits' => ['Z' => ['stops' => []]]]], [$PmRow('h', 'P1', 800)], ['P1' => 'B'], ['P1' => 'Z'], 0, 1000, $aPmSettings);
$Expect('un tren que el feed da con la hora pasada hace menos del margen no ha salido: llega ahora («0 min»)', $aHeldPm['live'] === ['h|800' => 1000], json_encode($aHeldPm['live']));
$aGonePm = \Services\PlatformMatcher::assign([['train' => 0, 'time' => 900, 'next' => 'B', 'fits' => ['Z' => ['stops' => []]]]], [$PmRow('h', 'P1', 800)], ['P1' => 'B'], ['P1' => 'Z'], 0, 1000, $aPmSettings);
$Expect('con la hora pasada hace más del margen ya ha salido: ni casa ni sale sin horario', $aGonePm['live'] === [] && $aGonePm['unmatched'] === [], json_encode($aGonePm));
$Expect('un tren seguro y otro con dos destinos no se cruzan: S (Z) a 1000 se queda la de Z y U no toma la de W de antes', \Services\PlatformMatcher::inOrder([$PmTrain(1000, ['Z']), $PmTrain(1100, ['Z', 'W'])], [$PmSlot(900, 'W'), $PmSlot(1000, 'Z')], 180, 600) === [0 => 1]);
foreach (['2026-10-25 10:00', '2026-03-29 10:00'] as $sChange) {
    $iChangeNow = (new DateTimeImmutable($sChange, new DateTimeZone('Europe/Madrid')))->getTimestamp();
    $iWall = Calendar::wallMidnight($iChangeNow);
    $aChange = \Services\PlatformMatcher::assign([['train' => 0, 'time' => $iChangeNow + 300, 'next' => 'B', 'fits' => ['Z' => ['stops' => []]]]], [$PmRow('c', 'P1', 10 * 3600 + 300)], ['P1' => 'B'], ['P1' => 'Z'], $iWall, $iChangeNow, $aPmSettings);
    $Expect("día de cambio de hora ($sChange): con la medianoche de pared, el tren de las 10:05 es el de las 10:05", $iWall === $iChangeNow - 10 * 3600 && $iWall !== (new DateTime('@' . $iChangeNow))->setTimezone(new DateTimeZone('Europe/Madrid'))->setTime(0, 0)->getTimestamp() && $aChange['live'] === ['c|36300' => 36300], json_encode($aChange['live']));
}

// La medianoche real (DateTime('today')) no sirve para pasar horas del feed a segundos del horario: el día del cambio
// de hora va una hora desfasada (los trenes salían como pasados o con 60 min). Solo Calendar::wallMidnight().
$aRealMidnight = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../api', FilesystemIterator::SKIP_DOTS)) as $File) {
    if ($File->getExtension() === 'php' && preg_match("/new \\\\?DateTime\\('today'/", (string)file_get_contents($File->getPathname()))) {
        $aRealMidnight[] = basename($File->getPathname());
    }
}
$Expect('ningún servicio usa la medianoche real como base de las horas (DateTime(\'today\'))', $aRealMidnight === [], implode(', ', $aRealMidnight));

// Horas de ayer de antes de medianoche (negativas tras restar un día): nunca «00:-1».
$Expect('secondsToHm: −59 s es 23:59 y 24:01 es 00:01', Calendar::secondsToHm(-59) === '23:59' && Calendar::secondsToHm(86460) === '00:01' && Calendar::secondsToHm(45000) === '12:30');
// «Ver horario oficial» del GTFS: el último día publicado entra aunque se pida a media mañana.
$TzMadrid = new DateTimeZone('Europe/Madrid');
$DatesOf = fn(string $sNow, string|null $sLast) => array_map(fn(DateTime $Day) => $Day->format('Y-m-d'), \Services\GtfsScheduleText::dates(new DateTime($sNow, $TzMadrid), $sLast));
$Expect('horario oficial: el último día publicado, pedido a las 10:00, tiene su día', $DatesOf('2026-12-19 10:00', '2026-12-19') === ['2026-12-19']);
$Expect('horario oficial: la víspera salen los dos días', $DatesOf('2026-12-18 10:00', '2026-12-19') === ['2026-12-18', '2026-12-19']);
$Expect('horario oficial: sin último día, 42 días desde hoy', \count($DatesOf('2026-11-20 23:30', null)) === 42 && $DatesOf('2026-11-20 23:30', null)[0] === '2026-11-20');
// Avisos de llegada: con más de los que caben en una vuelta, cada minuto va otro trozo y ninguno se queda sin mirar.
$aAlertIds = array_map(fn(int $i) => sprintf('%024x', $i), range(1, 2500));
$aSeenIds = [];
for ($iMinute = 0; $iMinute < 3; $iMinute++) {
    $aSeenIds = array_merge($aSeenIds, \Services\ArrivalAlerts::dueIds($aAlertIds, 1800000000 + 60 * $iMinute));
}
$Expect('avisos: con 2500 pendientes, en 3 vueltas se miran todos (antes, siempre los mismos 1000)', \count(array_unique($aSeenIds)) === 2500 && \count(\Services\ArrivalAlerts::dueIds($aAlertIds, 1800000000)) === 1000);
$Expect('avisos: con pocos, se miran todos en cada vuelta', \Services\ArrivalAlerts::dueIds(['b', 'a']) === ['b', 'a']);

// Datos reales del sábado 14-11-2026 a las 18:39 en Moyua (parada 8): el GTFS-Realtime de Metro y, de referencia, lo
// que daba a la vez la app oficial. Con el feed abierto tiene que salir el mismo destino en cada tren que lo dice.
$aPatternsReal = json_decode((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-patterns-20261114.json'), true);
$SequencesAt = fn(string $sStop) => array_map(fn(array $aPattern) => $aPattern['stops'], array_filter($aPatternsReal['patterns'], fn(array $aPattern) => \in_array($sStop, \array_slice($aPattern['stops'], 0, -1), true)));
$aTripsReal = array_map(fn(array $aPattern) => $aPattern['trips'], $aPatternsReal['patterns']);
$aSequencesReal = $SequencesAt('8');
$aTuReal = GtfsRealtimeClient::parseTripUpdates((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-trip-updates-20261114.pb'));
$aMoy = \Services\PlatformMatcher::arrivalsAt('8', $aTuReal['trips'], $aSequencesReal, $aTripsReal, $aPatternsReal['segments'], $aTuReal['generatedAt'], $aMetroNet['gtfs_rt']['platform_matching'] ?? $aPmSettings);
$iSure = 0;
$aWrong = [];
foreach (['ABA' => '7', 'IND' => '9'] as $sSide => $sPlatform) {
    foreach (json_decode((string)file_get_contents(__DIR__ . "/fixtures/metro-bilbao-oficial-MOY-$sSide-20261114.json"), true)['trains'] as $aOfficial) {
        $iOfficial = (new DateTimeImmutable($aOfficial['time'], new DateTimeZone('Europe/Madrid')))->getTimestamp();
        $aSide = array_filter($aMoy, fn(array $aArrival) => $aArrival['next'] === $sPlatform);
        usort($aSide, fn(array $aA, array $aB) => abs($aA['time'] - $iOfficial) <=> abs($aB['time'] - $iOfficial));
        if (\count($aSide[0]['fits']) === 1) {
            $iSure++;
            $sOurs = $aPatternsReal['names'][array_key_first($aSide[0]['fits'])];
            if ($sOurs !== $aOfficial['direction']) {
                $aWrong[] = $aOfficial['direction'] . ' ≠ ' . $sOurs;
            }
        }
    }
}
$Expect('Moyua real: de los 16 trenes de la app oficial, 13 con un solo destino del feed abierto, todos el mismo (los demás, con la lista cortada o un destino raro, los decide el horario)', $iSure === 13 && $aWrong === [], $iSure . ' ' . json_encode($aWrong));
$aTuGobela = GtfsRealtimeClient::parseTripUpdates((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-trip-updates-20261114-gobela.pb'));
$aGobela = \Services\PlatformMatcher::arrivalsAt('20', $aTuGobela['trips'], $SequencesAt('20'), $aTripsReal, $aPatternsReal['segments'], $aTuGobela['generatedAt'], $aMetroNet['gtfs_rt']['platform_matching'] ?? $aPmSettings);
$aGobelaById = array_column(array_map(fn(array $aArrival) => ['id' => $aTuGobela['trips'][$aArrival['train']]['tripId'], 'to' => array_map('strval', array_keys($aArrival['fits'])), 'next' => $aArrival['next']], $aGobela), null, 'id');
$Expect('Gobela real: «27, 28, 20» (Urduliz con el id de Gobela) no es un tren hacia Etxebarri en Gobela, a ese ritmo no puede ser', !isset($aGobelaById['902940']) && \count($aGobelaById) > 10, json_encode(array_keys($aGobelaById)));
$Expect('Gobela real: el que acaba en Sopela puede ir a Urduliz (exacto, raro) o a Plentzia (usual) y decide el horario; hacia Neguri', ($aGobelaById['903282']['to'] ?? null) === ['29', '30'] && $aGobelaById['903282']['next'] === '21', json_encode($aGobelaById['903282'] ?? null));
// 19:43: el tren 903210 hacia Plentzia trae «19, 21, …, 28, 20» (el feed da una sola parada «20»: aquí Urduliz) y
// se salta Gobela. La app oficial lo daba en Gobela a las 19:54:29.
$aTuPlentzia = GtfsRealtimeClient::parseTripUpdates((string)file_get_contents(__DIR__ . '/fixtures/metro-bilbao-trip-updates-20261114-gobela-plentzia.pb'));
$aSkipped = array_values(array_filter(\Services\PlatformMatcher::arrivalsAt('20', $aTuPlentzia['trips'], $SequencesAt('20'), $aTripsReal, $aPatternsReal['segments'], $aTuPlentzia['generatedAt'], $aMetroNet['gtfs_rt']['platform_matching'] ?? $aPmSettings), fn(array $aArrival) => $aTuPlentzia['trips'][$aArrival['train']]['tripId'] === '903210'));
$iOfficialSkipped = (new DateTimeImmutable('2026-11-14 19:54:29', new DateTimeZone('Europe/Madrid')))->getTimestamp();
$Expect('Gobela real: la parada que la lista se salta sale entre la de antes y la de después (a menos de 30 s de la oficial), hacia Plentzia', \count($aSkipped) === 1 && abs($aSkipped[0]['time'] - $iOfficialSkipped) < 30 && array_map('strval', array_keys($aSkipped[0]['fits'])) === ['30'] && $aSkipped[0]['next'] === '21', json_encode($aSkipped));
$aMoySiri = \Services\PlatformMatcher::arrivalsAt('8', $aSiriReal, $aSequencesReal, $aTripsReal, $aPatternsReal['segments'], $aTuReal['generatedAt'], $aMetroNet['gtfs_rt']['platform_matching'] ?? $aPmSettings);
$aBasauri = array_values(array_filter($aMoySiri, fn(array $aArrival) => $aSiriReal[$aArrival['train']]['tripId'] === '903106'));
$aUrduliz = array_values(array_filter($aMoySiri, fn(array $aArrival) => $aSiriReal[$aArrival['train']]['tripId'] === '902928'))[0]['fits'] ?? [];
$Expect('Moyua real con el SIRI: el tren que manda Urduliz como Gobela (20) va a Plentzia, con la parada arreglada', array_map('strval', array_keys($aUrduliz)) === ['30'] && end($aUrduliz[30]['stops'])[0] === '29', json_encode(array_keys($aUrduliz)));
$Expect('Moyua real con el SIRI: el mismo tren, el mismo destino (Basauri)', \count($aBasauri) === 1 && array_map('strval', array_keys($aBasauri[0]['fits'])) === ['51'], json_encode($aBasauri));

$Section('Directo del planificador');
$aParsed = \Controllers\PlannerController::legsByNetwork('metro|20|MB|57063|903255,metro|20|L2|57100|9,bus|3950|A3515|59402|trp%7C1,nadie|1|X|60|y');
$Expect('los tramos se agrupan por red y parada, con su línea, su hora y el viaje (con su escape), y una red que no existe se pasa por alto', $aParsed === ['metro' => ['20' => [['MB', 57063, '903255'], ['L2', 57100, '9']]], 'bus' => ['3950' => [['A3515', 59402, 'trp|1']]]], json_encode($aParsed));
$Expect('un tramo sin hora, con partes vacías o de más no vale; sin tramos, nada', \Controllers\PlannerController::legsByNetwork('metro|20|MB|x|1') === null && \Controllers\PlannerController::legsByNetwork('metro||MB|1|1') === null && \Controllers\PlannerController::legsByNetwork('metro|20|MB|1|1|2') === null && \Controllers\PlannerController::legsByNetwork('') === []);
$Expect('más de 40 tramos no vale', \Controllers\PlannerController::legsByNetwork(implode(',', array_fill(0, 41, 'metro|20|MB|1|1'))) === null);
$aPlannerUpdates = \Controllers\PlannerController::updatesOf('metro', ['a' => ['status' => 'live', 'delaySeconds' => 245], 'b' => ['status' => 'live', 'delaySeconds' => -600], 'c' => ['status' => 'cancelled', 'delaySeconds' => 0], 'd' => ['status' => 'scheduled', 'delaySeconds' => 0], 'e' => ['status' => 'estimated', 'delaySeconds' => 0], 'f' => ['status' => 'live', 'delaySeconds' => 60, 'yesterday' => true]], 20407);
$Expect('en directo va el retraso entero y el adelanto hasta 2 min; cancelado, cancelado; lo estimado o del horario no sale; cada viaje con su día de servicio (el de ayer de madrugada, con el de ayer)', $aPlannerUpdates === [['network' => 'metro', 'trip' => 'a', 'day' => 20407, 'delay' => 245, 'cancelled' => false], ['network' => 'metro', 'trip' => 'b', 'day' => 20407, 'delay' => -120, 'cancelled' => false], ['network' => 'metro', 'trip' => 'c', 'day' => 20407, 'delay' => 0, 'cancelled' => true], ['network' => 'metro', 'trip' => 'f', 'day' => 20406, 'delay' => 60, 'cancelled' => false]], json_encode($aPlannerUpdates));
$aRefuerzo = ['sequence' => ['A', 'B', 'C', 'D'], 'headsign' => 'Uve'];
$aRefuerzoTimes = ['A' => $iMidPm + 1000, 'B' => $iMidPm + 1100, 'D' => $iMidPm + 1300];
$aPlannerExtra = \Services\PlannerExtras::extraOf('metro', 'MB', 'rt:t3', $aRefuerzo, $aRefuerzoTimes, $iMidPm, 900);
$Expect('un refuerzo para el planificador: sus paradas en el orden del recorrido con su hora del día, sin las que no tienen hora', $aPlannerExtra === ['network' => 'metro', 'line' => 'MB', 'trip' => 'rt:t3', 'headsign' => 'Uve', 'stops' => [['stop' => 'A', 'arrive' => 1000, 'depart' => 1000], ['stop' => 'B', 'arrive' => 1100, 'depart' => 1100], ['stop' => 'D', 'arrive' => 1300, 'depart' => 1300]]], json_encode($aPlannerExtra));
$Expect('un refuerzo que no pasa por ninguna parada en las próximas 2 h no sale (ni el que ya pasó ni el de dentro de 3 h)', \Services\PlannerExtras::extraOf('metro', 'MB', 'x', $aRefuerzo, $aRefuerzoTimes, $iMidPm, 1400) === null && \Services\PlannerExtras::extraOf('metro', 'MB', 'x', $aRefuerzo, $aRefuerzoTimes, $iMidPm, 1000 - 3 * 3600) === null);
$aPlannerDay = \Services\PlannerExtras::extraOf('metro', 'MB', 'rt:t3', $aRefuerzo, $aRefuerzoTimes, $iMidPm, 900, 20772, true);
$Expect('el refuerzo lleva su día de servicio y, si la ficha lo dice, que es accesible', ($aPlannerDay['day'] ?? null) === 20772 && ($aPlannerDay['accessible'] ?? null) === true && array_key_last($aPlannerDay) === 'stops', json_encode($aPlannerDay));
$aOddTimes = ['A' => $iMidPm - 30, 'B' => $iMidPm + 1100, 'C' => $iMidPm + 1050, 'D' => $iMidPm + 1300];
$aOdd = \Services\PlannerExtras::extraOf('metro', 'MB', 'x', $aRefuerzo, $aOddTimes, $iMidPm, 900);
$Expect('del refuerzo se quitan las paradas de hora negativa (antes de medianoche) y las que retroceden', array_column($aOdd['stops'] ?? [], 'stop') === ['B', 'D'], json_encode($aOdd));
$Expect('la ficha de la red dice si su flota es toda accesible; si no, no se sabe', \Services\PlannerExtras::fleetAccessible(['accessibility' => ['fleet' => 'all']]) === true && \Services\PlannerExtras::fleetAccessible(['accessibility' => ['fleet' => 'some']]) === null && \Services\PlannerExtras::fleetAccessible([]) === null);
$KnownTrips = new class extends ServiceJourney {
    public function __construct()
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function journeyIdsForTripRefs(array $aTripRefs, DateTime|null $DatedDay = null): array
    {
        return array_intersect_key(['alias-1' => '896700'], array_flip($aTripRefs));
    }

    public function lineOfJourney(string $sServiceJourneyId): array|null
    {
        if ($sServiceJourneyId === '896643') {
            return ['id' => 'MB', 'code' => 'MB'];
        }
        return null;
    }
};
$Expect('un tren «sin horario» cuyo id del feed es un viaje del horario (o su alias) no es un refuerzo', \Services\PlannerExtras::scheduled($KnownTrips, '896643') && \Services\PlannerExtras::scheduled($KnownTrips, 'alias-1') && !\Services\PlannerExtras::scheduled($KnownTrips, '999') && !\Services\PlannerExtras::scheduled($KnownTrips, ''));
$Expect('un refuerzo con la hora de una sola parada no sale', \Services\PlannerExtras::extraOf('metro', 'MB', 'x', $aRefuerzo, ['A' => $iMidPm + 1000], $iMidPm, 900) === null);

$Section('Servicio reducido (huelgas, servicios mínimos)');
$aReducedRules = \Services\ReducedService::rules();
$TodayStrike = new DateTimeImmutable('2026-11-14');
$iNowStrike = (new DateTimeImmutable('2026-11-14 12:00', new DateTimeZone(\Services\Calendar::TIME_ZONE)))->getTimestamp();
$aRenfeLines = ['60T0001C1' => ['60T0001C1'], '60T0002C1' => ['60T0002C1'], '60T0003C2' => ['60T0003C2'], 'C2' => ['60T0003C2']];
$Reduced = fn(array $aAlerts, array $aLines = [], bool $bShared = false, DateTimeImmutable|null $Today = null) => \Services\ReducedService::fromAlerts($aAlerts, $aReducedRules, $Today ?? $TodayStrike, $iNowStrike, $aLines, $bShared);
// Avisos reales de Renfe del 14/11/2026: la huelga del 17 de noviembre (uno en Bilbao, 60T, y otro en Donostia, 61T)
// y dos que no son de huelga.
$aRenfeStrike = RenfeAlertsClient::parse((string)file_get_contents(__DIR__ . '/fixtures/renfe-alerts-huelga-20261114.json'), [], null);
$aStrike = $Reduced($aRenfeStrike, $aRenfeLines);
$Expect('avisos reales de Renfe: la huelga marca el 17 de noviembre, solo en sus líneas (el aviso de 61T no es de esta red)', $aStrike['days'] === ['2026-11-17'] && $aStrike['lines'] === ['60T0002C1', '60T0001C1', '60T0003C2'] && $aStrike['cause'] === 'strike' && \count($aRenfeStrike) === 4, json_encode($aStrike));
$aStrikeDay = $Reduced($aRenfeStrike, $aRenfeLines, false, new DateTimeImmutable('2026-11-17'));
$Expect('el mismo aviso el día 17: hoy hay servicio reducido', \Services\ReducedService::verdict($aStrikeDay, false, '2026-11-17')['today'] && \Services\ReducedService::verdict($aStrikeDay, false, '2026-11-17')['sources'] === ['alerts']);
// Avisos reales de Renfe del 16/11/2026: la huelga del 17 en siete núcleos de Cercanías (Bilbao, Donostia, Valencia…)
// es de toda Renfe y vale para media y larga distancia, que no tiene avisos; la del 14/11 solo sale en dos.
$aWideRule = \Core\Networks::get('renfe-ld')['renfe_alerts']['operator_wide'];
$TodayWide = new DateTimeImmutable('2026-11-16');
$aRenfeWide = RenfeAlertsClient::parse((string)file_get_contents(__DIR__ . '/fixtures/renfe-alerts-huelga-20261116.json'), [], null);
$aWide = \Services\ReducedService::operatorWide($aRenfeWide, $aWideRule, $aReducedRules, $TodayWide);
$Expect('huelga de toda Renfe (siete núcleos): media y larga distancia, el 17 de noviembre y por huelga', $aWide['days'] === ['2026-11-17'] && $aWide['cause'] === 'strike' && str_contains((string)$aWide['alert'], 'huelga') && !str_contains((string)$aWide['alert'], 'SEVILLA'), json_encode($aWide));
$aLdVerdict = \Services\ReducedService::withOperatorWide(\Services\ReducedService::fromAlerts([], $aReducedRules, $TodayWide, $iNowStrike, [], true), $aWide);
$Expect('la huelga de toda Renfe es de toda la red de media y larga distancia (sin líneas sueltas)', $aLdVerdict['days'] === ['2026-11-17'] && $aLdVerdict['lines'] === null, json_encode($aLdVerdict));
$aNarrow = \Services\ReducedService::operatorWide($aRenfeStrike, $aWideRule, $aReducedRules, $TodayStrike);
$Expect('una huelga en dos núcleos no es de toda Renfe', $aNarrow['days'] === [], json_encode($aNarrow));
$aUnchanged = \Services\ReducedService::withOperatorWide($aStrike, ['days' => [], 'alert' => null, 'cause' => null]);
$Expect('sin huelga de todo el operador, lo de los avisos de la red queda igual', $aUnchanged === $aStrike);
$aCases = [
    'otra red refuerza' => [[['title' => 'Refuerzo de servicio', 'description' => 'Con motivo de la huelga de Bizkaibus, Metro Bilbao refuerza su servicio']], []],
    'en vigor, sin fechas' => [[['summary' => 'Greba: gutxieneko zerbitzuak', 'startTime' => '2026-11-14T06:00:00+01:00']], ['2026-11-14']],
    'caducado, sin fechas' => [[['summary' => 'Huelga: servicios mínimos', 'startTime' => '2026-11-03T05:00:00+01:00', 'endTime' => '2026-11-03T23:59:00+01:00']], []],
    'empieza otro día, sin fechas' => [[['summary' => 'Paros parciales', 'startTime' => '2026-11-21T06:00:00+01:00']], []],
    'desconvoca una, mantiene otra' => [[['summary' => 'Huelga', 'description' => 'Se desconvoca la huelga del 17 de noviembre. Se mantiene la huelga del 24 de noviembre']], ['2026-11-24']],
    'desconvoca una, mantiene otra (una frase)' => [[['summary' => 'Huelga: se desconvoca la del 17 de noviembre, se mantiene la del 24 de noviembre']], ['2026-11-24']],
    'no desconvocan' => [[['summary' => 'Los sindicatos no desconvocan la huelga del 17 de noviembre']], ['2026-11-17']],
    'ez dute desdeitu' => [[['summary' => 'Sindikatuek ez dute desdeitu azaroaren 17ko greba']], ['2026-11-17']],
    'huelga pasada con fecha' => [[['summary' => 'Huelga el 13 de noviembre']], []],
    'vuelta a la normalidad' => [[['summary' => 'Fin de la huelga', 'description' => 'Servicio normal a partir de hoy tras la huelga del 13 de noviembre']], []],
    'se recupera' => [[['summary' => 'Se recupera el servicio', 'description' => 'Finalizada la huelga, se recupera el servicio habitual']], []],
    'ascensor' => [[['summary' => 'Ascensor de Santutxu fuera de servicio']], []],
    'fecha sin leer' => [[['summary' => 'Huelga en mayo']], []],
];
$aSeen = array_map(fn(array $aCase) => $Reduced($aCase[0])['days'], $aCases);
$Expect('avisos: en vigor o no, desconvocadas (por frase y con negación), pasadas, refuerzos, vuelta a la normalidad y sin fecha legible', $aSeen === array_map(fn(array $aCase) => $aCase[1], $aCases), json_encode($aSeen));
$aWorks = $Reduced([['summary' => 'Servicio reducido por obras en la línea 2', 'lineRefs' => ['60T0003C2']]], $aRenfeLines);
$Expect('un servicio reducido por obras no es una huelga: otra causa y solo en su línea', $aWorks['cause'] === 'reduced' && $aWorks['lines'] === ['60T0003C2'] && $aWorks['days'] === ['2026-11-14'], json_encode($aWorks));
$aShared = $Reduced([['summary' => 'Greba gaur', 'lineRefs' => []], ['summary' => 'Huelga hoy en el tranvía', 'lineRefs' => ['TR']]], ['E1' => ['E1']], true);
$Expect('feed compartido (Euskotren y tranvías): sin líneas o con las de otra red, no cuenta', $aShared['days'] === [], json_encode($aShared));
$aOperatorAlerts = \Services\SiriAlertsClient::withOperator([['summary' => 'Greba', 'lineRefs' => ['110']]], 'ekialdebus');
$Expect('Lurraldebus: las líneas de los avisos de cada operador llevan delante el suyo, como en la base', $aOperatorAlerts[0]['lineRefs'] === ['ekialdebus:110'] && $Reduced($aOperatorAlerts, ['ekialdebus:110' => ['ekialdebus:110']])['lines'] === ['ekialdebus:110']);
$aSharing = array_map(fn(string $sSlug) => \Services\ReducedService::sharesAlerts(\Core\Networks::get($sSlug)), ['euskotren', 'tranvia-bilbao', 'metro', 'renfe', 'lurraldebus']);
$Expect('avisos compartidos: Euskotren y el tranvía leen el mismo feed, Metro y Lurraldebus (avisos por operador) no', $aSharing[0] && $aSharing[1] && !$aSharing[2] && !$aSharing[4], json_encode($aSharing));
$aDatesCases = [
    'huelga del 17 al 19 de noviembre' => ['2026-11-17', '2026-11-18', '2026-11-19'],
    'huelga los dias 17, 18 y 19 de noviembre' => ['2026-11-17', '2026-11-18', '2026-11-19'],
    'huelga 29, 30 y 31 de diciembre' => ['2026-12-29', '2026-12-30', '2026-12-31'],
    'huelga del 30 de diciembre al 2 de enero' => ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'],
    'huelga del 30 al 2 de enero' => ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'],
    'greba azaroaren 17, 18 eta 19an' => ['2026-11-17', '2026-11-18', '2026-11-19'],
    'greba abenduaren 30etik urtarrilaren 2ra' => ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'],
    'greba 2026ko azaroaren 17an' => ['2026-11-17'],
    'huelga el 17-11-2026' => ['2026-11-17'],
    'huelga 17.11.2026' => ['2026-11-17'],
    'huelga el lunes 17 de noviembre de 2026 de 6:00 a 9:30' => ['2026-11-17'],
    'huelga 1/2 jornada el 17 de noviembre' => ['2026-11-17'],
    'greve du 17/11 au 18/11' => ['2026-11-17', '2026-11-18'],
    'huelga en el servicio de la linea 1, 10 de las expediciones afectadas el 17 de noviembre' => ['2026-11-17'],
    'huelga indefinida desde el 13 de diciembre' => array_map(fn(int $iDay) => sprintf('2026-12-%02d', $iDay), range(13, 15)),
    'huelga a partir de las 6:00 del 17 de noviembre' => ['2026-11-17'],
];
$aDatesSeen = [];
foreach (array_keys($aDatesCases) as $sText) {
    $aDatesSeen[$sText] = \Services\ReducedService::datesIn($sText, $aReducedRules, $TodayStrike)['days'];
}
$Expect('fechas de los avisos: rangos que cambian de mes, listas con comas, euskera, francés, con año, «1/2 jornada» y desde un día', $aDatesSeen === $aDatesCases, json_encode(array_diff_assoc(array_map('json_encode', $aDatesSeen), array_map('json_encode', $aDatesCases))));

// Día de huelga simulado en el directo: en las paradas de muestra solo pasa 1 de cada 4 trenes que tocaban.
$aLiveRules = ['window_seconds' => 1200, 'rows_per_stop' => 40, 'min_expected' => 8, 'missing_share' => 0.6, 'confirm_seconds' => 900];
$StrikeJourneys = new class (new PDO('sqlite::memory:')) extends ServiceJourney {
    public function upcomingAtStop(int|string $iStopId, int $iLimit = 8, int $iWindowSeconds = 4 * 3600, int $iDaysAhead = 0, int|null $iPastSeconds = null): array
    {
        $aRows = [];
        for ($iTrain = 0; $iTrain < 8; $iTrain++) {
            $aRows[] = ['service_journey_id' => $iStopId . '-' . $iTrain, 'line_id' => 'L1', 'trip_number' => (string)$iTrain, 'first_departure_seconds' => 12 * 3600 - 600 + $iTrain * 60, 'arrival_seconds' => 12 * 3600 + $iTrain * 150, 'departure_seconds' => 12 * 3600 + $iTrain * 150];
        }
        $aRows[] = ['service_journey_id' => $iStopId . '-later', 'line_id' => 'L1', 'trip_number' => 'x', 'first_departure_seconds' => 12 * 3600 + 900, 'arrival_seconds' => 12 * 3600 + 1100, 'departure_seconds' => 12 * 3600 + 1100];
        return $aRows;
    }
};
$FeedOf = fn(int $iEvery) => new class ($iEvery) {
    public function __construct(private int $iEvery)
    {
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aOut = [];
        foreach ($aRows as $iRow => $aRow) {
            if ($iRow % $this->iEvery === 0) {
                $aOut[] = ['status' => 'live'] + $aRow;
            }
        }
        return $aOut;
    }
};
$aStrikeSample = \Services\ReducedService::missingAtStops($StrikeJourneys, $FeedOf(4), ['A', 'B'], $aLiveRules, 12 * 3600);
$aNormalSample = \Services\ReducedService::missingAtStops($StrikeJourneys, $FeedOf(1), ['A', 'B'], $aLiveRules, 12 * 3600);
$Expect('huelga simulada: de 16 trenes que tocaban (los que aún no han salido de cabecera no cuentan), faltan 12', $aStrikeSample === [16, 12] && $aNormalSample === [16, 0], json_encode([$aStrikeSample, $aNormalSample]));
$Samples = function (array|null $aSample, int $iMinutes, array $aStart = []) use ($aLiveRules): array {
    $aSamples = $aStart;
    for ($iMinute = 0; $iMinute <= $iMinutes; $iMinute++) {
        $aSamples = \Services\ReducedService::withSample($aSamples, $aSample, 1000000 + $iMinute * 60, $aLiveRules);
    }
    return $aSamples;
};
$aSamples = $Samples($aStrikeSample, 20);
$Expect('huelga simulada: con 20 min de muestras así, la red sale como reducida', \Services\ReducedService::confirmed($aSamples, 1000000 + 1200, $aLiveRules));
$Expect('una sola muestra así no basta', !\Services\ReducedService::confirmed(\Services\ReducedService::withSample([], $aStrikeSample, 1000000, $aLiveRules), 1000000, $aLiveRules));
$aWithGap = \Services\ReducedService::withSample($aSamples, null, 1000000 + 1260, $aLiveRules);
$Expect('un hueco del feed no añade muestra ni borra las que había', $aWithGap === $aSamples && \Services\ReducedService::confirmed($aWithGap, 1000000 + 1260, $aLiveRules));
$aEmptyDay = $Samples(null, 60);
$Expect('feed vacío todo el rato (Bilbobus, Lurraldebus a ratos): no hay muestras y no dispara el aviso', $aEmptyDay === [] && !\Services\ReducedService::confirmed($aEmptyDay, 1000000 + 3600, $aLiveRules));
$Expect('un día normal (4 de 54 sin pasar, lo medido en Metro) no sale como reducido', !\Services\ReducedService::confirmed($Samples([54, 4], 20), 1000000 + 1200, $aLiveRules));
$Expect('de madrugada, con muy pocos viajes que tocaban, no se juzga', !\Services\ReducedService::confirmed($Samples([5, 5], 20), 1000000 + 1200, $aLiveRules));
$iEarly = (new DateTimeImmutable('2026-11-15 02:30', new DateTimeZone(\Services\Calendar::TIME_ZONE)))->getTimestamp();
$Expect('a las 02:30 el día de servicio aún es el de ayer', \Services\ReducedService::serviceDay($iEarly, $aReducedRules)->format('Y-m-d') === '2026-11-14' && \Services\ReducedService::serviceDay($iNowStrike, $aReducedRules)->format('Y-m-d') === '2026-11-14');

$Section('Latido del cron externo (/api/live/record) y última vez que vino lleno cada feed');
$aBeat = \Services\CronHeartbeat::commands(['bus' => 120, 'bilbobus' => 0, 'lurraldebus' => null], 1000);
$bBeatShape = count($aBeat) === 2 && $aBeat[0][0] === 'SET' && $aBeat[0][2] === '1000' && $aBeat[0][3] === 'EX';
$Expect('el latido es un SET con caducidad, más uno por cada red que vino llena (no las vacías)',
    $bBeatShape && $aBeat[1][1] === 'live:full:bus', json_encode($aBeat));
$sBeatMarker = sys_get_temp_dir() . '/bide-latido-' . getmypid();
@unlink($sBeatMarker);
$bFirst = \Services\CronHeartbeat::due($sBeatMarker, 5000);
touch($sBeatMarker, 5000);
$bSoon = \Services\CronHeartbeat::due($sBeatMarker, 5000 + \Services\CronHeartbeat::EVERY_SECONDS - 1);
$bLater = \Services\CronHeartbeat::due($sBeatMarker, 5000 + \Services\CronHeartbeat::EVERY_SECONDS);
unlink($sBeatMarker);
$Expect('se escribe como mucho cada 10 minutos, no en cada llamada del cron', $bFirst && !$bSoon && $bLater);
$aAges = \Services\CronHeartbeat::agesFrom(['400', null], 1000);
$Expect('/api/status: antigüedad del latido y, sin marca de lleno, null',
    $aAges['cronSeconds'] === 600 && $aAges['lastFullSeconds'] === null, json_encode($aAges));
$Expect('sin almacén o si no responde, no se dice nada', \Services\CronHeartbeat::agesFrom(null, 1000) === []);

// Nombres en formato título (Core\DisplayName) y medidas de títulos y descripciones (Core\Seo).
$aTitleCase = ['features' => ['title_case_names' => ['keep' => ['BBK', 'BBKren', 'CC', 'IMQ', 'McDonalds', "O'Donnell"], 'abbreviations' => ['AV' => 'Av.']]]];
$aNames = [
    'BILBAO INTERMODAL' => 'Bilbao Intermodal', 'AREETA/LAS ARENAS' => 'Areeta/Las Arenas', 'ARTXANDA (EL GALLO)' => 'Artxanda (El Gallo)',
    'BARRIO SAN JUAN DE DIOS' => 'Barrio San Juan de Dios', 'ERANDIO-LA LLANA' => 'Erandio-La Llana', 'MUNGIA XII' => 'Mungia XII',
    'COLEGIO JUAN XXIII' => 'Colegio Juan XXIII', 'CC MAX CENTER' => 'CC Max Center', 'MCDONALDS' => 'McDonalds',
    "O'DONNELL 12" => "O'Donnell 12", 'BBKREN UDALEKUA' => 'BBKren Udalekua', 'Mixed Case' => 'Mixed Case',
    'AV ALTOS HORNOS' => 'Av. Altos Hornos', 'AV. DEL ANGEL 18' => 'Av. del Angel 18', 'CLÍNICA IMQ' => 'Clínica IMQ',
    'INDALECIO PRIETO / JM LIDÓN' => 'Indalecio Prieto / JM Lidón', 'NH LA AVANZADA' => 'NH la Avanzada', 'CORRO (TC)' => 'Corro (TC)',
];
$aWrongNames = [];
foreach ($aNames as $sIn => $sOut) {
    if (\Core\DisplayName::of($sIn, $aTitleCase) !== $sOut) {
        $aWrongNames[] = $sIn . ' → ' . \Core\DisplayName::of($sIn, $aTitleCase);
    }
}
$Expect('formato título: artículos tras «/», «(» y «-» con mayúscula, romanos, siglas sin vocales, «Av.» y excepciones de la ficha', $aWrongNames === [], implode(' | ', $aWrongNames));
$Expect('formato título: sin title_case_names en la ficha, el nombre no cambia', \Core\DisplayName::of('BILBAO', ['features' => []]) === 'BILBAO');
$sLongTitle = \Core\Seo::title([str_repeat('Parada muy larga ', 6)]);
$Expect('título: si nada cabe, se recorta por una palabra con «…» y no pasa de 60', mb_strlen($sLongTitle) <= \Core\Seo::TITLE_MAX && str_ends_with($sLongTitle, '…'), $sLongTitle);
$sShortDescription = \Core\Seo::description([str_repeat('a', 100) . '.'], [str_repeat('b', 60), 'Gratis y sin anuncios.', str_repeat('c', 30)]);
$Expect('descripción corta: la frase de relleno más corta que la deja entre 120 y 155', mb_strlen($sShortDescription) === 124, (string)mb_strlen($sShortDescription));
$sLongDescription = \Core\Seo::description([str_repeat('palabra ', 30)], []);
$Expect('descripción larga: recortada a 155 como mucho', mb_strlen($sLongDescription) <= \Core\Seo::DESCRIPTION_MAX);
$Expect('medianoche de Madrid: lo que falta está entre 0 y 25 h', \Services\Calendar::secondsUntilMidnight() > 0 && \Services\Calendar::secondsUntilMidnight() <= 25 * 3600);

// Una fuente caída: con copia anterior, la copia; sin ella, [] contado como fallo, que no se guarda en el CDN ni cuenta
// como «sin incidencias»; y lo que no se entiende (una página de error) lanza para que Cache conserve el último bueno.
$sCacheKey = 'prueba_caida_' . bin2hex(random_bytes(4));
\Core\Cache::remember($sCacheKey . '_buena', 0, fn() => ['bueno']);
$iFailuresBefore = \Core\Cache::$iEmptyFailures;
$aStale = \Core\Cache::remember($sCacheKey . '_buena', 0, function () {
    throw new RuntimeException('caída');
});
$Expect('caché: si la fuente cae, sale la última copia buena y no cuenta como fallo vacío', $aStale === ['bueno'] && \Core\Cache::$iEmptyFailures === $iFailuresBefore);
$aEmpty = \Core\Cache::remember($sCacheKey . '_nueva', 60, function () {
    throw new RuntimeException('caída');
});
$Expect('caché: sin copia anterior sale [] y se cuenta', $aEmpty === [] && \Core\Cache::$iEmptyFailures === $iFailuresBefore + 1);
\Core\Response::$iCacheSeconds = 3600;
$Expect('caché: una respuesta hecha con una fuente caída no se guarda en el CDN', \Core\Response::cacheControl(200) === 'no-store');
\Core\Cache::$iEmptyFailures = 0;
$Expect('caché: sin fallos, la respuesta lleva su caché pública', str_contains(\Core\Response::cacheControl(200), 's-maxage=3600'));
\Core\Response::$iCacheSeconds = 0;
foreach (glob(sys_get_temp_dir() . '/bizkaibusplus_' . $sCacheKey . '*') as $sCacheFile) {
    unlink($sCacheFile);
}
$aUnreadable = [];
foreach ([[Services\ScheduleTextClient::class, '<html>Mantenimiento</html>'], [Services\MetroAlertsClient::class, '<html>Mantenimiento</html>'], [Services\SiriAlertsClient::class, '<html>Mantenimiento']] as [$sClass, $sBody]) {
    try {
        (new ReflectionMethod($sClass, 'parse'))->invoke(null, $sBody);
        $aUnreadable[] = $sClass;
    } catch (RuntimeException $Ex) {
    }
}
$Expect('fuentes: una página de error no se lee como «ninguna incidencia» ni «ningún horario»: lanza', $aUnreadable === [], implode(', ', $aUnreadable));
$IncidentFetch = new ReflectionMethod(Services\IncidentAlerts::class, 'fetch');
$aFetched = $IncidentFetch->invoke(null, function () {
    \Core\Cache::$iEmptyFailures++;
    return [];
}, 'metro', null, null);
$Expect('avisos de incidencias: una fuente caída sin copia es un fallo (null), no la línea base vacía', $aFetched === null);
\Core\Cache::$iEmptyFailures = 0;

// Horario de una línea con una franja que pasa de medianoche: cada salida con el emparejador de su día de servicio. Las
// de ayer de después de las 24:00 van con el de ayer en sus horas (24:30); las de la madrugada de mañana, con su
// horario (antes pasaban por el de hoy y salían «puede haber pasado»).
$TimetableStatus = new ReflectionMethod(Controllers\TimetableController::class, 'withStatus');
$SeenBy = new ArrayObject([0 => [], -1 => []]);
$MatcherFor = fn(int $iOffset) => fn() => new class ($iOffset, $SeenBy) {
    public function __construct(private int $iOffset, private ArrayObject $Seen)
    {
    }
    public function enrich(array $aRows): array
    {
        $this->Seen[$this->iOffset] = array_merge($this->Seen[$this->iOffset], array_column($aRows, 'departure_seconds'));
        return array_map(fn(array $aRow) => $aRow + ['status' => 'live', 'delaySeconds' => 60], $aRows);
    }
};
$aTimetable = $TimetableStatus->invoke(null, new ServiceJourney(buildFixture(40)), [
    ['trip_number' => 'ayer', 'departure_seconds' => 1800, 'dayOffset' => -1],
    ['trip_number' => 'hoy', 'departure_seconds' => 80000, 'dayOffset' => 0],
    ['trip_number' => 'manana', 'departure_seconds' => 86400 + 18000, 'dayOffset' => 1],
], Calendar::todayMadrid(), [0 => $MatcherFor(0), -1 => $MatcherFor(-1)]);
$Expect('horario de línea: la de ayer va con el de ayer a las 24:30, la de hoy con el de hoy y la de mañana con su horario, en orden',
    array_column($aTimetable, 'status') === ['live', 'live', 'scheduled'] && array_column($aTimetable, 'departure_seconds') === [1800, 80000, 104400]
    && $SeenBy[-1] === [88200] && $SeenBy[0] === [80000], json_encode([$aTimetable, $SeenBy->getArrayCopy()]));

$Section('Pantalla de carga de cada app');
require_once __DIR__ . '/../api/Views/bide-components.php';
$sNetworksCss = (string)file_get_contents(__DIR__ . '/../css/style-networks.css');
foreach (\Core\Networks::all() as $sSplashSlug => $aSplashNetwork) {
    $sSplash = bideAppSplash($aSplashNetwork, $sSplashSlug);
    $bLogo = \Core\Networks::splashLogoPath($aSplashNetwork) !== null;
    $bSpins = substr_count($sSplash, 'splash-spin') === 1;
    $bOwnBackground = !$bLogo || str_contains($sNetworksCss, ".splash--$sSplashSlug {");
    $sKind = 'logo con su nombre';
    if ($bLogo) {
        $sKind = 'solo su logo';
    }
    $Expect("$sSplashSlug: pantalla de carga con $sKind, un solo «+» que gira y su fondo en el CSS", $bSpins && $bOwnBackground && str_contains($sSplash, 'aria-hidden="true"'));
}
$Expect('Renfe: «Cercanías» debajo del nombre en la pantalla de carga', str_contains(bideAppSplash(\Core\Networks::get('renfe'), 'renfe'), '<span class="splash-city">CERCANÍAS</span>'));
$Expect('Tranvía Bilbao: la ciudad debajo, como en la cabecera', str_contains(bideAppSplash(\Core\Networks::get('tranvia-bilbao'), 'tranvia-bilbao'), '<span class="splash-city">BILBAO</span>'));

$Finish('unitarias');
