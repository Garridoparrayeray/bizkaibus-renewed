<?php
// Viajes, recorridos y horas de paso: de stop_times.txt a la base de datos, y los trazados. Lo usa build-database.php.

declare(strict_types=1);

function timingKeyFor(array $aBuffer, int|null $iFirstDeparture): string
{
    $aOffsets = [];
    foreach ($aBuffer as $aRow) {
        $iArrival = $aRow['arrival'] ?? $aRow['departure'] ?? null;
        $iDeparture = $aRow['departure'] ?? $iArrival;
        if ($iArrival === null) {
            $aOffsets[] = '-';
            continue;
        }
        $aOffsets[] = ($iArrival - ($iFirstDeparture ?? 0)) . '/' . ($iDeparture - $iArrival);
    }
    return substr(md5(implode(',', $aOffsets)), 0, 12);
}

function processStopTimes(PDO $Pdo, ZipArchive $Zip, array $aTrips, array $aRoutes, array $aCalendars, array &$aTotals, bool $bMergeCalendars, array $aWindow = []): void
{
    echo "  Pass 1/2: computing trip signatures and merge groups...\n";

    $aSignatures = [];
    $aSeenTripIds = [];
    $iSkippedUnknownTrip = 0;
    $iSkippedUnknownRoute = 0;
    $iSkippedDuplicateTrip = 0;

    foreach (streamStopTimesByTrip($Zip) as $sTripId => $aBuffer) {
        if (isset($aSeenTripIds[$sTripId])) {
            $iSkippedDuplicateTrip++;
            continue;
        }
        $aSeenTripIds[$sTripId] = true;

        $aTrip = null;
        if (isset($aTrips[$sTripId])) {
            $aTrip = $aTrips[$sTripId];
        }
        if ($aTrip === null) {
            $iSkippedUnknownTrip++;
            continue;
        }
        if (!isset($aRoutes[$aTrip['routeId']])) {
            $iSkippedUnknownRoute++;
            continue;
        }

        usort($aBuffer, fn($aA, $aB) => $aA['seqOrder'] <=> $aB['seqOrder']);
        $aStopIds = array_column($aBuffer, 'stopId');
        $sPatternKey = 'gp_' . $aTrip['routeId'] . '_' . substr(md5(implode(',', $aStopIds)), 0, 12);
        $iFirstDeparture = $aBuffer[0]['arrival'];
        if (isset($aBuffer[0]['departure'])) {
            $iFirstDeparture = $aBuffer[0]['departure'];
        }

        $iWeekdayMask = 0;
        $aIncludedDates = [];
        $aExcludedDates = [];
        if (isset($aCalendars[$aTrip['serviceId']])) {
            $iWeekdayMask = $aCalendars[$aTrip['serviceId']]['weekdayMask'];
            $aIncludedDates = $aCalendars[$aTrip['serviceId']]['includedDates'];
            $aExcludedDates = $aCalendars[$aTrip['serviceId']]['excludedDates'];
        }
        $sCalendarGroupKey = calendarGroupKeyFor($bMergeCalendars, $aTrip['serviceId'], $aCalendars);
        $sTimingKey = timingKeyFor($aBuffer, $iFirstDeparture);

        $aSignatures[$sTripId] = [
            'serviceId' => $aTrip['serviceId'],
            'routeId' => $aTrip['routeId'],
            'tripNumber' => $aTrip['tripNumber'],
            'headsign' => $aTrip['headsign'],
            'patternKey' => $sPatternKey,
            'firstDeparture' => $iFirstDeparture,
            'weekdayMask' => $iWeekdayMask,
            'includedDates' => $aIncludedDates,
            'excludedDates' => $aExcludedDates,
            'calendarGroupKey' => $sCalendarGroupKey,
            'timingKey' => $sTimingKey,
        ];
    }

    $iDepartureClusterGapSeconds = 90;

    $aByRoutePattern = [];
    foreach ($aSignatures as $sTripId => $aSig) {
        $sTripNumberPart = '';
        if (isset($aSig['tripNumber'])) {
            $sTripNumberPart = $aSig['tripNumber'];
        }

        $sKey = $aSig['routeId'] . '|' . $sTripNumberPart . '|' . $aSig['patternKey'] . '|' . $aSig['calendarGroupKey'] . '|' . $aSig['timingKey'];
        $aByRoutePattern[$sKey][] = $sTripId;
    }

    $aGroups = [];
    foreach ($aByRoutePattern as $aTripIds) {
        usort($aTripIds, fn($sA, $sB) => $aSignatures[$sA]['firstDeparture'] <=> $aSignatures[$sB]['firstDeparture']);

        $sClusterKey = null;
        $iPreviousDeparture = null;
        foreach ($aTripIds as $sTripId) {
            $aSig = $aSignatures[$sTripId];
            if ($iPreviousDeparture === null || ($aSig['firstDeparture'] - $iPreviousDeparture) > $iDepartureClusterGapSeconds) {
                $sClusterKey = $sTripId;
                $aGroups[$sClusterKey] = $aSig;
                $aGroups[$sClusterKey]['representativeTripId'] = $sTripId;
                $aGroups[$sClusterKey]['weekdayMask'] = 0;
                $aGroups[$sClusterKey]['includedDates'] = [];
                $aGroups[$sClusterKey]['excludedDates'] = [];
                $aGroups[$sClusterKey]['memberTripIds'] = [];
            }
            $aGroups[$sClusterKey]['memberTripIds'][] = $sTripId;
            $aGroups[$sClusterKey]['weekdayMask'] |= $aSig['weekdayMask'];

            foreach ($aSig['includedDates'] as $sDate) {
                $aGroups[$sClusterKey]['includedDates'][$sDate] = true;
            }
            foreach ($aSig['excludedDates'] as $sDate) {
                $aGroups[$sClusterKey]['excludedDates'][$sDate] = true;
            }
            $iPreviousDeparture = $aSig['firstDeparture'];
        }
    }

    foreach ($aGroups as $sClusterKey => $aGroup) {
        $aGroups[$sClusterKey]['excludedDates'] = excludedDatesForGroup($aGroup, $aSignatures);
    }

    // Bizkaibus: el calendario de cada viaje fusionado es exacto dentro del periodo publicado (ver exactCalendarForWindow).
    $bExactWindow = !empty($aWindow);
    if ($bExactWindow) {
        $aExactByService = [];
        foreach ($aGroups as $sClusterKey => $aGroup) {
            $aRunsOn = [];
            foreach ($aGroup['memberTripIds'] as $sMemberTripId) {
                $sServiceId = $aSignatures[$sMemberTripId]['serviceId'];
                if (!isset($aExactByService[$sServiceId])) {
                    $aExactByService[$sServiceId] = exactServiceDates($aCalendars[$sServiceId]['exact'] ?? ['mask' => 0, 'from' => '', 'to' => '', 'available' => [], 'excluded' => []], $aWindow);
                }
                $aRunsOn += $aExactByService[$sServiceId];
            }
            if (getenv('BIDEPLUS_CALENDAR_MODE') === 'literal') {
                // Modo de emergencia: sin patrón semanal, solo las fechas exactas en las que circula. No puede quedar mal.
                [$iExactMask, $aExactIncluded, $aExactExcluded] = [0, array_keys($aRunsOn), []];
            } else {
                [$iExactMask, $aExactIncluded, $aExactExcluded] = exactCalendarForWindow($aRunsOn, $aWindow);
            }
            $aGroups[$sClusterKey]['weekdayMask'] = $iExactMask;
            $aGroups[$sClusterKey]['includedDates'] = array_fill_keys($aExactIncluded, true);
            $aGroups[$sClusterKey]['excludedDates'] = array_fill_keys($aExactExcluded, true);
            $aGroups[$sClusterKey]['exactRunsOn'] = $aRunsOn;
        }
    }

    $aMaskToCalendarId = [];
    foreach ($aCalendars as $sCalId => $aCal) {
        if ($sCalId === 'PRUEBA') {
            continue;
        }
        if ($aCal['from'] !== '') {
            continue;
        }
        if (!empty($aCal['excludedDates'])) {
            // No reutilizar un calendario real que ya tiene sus propias
            // excepciones: si lo compartimos con otro grupo fusionado,
            // esas fechas excluidas se le pegarian tambien sin que le
            // correspondan.
            continue;
        }
        if (!isset($aMaskToCalendarId[$aCal['weekdayMask']])) {
            $aMaskToCalendarId[$aCal['weekdayMask']] = $sCalId;
        }
    }
    $aSyntheticCalendars = [];
    $aRepresentatives = [];

    $aExtraIncludedDatesByCalendarId = [];
    $aExtraExcludedDatesByCalendarId = [];
    $aSignatureToCalendarId = [];
    foreach ($aGroups as $aGroup) {
        if ($aGroup['calendarGroupKey'] !== '') {
            $aGroup['calendarId'] = $aGroup['calendarGroupKey'];
            $aRepresentatives[$aGroup['representativeTripId']] = $aGroup;
            continue;
        }

        $iMask = $aGroup['weekdayMask'];
        $aExcludedDates = array_keys($aGroup['excludedDates']);
        sort($aExcludedDates);

        $aIncludedDates = array_keys($aGroup['includedDates']);
        sort($aIncludedDates);
        // Con calendario exacto, un grupo con fechas sueltas (incluidas o excluidas) no puede compartir el calendario de
        // otros viajes: esas fechas se les pegarían también.
        if (empty($aExcludedDates) && (!$bExactWindow || empty($aIncludedDates)) && isset($aMaskToCalendarId[$iMask])) {
            $sCalendarId = $aMaskToCalendarId[$iMask];
        } else {
            // Un mismo dia de la semana puede tener grupos con distintas
            // fechas excluidas (p.ej. dos servicios de lunes a viernes,
            // solo uno de ellos anulado en un festivo concreto): la firma
            // incluye las fechas excluidas para no fusionarlos y perder
            // esa excepcion.
            $sSignature = $iMask . '|' . implode(',', $aExcludedDates);
            if ($bExactWindow) {
                $sSignature .= '|' . implode(',', $aIncludedDates);
            }
            if (!isset($aSignatureToCalendarId[$sSignature])) {
                $sNewId = 'merged_' . $iMask;
                if (!empty($aExcludedDates) || ($bExactWindow && !empty($aIncludedDates))) {
                    $sNewId .= '_' . substr(md5($sSignature), 0, 8);
                }
                $aSignatureToCalendarId[$sSignature] = $sNewId;
                $aSyntheticCalendars[$sNewId] = $iMask;
            }
            $sCalendarId = $aSignatureToCalendarId[$sSignature];
        }
        $aGroup['calendarId'] = $sCalendarId;
        foreach (array_keys($aGroup['includedDates']) as $sDate) {
            $aExtraIncludedDatesByCalendarId[$sCalendarId][$sDate] = true;
        }
        foreach ($aExcludedDates as $sDate) {
            $aExtraExcludedDatesByCalendarId[$sCalendarId][$sDate] = true;
        }
        $aRepresentatives[$aGroup['representativeTripId']] = $aGroup;
    }

    printf("  %d raw trips merged into %d distinct journeys (%d synthetic calendars for OR'd weekday masks)\n", count($aSignatures), count($aGroups), count($aSyntheticCalendars));

    if (!empty($aSyntheticCalendars)) {
        $Stmt = $Pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
        foreach ($aSyntheticCalendars as $sId => $iMask) {
            $Stmt->execute([$sId, '', '', $iMask]);
        }
    }

    if (!empty($aExtraIncludedDatesByCalendarId)) {
        $aAlreadyIncluded = [];
        foreach ($aCalendars as $sCalId => $aCal) {
            foreach ($aCal['includedDates'] as $sDate) {
                $aAlreadyIncluded[$sCalId][$sDate] = true;
            }
        }
        $IncludeStmt = $Pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 1)');
        foreach ($aExtraIncludedDatesByCalendarId as $sCalendarId => $aDates) {
            foreach (array_keys($aDates) as $sDate) {
                if (isset($aAlreadyIncluded[$sCalendarId][$sDate])) {
                    continue;
                }
                $IncludeStmt->execute([$sCalendarId, $sDate]);
            }
        }
    }

    if (!empty($aExtraExcludedDatesByCalendarId)) {
        $ExcludeStmt = $Pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 0)');
        foreach ($aExtraExcludedDatesByCalendarId as $sCalendarId => $aDates) {
            foreach (array_keys($aDates) as $sDate) {
                $ExcludeStmt->execute([$sCalendarId, $sDate]);
            }
        }
    }

    echo "  Pass 2/2: inserting merged journeys + passing_times...\n";

    $InsertPattern = $Pdo->prepare('INSERT OR IGNORE INTO journey_patterns (id, line_id, headsign) VALUES (?, ?, ?)');
    $InsertPatternStop = $Pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $InsertJourney = $Pdo->prepare('INSERT OR IGNORE INTO service_journeys (id, line_id, journey_pattern_id, trip_number, calendar_id, first_departure_seconds, shape_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $InsertPassingTime = $Pdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds, dist_m) VALUES (?, ?, ?, ?, ?, ?)');
    $aUsedShapeIds = [];
    $InsertTripAlias = $Pdo->prepare('INSERT OR IGNORE INTO trip_aliases (trip_id, service_journey_id) VALUES (?, ?)');
    $aSeenPatterns = [];
    $iRowCount = 0;

    foreach (streamStopTimesByTrip($Zip) as $sTripId => $aBuffer) {
        $aGroup = null;
        if (isset($aRepresentatives[$sTripId])) {
            $aGroup = $aRepresentatives[$sTripId];
        }
        if ($aGroup === null) {
            continue;
        }

        usort($aBuffer, fn($aA, $aB) => $aA['seqOrder'] <=> $aB['seqOrder']);
        $sPatternKey = $aGroup['patternKey'];

        if (!isset($aSeenPatterns[$sPatternKey])) {
            $aSeenPatterns[$sPatternKey] = true;
            $InsertPattern->execute([$sPatternKey, $aGroup['routeId'], $aGroup['headsign']]);
            foreach ($aBuffer as $aRow) {
                $InsertPatternStop->execute([$sPatternKey, $aRow['seqOrder'], $aRow['stopId']]);
            }
            $aTotals['patterns']++;
        }

        $sTripNumber = $aGroup['tripNumber'];
        if ($sTripNumber === null) {
            $sTripNumber = $sTripId;
        }
        $sShapeId = null;
        if (isset($aTrips[$sTripId]['shapeId'])) {
            $sShapeId = $aTrips[$sTripId]['shapeId'];
            $aUsedShapeIds[$sShapeId] = true;
        }
        $InsertJourney->execute([$sTripId, $aGroup['routeId'], $sPatternKey, $sTripNumber, $aGroup['calendarId'], $aGroup['firstDeparture'], $sShapeId]);
        $aTotals['journeys']++;

        foreach ($aGroup['memberTripIds'] as $sMemberTripId) {
            $InsertTripAlias->execute([$sMemberTripId, $sTripId]);
            $aTotals['tripAliases']++;
        }

        foreach ($aBuffer as $aRow) {
            $InsertPassingTime->execute([$sTripId, $aRow['seqOrder'], $aRow['stopId'], $aRow['arrival'], $aRow['departure'], $aRow['distance']]);
            $aTotals['passingTimes']++;
        }

        $iRowCount += count($aBuffer);
        if ($iRowCount % 50000 < 40) {
            printf("  processed ~%d passing_times rows\r", $iRowCount);
        }
    }
    echo "\n";

    $aTotals['shapes'] = insertShapes($Pdo, $Zip, $aUsedShapeIds);

    if ($bExactWindow) {
        $aAudit = auditExactCalendars($Pdo, $aRepresentatives, $aWindow);
        printf("  Auditoría de calendarios (Bizkaibus, periodo publicado %s a %s): %d viajes comprobados, %d con diferencias contra el GTFS (%d fechas de más, %d que faltan)\n",
            $aWindow[0], $aWindow[count($aWindow) - 1], $aAudit['checked'], $aAudit['differences'], $aAudit['extra'], $aAudit['missing']);
        foreach ($aAudit['examples'] as $sExample) {
            fwrite(STDERR, "  AVISO calendario: $sExample\n");
        }
        if ($aAudit['differences'] > 0) {
            if (getenv('BIDEPLUS_CALENDAR_MODE') !== 'literal' && isset($GLOBALS['BUILD_ARGV'])) {
                // El calendario compacto no cuadra con el GTFS: se vuelve a construir con las fechas exactas, que no pueden fallar.
                fwrite(STDERR, "AVISO: {$aAudit['differences']} viajes con calendario distinto al del GTFS. Se reconstruye con fechas exactas (modo literal).\n");
                putenv('BIDEPLUS_CALENDAR_MODE=literal');
                $sCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . implode(' ', array_map('escapeshellarg', $GLOBALS['BUILD_ARGV']));
                passthru($sCommand, $iExitCode);
                exit($iExitCode);
            }
            // Un viaje que se muestra un día que no circula es el fallo que esta comprobación existe para evitar: se aborta
            // para que el despliegue automático no publique esa base y producción siga con la última buena.
            fwrite(STDERR, "ERROR: {$aAudit['differences']} viajes con calendario distinto al del GTFS. Build abortado.\n");
            exit(1);
        }
    }

    if ($iSkippedUnknownTrip > 0) {
        fwrite(STDERR, "  WARNING: $iSkippedUnknownTrip stop_times groups skipped (trip_id not found in trips.txt)\n");
    }
    if ($iSkippedUnknownRoute > 0) {
        fwrite(STDERR, "  WARNING: $iSkippedUnknownRoute stop_times groups skipped (route_id not found in routes.txt)\n");
    }
    if ($iSkippedDuplicateTrip > 0) {
        fwrite(STDERR, "  WARNING: $iSkippedDuplicateTrip duplicate/non-contiguous trip_id groups skipped\n");
    }
}

