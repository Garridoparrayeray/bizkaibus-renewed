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
 * también la hora estimada de cada viaje con los tramos aprendidos, para un día laborable y para un fin de semana:
 * sin conexión la parada enseña primero esa estimación y al lado la hora oficial, como con conexión y sin datos del bus.
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
// Un día de cada tipo para elegir la franja horaria de los tramos aprendidos.
const ESTIMATE_DAYS = ['laborable' => '2026-11-04', 'fin_semana' => '2026-11-07'];

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

function buildOfflineNetwork(string $sSlug, array $aNetwork): array
{
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $aLineIndex = [];
    $aLines = [];
    foreach ($Pdo->query('SELECT id, code, name FROM lines ORDER BY code') as $aRow) {
        $aLineIndex[$aRow['id']] = \count($aLines);
        $aLines[] = [(string)$aRow['id'], $aRow['code'], $aRow['name']];
    }

    $aCalendarIndex = [];
    $aCalendars = [];
    foreach ($Pdo->query("SELECT id, weekday_mask FROM service_calendars WHERE id != 'PRUEBA' ORDER BY id") as $aRow) {
        $aCalendarIndex[$aRow['id']] = \count($aCalendars);
        $aCalendars[] = [(int)$aRow['weekday_mask'], [], []];
    }
    foreach ($Pdo->query('SELECT calendar_id, date, available FROM service_calendar_exceptions ORDER BY date') as $aRow) {
        if (!isset($aCalendarIndex[$aRow['calendar_id']])) {
            continue;
        }
        $iSlot = 2;
        if ((int)$aRow['available'] === 1) {
            $iSlot = 1;
        }
        $aCalendars[$aCalendarIndex[$aRow['calendar_id']]][$iSlot][] = $aRow['date'];
    }

    $aJourneys = [];
    $Stmt = $Pdo->query('
        SELECT sj.id, sj.line_id, sj.trip_number, sj.calendar_id, sj.first_departure_seconds, jp.headsign
        FROM service_journeys sj
        JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
    ');
    foreach ($Stmt as $aRow) {
        if (!isset($aCalendarIndex[$aRow['calendar_id']], $aLineIndex[$aRow['line_id']])) {
            continue;
        }
        $aJourneys[$aRow['id']] = $aRow;
    }

    $aPatternIndex = [];
    $aPatterns = [];
    $aTimingIndex = [];
    $aTimings = [];
    $aTrips = [];
    $aStopsUsed = [];
    $sCurrent = null;
    $aStops = [];
    $aTimes = [];
    $aMeters = [];
    $iFirstDeparture = null;
    $Estimator = offlineEstimator($aNetwork);
    $aEstimateIndex = [];
    $aEstimates = [];
    $aEstimateTrusted = [];
    $Flush = function () use (&$sCurrent, &$aStops, &$aTimes, &$aMeters, &$aJourneys, &$aLineIndex, &$aCalendarIndex, &$aPatternIndex, &$aPatterns, &$aTimingIndex, &$aTimings, &$aTrips, &$aStopsUsed, &$aCalendars, &$iFirstDeparture, $Estimator, &$aEstimateIndex, &$aEstimates, &$aEstimateTrusted): void {
        if ($sCurrent === null || !isset($aJourneys[$sCurrent]) || \count($aStops) < 2) {
            return;
        }
        $aJourney = $aJourneys[$sCurrent];
        $iFirst = $aTimes[0];
        $sPatternKey = $aJourney['line_id'] . '|' . $aJourney['headsign'] . '|' . implode(',', $aStops);
        if (!isset($aPatternIndex[$sPatternKey])) {
            $aPatternIndex[$sPatternKey] = \count($aPatterns);
            $aPatterns[] = [$aLineIndex[$aJourney['line_id']], (string)$aJourney['headsign'], $aStops];
            foreach ($aStops as $sStopId) {
                $aStopsUsed[$sStopId] = true;
            }
        }
        $aOffsets = array_map(fn($iTime) => $iTime - $iFirst, $aTimes);
        $sTimingKey = implode(',', $aOffsets);
        if (!isset($aTimingIndex[$sTimingKey])) {
            $aTimingIndex[$sTimingKey] = \count($aTimings);
            $aTimings[] = $aOffsets;
        }
        $aTrip = [$aPatternIndex[$sPatternKey], $aTimingIndex[$sTimingKey], $iFirst, $aCalendarIndex[$aJourney['calendar_id']], (string)$aJourney['trip_number'], (int)$aJourney['first_departure_seconds']];
        if ($Estimator !== null) {
            // Posiciones 6 y 7: hora estimada en laborable y en fin de semana (índice en «estimates», -1 si ese día no hay viaje).
            $iMask = $aCalendars[$aCalendarIndex[$aJourney['calendar_id']]][0];
            $bHasExtraDays = !empty($aCalendars[$aCalendarIndex[$aJourney['calendar_id']]][1]);
            foreach (['laborable' => 31, 'fin_semana' => 96] as $sDayType => $iDayBits) {
                $iSlot = -1;
                if (($iMask & $iDayBits) !== 0 || $bHasExtraDays) {
                    [$aEstimate, $bTrusted] = $Estimator((string)$aJourney['line_id'], $aStops, $aOffsets, $aMeters, $iFirst, $iFirstDeparture ?? $iFirst, ESTIMATE_DAYS[$sDayType]);
                    $sEstimateKey = implode(',', $aEstimate) . '|' . (int)$bTrusted;
                    if (!isset($aEstimateIndex[$sEstimateKey])) {
                        $aEstimateIndex[$sEstimateKey] = \count($aEstimates);
                        $aEstimates[] = $aEstimate;
                        $aEstimateTrusted[] = (int)$bTrusted;
                    }
                    $iSlot = $aEstimateIndex[$sEstimateKey];
                }
                $aTrip[] = $iSlot;
            }
        }
        $aTrips[] = $aTrip;
    };
    // dist_m (metros desde la cabecera) solo lo tienen las redes con trazado; sin él, los tramos sin aprender usan el ritmo.
    $sMeters = 'NULL';
    foreach ($Pdo->query('PRAGMA table_info(passing_times)') as $aColumn) {
        if ($aColumn['name'] === 'dist_m') {
            $sMeters = 'dist_m';
        }
    }
    $Stmt = $Pdo->query("SELECT service_journey_id, stop_id, arrival_seconds, departure_seconds, $sMeters AS dist_m FROM passing_times ORDER BY service_journey_id, seq_order");
    foreach ($Stmt as $aRow) {
        if ($aRow['service_journey_id'] !== $sCurrent) {
            $Flush();
            $sCurrent = $aRow['service_journey_id'];
            $aStops = [];
            $aTimes = [];
            $aMeters = [];
            $iFirstDeparture = null;
        }
        // La hora que se enseña es la de llegada a la parada, como en la API.
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
    $Flush();

    // Las estaciones con andenes (Metro, Euskotren) se abren por la estación: cada andén dice a cuál pertenece.
    $aStopsOut = [];
    $StopStmt = $Pdo->query('SELECT id, name, station_id, platform_label, lon FROM stops');
    $aAll = [];
    foreach ($StopStmt as $aRow) {
        $aAll[(string)$aRow['id']] = $aRow;
    }
    foreach (array_keys($aStopsUsed) as $sStopId) {
        if (!isset($aAll[$sStopId])) {
            continue;
        }
        $aStop = $aAll[$sStopId];
        $aStopsOut[$sStopId] = [$aStop['name']];
        if ($aStop['station_id'] !== null && $aStop['station_id'] !== '') {
            $sStationId = (string)$aStop['station_id'];
            $aStopsOut[$sStopId][] = $sStationId;
            $aStopsOut[$sStopId][] = (string)($aStop['platform_label'] ?? '');
            if (isset($aAll[$sStationId]) && !isset($aStopsOut[$sStationId])) {
                $aStopsOut[$sStationId] = [$aAll[$sStationId]['name']];
            }
        }
    }

    // Metro reparte las salidas en dos columnas según vayan hacia una estación de referencia o al revés (como
    // ServiceJourney::directionsByPattern): hace falta la longitud de las paradas.
    $sReference = null;
    $aLon = [];
    if (isset($aNetwork['direction_reference_stop_id'])) {
        $sReference = (string)$aNetwork['direction_reference_stop_id'];
        foreach (array_keys($aStopsOut) as $sStopId) {
            $aLon[$sStopId] = round((float)$aAll[$sStopId]['lon'], 6);
        }
        if (isset($aAll[$sReference])) {
            $aLon[$sReference] = round((float)$aAll[$sReference]['lon'], 6);
        }
    }

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
        'patterns' => $aPatterns,
        'timings' => $aTimings,
        'trips' => $aTrips,
        'estimates' => $aEstimates,
        'estimateTrusted' => $aEstimateTrusted,
    ];
}

/*
 * Calcula la hora estimada de un viaje en cada parada con los tramos aprendidos del motor, igual que el motor cuando no
 * hay datos del bus: salida publicada + 1 min + lo que tardan de verdad los buses en cada tramo, con la franja horaria y
 * el ritmo del punto medio hasta esa parada; un tramo sin aprender usa la proporción por largo o el ritmo de la línea. Devuelve los segundos desde la salida en cada parada (0 en la
 * primera) y si los tramos aprendidos cubren lo bastante del recorrido para fiarse de que un bus ya pasó.
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
    // $iFirst es la llegada a la cabecera (de ella cuentan las horas del fichero) y $iDeparture su salida (de ella cuenta el motor).
    return function (string $sLineId, array $aStops, array $aOffsets, array $aMeters, int $iFirst, int $iDeparture, string $sDate) use ($Segments, $Pace, $sPaceClass): array {
        // Lo que tarda cada tramo en cada franja, con lo aprendido o, si no hay, la proporción por largo o el ritmo.
        $aSegmentCache = [];
        $dSegment = function (int $i, string $sBand, float $dFactor) use ($Segments, $aStops, $aOffsets, $aMeters, &$aSegmentCache): array {
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
        for ($iTarget = 1; $iTarget < \count($aStops); $iTarget++) {
            $iMid = intdiv($iDeparture + $iFirst + $aOffsets[$iTarget], 2);
            $sBand = $sPaceClass::bandFor($sDate, $iMid);
            $dFactor = $Pace->factor($sLineId, $sDate, $iMid);
            $dCumulative = (float)($iDeparture - $iFirst + ESTIMATE_ORIGIN_OFFSET_SECONDS);
            for ($i = 0; $i < $iTarget; $i++) {
                [$dSeconds, $bLearned, $iScheduled] = $dSegment($i, $sBand, $dFactor);
                $dCumulative += $dSeconds;
                if ($iTarget === \count($aStops) - 1) {
                    $iScheduledTotal += $iScheduled;
                    if ($bLearned) {
                        $iLearnedTotal += $iScheduled;
                    }
                }
            }
            $aEstimate[] = (int)round($dCumulative);
        }
        $bTrusted = $iScheduledTotal > 0 && $iLearnedTotal / $iScheduledTotal >= ESTIMATE_TRUSTED_SHARE;
        return [$aEstimate, $bTrusted];
    };
}
