<?php

declare(strict_types=1);

/*
 * Horario oficial completo de cada red para usarlo sin conexión: offline-data/<red>.json y offline-data/index.json.
 * Se rehace con las bases en cada reconstrucción; el móvil solo guarda las redes que elige la persona (js/offline.js).
 *
 * Cada recorrido y cada patrón de horas de paso se guardan una vez; cada viaje solo lleva su recorrido, sus horas, su
 * hora de salida y su calendario. Un viaje pasa por una parada a «salida + desfase» de esa parada. Los calendarios son
 * los mismos que usa la API (días de la semana y excepciones), sin el de pruebas.
 *
 * Con el motor de llegadas a mano (engine/, que el workflow trae del repositorio privado), las redes que lo usan llevan
 * también la hora estimada de cada viaje con los tramos aprendidos y lo que suele apartarse ese viaje, para un día
 * laborable y para un fin de semana: sin conexión la parada enseña primero esa estimación y al lado la hora oficial,
 * como con conexión y sin datos del bus.
 *
 * Uso: php scripts/build-offline-data.php [--out=dir]
 */

ini_set('memory_limit', '1024M');
date_default_timezone_set('Europe/Madrid');

require_once __DIR__ . '/../api/autoload.php';

use Core\Networks;

const OFFLINE_FORMAT = 2;
// Como el motor sin datos del bus: la salida real de cabecera va un minuto después de la publicada.
const ESTIMATE_ORIGIN_OFFSET_SECONDS = 60;
// Por debajo de este reparto de tiempo aprendido, la estimación no basta para decir que un bus ya pasó.
const ESTIMATE_TRUSTED_SHARE = 0.8;
// Un día de cada tipo para elegir la franja horaria de los tramos aprendidos, y sus días en la máscara del calendario
// (de lunes a viernes; sábado y domingo).
const ESTIMATE_DAYS = ['laborable' => '2026-11-04', 'fin_semana' => '2026-11-07'];
const ESTIMATE_DAY_BITS = ['laborable' => 0b0011111, 'fin_semana' => 0b1100000];
// Cada calendario es [máscara de días de la semana, días que se añaden, días que se quitan].
const CALENDAR_WEEKDAYS = 0;
const CALENDAR_ADDED_DAYS = 1;
const CALENDAR_REMOVED_DAYS = 2;

$sOut = __DIR__ . '/../offline-data';
foreach ($argv as $sArg) {
    if (str_starts_with($sArg, '--out=')) {
        $sOut = substr($sArg, 6);
    }
}
if (!is_dir($sOut)) {
    mkdir($sOut, 0775, true);
}

$aIndex = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    if (!is_file($aNetwork['db_path'])) {
        fwrite(STDERR, "  $sSlug: sin base de datos, se omite\n");
        continue;
    }
    $aData = buildOfflineNetwork($sSlug, $aNetwork);
    $sJson = json_encode($aData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents("$sOut/$sSlug.json", $sJson);
    $aIndex[] = ['slug' => $sSlug, 'bytes' => \strlen($sJson), 'generated' => $aData['generated']];
    printf("  %s: %d viajes, %d recorridos, %d horas estimadas, %.0f KB\n", $sSlug, \count($aData['trips']), \count($aData['patterns']), \count($aData['estimates']), \strlen($sJson) / 1024);
}
file_put_contents("$sOut/index.json", json_encode(['format' => OFFLINE_FORMAT, 'networks' => $aIndex], JSON_UNESCAPED_SLASHES));

