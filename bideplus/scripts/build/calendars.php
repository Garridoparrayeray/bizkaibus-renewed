<?php
// Calendarios de servicio: días de la semana, excepciones y fechas exactas de cada viaje. Lo usa build-database.php.

declare(strict_types=1);

/*
 * Con $bLiteralDates (Metro Bilbao) las fechas de calendar_dates.txt valen exactamente por esas fechas:
 * no se deduce un patrón semanal de ellas. El CTB publica las obras de vía como un calendario Lun-Jue
 * (obranegvia2) y otro solo por fechas (obranegvia1) que sustituye al primero esos días; deducir «todos los
 * jueves» de las fechas de este último duplicaba todos los trenes los jueves sin sustitución.
 */
function loadCalendars(ZipArchive $Zip, bool $bLiteralDates = false): array
{
    $aRanges = [];
    $aBaseWeekdayMask = [];
    $aWeekdayColumns = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    foreach (readCsv($Zip, 'calendar.txt') as $aRow) {
        $aRanges[$aRow['service_id']] = [
            'from' => gtfsDateToIso($aRow['start_date']),
            'to' => gtfsDateToIso($aRow['end_date']),
        ];
        $iMask = 0;
        foreach ($aWeekdayColumns as $iIndex => $sColumn) {
            $iColumnValue = 0;
            if (isset($aRow[$sColumn])) {
                $iColumnValue = (int)$aRow[$sColumn];
            }
            if ($iColumnValue === 1) {
                $iMask |= (1 << $iIndex);
            }
        }
        $aBaseWeekdayMask[$aRow['service_id']] = $iMask;
    }

    $aActiveDates = [];
    foreach (readCsv($Zip, 'calendar_dates.txt', false) as $aRow) {
        $sDate = gtfsDateToIso($aRow['date']);
        $aActiveDates[$aRow['service_id']][$sDate] = ((int)$aRow['exception_type']) === 1;
    }

    $aCalendars = [];
    foreach (array_unique(array_merge(array_keys($aRanges), array_keys($aActiveDates))) as $sId) {
        $aDates = [];
        if (isset($aActiveDates[$sId])) {
            $aDates = $aActiveDates[$sId];
        }
        $iBaseMask = 0;
        $bHasCalendarRow = isset($aBaseWeekdayMask[$sId]);
        if ($bHasCalendarRow) {
            $iBaseMask = $aBaseWeekdayMask[$sId];
        }

        $aExcludedDates = [];
        $aAvailableDates = [];
        foreach ($aDates as $sDate => $bIsAvailable) {
            if ($bIsAvailable) {
                $aAvailableDates[$sDate] = true;
            } else {
                $aExcludedDates[] = $sDate;
            }
        }

        $aIncludedDates = [];
        if ($bLiteralDates) {
            $iWeekdayMask = $iBaseMask;
            $aIncludedDates = array_keys($aAvailableDates);
        } elseif ($iBaseMask !== 0) {
            $iWeekdayMask = $iBaseMask | computeWeekdayMask($aAvailableDates);
        } else {
            $iWeekdayMask = computeWeekdayMaskFromEvidence($aAvailableDates, $aIncludedDates);
        }

        $sFrom = '';
        $sTo = '';
        if (isset($aRanges[$sId])) {
            $sFrom = $aRanges[$sId]['from'];
            $sTo = $aRanges[$sId]['to'];
        }

        $aCalendars[$sId] = [
            'from' => $sFrom,
            'to' => $sTo,
            'weekdayMask' => $iWeekdayMask,
            'activeDateCount' => count(array_filter($aDates)),
            'excludedDates' => $aExcludedDates,
            'includedDates' => $aIncludedDates,
            // Lo que dice el GTFS tal cual, sin deducir patrones: para saber exactamente en qué fechas circula el servicio.
            'exact' => [
                'mask' => $iBaseMask,
                'from' => $sFrom,
                'to' => $sTo,
                'available' => array_keys($aAvailableDates),
                'excluded' => $aExcludedDates,
            ],
        ];
    }
    return $aCalendars;
}

/*
 * Fechas en las que un servicio circula de verdad según el GTFS, dentro del periodo publicado: las fechas añadidas
 * (calendar_dates, tipo 1) más los días de calendar.txt dentro de su rango, menos las anuladas (tipo 2).
 * $aWindow es la lista de fechas «Y-m-d» del periodo. Devuelve [fecha => true].
 */
