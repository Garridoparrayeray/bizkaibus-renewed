<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('Europe/Madrid');

const MIN_OCCURRENCES_FOR_WEEKLY_PATTERN = 2;

const NETWORK_DEFAULTS = [
    'bus' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bizkaibus.zip',
        'output' => __DIR__ . '/../data/bizkaibus.sqlite',
        'label' => 'BizkaiBus+',
        'agencyId' => '200',
        'skipGeocode' => false,
    ],
    'metro' => [
        'source' => 'https://cms.metrobilbao.eus/es/get/open_data/horarios/es',
        'output' => __DIR__ . '/../data/metrobilbao.sqlite',
        'label' => 'Metro+',

        'agencyId' => null,

        'skipGeocode' => true,
    ],
];
const GEOCACHE_PATH = __DIR__ . '/geocache.json';
const NOMINATIM_CONTACT = 'garridoparrayeraytx@gmail.com';

function main(array $aArgv): void
{
    $aOptions = parseArgs($aArgv);
    $sNetwork = 'bus';
    if (isset($aOptions['network'])) {
        $sNetwork = $aOptions['network'];
    }
    if (!isset(NETWORK_DEFAULTS[$sNetwork])) {
        fwrite(STDERR, "Unknown --network=\"$sNetwork\" (expected bus|metro)\n");
        exit(1);
    }
    $aDefaults = NETWORK_DEFAULTS[$sNetwork];

    $sSource = $aDefaults['source'];
    if (isset($aOptions['source'])) {
        $sSource = $aOptions['source'];
    }
    $sOutput = $aDefaults['output'];
    if (isset($aOptions['output'])) {
        $sOutput = $aOptions['output'];
    }
    $bSkipGeocode = isset($aOptions['skip-geocode']) || $aDefaults['skipGeocode'];
    $sAgencyId = $aDefaults['agencyId'];

    echo "== {$aDefaults['label']} database build (GTFS, network=$sNetwork) ==\n";
    $sZipPath = resolveZipPath($sSource);
    echo "Reading GTFS export from: $sSource\n";

    $Zip = new ZipArchive();
    if ($Zip->open($sZipPath) !== true) {
        fwrite(STDERR, "Could not open zip: $sZipPath\n");
        exit(1);
    }

    echo "Parsing routes.txt...\n";
    $aRoutes = loadRoutes($Zip, $sAgencyId);
    echo '  ' . count($aRoutes) . " routes\n";

    echo "Parsing stops.txt...\n";
    if ($sNetwork === 'metro') {
        $aStops = loadStopsMetro($Zip);
    } else {
        $aStops = loadStopsBus($Zip);
    }
    echo '  ' . count($aStops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $aStops = geocodeStops($aStops, $bSkipGeocode);

    echo "Parsing calendar.txt / calendar_dates.txt...\n";
    $aCalendars = loadCalendars($Zip);
    echo '  ' . count($aCalendars) . " service calendars\n";

    echo "Parsing trips.txt...\n";
    $aTrips = loadTrips($Zip);
    echo '  ' . count($aTrips) . " trips\n";

    $aFeedInfo = loadFeedInfo($Zip);
    if (isset($aFeedInfo['feed_version'])) {
        echo "  feed_version: {$aFeedInfo['feed_version']} (start: {$aFeedInfo['feed_start_date']}, end: {$aFeedInfo['feed_end_date']})\n";
    }

    $sFeedEndIso = '';
    if (!empty($aFeedInfo['feed_end_date'])) {
        $sFeedEndIso = gtfsDateToIso($aFeedInfo['feed_end_date']);
    }
    if ($sFeedEndIso !== '' && $sFeedEndIso < date('Y-m-d')) {
        fwrite(STDERR, "ERROR: el GTFS terminó el $sFeedEndIso, hoy es " . date('Y-m-d') . ". Build abortado.\n");
        exit(1);
    }

    if (file_exists($sOutput)) {
        unlink($sOutput);
    }
    $Pdo = new PDO('sqlite:' . $sOutput);
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $Pdo->exec('PRAGMA journal_mode = DELETE');
    $Pdo->exec('PRAGMA synchronous = OFF');

    createSchema($Pdo);

    $Pdo->beginTransaction();

    insertStops($Pdo, $aStops);
    insertCalendars($Pdo, $aCalendars);
    insertMeta($Pdo, $aFeedInfo);

    $InsertLine = $Pdo->prepare('INSERT OR IGNORE INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');
    foreach ($aRoutes as $iRouteId => $aRoute) {
        $InsertLine->execute([$iRouteId, $aRoute['code'], $aRoute['name'], normalize($aRoute['name'])]);
    }

    echo "Processing stop_times.txt (the big one, ~1.1M rows, two bounded-memory passes)...\n";
    $aTotals = ['patterns' => 0, 'journeys' => 0, 'passingTimes' => 0];
    processStopTimes($Pdo, $Zip, $aTrips, $aRoutes, $aCalendars, $aTotals, $sNetwork);

    $Pdo->commit();

    echo "Building indexes...\n";
    createIndexes($Pdo);

    $Zip->close();

    echo "\n== Summary ==\n";
    $sGeocodedCount = $Pdo->query("SELECT COUNT(*) FROM stops WHERE area != ''")->fetchColumn();
    printf("  stops geocoded (municipality/suburb/neighbourhood): %d / %d\n", $sGeocodedCount, count($aStops));
    printf("  lines:            %d\n", count($aRoutes));
    printf("  patterns:         %d\n", $aTotals['patterns']);
    printf("  service_journeys: %d\n", $aTotals['journeys']);
    printf("  passing_times:    %d\n", $aTotals['passingTimes']);

    echo "\nDatabase written to: $sOutput\n";
    printf("File size: %.1f MB\n", filesize($sOutput) / 1024 / 1024);
}

function parseArgs(array $aArgv): array
{
    $aOut = [];
    foreach ($aArgv as $sArg) {
        if (preg_match('/^--(network|source|output)=(.+)$/', $sArg, $aM)) {
            $aOut[$aM[1]] = $aM[2];
        } elseif ($sArg === '--skip-geocode') {
            $aOut['skip-geocode'] = true;
        }
    }
    return $aOut;
}

function geocodeStops(array $aStops, bool $bSkip): array
{
    if ($bSkip) {
        foreach ($aStops as &$aStop) {
            $aStop['area'] = '';
        }
        return $aStops;
    }

    $aCache = [];
    if (is_file(GEOCACHE_PATH)) {
        $aCache = json_decode((string)file_get_contents(GEOCACHE_PATH), true);
    }
    if (!is_array($aCache)) {
        $aCache = [];
    }

    $aClusterKeys = [];
    foreach ($aStops as $iId => $aStop) {
        $sKey = round($aStop['lat'], 3) . ',' . round($aStop['lon'], 3);
        $aClusterKeys[$iId] = $sKey;
    }
    $aUniqueKeys = array_unique(array_values($aClusterKeys));
    $aMissing = array_values(array_diff($aUniqueKeys, array_keys($aCache)));

    echo '  ' . count($aUniqueKeys) . ' unique ~1km clusters, ' . count($aMissing) . " not yet cached\n";

    foreach ($aMissing as $iIndex => $sKey) {
        [$sLat, $sLon] = explode(',', $sKey);
        $aCache[$sKey] = reverseGeocode((float)$sLat, (float)$sLon);
        if (($iIndex + 1) % 25 === 0 || $iIndex + 1 === count($aMissing)) {
            echo '    geocoded ' . ($iIndex + 1) . '/' . count($aMissing) . "\r";
            file_put_contents(GEOCACHE_PATH, json_encode($aCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($iIndex + 1 < count($aMissing)) {
            usleep(1_100_000);
        }
    }
    if (!empty($aMissing)) {
        echo "\n";
    }
    file_put_contents(GEOCACHE_PATH, json_encode($aCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    foreach ($aStops as $iId => &$aStop) {
        $aStop['area'] = '';
        if (isset($aCache[$aClusterKeys[$iId]])) {
            $aStop['area'] = $aCache[$aClusterKeys[$iId]];
        }
    }
    return $aStops;
}

function reverseGeocode(float $dLat, float $dLon): string
{
    $sUrl = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $dLat,
        'lon' => $dLon,
        'format' => 'jsonv2',
        'zoom' => 16,
        'addressdetails' => 1,
    ]);
    $Ch = curl_init($sUrl);
    curl_setopt_array($Ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: BizkaiBusPlus-etl/1.0 (' . NOMINATIM_CONTACT . ')'],
    ]);
    $sBody = curl_exec($Ch);
    if ($sBody === false) {
        return '';
    }
    $aData = json_decode($sBody, true);
    $aAddress = [];
    if (isset($aData['address'])) {
        $aAddress = $aData['address'];
    }

    $sNeighbourhood = null;
    if (isset($aAddress['neighbourhood'])) {
        $sNeighbourhood = $aAddress['neighbourhood'];
    }
    $sSuburb = null;
    if (isset($aAddress['suburb'])) {
        $sSuburb = $aAddress['suburb'];
    }
    $sTownLevel = null;
    if (isset($aAddress['town'])) {
        $sTownLevel = $aAddress['town'];
    } elseif (isset($aAddress['city'])) {
        $sTownLevel = $aAddress['city'];
    } elseif (isset($aAddress['village'])) {
        $sTownLevel = $aAddress['village'];
    }

    $aParts = array_filter([$sNeighbourhood, $sSuburb, $sTownLevel]);
    return implode(', ', array_unique($aParts));
}

function resolveZipPath(string $sSource): string
{
    if (preg_match('#^https?://#i', $sSource)) {
        $sTmp = tempnam(sys_get_temp_dir(), 'bbgtfs') . '.zip';
        $Ch = curl_init($sSource);
        $Fp = fopen($sTmp, 'wb');
        curl_setopt_array($Ch, [
            CURLOPT_FILE => $Fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
        ]);
        $bOk = curl_exec($Ch);
        if ($bOk === false) {
            fwrite(STDERR, 'Download failed: ' . curl_error($Ch) . "\n");
            exit(1);
        }
        curl_close($Ch);
        fclose($Fp);
        return $sTmp;
    }
    if (!file_exists($sSource)) {
        fwrite(STDERR, "Source file not found: $sSource\n");
        exit(1);
    }
    return $sSource;
}

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

function readCsv(ZipArchive $Zip, string $sName): Generator
{
    $Stream = $Zip->getStream($sName);
    if ($Stream === false) {
        throw new RuntimeException("Could not open $sName from zip");
    }
    $aHeader = fgetcsv($Stream, 0, ',', '"', '\\');
    while (($aRow = fgetcsv($Stream, 0, ',', '"', '\\')) !== false) {
        if ($aRow === null || $aRow === [null]) {
            continue;
        }
        if (count($aRow) !== count($aHeader)) {
            continue;
        }
        yield array_combine($aHeader, $aRow);
    }
    fclose($Stream);
}

function gtfsDateToIso(string $sYmd): string
{
    return substr($sYmd, 0, 4) . '-' . substr($sYmd, 4, 2) . '-' . substr($sYmd, 6, 2);
}

function loadRoutes(ZipArchive $Zip, string|null $sExpectedAgencyId): array
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
        $iRouteId = (int)$aRow['route_id'];
        $sCode = $aRow['route_short_name'];
        if ($sCode === '') {
            $sCode = $aRow['route_id'];
        }
        $aRoutes[$iRouteId] = [
            'code' => $sCode,
            'name' => $aRow['route_long_name'],
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

function loadStopsBus(ZipArchive $Zip): array
{
    $aStops = [];
    foreach (realStopRows($Zip) as $aRow) {
        $iId = (int)$aRow['stop_id'];
        $sName = $aRow['stop_name'];
        $sStripped = preg_replace('/\s*\(' . preg_quote((string)$iId, '/') . '\)$/', '', $sName);
        if ($sStripped === $sName) {
            fwrite(STDERR, "  WARNING: stop $iId name \"$sName\" lacked the expected trailing \"($iId)\" suffix\n");
        } else {
            $sName = $sStripped;
        }

        $aStops[$iId] = [
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
        $iId = (int)$aRow['stop_id'];
        $aStops[$iId] = [
            'name' => $aRow['stop_name'],
            'lat' => (float)$aRow['stop_lat'],
            'lon' => (float)$aRow['stop_lon'],
        ];
    }
    return $aStops;
}

function loadCalendars(ZipArchive $Zip): array
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
    foreach (readCsv($Zip, 'calendar_dates.txt') as $aRow) {
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
        if ($iBaseMask !== 0) {

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
        ];
    }
    return $aCalendars;
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
        $aTrips[$sTripId] = [
            'routeId' => (int)$aRow['route_id'],
            'serviceId' => $aRow['service_id'],
            'headsign' => $sHeadsign,
            'tripNumber' => $sTripNumber,
        ];
    }
    return $aTrips;
}

function streamStopTimesByTrip(ZipArchive $Zip): Generator
{
    $Stream = $Zip->getStream('stop_times.txt');
    if ($Stream === false) {
        fwrite(STDERR, "Could not open stop_times.txt stream\n");
        exit(1);
    }
    $aHeader = fgetcsv($Stream, 0, ',', '"', '\\');

    $aByTrip = [];

    while (($aRow = fgetcsv($Stream, 0, ',', '"', '\\')) !== false) {
        if ($aRow === null || $aRow === [null] || count($aRow) !== count($aHeader)) {
            continue;
        }
        $aAssoc = array_combine($aHeader, $aRow);
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

        $aByTrip[$sTripId][] = [
            'seqOrder' => (int)$aAssoc['stop_sequence'],
            'stopId' => (int)$aAssoc['stop_id'],
            'arrival' => $iArrival,
            'departure' => $iDeparture,
        ];
    }
    fclose($Stream);

    foreach ($aByTrip as $sTripId => $aBuffer) {
        yield $sTripId => $aBuffer;
        unset($aByTrip[$sTripId]);
    }
}

function calendarGroupKeyFor(string $sNetwork, string $sServiceId, array $aCalendars): string
{
    if ($sNetwork !== 'metro') {
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

function processStopTimes(PDO $Pdo, ZipArchive $Zip, array $aTrips, array $aRoutes, array $aCalendars, array &$aTotals, string $sNetwork): void
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
        if (isset($aCalendars[$aTrip['serviceId']])) {
            $iWeekdayMask = $aCalendars[$aTrip['serviceId']]['weekdayMask'];
            $aIncludedDates = $aCalendars[$aTrip['serviceId']]['includedDates'];
        }
        $sCalendarGroupKey = calendarGroupKeyFor($sNetwork, $aTrip['serviceId'], $aCalendars);

        $aSignatures[$sTripId] = [
            'routeId' => $aTrip['routeId'],
            'tripNumber' => $aTrip['tripNumber'],
            'headsign' => $aTrip['headsign'],
            'patternKey' => $sPatternKey,
            'firstDeparture' => $iFirstDeparture,
            'weekdayMask' => $iWeekdayMask,
            'includedDates' => $aIncludedDates,
            'calendarGroupKey' => $sCalendarGroupKey,
        ];
    }

    $iDepartureClusterGapSeconds = 90;

    $aByRoutePattern = [];
    foreach ($aSignatures as $sTripId => $aSig) {
        $sTripNumberPart = '';
        if (isset($aSig['tripNumber'])) {
            $sTripNumberPart = $aSig['tripNumber'];
        }

        $sKey = $aSig['routeId'] . '|' . $sTripNumberPart . '|' . $aSig['patternKey'] . '|' . $aSig['calendarGroupKey'];
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
            }
            $aGroups[$sClusterKey]['weekdayMask'] |= $aSig['weekdayMask'];

            foreach ($aSig['includedDates'] as $sDate) {
                $aGroups[$sClusterKey]['includedDates'][$sDate] = true;
            }
            $iPreviousDeparture = $aSig['firstDeparture'];
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
        if (!isset($aMaskToCalendarId[$aCal['weekdayMask']])) {
            $aMaskToCalendarId[$aCal['weekdayMask']] = $sCalId;
        }
    }
    $aSyntheticCalendars = [];
    $aRepresentatives = [];

    $aExtraIncludedDatesByCalendarId = [];
    foreach ($aGroups as $aGroup) {

        if ($aGroup['calendarGroupKey'] !== '') {
            $aGroup['calendarId'] = $aGroup['calendarGroupKey'];
            $aRepresentatives[$aGroup['representativeTripId']] = $aGroup;
            continue;
        }

        $iMask = $aGroup['weekdayMask'];
        if (!isset($aMaskToCalendarId[$iMask])) {
            $sNewId = 'merged_' . $iMask;
            $aMaskToCalendarId[$iMask] = $sNewId;
            $aSyntheticCalendars[$sNewId] = $iMask;
        }
        $sCalendarId = $aMaskToCalendarId[$iMask];
        $aGroup['calendarId'] = $sCalendarId;
        foreach (array_keys($aGroup['includedDates']) as $sDate) {
            $aExtraIncludedDatesByCalendarId[$sCalendarId][$sDate] = true;
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

    echo "  Pass 2/2: inserting merged journeys + passing_times...\n";

    $InsertPattern = $Pdo->prepare('INSERT OR IGNORE INTO journey_patterns (id, line_id, headsign) VALUES (?, ?, ?)');
    $InsertPatternStop = $Pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $InsertJourney = $Pdo->prepare('INSERT OR IGNORE INTO service_journeys (id, line_id, journey_pattern_id, trip_number, calendar_id, first_departure_seconds) VALUES (?, ?, ?, ?, ?, ?)');
    $InsertPassingTime = $Pdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
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
        $InsertJourney->execute([$sTripId, $aGroup['routeId'], $sPatternKey, $sTripNumber, $aGroup['calendarId'], $aGroup['firstDeparture']]);
        $aTotals['journeys']++;

        foreach ($aBuffer as $aRow) {
            $InsertPassingTime->execute([$sTripId, $aRow['seqOrder'], $aRow['stopId'], $aRow['arrival'], $aRow['departure']]);
            $aTotals['passingTimes']++;
        }

        $iRowCount += count($aBuffer);
        if ($iRowCount % 50000 < 40) {
            printf("  processed ~%d passing_times rows\r", $iRowCount);
        }
    }
    echo "\n";

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

function timeToSeconds(string $sHms): int
{
    [$iH, $iM, $iS] = array_map('intval', explode(':', $sHms));
    return $iH * 3600 + $iM * 60 + $iS;
}

function createSchema(PDO $Pdo): void
{
    $Pdo->exec('
        CREATE TABLE stops (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL,
            area TEXT NOT NULL DEFAULT \'\',
            area_normalized TEXT NOT NULL DEFAULT \'\',
            lat REAL NOT NULL,
            lon REAL NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE lines (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE journey_patterns (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            headsign TEXT
        )
    ');
    $Pdo->exec('
        CREATE TABLE journey_pattern_stops (
            journey_pattern_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE service_journeys (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            journey_pattern_id TEXT NOT NULL,
            trip_number TEXT,
            calendar_id TEXT NOT NULL,
            first_departure_seconds INTEGER
        )
    ');
    $Pdo->exec('
        CREATE TABLE passing_times (
            service_journey_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL,
            arrival_seconds INTEGER,
            departure_seconds INTEGER
        )
    ');
    $Pdo->exec('
        CREATE TABLE service_calendars (
            id TEXT PRIMARY KEY,
            from_date TEXT NOT NULL,
            to_date TEXT NOT NULL,
            weekday_mask INTEGER NOT NULL
        )
    ');

    $Pdo->exec('
        CREATE TABLE service_calendar_exceptions (
            calendar_id TEXT NOT NULL,
            date TEXT NOT NULL,
            available INTEGER NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ');
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

function insertMeta(PDO $Pdo, array $aFeedInfo): void
{
    $sPublishedDate = date('Y-m-d');
    if (isset($aFeedInfo['feed_version']) && preg_match('/^\d{8}$/', $aFeedInfo['feed_version'])) {
        $sPublishedDate = gtfsDateToIso($aFeedInfo['feed_version']);
    }

    $Stmt = $Pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
    $Stmt->execute(['schedule_source_published', $sPublishedDate]);
    if (isset($aFeedInfo['feed_start_date']) && $aFeedInfo['feed_start_date'] !== '') {
        $Stmt->execute(['feed_start_date', gtfsDateToIso($aFeedInfo['feed_start_date'])]);
    }
    if (isset($aFeedInfo['feed_end_date']) && $aFeedInfo['feed_end_date'] !== '') {
        $Stmt->execute(['feed_end_date', gtfsDateToIso($aFeedInfo['feed_end_date'])]);
    }
}

function createIndexes(PDO $Pdo): void
{
    $Pdo->exec('CREATE INDEX idx_passing_times_stop ON passing_times (stop_id, departure_seconds)');
    $Pdo->exec('CREATE INDEX idx_passing_times_journey ON passing_times (service_journey_id)');
    $Pdo->exec('CREATE INDEX idx_journeys_line ON service_journeys (line_id, trip_number, first_departure_seconds)');
    $Pdo->exec('CREATE INDEX idx_journeys_calendar ON service_journeys (calendar_id)');
    $Pdo->exec('CREATE INDEX idx_pattern_stops ON journey_pattern_stops (journey_pattern_id, seq_order)');
    $Pdo->exec('CREATE INDEX idx_stops_normalized ON stops (name_normalized)');
    $Pdo->exec('CREATE INDEX idx_lines_normalized ON lines (name_normalized)');
    $Pdo->exec('CREATE INDEX idx_calendar_exceptions ON service_calendar_exceptions (calendar_id, date)');
}

function insertStops(PDO $Pdo, array $aStops): void
{
    $Stmt = $Pdo->prepare('INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($aStops as $iId => $aStop) {
        $sArea = '';
        if (isset($aStop['area'])) {
            $sArea = $aStop['area'];
        }
        $Stmt->execute([$iId, $aStop['name'], normalize($aStop['name']), $sArea, normalize($sArea), $aStop['lat'], $aStop['lon']]);
    }
}

function insertCalendars(PDO $Pdo, array $aCalendars): void
{
    $Stmt = $Pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
    $ExcludeStmt = $Pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 0)');
    $IncludeStmt = $Pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 1)');
    foreach ($aCalendars as $sId => $aCal) {
        $Stmt->execute([$sId, $aCal['from'], $aCal['to'], $aCal['weekdayMask']]);
        foreach ($aCal['excludedDates'] as $sDate) {
            $ExcludeStmt->execute([$sId, $sDate]);
        }
        foreach ($aCal['includedDates'] as $sDate) {
            $IncludeStmt->execute([$sId, $sDate]);
        }
    }
}

main(array_slice($argv, 1));