// Una red entera para usarla sin conexión: sus líneas, calendarios, recorridos, patrones de horas y viajes, y las
// paradas que usan.
function buildOfflineNetwork(string $sSlug, array $aNetwork): array
{
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    [$aLines, $aLineIndex] = offlineLines($Pdo);
    [$aCalendars, $aCalendarIndex] = offlineCalendars($Pdo);
    $aJourneys = offlineJourneys($Pdo, $aLineIndex, $aCalendarIndex);
    $Estimator = offlineEstimator($aNetwork);

    $Patterns = new IndexedList();
    $Timings = new IndexedList();
    $Estimates = new IndexedList();
    $aTrips = [];
    $aStopsUsed = [];
    foreach (journeyStopTimes($Pdo) as [$sJourneyId, $aStops, $aTimes, $aMeters, $iFirstDeparture]) {
        if (!isset($aJourneys[$sJourneyId]) || \count($aStops) < 2) {
            continue;
        }
        $aJourney = $aJourneys[$sJourneyId];
        $iFirst = $aTimes[0];
        $sPatternKey = $aJourney['line_id'] . '|' . $aJourney['headsign'] . '|' . implode(',', $aStops);
        if (!$Patterns->has($sPatternKey)) {
            foreach ($aStops as $sStopId) {
                $aStopsUsed[$sStopId] = true;
            }
        }
        $iPattern = $Patterns->add($sPatternKey, [$aLineIndex[$aJourney['line_id']], (string)$aJourney['headsign'], $aStops]);
        $aOffsets = array_map(fn($iTime) => $iTime - $iFirst, $aTimes);
        $iTiming = $Timings->add(implode(',', $aOffsets), $aOffsets);
        $iCalendar = $aCalendarIndex[$aJourney['calendar_id']];
        $aTrip = [$iPattern, $iTiming, $iFirst, $iCalendar, (string)$aJourney['trip_number'], (int)$aJourney['first_departure_seconds']];
        if ($Estimator !== null) {
            $iDeparture = $iFirstDeparture ?? $iFirst;
            array_push($aTrip, ...estimateSlots($Estimator, $Estimates, $aCalendars[$iCalendar], (string)$aJourney['line_id'], $aStops, $aOffsets, $aMeters, $iFirst, $iDeparture));
        }
        $aTrips[] = $aTrip;
    }
    [$aStopsOut, $aAllStops] = offlineStops($Pdo, array_keys($aStopsUsed));
    [$sReference, $aLon] = directionLongitudes($aNetwork, $aStopsOut, $aAllStops);

    return [
        'format' => OFFLINE_FORMAT,
        'network' => $sSlug,
        'generated' => date('Y-m-d'),
        'headsignFromLastStop' => (bool)($aNetwork['features']['headsign_from_last_stop'] ?? false),
        'directionReference' => $sReference,
        'lon' => (object)$aLon,
        'stops' => $aStopsOut,
        'lines' => $aLines,
        'calendars' => $aCalendars,
        'patterns' => $Patterns->values(),
        'timings' => $Timings->values(),
        'trips' => $aTrips,
        'estimates' => array_column($Estimates->values(), 0),
        'estimateTrusted' => array_column($Estimates->values(), 1),
    ];
}

// Una lista sin repetidos: cada valor distinto se guarda una vez y los viajes lo nombran por su posición.
final class IndexedList
{
    private array $aIndex = [];
    private array $aValues = [];

    public function has(string $sKey): bool
    {
        return isset($this->aIndex[$sKey]);
    }

    public function add(string $sKey, array $aValue): int
    {
        if (!isset($this->aIndex[$sKey])) {
            $this->aIndex[$sKey] = \count($this->aValues);
            $this->aValues[] = $aValue;
        }
        return $this->aIndex[$sKey];
    }

    public function values(): array
    {
        return $this->aValues;
    }
}

// Las líneas por código, y la posición de cada una por su id.
function offlineLines(PDO $Pdo): array
{
    $aLines = [];
    $aIndex = [];
    foreach ($Pdo->query('SELECT id, code, name FROM lines ORDER BY code') as $aRow) {
        $aIndex[$aRow['id']] = \count($aLines);
        $aLines[] = [(string)$aRow['id'], $aRow['code'], $aRow['name']];
    }
    return [$aLines, $aIndex];
}

