<?php
// Lectura de los archivos del GTFS: líneas, paradas, viajes, horas de paso e información del feed. Lo usa build-database.php.

declare(strict_types=1);

function normalize(string $sText): string
{
    $aMap = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'Ñ' => 'n', 'Ç' => 'c',
    ];
    $sLower = mb_strtolower(strtr($sText, $aMap), 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $sLower));
}

// «Azkoitia(altzibar» -> «Azkoitia (Altzibar)»: espacio antes del paréntesis, paréntesis cerrado y mayúscula
// inicial en cada palabra que empieza en minúscula (también tras «-» y «(»).
function tidyHeadsign(string $sHeadsign): string
{
    $sHeadsign = preg_replace('/\s*\(\s*/u', ' (', trim($sHeadsign));
    if (substr_count($sHeadsign, '(') > substr_count($sHeadsign, ')')) {
        $sHeadsign = rtrim($sHeadsign) . ')';
    }
    return preg_replace_callback('/(^|[\s(\-\/])(\p{Ll})/u', fn($aM) => $aM[1] . mb_strtoupper($aM[2]), $sHeadsign);
}

// «Uk01-azkoitia>azpeitia>zestoa» -> «Azkoitia - Azpeitia - Zestoa»: sin el código delante y con mayúscula inicial.
function tidyRouteName(string $sName, string $sCode): string
{
    if ($sCode !== '' && stripos($sName, $sCode . '-') === 0) {
        $sName = substr($sName, strlen($sCode) + 1);
    }
    $aParts = array_map('trim', explode('>', $sName));
    $aParts = array_map(function ($sPart) {
        if ($sPart === mb_strtolower($sPart)) {
            return mb_convert_case($sPart, MB_CASE_TITLE);
        }
        return mb_strtoupper(mb_substr($sPart, 0, 1)) . mb_substr($sPart, 1);
    }, $aParts);
    return implode(' - ', array_filter($aParts, fn($sPart) => $sPart !== ''));
}

function readCsv(ZipArchive $Zip, string $sName, bool $bRequired = true): Generator
{
    $Stream = $Zip->getStream($sName);
    if ($Stream === false) {
        if ($bRequired) {
            throw new RuntimeException("Could not open $sName from zip");
        }
        return;
    }
    $aHeader = fgetcsv($Stream, 0, ',', '"', '\\');
    $aHeader = array_map('trim', $aHeader);
    $aHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $aHeader[0]);
    while (($aRow = fgetcsv($Stream, 0, ',', '"', '\\')) !== false) {
        if ($aRow === null || $aRow === [null]) {
            continue;
        }
        if (count($aRow) !== count($aHeader)) {
            continue;
        }
        yield array_combine($aHeader, array_map('trim', $aRow));
    }
    fclose($Stream);
}

function gtfsColor(string|null $sValue): string|null
{
    $sValue = strtoupper(ltrim(trim((string)$sValue), '#'));
    if (!preg_match('/^[0-9A-F]{6}$/', $sValue)) {
        return null;
    }
    return '#' . $sValue;
}

function gtfsDateToIso(string $sYmd): string
{
    return substr($sYmd, 0, 4) . '-' . substr($sYmd, 4, 2) . '-' . substr($sYmd, 6, 2);
}

function loadRoutes(ZipArchive $Zip, string|null $sExpectedAgencyId, array|null $aRouteIdPrefixes = null): array
{
    $aRoutes = [];
    foreach (readCsv($Zip, 'routes.txt') as $aRow) {
        $sAgencyId = '';
        if (isset($aRow['agency_id'])) {
            $sAgencyId = $aRow['agency_id'];
        }
        if ($sExpectedAgencyId !== null && $sAgencyId !== $sExpectedAgencyId) {
            fwrite(STDERR, "  WARNING: skipping route {$aRow['route_id']} with unexpected agency_id \"{$aRow['agency_id']}\"\n");
            continue;
        }
        if ($aRouteIdPrefixes !== null) {
            $bMatchesPrefix = false;
            foreach ($aRouteIdPrefixes as $sPrefix) {
                if (str_starts_with($aRow['route_id'], $sPrefix)) {
                    $bMatchesPrefix = true;
                    break;
                }
            }
            if (!$bMatchesPrefix) {
                continue;
            }
        }
        $sRouteId = $aRow['route_id'];
        $sCode = $aRow['route_short_name'];
        if ($sCode === '') {
            $sCode = $aRow['route_id'];
        }
        $aRoutes[$sRouteId] = [
            'code' => $sCode,
            'name' => trim(preg_replace('/ {2,}/', ' ', $aRow['route_long_name'])),
            // Colores del operador (route_color / route_text_color) para el mapa de la red; null si no los publica.
            'color' => gtfsColor($aRow['route_color'] ?? null),
            'textColor' => gtfsColor($aRow['route_text_color'] ?? null),
        ];
    }
    return $aRoutes;
}