function exactServiceDates(array $aExact, array $aWindow): array
{
    $aAvailable = array_flip($aExact['available']);
    $aExcluded = array_flip($aExact['excluded']);
    $aRuns = [];
    foreach ($aWindow as $sDate) {
        $bRuns = isset($aAvailable[$sDate]);
        if (!$bRuns && $aExact['mask'] !== 0
            && ($aExact['from'] === '' || $sDate >= $aExact['from'])
            && ($aExact['to'] === '' || $sDate <= $aExact['to'])) {
            $bRuns = ($aExact['mask'] & (1 << ((int)(new DateTime($sDate))->format('N') - 1))) !== 0;
        }
        if ($bRuns && !isset($aExcluded[$sDate])) {
            $aRuns[$sDate] = true;
        }
    }
    return $aRuns;
}

/*
 * Calendario exacto dentro del periodo publicado para un conjunto de fechas en las que circula un viaje: un patrón
 * semanal compacto (los días de la semana en los que circula al menos la mitad de las veces) más las fechas
 * sueltas que lo corrigen (incluidas y excluidas). Así el viaje circula exactamente en esas fechas, ni una más:
 * un festivo entre semana o un servicio que empieza a mitad de semana no se rellena con el patrón.
 * Fuera del periodo publicado el patrón semanal sigue siendo la mejor suposición.
 * @return array{0: int, 1: string[], 2: string[]} máscara, fechas incluidas, fechas excluidas
 */
function exactCalendarForWindow(array $aRunsOn, array $aWindow): array
{
    $aOccurrences = array_fill(1, 7, 0);
    $aCovered = array_fill(1, 7, 0);
    $aWeekdayOf = [];
    foreach ($aWindow as $sDate) {
        $iWeekday = (int)(new DateTime($sDate))->format('N');
        $aWeekdayOf[$sDate] = $iWeekday;
        $aOccurrences[$iWeekday]++;
        if (isset($aRunsOn[$sDate])) {
            $aCovered[$iWeekday]++;
        }
    }
    $iMask = 0;
    foreach ($aOccurrences as $iWeekday => $iCount) {
        if ($aCovered[$iWeekday] > 0 && $aCovered[$iWeekday] * 2 >= $iCount) {
            $iMask |= 1 << ($iWeekday - 1);
        }
    }
    $aIncluded = [];
    $aExcluded = [];
    foreach ($aWindow as $sDate) {
        $bInMask = ($iMask & (1 << ($aWeekdayOf[$sDate] - 1))) !== 0;
        $bRuns = isset($aRunsOn[$sDate]);
        if ($bRuns && !$bInMask) {
            $aIncluded[] = $sDate;
        } elseif (!$bRuns && $bInMask) {
            $aExcluded[] = $sDate;
        }
    }
    return [$iMask, $aIncluded, $aExcluded];
}

/** Lista de fechas «Y-m-d» entre dos fechas ISO, ambas incluidas. */
function datesBetween(string $sFromIso, string $sToIso): array
{
    $aDates = [];
    $Day = new DateTime($sFromIso);
    $End = new DateTime($sToIso);
    while ($Day <= $End) {
        $aDates[] = $Day->format('Y-m-d');
        $Day->modify('+1 day');
    }
    return $aDates;
}

function computeWeekdayMask(array $aDateAvailability): int
{
    $iMask = 0;
    foreach ($aDateAvailability as $sDate => $bIsAvailable) {
        if (!$bIsAvailable) {
            continue;
        }
        $iWeekday = (int)(new DateTime($sDate))->format('N');
        $iMask |= (1 << ($iWeekday - 1));
    }
    return $iMask;
}

function computeWeekdayMaskFromEvidence(array $aDateAvailability, array &$aUnbackedDates): int
{
    $aByWeekday = [];
    foreach ($aDateAvailability as $sDate => $bIsAvailable) {
        if (!$bIsAvailable) {
            continue;
        }
        $iWeekday = (int)(new DateTime($sDate))->format('N');
        $aByWeekday[$iWeekday][] = $sDate;
    }

    $iMask = 0;
    foreach ($aByWeekday as $iWeekday => $aDates) {
        if (count($aDates) >= MIN_OCCURRENCES_FOR_WEEKLY_PATTERN) {
            $iMask |= (1 << ($iWeekday - 1));
        } else {
            foreach ($aDates as $sDate) {
                $aUnbackedDates[] = $sDate;
            }
        }
    }
    return $iMask;
}

