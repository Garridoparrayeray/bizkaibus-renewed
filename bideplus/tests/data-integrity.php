<?php

declare(strict_types=1);

/*
 * Integridad de las bases de datos que genera scripts/build-database.php.
 * Protege de un ETL roto o de un GTFS raro del operador antes de que llegue a producción.
 */

require __DIR__ . '/../api/Core/Networks.php';
$aDatabases = array_values(array_map(fn($aNetwork) => $aNetwork['db'], \Core\Networks::all()));

$aFailures = [];
$Expect = function (string $sCheck, bool $bOk, string $sDetail = '') use (&$aFailures): void {
    $sOutput = 'OK  ' . $sCheck;
    if (!$bOk) {
        $sOutput = 'MAL ' . $sCheck;
    }
    if ($sDetail !== '') {
        $sOutput .= '  [' . $sDetail . ']';
    }
    echo $sOutput . "\n";
    if (!$bOk) {
        $aFailures[] = $sCheck;
    }
};

foreach ($aDatabases as $sName) {
    $sPath = __DIR__ . '/../data/' . $sName . '.sqlite';
    echo "\n== " . $sName . "\n";
    if (!is_file($sPath)) {
        $Expect($sName . ': existe data/' . $sName . '.sqlite', false);
        continue;
    }
    // PHP 8.4 tiene las constantes en Pdo\\Sqlite; antes, en PDO.
    $sOpenFlags = 'PDO::SQLITE_ATTR_OPEN_FLAGS';
    if (defined('Pdo\\Sqlite::ATTR_OPEN_FLAGS')) {
        $sOpenFlags = 'Pdo\\Sqlite::ATTR_OPEN_FLAGS';
    }
    $sReadOnly = 'PDO::SQLITE_OPEN_READONLY';
    if (defined('Pdo\\Sqlite::OPEN_READONLY')) {
        $sReadOnly = 'Pdo\\Sqlite::OPEN_READONLY';
    }
    $Pdo = new PDO('sqlite:' . $sPath, null, null, [constant($sOpenFlags) => constant($sReadOnly)]);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $Count = fn(string $sSql): int => (int)$Pdo->query($sSql)->fetchColumn();
    $aTables = $Pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    $bShapes = \in_array('shapes', $aTables, true);

    $aRequired = ['stops', 'lines', 'journey_patterns', 'journey_pattern_stops', 'service_journeys', 'passing_times', 'service_calendars', 'service_calendar_exceptions', 'meta'];
    $aMissing = array_diff($aRequired, $aTables);
    $Expect($sName . ': tiene todas las tablas', empty($aMissing), implode(', ', $aMissing));
    if (!empty($aMissing)) {
        continue;
    }

    $iStops = $Count('SELECT COUNT(*) FROM stops');
    $iLines = $Count('SELECT COUNT(*) FROM lines');
    $iJourneys = $Count('SELECT COUNT(*) FROM service_journeys');
    $Expect($sName . ': hay paradas, líneas y viajes', $iStops > 0 && $iLines > 0 && $iJourneys > 0, "$iStops paradas, $iLines líneas, $iJourneys viajes");

    $iBadCoords = $Count('SELECT COUNT(*) FROM stops WHERE lat NOT BETWEEN 41.5 AND 44.0 OR lon NOT BETWEEN -6.5 AND -1.0');
    $Expect($sName . ': todas las paradas están en el norte peninsular (Renfe llega a León)', $iBadCoords === 0, $iBadCoords . ' fuera');

    $iEmptyNames = $Count("SELECT COUNT(*) FROM stops WHERE TRIM(name) = '' OR TRIM(name_normalized) = ''");
    $Expect($sName . ': ninguna parada sin nombre', $iEmptyNames === 0, (string)$iEmptyNames);

    $iOrphanStops = $Count('SELECT COUNT(*) FROM passing_times pt LEFT JOIN stops s ON s.id = pt.stop_id WHERE s.id IS NULL');
    $Expect($sName . ': cada paso apunta a una parada que existe', $iOrphanStops === 0, (string)$iOrphanStops);

    $iOrphanJourneys = $Count('SELECT COUNT(*) FROM passing_times pt LEFT JOIN service_journeys sj ON sj.id = pt.service_journey_id WHERE sj.id IS NULL');
    $Expect($sName . ': cada paso apunta a un viaje que existe', $iOrphanJourneys === 0, (string)$iOrphanJourneys);

    $iOrphanLines = $Count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN lines l ON l.id = sj.line_id WHERE l.id IS NULL');
    $Expect($sName . ': cada viaje pertenece a una línea que existe', $iOrphanLines === 0, (string)$iOrphanLines);

    $iOrphanCalendars = $Count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN service_calendars c ON c.id = sj.calendar_id WHERE c.id IS NULL');
    $Expect($sName . ': cada viaje tiene calendario', $iOrphanCalendars === 0, (string)$iOrphanCalendars);

    $iOrphanPatterns = $Count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id WHERE jp.id IS NULL');
    $Expect($sName . ': cada viaje tiene recorrido', $iOrphanPatterns === 0, (string)$iOrphanPatterns);

    $iShort = $Count('SELECT COUNT(*) FROM (SELECT service_journey_id FROM passing_times GROUP BY service_journey_id HAVING COUNT(*) < 2)')
        + $Count('SELECT COUNT(*) FROM service_journeys sj WHERE NOT EXISTS (SELECT 1 FROM passing_times pt WHERE pt.service_journey_id = sj.id)');
    $Expect($sName . ': cada viaje pasa por al menos 2 paradas', $iShort === 0, (string)$iShort);

    $iDupSeq = $Count('SELECT COUNT(*) FROM (SELECT 1 FROM passing_times GROUP BY service_journey_id, seq_order HAVING COUNT(*) > 1)');
    $Expect($sName . ': no hay pasos repetidos en un mismo viaje', $iDupSeq === 0, (string)$iDupSeq);

    $iNullTimes = $Count('SELECT COUNT(*) FROM passing_times WHERE arrival_seconds IS NULL OR departure_seconds IS NULL');
    $Expect($sName . ': todos los pasos tienen hora', $iNullTimes === 0, (string)$iNullTimes);

    $iDwellBack = $Count('SELECT COUNT(*) FROM passing_times WHERE departure_seconds < arrival_seconds');
    $Expect($sName . ': nadie sale de una parada antes de llegar', $iDwellBack === 0, (string)$iDwellBack);

    $iBackwards = $Count('
        SELECT COUNT(*) FROM (
            SELECT arrival_seconds, LAG(departure_seconds) OVER (PARTITION BY service_journey_id ORDER BY seq_order) AS prev_departure
            FROM passing_times
        ) WHERE prev_departure IS NOT NULL AND arrival_seconds < prev_departure
    ');
    $Expect($sName . ': las horas nunca van hacia atrás dentro de un viaje', $iBackwards === 0, (string)$iBackwards);

    $iFirstMismatch = $Count('
        SELECT COUNT(*) FROM service_journeys sj
        JOIN passing_times pt ON pt.service_journey_id = sj.id
        AND pt.seq_order = (SELECT MIN(seq_order) FROM passing_times WHERE service_journey_id = sj.id)
        WHERE sj.first_departure_seconds IS NOT NULL AND sj.first_departure_seconds <> pt.departure_seconds
    ');
    $Expect($sName . ': first_departure_seconds coincide con la primera salida', $iFirstMismatch === 0, (string)$iFirstMismatch);

    $iLong = $Count('SELECT COUNT(*) FROM (SELECT service_journey_id FROM passing_times GROUP BY service_journey_id HAVING MAX(arrival_seconds) - MIN(departure_seconds) > 10 * 3600)');
    $Expect($sName . ': ningún viaje dura más de 10 h (Bilbao-León tarda ~8 h)', $iLong === 0, (string)$iLong);

    $iBadMask = $Count('SELECT COUNT(*) FROM service_calendars WHERE weekday_mask < 0 OR weekday_mask > 127 OR from_date > to_date');
    $Expect($sName . ': calendarios con días y fechas válidos', $iBadMask === 0, (string)$iBadMask);

    if (\in_array('trip_aliases', $aTables, true)) {
        $iBadAlias = $Count('SELECT COUNT(*) FROM trip_aliases a LEFT JOIN service_journeys sj ON sj.id = a.service_journey_id WHERE sj.id IS NULL');
        $Expect($sName . ': cada trip_alias apunta a un viaje que existe', $iBadAlias === 0, (string)$iBadAlias);
    }

    if ($bShapes) {
        $iBadShapeRef = $Count('SELECT COUNT(*) FROM service_journeys sj LEFT JOIN shapes s ON s.id = sj.shape_id WHERE sj.shape_id IS NOT NULL AND s.id IS NULL');
        $Expect($sName . ': cada shape_id apunta a un trazado que existe', $iBadShapeRef === 0, (string)$iBadShapeRef);

        $iDistBack = $Count('
            SELECT COUNT(*) FROM (
                SELECT dist_m, LAG(dist_m) OVER (PARTITION BY service_journey_id ORDER BY seq_order) AS prev_dist
                FROM passing_times
            ) WHERE dist_m IS NOT NULL AND prev_dist IS NOT NULL AND dist_m < prev_dist
        ');
        $Expect($sName . ': dist_m nunca disminuye a lo largo del viaje', $iDistBack === 0, (string)$iDistBack);

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
        $Expect($sName . ': cada trazado es [lat, lon, metros] con metros crecientes', $iBadShapes === 0, (string)$iBadShapes);
    }
}

if (!empty($aFailures)) {
    echo "\nRESULTADO datos: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO datos: OK\n";
exit(0);