// Los calendarios como los usa la API (días de la semana, días que se añaden y días que se quitan), sin el de pruebas,
// y la posición de cada uno por su id.
function offlineCalendars(PDO $Pdo): array
{
    $aCalendars = [];
    $aIndex = [];
    foreach ($Pdo->query("SELECT id, weekday_mask FROM service_calendars WHERE id != 'PRUEBA' ORDER BY id") as $aRow) {
        $aIndex[$aRow['id']] = \count($aCalendars);
        $aCalendars[] = [(int)$aRow['weekday_mask'], [], []];
    }
    foreach ($Pdo->query('SELECT calendar_id, date, available FROM service_calendar_exceptions ORDER BY date') as $aRow) {
        if (!isset($aIndex[$aRow['calendar_id']])) {
            continue;
        }
        $iSlot = CALENDAR_REMOVED_DAYS;
        if ((int)$aRow['available'] === 1) {
            $iSlot = CALENDAR_ADDED_DAYS;
        }
        $aCalendars[$aIndex[$aRow['calendar_id']]][$iSlot][] = $aRow['date'];
    }
    return [$aCalendars, $aIndex];
}

// Los viajes con línea y calendario conocidos, por su id.
function offlineJourneys(PDO $Pdo, array $aLineIndex, array $aCalendarIndex): array
{
    $aJourneys = [];
    $Statement = $Pdo->query('
        SELECT sj.id, sj.line_id, sj.trip_number, sj.calendar_id, sj.first_departure_seconds, jp.headsign
        FROM service_journeys sj
        JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
    ');
    foreach ($Statement as $aRow) {
        if (isset($aCalendarIndex[$aRow['calendar_id']], $aLineIndex[$aRow['line_id']])) {
            $aJourneys[$aRow['id']] = $aRow;
        }
    }
    return $aJourneys;
}

// Las horas de paso de cada viaje, en orden: [id, paradas, horas, metros desde la cabecera, salida de la cabecera].
// La hora que se enseña es la de llegada a la parada, como en la API. dist_m solo lo tienen las redes con trazado; sin
// él, los tramos sin aprender usan el ritmo.
function journeyStopTimes(PDO $Pdo): Generator
{
    $sMeters = 'NULL';
    foreach ($Pdo->query('PRAGMA table_info(passing_times)') as $aColumn) {
        if ($aColumn['name'] === 'dist_m') {
            $sMeters = 'dist_m';
        }
    }
    $sCurrent = null;
    $aStops = [];
    $aTimes = [];
    $aMeters = [];
    $iFirstDeparture = null;
    $Statement = $Pdo->query("SELECT service_journey_id, stop_id, arrival_seconds, departure_seconds, $sMeters AS dist_m FROM passing_times ORDER BY service_journey_id, seq_order");
    foreach ($Statement as $aRow) {
        if ($aRow['service_journey_id'] !== $sCurrent) {
            if ($sCurrent !== null) {
                yield [$sCurrent, $aStops, $aTimes, $aMeters, $iFirstDeparture];
            }
            $sCurrent = $aRow['service_journey_id'];
            $aStops = [];
            $aTimes = [];
            $aMeters = [];
            $iFirstDeparture = null;
        }
        $iTime = $aRow['arrival_seconds'] ?? $aRow['departure_seconds'];
        if ($iTime === null) {
            continue;
        }
        if ($aStops === [] && $aRow['departure_seconds'] !== null) {
            $iFirstDeparture = (int)$aRow['departure_seconds'];
        }
        $aStops[] = (string)$aRow['stop_id'];
        $aTimes[] = (int)$iTime;
        $aMeters[] = $aRow['dist_m'];
    }
    if ($sCurrent !== null) {
        yield [$sCurrent, $aStops, $aTimes, $aMeters, $iFirstDeparture];
    }
}

// Posiciones 6 y 7 de cada viaje: su hora estimada en laborable y en fin de semana, como índice en «estimates» (-1 si
// ese tipo de día no circula).
function estimateSlots(Closure $Estimator, IndexedList $Estimates, array $aCalendar, string $sLineId, array $aStops, array $aOffsets, array $aMeters, int $iFirst, int $iDeparture): array
{
    $aSlots = [];
    $bHasAddedDays = !empty($aCalendar[CALENDAR_ADDED_DAYS]);
    foreach (ESTIMATE_DAY_BITS as $sDayType => $iDayBits) {
        $iSlot = -1;
        if (($aCalendar[CALENDAR_WEEKDAYS] & $iDayBits) !== 0 || $bHasAddedDays) {
            [$aEstimate, $bTrusted] = $Estimator($sLineId, $aStops, $aOffsets, $aMeters, $iFirst, $iDeparture, ESTIMATE_DAYS[$sDayType]);
            $iSlot = $Estimates->add(implode(',', $aEstimate) . '|' . (int)$bTrusted, [$aEstimate, (int)$bTrusted]);
        }
        $aSlots[] = $iSlot;
    }
    return $aSlots;
}

// Las paradas que usa algún recorrido. Las estaciones con andenes (Metro, Euskotren) se abren por la estación: cada
// andén dice a cuál pertenece y qué andén es. Devuelve también todas las paradas, para las longitudes.
function offlineStops(PDO $Pdo, array $aUsed): array
{
    $aAll = [];
    foreach ($Pdo->query('SELECT id, name, station_id, platform_label, lon FROM stops') as $aRow) {
        $aAll[(string)$aRow['id']] = $aRow;
    }
    $aStops = [];
    foreach ($aUsed as $sStopId) {
        if (!isset($aAll[$sStopId])) {
            continue;
        }
        $aStop = $aAll[$sStopId];
        $aStops[$sStopId] = [$aStop['name']];
        if ($aStop['station_id'] !== null && $aStop['station_id'] !== '') {
            $sStationId = (string)$aStop['station_id'];
            $aStops[$sStopId][] = $sStationId;
            $aStops[$sStopId][] = (string)($aStop['platform_label'] ?? '');
            if (isset($aAll[$sStationId]) && !isset($aStops[$sStationId])) {
                $aStops[$sStationId] = [$aAll[$sStationId]['name']];
            }
        }
    }
    return [$aStops, $aAll];
}

// Metro reparte las salidas en dos columnas según vayan hacia una estación de referencia o al revés (como
// ServiceJourney::directionsByPattern): para eso hacen falta la referencia y la longitud de las paradas.
function directionLongitudes(array $aNetwork, array $aStops, array $aAll): array
{
    if (!isset($aNetwork['direction_reference_stop_id'])) {
        return [null, []];
    }
    $sReference = (string)$aNetwork['direction_reference_stop_id'];
    $aLon = [];
    foreach (array_keys($aStops) as $sStopId) {
        $aLon[$sStopId] = round((float)$aAll[$sStopId]['lon'], 6);
    }
    if (isset($aAll[$sReference])) {
        $aLon[$sReference] = round((float)$aAll[$sReference]['lon'], 6);
    }
    return [$sReference, $aLon];
}

/*
 * Calcula la hora estimada de un viaje en cada parada con lo aprendido por el motor, igual que el motor cuando no hay
 * datos del bus: salida publicada + 1 min + lo que tardan de verdad los buses en cada tramo, con la franja horaria y el
 * ritmo del punto medio hasta esa parada (un tramo sin aprender usa la proporción por largo o el ritmo de la línea), más
 * lo que suele apartarse ese viaje en ese tercio del recorrido. Devuelve los segundos desde la salida en cada parada (0
 * en la primera) y si los tramos aprendidos cubren lo bastante del recorrido para fiarse de que un bus ya pasó.
 * Null si la red no usa el motor o no está a mano.
 */
function offlineEstimator(array $aNetwork): \Closure|null
{
    $sEngine = $aNetwork['engine'] ?? null;
    if ($sEngine === null) {
        return null;
    }
    $sSegmentsClass = 'Engine\\' . ucfirst($sEngine) . '\\SegmentTimes';
    $sPaceClass = 'Engine\\' . ucfirst($sEngine) . '\\PaceFactors';
    if (!class_exists($sSegmentsClass) || !class_exists($sPaceClass)) {
        if (!is_dir(__DIR__ . '/../engine')) {
            fwrite(STDERR, "  {$aNetwork['network']}: sin el motor (engine/), solo horario oficial\n");
        }
        return null;
    }
    $Segments = $sSegmentsClass::fromFile();
    if ($Segments->isEmpty()) {
        return null;
    }
    $Pace = $sPaceClass::fromFile();
    $sTripsClass = 'Engine\\' . ucfirst($sEngine) . '\\TripOffsets';
    $Trips = null;
    if (class_exists($sTripsClass)) {
        $Trips = $sTripsClass::fromFile();
    }
    // $iFirst es la llegada a la cabecera (de ella cuentan las horas del fichero) y $iDeparture su salida (de ella cuenta el motor).
    return function (string $sLineId, array $aStops, array $aOffsets, array $aMeters, int $iFirst, int $iDeparture, string $sDate) use ($Segments, $Pace, $sPaceClass, $Trips, $sTripsClass): array {
        // Lo que tarda cada tramo en cada franja, con lo aprendido o, si no hay, la proporción por largo o el ritmo.
        $aSegmentCache = [];
        $SegmentSeconds = function (int $i, string $sBand, float $dFactor) use ($Segments, $aStops, $aOffsets, $aMeters, &$aSegmentCache): array {
            $sKey = $i . '|' . $sBand . '|' . $dFactor;
            if (!isset($aSegmentCache[$sKey])) {
                $iScheduled = $aOffsets[$i + 1] - $aOffsets[$i];
                $dSeconds = $Segments->seconds($aStops[$i], $aStops[$i + 1], $sBand);
                $bLearned = $dSeconds !== null;
                if (!$bLearned) {
                    $dMeters = null;
                    if ($aMeters[$i] !== null && $aMeters[$i + 1] !== null) {
                        $dMeters = (float)$aMeters[$i + 1] - (float)$aMeters[$i];
                    }
                    $dSeconds = $Segments->fallbackSeconds((float)$iScheduled, $dMeters, $sBand) ?? $dFactor * $iScheduled;
                }
                $aSegmentCache[$sKey] = [$dSeconds, $bLearned, $iScheduled];
            }
            return $aSegmentCache[$sKey];
        };
        // Como el motor: la franja y el ritmo de cada parada son los del punto medio entre la salida y esa parada.
        $aEstimate = [0];
        $iScheduledTotal = 0;
        $iLearnedTotal = 0;
        $iCount = \count($aStops);
        $sTripKey = '';
        if ($Trips !== null) {
            $sTripKey = $sTripsClass::key($sLineId, $aStops[0], $aStops[$iCount - 1], $iDeparture, $iCount);
        }
        for ($iTarget = 1; $iTarget < $iCount; $iTarget++) {
            $iMid = intdiv($iDeparture + $iFirst + $aOffsets[$iTarget], 2);
            $sBand = $sPaceClass::bandFor($sDate, $iMid);
            $dFactor = $Pace->factor($sLineId, $sDate, $iMid);
            $dCumulative = (float)($iDeparture - $iFirst + ESTIMATE_ORIGIN_OFFSET_SECONDS);
            for ($i = 0; $i < $iTarget; $i++) {
                [$dSeconds, $bLearned, $iScheduled] = $SegmentSeconds($i, $sBand, $dFactor);
                $dCumulative += $dSeconds;
                if ($iTarget === $iCount - 1) {
                    $iScheduledTotal += $iScheduled;
                    if ($bLearned) {
                        $iLearnedTotal += $iScheduled;
                    }
                }
            }
            $iEstimate = (int)round($dCumulative);
            if ($Trips !== null) {
                $iEstimate += (int)round($Trips->seconds($sTripKey, $sLineId, $sDate, $iDeparture, $iTarget, $iCount));
            }
            $aEstimate[] = $iEstimate;
        }
        $bTrusted = $iScheduledTotal > 0 && $iLearnedTotal / $iScheduledTotal >= ESTIMATE_TRUSTED_SHARE;
        return [$aEstimate, $bTrusted];
    };
}
