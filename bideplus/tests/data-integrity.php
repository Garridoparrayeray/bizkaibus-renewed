<?php

declare(strict_types=1);

/*
 * Integridad de las bases de datos que genera scripts/build-database.php.
 * Protege de un ETL roto o de un GTFS raro del operador antes de que llegue a producción.
 */

$aDatabases = ['bizkaibus', 'metrobilbao', 'euskotren', 'tranviabilbao', 'tranviavitoria', 'renfe'];

$failures = [];
$expect = function (string $name, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? 'OK  ' : 'MAL ') . $name . ($detail === '' ? '' : '  [' . $detail . ']') . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
};

foreach ($aDatabases as $sName) {
    $sPath = __DIR__ . '/../data/' . $sName . '.sqlite';
    echo "\n== " . $sName . "\n";
    if (!is_file($sPath)) {
        $expect($sName . ': existe data/' . $sName . '.sqlite', false);
        continue;
    }
    $Pdo = new PDO('sqlite:' . $sPath, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $count = fn(string $sSql): int => (int)$Pdo->query($sSql)->fetchColumn();
    $aTables = $Pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $bShapes = \in_array('shapes', $aTables, true);

    $aRequired = ['stops', 'lines', 'journey_patterns', 'journey_pattern_stops', 'service_journeys', 'passing_times', 'service_calendars', 'service_calendar_exceptions', 'meta'];
    $aMissing = array_diff($aRequired, $aTables);
    $expect($sName . ': tiene todas las tablas', empty($aMissing), implode(', ', $aMissing));
    if (!empty($aMissing)) {
        continue;
    }

    $iStops = $count('SELECT COUNT(*) FROM stops');
    $iLines = $count('SELECT COUNT(*) FROM lines');
    $iJourneys = $count('SELECT COUNT(*) FROM service_journeys');
    $expect($sName . ': hay paradas, líneas y viajes', $iStops > 0 && $iLines > 0 && $iJourneys > 0, "$iStops paradas, $iLines líneas, $iJourneys viajes");

    $iBadCoords = $count('SELECT COUNT(*) FROM stops WHERE lat NOT BETWEEN 41.5 AND 44.0 OR lon NOT BETWEEN -6.5 AND -1.0');
    $expect($sName . ': todas las paradas están en el norte peninsular (Renfe llega a León)', $iBadCoords === 0, $iBadCoords . ' fuera');

    $iEmptyNames = $count("SELECT COUNT(*) FROM stops WHERE TRIM(name) = '' OR TRIM(name_normalized) = ''");
    $expect($sName . ': ninguna parada sin nombre', $iEmptyNames === 0, (string)$iEmptyNames);

    $iOrphanStops = $count('SELECT COUNT(*) FROM passing_times pt LEFT JOIN stops s ON s.id = pt.stop_id WHERE s.id IS NULL');
    $expect($sName . ': cada paso apunta a una parada que existe', $iOrphanStops === 0, (string)$iOrphanStops);

    $iOrphanJourneys = $count('SELECT COUNT(*) FROM passing_times pt LEFT JOIN service_journeys sj ON sj.id = pt.service_journey_id WHERE sj.id IS NULL');
    $expect($sName . ': cada paso apunta a un viaje que existe', $iOrphanJourneys === 0, (string)$iOrphanJourneys);

    $iOrphanLines = $count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN lines l ON l.id = sj.line_id WHERE l.id IS NULL');
    $expect($sName . ': cada viaje pertenece a una línea que existe', $iOrphanLines === 0, (string)$iOrphanLines);

    $iOrphanCalendars = $count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN service_calendars c ON c.id = sj.calendar_id WHERE c.id IS NULL');
    $expect($sName . ': cada viaje tiene calendario', $iOrphanCalendars === 0, (string)$iOrphanCalendars);

    $iOrphanPatterns = $count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id WHERE jp.id IS NULL');
    $expect($sName . ': cada viaje tiene recorrido', $iOrphanPatterns === 0, (string)$iOrphanPatterns);

    $iShort = $count('SELECT COUNT(*) FROM (SELECT service_journey_id FROM passing_times GROUP BY service_journey_id HAVING COUNT(*) < 2)')
        + $count('SELECT COUNT(*) FROM service_journeys sj WHERE NOT EXISTS (SELECT 1 FROM passing_times pt WHERE pt.service_journey_id = sj.id)');
    $expect($sName . ': cada viaje pasa por al menos 2 paradas', $iShort === 0, (string)$iShort);

    $iDupSeq = $count('SELECT COUNT(*) FROM (SELECT 1 FROM passing_times GROUP BY service_journey_id, seq_order HAVING COUNT(*) > 1)');
    $expect($sName . ': no hay pasos repetidos en un mismo viaje', $iDupSeq === 0, (string)$iDupSeq);

    $iNullTimes = $count('SELECT COUNT(*) FROM passing_times WHERE arrival_seconds IS NULL OR departure_seconds IS NULL');
    $expect($sName . ': todos los pasos tienen hora', $iNullTimes === 0, (string)$iNullTimes);

    $iDwellBack = $count('SELECT COUNT(*) FROM passing_times WHERE departure_seconds < arrival_seconds');
    $expect($sName . ': nadie sale de una parada antes de llegar', $iDwellBack === 0, (string)$iDwellBack);

    $iBackwards = $count('
        SELECT COUNT(*) FROM (
            SELECT arrival_seconds, LAG(departure_seconds) OVER (PARTITION BY service_journey_id ORDER BY seq_order) AS prev_departure
            FROM passing_times
        ) WHERE prev_departure IS NOT NULL AND arrival_seconds < prev_departure
    ');
    $expect($sName . ': las horas nunca van hacia atrás dentro de un viaje', $iBackwards === 0, (string)$iBackwards);

    $iFirstMismatch = $count('
        SELECT COUNT(*) FROM service_journeys sj
        JOIN passing_times pt ON pt.service_journey_id = sj.id
        AND pt.seq_order = (SELECT MIN(seq_order) FROM passing_times WHERE service_journey_id = sj.id)
        WHERE sj.first_departure_seconds IS NOT NULL AND sj.first_departure_seconds <> pt.departure_seconds
    ');
    $expect($sName . ': first_departure_seconds coincide con la primera salida', $iFirstMismatch === 0, (string)$iFirstMismatch);

    $iLong = $count('SELECT COUNT(*) FROM (SELECT service_journey_id FROM passing_times GROUP BY service_journey_id HAVING MAX(arrival_seconds) - MIN(departure_seconds) > 10 * 3600)');
    $expect($sName . ': ningún viaje dura más de 10 h (Bilbao-León tarda ~8 h)', $iLong === 0, (string)$iLong);

    $iBadMask = $count('SELECT COUNT(*) FROM service_calendars WHERE weekday_mask < 0 OR weekday_mask > 127 OR from_date > to_date');
    $expect($sName . ': calendarios con días y fechas válidos', $iBadMask === 0, (string)$iBadMask);

    if (\in_array('trip_aliases', $aTables, true)) {
        $iBadAlias = $count('SELECT COUNT(*) FROM trip_aliases a LEFT JOIN service_journeys sj ON sj.id = a.service_journey_id WHERE sj.id IS NULL');
        $expect($sName . ': cada trip_alias apunta a un viaje que existe', $iBadAlias === 0, (string)$iBadAlias);
    }

    if ($bShapes) {
        $iBadShapeRef = $count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN shapes s ON s.id = sj.shape_id WHERE sj.shape_id IS NOT NULL AND s.id IS NULL');
        $expect($sName . ': cada shape_id apunta a un trazado que existe', $iBadShapeRef === 0, (string)$iBadShapeRef);

        $iDistBack = $count('
            SELECT COUNT(*) FROM (
                SELECT dist_m, LAG(dist_m) OVER (PARTITION BY service_journey_id ORDER BY seq_order) AS prev_dist
                FROM passing_times
            ) WHERE dist_m IS NOT NULL AND prev_dist IS NOT NULL AND dist_m < prev_dist
        ');
        $expect($sName . ': dist_m nunca disminuye a lo largo del viaje', $iDistBack === 0, (string)$iDistBack);

        $iBadShapes = 0;
        foreach ($Pdo->query('SELECT points FROM shapes') as $aRow) {
            $aPoints = json_decode($aRow['points'], true);
            if (!\is_array($aPoints) || \count($aPoints) < 2) {
                $iBadShapes++;
                continue;
            }
            $dPrev = -1.0;
            foreach ($aPoints as $aPoint) {
                if (!\is_array($aPoint) || \count($aPoint) < 3 || $aPoint[2] < $dPrev) {
                    $iBadShapes++;
                    break;
                }
                $dPrev = (float)$aPoint[2];
            }
        }
        $expect($sName . ': cada trazado es [lat, lon, metros] con metros crecientes', $iBadShapes === 0, (string)$iBadShapes);
    }
}

echo "\nRESULTADO datos: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
