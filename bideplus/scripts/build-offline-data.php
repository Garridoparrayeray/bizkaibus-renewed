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
 * Uso: php scripts/build-offline-data.php [--out=dir]
 */

ini_set('memory_limit', '1024M');
date_default_timezone_set('Europe/Madrid');

require_once __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const OFFLINE_FORMAT = 1;

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
    printf("  %s: %d viajes, %d recorridos, %.0f KB\n", $sSlug, \count($aData['trips']), \count($aData['patterns']), \strlen($sJson) / 1024);
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
    $Flush = function () use (&$sCurrent, &$aStops, &$aTimes, &$aJourneys, &$aLineIndex, &$aCalendarIndex, &$aPatternIndex, &$aPatterns, &$aTimingIndex, &$aTimings, &$aTrips, &$aStopsUsed): void {
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
        $aTrips[] = [$aPatternIndex[$sPatternKey], $aTimingIndex[$sTimingKey], $iFirst, $aCalendarIndex[$aJourney['calendar_id']], (string)$aJourney['trip_number'], (int)$aJourney['first_departure_seconds']];
    };
    $Stmt = $Pdo->query('SELECT service_journey_id, stop_id, arrival_seconds, departure_seconds FROM passing_times ORDER BY service_journey_id, seq_order');
    foreach ($Stmt as $aRow) {
        if ($aRow['service_journey_id'] !== $sCurrent) {
            $Flush();
            $sCurrent = $aRow['service_journey_id'];
            $aStops = [];
            $aTimes = [];
        }
        // La hora que se enseña es la de llegada a la parada, como en la API.
        $iTime = $aRow['arrival_seconds'] ?? $aRow['departure_seconds'];
        if ($iTime === null) {
            continue;
        }
        $aStops[] = (string)$aRow['stop_id'];
        $aTimes[] = (int)$iTime;
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
    ];
}