function realStopRows(ZipArchive $Zip): Generator
{
    foreach (readCsv($Zip, 'stops.txt') as $aRow) {
        $sLocationType = '';
        if (isset($aRow['location_type'])) {
            $sLocationType = $aRow['location_type'];
        }
        if ($sLocationType !== '' && $sLocationType !== '0') {
            continue;
        }
        yield $aRow;
    }
}

function loadStopsRenfe(ZipArchive $Zip): array
{
    $aStops = [];
    foreach (realStopRows($Zip) as $aRow) {
        $aStops[$aRow['stop_id']] = [
            'name' => $aRow['stop_name'],
            'lat' => (float)$aRow['stop_lat'],
            'lon' => (float)$aRow['stop_lon'],
        ];
    }
    return $aStops;
}

function loadStopsBus(ZipArchive $Zip): array
{
    $aStops = [];
    foreach (realStopRows($Zip) as $aRow) {
        $sId = $aRow['stop_id'];
        $sName = $aRow['stop_name'];
        $sStripped = preg_replace('/\s*\(' . preg_quote((string)$sId, '/') . '\)$/', '', $sName);
        if ($sStripped === $sName && is_numeric($sId)) {
            fwrite(STDERR, "  WARNING: stop $sId name \"$sName\" lacked the expected trailing \"($sId)\" suffix\n");
        } else {
            $sName = $sStripped;
        }

        $aStops[$sId] = [
            'name' => $sName,
            'lat' => (float)$aRow['stop_lat'],
            'lon' => (float)$aRow['stop_lon'],
        ];
    }
    return $aStops;
}

function loadStopsMetro(ZipArchive $Zip): array
{
    $aStops = [];
    foreach (realStopRows($Zip) as $aRow) {
        $sId = stripFloatIdSuffix($aRow['stop_id']);
        $aStops[$sId] = [
            'name' => $aRow['stop_name'],
            'lat' => (float)$aRow['stop_lat'],
            'lon' => (float)$aRow['stop_lon'],
        ];
    }
    return $aStops;
}

function loadStopsEuskotren(ZipArchive $Zip): array
{
    $aStations = [];
    $aQuaysByParent = [];

    foreach (readCsv($Zip, 'stops.txt') as $aRow) {
        $sLocationType = '';
        if (isset($aRow['location_type'])) {
            $sLocationType = $aRow['location_type'];
        }

        if ($sLocationType === '1') {
            $aStations[$aRow['stop_id']] = [
                'name' => $aRow['stop_name'],
                'lat' => (float)$aRow['stop_lat'],
                'lon' => (float)$aRow['stop_lon'],
            ];
            continue;
        }

        if ($sLocationType === '0') {
            $sParentId = '';
            if (isset($aRow['parent_station'])) {
                $sParentId = $aRow['parent_station'];
            }
            if ($sParentId !== '') {
                $aQuaysByParent[$sParentId][] = $aRow;
            }
        }
    }

    $aStops = $aStations;
    foreach ($aQuaysByParent as $sParentId => $aQuays) {
        if (!isset($aStations[$sParentId])) {
            continue;
        }

        usort($aQuays, fn($aA, $aB) => $aA['stop_id'] <=> $aB['stop_id']);

        $iIndex = 1;
        foreach ($aQuays as $aQuay) {
            $sLabel = 'Andén ' . $iIndex;
            if (preg_match('/_Q(\d+):$/', $aQuay['stop_id'], $aM)) {
                $sLabel = 'Andén ' . $aM[1];
            }
            $aStops[$aQuay['stop_id']] = [
                'name' => $aStations[$sParentId]['name'],
                'lat' => (float)$aQuay['stop_lat'],
                'lon' => (float)$aQuay['stop_lon'],
                'stationId' => $sParentId,
                'platformLabel' => $sLabel,
            ];
            $iIndex++;
        }
    }

    return $aStops;
}