function excludedDatesForGroup(array $aGroup, array $aSignatures): array
{
    $aExcluded = [];
    foreach (array_keys($aGroup['excludedDates']) as $sDate) {
        $bAnyMemberRuns = false;
        foreach ($aGroup['memberTripIds'] as $sTripId) {
            if (signatureRunsOn($aSignatures[$sTripId], $sDate)) {
                $bAnyMemberRuns = true;
                break;
            }
        }
        if (!$bAnyMemberRuns) {
            $aExcluded[$sDate] = true;
        }
    }
    return $aExcluded;
}

function signatureRunsOn(array $aSig, string $sDate): bool
{
    if (in_array($sDate, $aSig['includedDates'], true)) {
        return true;
    }
    if (in_array($sDate, $aSig['excludedDates'], true)) {
        return false;
    }
    $iWeekdayBit = 1 << ((int)(new DateTime($sDate))->format('N') - 1);
    return ($aSig['weekdayMask'] & $iWeekdayBit) !== 0;
}

function calendarGroupKeyFor(bool $bMergeCalendars, string $sServiceId, array $aCalendars): string
{
    if (!$bMergeCalendars) {
        return '';
    }
    if (!isset($aCalendars[$sServiceId])) {
        return '';
    }
    $aCal = $aCalendars[$sServiceId];
    if ($aCal['from'] !== '') {
        return $sServiceId;
    }
    if ($aCal['weekdayMask'] === 0 && !empty($aCal['includedDates'])) {
        return $sServiceId;
    }
    return '';
}

/*
 * Comprobación de punta a punta: para cada viaje insertado, las fechas del periodo en las que la app lo mostraría
 * (máscara semanal o fecha incluida, menos las excluidas, tal como lo consultan las pantallas) contra las fechas
 * exactas del GTFS. Debe dar cero diferencias; si no, hay un viaje que se muestra un día que no circula (o al revés).
 * @return array{checked: int, differences: int, extra: int, missing: int, examples: string[]}
 */
function auditExactCalendars(PDO $Pdo, array $aRepresentatives, array $aWindow): array
{
    $aMask = [];
    foreach ($Pdo->query('SELECT id, weekday_mask FROM service_calendars') as $aRow) {
        $aMask[$aRow['id']] = (int)$aRow['weekday_mask'];
    }
    $aIncluded = [];
    $aExcluded = [];
    foreach ($Pdo->query('SELECT calendar_id, date, available FROM service_calendar_exceptions') as $aRow) {
        if ((int)$aRow['available'] === 1) {
            $aIncluded[$aRow['calendar_id']][$aRow['date']] = true;
        } else {
            $aExcluded[$aRow['calendar_id']][$aRow['date']] = true;
        }
    }
    $aWeekdayOf = [];
    foreach ($aWindow as $sDate) {
        $aWeekdayOf[$sDate] = (int)(new DateTime($sDate))->format('N');
    }
    $aResult = ['checked' => 0, 'differences' => 0, 'extra' => 0, 'missing' => 0, 'examples' => []];
    foreach ($aRepresentatives as $sTripId => $aGroup) {
        if (!isset($aGroup['exactRunsOn'])) {
            continue;
        }
        $aResult['checked']++;
        $sCalendarId = $aGroup['calendarId'];
        $iMask = $aMask[$sCalendarId] ?? 0;
        $aExtra = [];
        $aMissing = [];
        foreach ($aWindow as $sDate) {
            $bApp = ((($iMask >> ($aWeekdayOf[$sDate] - 1)) & 1) === 1 || isset($aIncluded[$sCalendarId][$sDate])) && !isset($aExcluded[$sCalendarId][$sDate]);
            $bTruth = isset($aGroup['exactRunsOn'][$sDate]);
            if ($bApp && !$bTruth) {
                $aExtra[] = $sDate;
            } elseif (!$bApp && $bTruth) {
                $aMissing[] = $sDate;
            }
        }
        if ($aExtra || $aMissing) {
            $aResult['differences']++;
            $aResult['extra'] += count($aExtra);
            $aResult['missing'] += count($aMissing);
            if (count($aResult['examples']) < 10) {
                $aResult['examples'][] = $sTripId . ' (calendario ' . $sCalendarId . '): de más ' . implode(',', array_slice($aExtra, 0, 3)) . ' | faltan ' . implode(',', array_slice($aMissing, 0, 3));
            }
        }
    }
    return $aResult;
}