function insertShapes(PDO $Pdo, ZipArchive $Zip, array $aUsedShapeIds): int
{
    if (empty($aUsedShapeIds) || $Zip->locateName('shapes.txt') === false) {
        return 0;
    }
    $aPointsByShape = [];
    foreach (readCsv($Zip, 'shapes.txt') as $aRow) {
        if (!isset($aUsedShapeIds[$aRow['shape_id']])) {
            continue;
        }
        $dDistance = 0.0;
        if (isset($aRow['shape_dist_traveled']) && $aRow['shape_dist_traveled'] !== '') {
            $dDistance = (float)$aRow['shape_dist_traveled'];
        }
        $aPointsByShape[$aRow['shape_id']][(int)$aRow['shape_pt_sequence']] = [
            (float)$aRow['shape_pt_lat'],
            (float)$aRow['shape_pt_lon'],
            $dDistance,
        ];
    }

    $InsertShape = $Pdo->prepare('INSERT INTO shapes (id, points) VALUES (?, ?)');
    foreach ($aPointsByShape as $sShapeId => $aPoints) {
        ksort($aPoints);
        $aRounded = array_map(fn($aPoint) => [round($aPoint[0], 7), round($aPoint[1], 7), round($aPoint[2], 2)], array_values($aPoints));
        $InsertShape->execute([$sShapeId, json_encode($aRounded)]);
    }
    return count($aPointsByShape);
}