function loadTrips(ZipArchive $Zip): array
{
    $aTrips = [];
    foreach (readCsv($Zip, 'trips.txt') as $aRow) {
        $sTripId = $aRow['trip_id'];
        $sTripNumber = null;
        if (preg_match('/^trp_[A-Za-z]*\d+_(\d+)_/', $sTripId, $aM)) {
            $sTripNumber = $aM[1];
        }
        $sHeadsign = '';
        if (isset($aRow['trip_headsign'])) {
            $sHeadsign = $aRow['trip_headsign'];
        }
        $sShapeId = null;
        if (isset($aRow['shape_id']) && $aRow['shape_id'] !== '') {
            $sShapeId = $aRow['shape_id'];
        }
        $aTrips[$sTripId] = [
            'routeId' => (string)$aRow['route_id'],
            'serviceId' => $aRow['service_id'],
            'headsign' => $sHeadsign,
            'tripNumber' => $sTripNumber,
            'shapeId' => $sShapeId,
        ];
    }
    return $aTrips;
}

function stripFloatIdSuffix(string $sId): string
{
    return preg_replace('/\.0$/', '', $sId);
}

function streamStopTimesByTrip(ZipArchive $Zip): Generator
{
    $Stream = $Zip->getStream('stop_times.txt');
    if ($Stream === false) {
        fwrite(STDERR, "Could not open stop_times.txt stream\n");
        exit(1);
    }
    $aHeader = fgetcsv($Stream, 0, ',', '"', '\\');
    $aHeader = array_map('trim', $aHeader);

    $aByTrip = [];

    while (($aRow = fgetcsv($Stream, 0, ',', '"', '\\')) !== false) {
        if ($aRow === null || $aRow === [null] || count($aRow) !== count($aHeader)) {
            continue;
        }
        $aAssoc = array_combine($aHeader, array_map('trim', $aRow));
        $sTripId = $aAssoc['trip_id'];

        $iArrival = null;
        if ($aAssoc['arrival_time'] !== '') {
            $iArrival = timeToSeconds($aAssoc['arrival_time']);
        }
        $iDeparture = $iArrival;
        if ($aAssoc['departure_time'] !== '') {
            $iDeparture = timeToSeconds($aAssoc['departure_time']);
        }
        if ($iArrival === null) {
            $iArrival = $iDeparture;
        }
        $dDistance = null;
        if (isset($aAssoc['shape_dist_traveled']) && $aAssoc['shape_dist_traveled'] !== '') {
            $dDistance = (float)$aAssoc['shape_dist_traveled'];
        }

        $aByTrip[$sTripId][] = [
            'seqOrder' => (int)$aAssoc['stop_sequence'],
            'stopId' => stripFloatIdSuffix($aAssoc['stop_id']),
            'arrival' => $iArrival,
            'departure' => $iDeparture,
            'distance' => $dDistance,
        ];
    }
    fclose($Stream);

    foreach ($aByTrip as $sTripId => $aBuffer) {
        yield $sTripId => $aBuffer;
        unset($aByTrip[$sTripId]);
    }
}

function timeToSeconds(string $sHms): int
{
    [$iH, $iM, $iS] = array_map('intval', explode(':', $sHms));
    return $iH * 3600 + $iM * 60 + $iS;
}

function loadFeedInfo(ZipArchive $Zip): array
{
    if ($Zip->locateName('feed_info.txt') === false) {
        return [];
    }
    foreach (readCsv($Zip, 'feed_info.txt') as $aRow) {
        $sFeedVersion = '';
        if (isset($aRow['feed_version'])) {
            $sFeedVersion = $aRow['feed_version'];
        }
        $sFeedStartDate = '';
        if (isset($aRow['feed_start_date'])) {
            $sFeedStartDate = $aRow['feed_start_date'];
        }
        $sFeedEndDate = '';
        if (isset($aRow['feed_end_date'])) {
            $sFeedEndDate = $aRow['feed_end_date'];
        }
        return [
            'feed_version' => $sFeedVersion,
            'feed_start_date' => $sFeedStartDate,
            'feed_end_date' => $sFeedEndDate,
        ];
    }
    return [];
}
