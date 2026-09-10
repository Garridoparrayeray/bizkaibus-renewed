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

function main(array $argv): void
{
    $options = parseArgs($argv);
    $network = 'bus';
    if (isset($options['network'])) {
        $network = $options['network'];
    }
    if (!isset(NETWORK_DEFAULTS[$network])) {
        fwrite(STDERR, "Unknown --network=\"$network\" (expected bus|metro)\n");
        exit(1);
    }
    $defaults = NETWORK_DEFAULTS[$network];

    $source = $defaults['source'];
    if (isset($options['source'])) {
        $source = $options['source'];
    }
    $output = $defaults['output'];
    if (isset($options['output'])) {
        $output = $options['output'];
    }
    $skipGeocode = isset($options['skip-geocode']) || $defaults['skipGeocode'];
    $agencyId = $defaults['agencyId'];

    echo "== {$defaults['label']} database build (GTFS, network=$network) ==\n";
    $zipPath = resolveZipPath($source);
    echo "Reading GTFS export from: $source\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fwrite(STDERR, "Could not open zip: $zipPath\n");
        exit(1);
    }

    echo "Parsing routes.txt...\n";
    $routes = loadRoutes($zip, $agencyId);
    echo '  ' . count($routes) . " routes\n";

    echo "Parsing stops.txt...\n";
    if ($network === 'metro') {
        $stops = loadStopsMetro($zip);
    } else {
        $stops = loadStopsBus($zip);
    }
    echo '  ' . count($stops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $stops = geocodeStops($stops, $skipGeocode);

    echo "Parsing calendar.txt / calendar_dates.txt...\n";
    $calendars = loadCalendars($zip);
    echo '  ' . count($calendars) . " service calendars\n";

    echo "Parsing trips.txt...\n";
    $trips = loadTrips($zip);
    echo '  ' . count($trips) . " trips\n";

    $feedInfo = loadFeedInfo($zip);
    if (isset($feedInfo['feed_version'])) {
        echo "  feed_version: {$feedInfo['feed_version']} (start: {$feedInfo['feed_start_date']}, end: {$feedInfo['feed_end_date']})\n";
    }

    $feedEndIso = '';
    if (!empty($feedInfo['feed_end_date'])) {
        $feedEndIso = gtfsDateToIso($feedInfo['feed_end_date']);
    }
    if ($feedEndIso !== '' && $feedEndIso < date('Y-m-d')) {
        fwrite(STDERR, "ERROR: el GTFS terminó el $feedEndIso, hoy es " . date('Y-m-d') . ". Build abortado.\n");
        exit(1);
    }

    if (file_exists($output)) {
        unlink($output);
    }
    $pdo = new PDO('sqlite:' . $output);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = OFF');

    createSchema($pdo);

    $pdo->beginTransaction();

    insertStops($pdo, $stops);
    insertCalendars($pdo, $calendars);
    insertMeta($pdo, $feedInfo);

    $insertLine = $pdo->prepare('INSERT OR IGNORE INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');
    foreach ($routes as $routeId => $route) {
        $insertLine->execute([$routeId, $route['code'], $route['name'], normalize($route['name'])]);
    }

    echo "Processing stop_times.txt (the big one, ~1.1M rows, two bounded-memory passes)...\n";
    $totals = ['patterns' => 0, 'journeys' => 0, 'passingTimes' => 0];
    processStopTimes($pdo, $zip, $trips, $routes, $calendars, $totals, $network);

    $pdo->commit();

    echo "Building indexes...\n";
    createIndexes($pdo);

    $zip->close();

    echo "\n== Summary ==\n";
    $geocodedCount = $pdo->query("SELECT COUNT(*) FROM stops WHERE area != ''")->fetchColumn();
    printf("  stops geocoded (municipality/suburb/neighbourhood): %d / %d\n", $geocodedCount, count($stops));
    printf("  lines:            %d\n", count($routes));
    printf("  patterns:         %d\n", $totals['patterns']);
    printf("  service_journeys: %d\n", $totals['journeys']);
    printf("  passing_times:    %d\n", $totals['passingTimes']);

    echo "\nDatabase written to: $output\n";
    printf("File size: %.1f MB\n", filesize($output) / 1024 / 1024);
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--(network|source|output)=(.+)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        } elseif ($arg === '--skip-geocode') {
            $out['skip-geocode'] = true;
        }
    }
    return $out;
}

function geocodeStops(array $stops, bool $skip): array
{
    if ($skip) {
        foreach ($stops as &$stop) {
            $stop['area'] = '';
        }
        return $stops;
    }

    $cache = [];
    if (is_file(GEOCACHE_PATH)) {
        $cache = json_decode((string)file_get_contents(GEOCACHE_PATH), true);
    }
    if (!is_array($cache)) {
        $cache = [];
    }

    $clusterKeys = [];
    foreach ($stops as $id => $stop) {
        $key = round($stop['lat'], 3) . ',' . round($stop['lon'], 3);
        $clusterKeys[$id] = $key;
    }
    $uniqueKeys = array_unique(array_values($clusterKeys));
    $missing = array_values(array_diff($uniqueKeys, array_keys($cache)));

    echo '  ' . count($uniqueKeys) . ' unique ~1km clusters, ' . count($missing) . " not yet cached\n";

    foreach ($missing as $i => $key) {
        [$lat, $lon] = explode(',', $key);
        $cache[$key] = reverseGeocode((float)$lat, (float)$lon);
        if (($i + 1) % 25 === 0 || $i + 1 === count($missing)) {
            echo '    geocoded ' . ($i + 1) . '/' . count($missing) . "\r";
            file_put_contents(GEOCACHE_PATH, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($i + 1 < count($missing)) {
            usleep(1_100_000);
        }
    }
    if (!empty($missing)) {
        echo "\n";
    }
    file_put_contents(GEOCACHE_PATH, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    foreach ($stops as $id => &$stop) {
        $stop['area'] = '';
        if (isset($cache[$clusterKeys[$id]])) {
            $stop['area'] = $cache[$clusterKeys[$id]];
        }
    }
    return $stops;
}

function reverseGeocode(float $lat, float $lon): string
{
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat,
        'lon' => $lon,
        'format' => 'jsonv2',
        'zoom' => 16,
        'addressdetails' => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: BizkaiBusPlus-etl/1.0 (' . NOMINATIM_CONTACT . ')'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        return '';
    }
    $data = json_decode($body, true);
    $address = [];
    if (isset($data['address'])) {
        $address = $data['address'];
    }

    $neighbourhood = null;
    if (isset($address['neighbourhood'])) {
        $neighbourhood = $address['neighbourhood'];
    }
    $suburb = null;
    if (isset($address['suburb'])) {
        $suburb = $address['suburb'];
    }
    $townLevel = null;
    if (isset($address['town'])) {
        $townLevel = $address['town'];
    } elseif (isset($address['city'])) {
        $townLevel = $address['city'];
    } elseif (isset($address['village'])) {
        $townLevel = $address['village'];
    }

    $parts = array_filter([$neighbourhood, $suburb, $townLevel]);
    return implode(', ', array_unique($parts));
}

function resolveZipPath(string $source): string
{
    if (preg_match('#^https?://#i', $source)) {
        $tmp = tempnam(sys_get_temp_dir(), 'bbgtfs') . '.zip';
        $ch = curl_init($source);
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) {
            fwrite(STDERR, 'Download failed: ' . curl_error($ch) . "\n");
            exit(1);
        }
        curl_close($ch);
        fclose($fp);
        return $tmp;
    }
    if (!file_exists($source)) {
        fwrite(STDERR, "Source file not found: $source\n");
        exit(1);
    }
    return $source;
}

function normalize(string $text): string
{
    $map = [
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
    $lower = mb_strtolower(strtr($text, $map), 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $lower));
}

function readCsv(ZipArchive $zip, string $name): Generator
{
    $stream = $zip->getStream($name);
    if ($stream === false) {
        throw new RuntimeException("Could not open $name from zip");
    }
    $header = fgetcsv($stream, 0, ',', '"', '\\');
    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($row === null || $row === [null]) {
            continue;
        }
        if (count($row) !== count($header)) {
            continue;
        }
        yield array_combine($header, $row);
    }
    fclose($stream);
}

function gtfsDateToIso(string $ymd): string
{
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

function loadRoutes(ZipArchive $zip, string|null $expectedAgencyId): array
{
    $routes = [];
    foreach (readCsv($zip, 'routes.txt') as $row) {
        $agencyId = '';
        if (isset($row['agency_id'])) {
            $agencyId = $row['agency_id'];
        }
        if ($expectedAgencyId !== null && $agencyId !== $expectedAgencyId) {
            fwrite(STDERR, "  WARNING: skipping route {$row['route_id']} with unexpected agency_id \"{$row['agency_id']}\"\n");
            continue;
        }
        $routeId = (int)$row['route_id'];
        $code = $row['route_short_name'];
        if ($code === '') {
            $code = $row['route_id'];
        }
        $routes[$routeId] = [
            'code' => $code,
            'name' => $row['route_long_name'],
        ];
    }
    return $routes;
}

function realStopRows(ZipArchive $zip): Generator
{
    foreach (readCsv($zip, 'stops.txt') as $row) {
        $locationType = '';
        if (isset($row['location_type'])) {
            $locationType = $row['location_type'];
        }
        if ($locationType !== '' && $locationType !== '0') {
            continue;
        }
        yield $row;
    }
}

function loadStopsBus(ZipArchive $zip): array
{
    $stops = [];
    foreach (realStopRows($zip) as $row) {
        $id = (int)$row['stop_id'];
        $name = $row['stop_name'];
        $stripped = preg_replace('/\s*\(' . preg_quote((string)$id, '/') . '\)$/', '', $name);
        if ($stripped === $name) {
            fwrite(STDERR, "  WARNING: stop $id name \"$name\" lacked the expected trailing \"($id)\" suffix\n");
        } else {
            $name = $stripped;
        }

        $stops[$id] = [
            'name' => $name,
            'lat' => (float)$row['stop_lat'],
            'lon' => (float)$row['stop_lon'],
        ];
    }
    return $stops;
}

function loadStopsMetro(ZipArchive $zip): array
{
    $stops = [];
    foreach (realStopRows($zip) as $row) {
        $id = (int)$row['stop_id'];
        $stops[$id] = [
            'name' => $row['stop_name'],
            'lat' => (float)$row['stop_lat'],
            'lon' => (float)$row['stop_lon'],
        ];
    }
    return $stops;
}

function loadCalendars(ZipArchive $zip): array
{
    $ranges = [];
    $baseWeekdayMask = [];
    $weekdayColumns = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    foreach (readCsv($zip, 'calendar.txt') as $row) {
        $ranges[$row['service_id']] = [
            'from' => gtfsDateToIso($row['start_date']),
            'to' => gtfsDateToIso($row['end_date']),
        ];
        $mask = 0;
        foreach ($weekdayColumns as $i => $column) {
            $columnValue = 0;
            if (isset($row[$column])) {
                $columnValue = (int)$row[$column];
            }
            if ($columnValue === 1) {
                $mask |= (1 << $i);
            }
        }
        $baseWeekdayMask[$row['service_id']] = $mask;
    }

    $activeDates = [];
    foreach (readCsv($zip, 'calendar_dates.txt') as $row) {
        $date = gtfsDateToIso($row['date']);
        $activeDates[$row['service_id']][$date] = ((int)$row['exception_type']) === 1;
    }

    $calendars = [];
    foreach (array_unique(array_merge(array_keys($ranges), array_keys($activeDates))) as $id) {
        $dates = [];
        if (isset($activeDates[$id])) {
            $dates = $activeDates[$id];
        }
        $baseMask = 0;
        $hasCalendarRow = isset($baseWeekdayMask[$id]);
        if ($hasCalendarRow) {
            $baseMask = $baseWeekdayMask[$id];
        }

        $excludedDates = [];
        $availableDates = [];
        foreach ($dates as $date => $isAvailable) {
            if ($isAvailable) {
                $availableDates[$date] = true;
            } else {
                $excludedDates[] = $date;
            }
        }

        $includedDates = [];
        if ($baseMask !== 0) {

            $weekdayMask = $baseMask | computeWeekdayMask($availableDates);
        } else {
            $weekdayMask = computeWeekdayMaskFromEvidence($availableDates, $includedDates);
        }

        $from = '';
        $to = '';
        if (isset($ranges[$id])) {
            $from = $ranges[$id]['from'];
            $to = $ranges[$id]['to'];
        }

        $calendars[$id] = [
            'from' => $from,
            'to' => $to,
            'weekdayMask' => $weekdayMask,
            'activeDateCount' => count(array_filter($dates)),
            'excludedDates' => $excludedDates,
            'includedDates' => $includedDates,
        ];
    }
    return $calendars;
}

function computeWeekdayMask(array $dateAvailability): int
{
    $mask = 0;
    foreach ($dateAvailability as $date => $isAvailable) {
        if (!$isAvailable) {
            continue;
        }
        $weekday = (int)(new DateTime($date))->format('N');
        $mask |= (1 << ($weekday - 1));
    }
    return $mask;
}

function computeWeekdayMaskFromEvidence(array $dateAvailability, array &$unbackedDates): int
{
    $byWeekday = [];
    foreach ($dateAvailability as $date => $isAvailable) {
        if (!$isAvailable) {
            continue;
        }
        $weekday = (int)(new DateTime($date))->format('N');
        $byWeekday[$weekday][] = $date;
    }

    $mask = 0;
    foreach ($byWeekday as $weekday => $dates) {
        if (count($dates) >= MIN_OCCURRENCES_FOR_WEEKLY_PATTERN) {
            $mask |= (1 << ($weekday - 1));
        } else {
            foreach ($dates as $date) {
                $unbackedDates[] = $date;
            }
        }
    }
    return $mask;
}

function loadTrips(ZipArchive $zip): array
{
    $trips = [];
    foreach (readCsv($zip, 'trips.txt') as $row) {
        $tripId = $row['trip_id'];
        $tripNumber = null;
        if (preg_match('/^trp_[A-Za-z]*\d+_(\d+)_/', $tripId, $m)) {
            $tripNumber = $m[1];
        }
        $headsign = '';
        if (isset($row['trip_headsign'])) {
            $headsign = $row['trip_headsign'];
        }
        $trips[$tripId] = [
            'routeId' => (int)$row['route_id'],
            'serviceId' => $row['service_id'],
            'headsign' => $headsign,
            'tripNumber' => $tripNumber,
        ];
    }
    return $trips;
}

function streamStopTimesByTrip(ZipArchive $zip): Generator
{
    $stream = $zip->getStream('stop_times.txt');
    if ($stream === false) {
        fwrite(STDERR, "Could not open stop_times.txt stream\n");
        exit(1);
    }
    $header = fgetcsv($stream, 0, ',', '"', '\\');

    $byTrip = [];

    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($row === null || $row === [null] || count($row) !== count($header)) {
            continue;
        }
        $assoc = array_combine($header, $row);
        $tripId = $assoc['trip_id'];

        $arrival = null;
        if ($assoc['arrival_time'] !== '') {
            $arrival = timeToSeconds($assoc['arrival_time']);
        }
        $departure = $arrival;
        if ($assoc['departure_time'] !== '') {
            $departure = timeToSeconds($assoc['departure_time']);
        }
        if ($arrival === null) {
            $arrival = $departure;
        }

        $byTrip[$tripId][] = [
            'seqOrder' => (int)$assoc['stop_sequence'],
            'stopId' => (int)$assoc['stop_id'],
            'arrival' => $arrival,
            'departure' => $departure,
        ];
    }
    fclose($stream);

    foreach ($byTrip as $tripId => $buffer) {
        yield $tripId => $buffer;
        unset($byTrip[$tripId]);
    }
}

function calendarGroupKeyFor(string $network, string $serviceId, array $calendars): string
{
    if ($network !== 'metro') {
        return '';
    }
    if (!isset($calendars[$serviceId])) {
        return '';
    }
    $cal = $calendars[$serviceId];
    if ($cal['from'] !== '') {
        return $serviceId;
    }
    if ($cal['weekdayMask'] === 0 && !empty($cal['includedDates'])) {
        return $serviceId;
    }
    return '';
}

function processStopTimes(PDO $pdo, ZipArchive $zip, array $trips, array $routes, array $calendars, array &$totals, string $network): void
{
    echo "  Pass 1/2: computing trip signatures and merge groups...\n";

    $signatures = [];
    $seenTripIds = [];
    $skippedUnknownTrip = 0;
    $skippedUnknownRoute = 0;
    $skippedDuplicateTrip = 0;

    foreach (streamStopTimesByTrip($zip) as $tripId => $buffer) {
        if (isset($seenTripIds[$tripId])) {
            $skippedDuplicateTrip++;
            continue;
        }
        $seenTripIds[$tripId] = true;

        $trip = null;
        if (isset($trips[$tripId])) {
            $trip = $trips[$tripId];
        }
        if ($trip === null) {
            $skippedUnknownTrip++;
            continue;
        }
        if (!isset($routes[$trip['routeId']])) {
            $skippedUnknownRoute++;
            continue;
        }

        usort($buffer, fn($a, $b) => $a['seqOrder'] <=> $b['seqOrder']);
        $stopIds = array_column($buffer, 'stopId');
        $patternKey = 'gp_' . $trip['routeId'] . '_' . substr(md5(implode(',', $stopIds)), 0, 12);
        $firstDeparture = $buffer[0]['arrival'];
        if (isset($buffer[0]['departure'])) {
            $firstDeparture = $buffer[0]['departure'];
        }

        $weekdayMask = 0;
        $includedDates = [];
        if (isset($calendars[$trip['serviceId']])) {
            $weekdayMask = $calendars[$trip['serviceId']]['weekdayMask'];
            $includedDates = $calendars[$trip['serviceId']]['includedDates'];
        }
        $calendarGroupKey = calendarGroupKeyFor($network, $trip['serviceId'], $calendars);

        $signatures[$tripId] = [
            'routeId' => $trip['routeId'],
            'tripNumber' => $trip['tripNumber'],
            'headsign' => $trip['headsign'],
            'patternKey' => $patternKey,
            'firstDeparture' => $firstDeparture,
            'weekdayMask' => $weekdayMask,
            'includedDates' => $includedDates,
            'calendarGroupKey' => $calendarGroupKey,
        ];
    }

    $departureClusterGapSeconds = 90;

    $byRoutePattern = [];
    foreach ($signatures as $tripId => $sig) {
        $tripNumberPart = '';
        if (isset($sig['tripNumber'])) {
            $tripNumberPart = $sig['tripNumber'];
        }

        $key = $sig['routeId'] . '|' . $tripNumberPart . '|' . $sig['patternKey'] . '|' . $sig['calendarGroupKey'];
        $byRoutePattern[$key][] = $tripId;
    }

    $groups = [];
    foreach ($byRoutePattern as $tripIds) {
        usort($tripIds, fn($a, $b) => $signatures[$a]['firstDeparture'] <=> $signatures[$b]['firstDeparture']);

        $clusterKey = null;
        $previousDeparture = null;
        foreach ($tripIds as $tripId) {
            $sig = $signatures[$tripId];
            if ($previousDeparture === null || ($sig['firstDeparture'] - $previousDeparture) > $departureClusterGapSeconds) {
                $clusterKey = $tripId;
                $groups[$clusterKey] = $sig;
                $groups[$clusterKey]['representativeTripId'] = $tripId;
                $groups[$clusterKey]['weekdayMask'] = 0;
                $groups[$clusterKey]['includedDates'] = [];
            }
            $groups[$clusterKey]['weekdayMask'] |= $sig['weekdayMask'];

            foreach ($sig['includedDates'] as $date) {
                $groups[$clusterKey]['includedDates'][$date] = true;
            }
            $previousDeparture = $sig['firstDeparture'];
        }
    }

    $maskToCalendarId = [];
    foreach ($calendars as $calId => $cal) {
        if ($calId === 'PRUEBA') {
            continue;
        }
        if ($cal['from'] !== '') {
            continue;
        }
        if (!isset($maskToCalendarId[$cal['weekdayMask']])) {
            $maskToCalendarId[$cal['weekdayMask']] = $calId;
        }
    }
    $syntheticCalendars = [];
    $representatives = [];

    $extraIncludedDatesByCalendarId = [];
    foreach ($groups as $group) {

        if ($group['calendarGroupKey'] !== '') {
            $group['calendarId'] = $group['calendarGroupKey'];
            $representatives[$group['representativeTripId']] = $group;
            continue;
        }

        $mask = $group['weekdayMask'];
        if (!isset($maskToCalendarId[$mask])) {
            $newId = 'merged_' . $mask;
            $maskToCalendarId[$mask] = $newId;
            $syntheticCalendars[$newId] = $mask;
        }
        $calendarId = $maskToCalendarId[$mask];
        $group['calendarId'] = $calendarId;
        foreach (array_keys($group['includedDates']) as $date) {
            $extraIncludedDatesByCalendarId[$calendarId][$date] = true;
        }
        $representatives[$group['representativeTripId']] = $group;
    }

    printf("  %d raw trips merged into %d distinct journeys (%d synthetic calendars for OR'd weekday masks)\n", count($signatures), count($groups), count($syntheticCalendars));

    if (!empty($syntheticCalendars)) {
        $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
        foreach ($syntheticCalendars as $id => $mask) {
            $stmt->execute([$id, '', '', $mask]);
        }
    }

    if (!empty($extraIncludedDatesByCalendarId)) {

        $alreadyIncluded = [];
        foreach ($calendars as $calId => $cal) {
            foreach ($cal['includedDates'] as $date) {
                $alreadyIncluded[$calId][$date] = true;
            }
        }
        $includeStmt = $pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 1)');
        foreach ($extraIncludedDatesByCalendarId as $calendarId => $dates) {
            foreach (array_keys($dates) as $date) {
                if (isset($alreadyIncluded[$calendarId][$date])) {
                    continue;
                }
                $includeStmt->execute([$calendarId, $date]);
            }
        }
    }

    echo "  Pass 2/2: inserting merged journeys + passing_times...\n";

    $insertPattern = $pdo->prepare('INSERT OR IGNORE INTO journey_patterns (id, line_id, headsign) VALUES (?, ?, ?)');
    $insertPatternStop = $pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $insertJourney = $pdo->prepare('INSERT OR IGNORE INTO service_journeys (id, line_id, journey_pattern_id, trip_number, calendar_id, first_departure_seconds) VALUES (?, ?, ?, ?, ?, ?)');
    $insertPassingTime = $pdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
    $seenPatterns = [];
    $rowCount = 0;

    foreach (streamStopTimesByTrip($zip) as $tripId => $buffer) {
        $group = null;
        if (isset($representatives[$tripId])) {
            $group = $representatives[$tripId];
        }
        if ($group === null) {
            continue;
        }

        usort($buffer, fn($a, $b) => $a['seqOrder'] <=> $b['seqOrder']);
        $patternKey = $group['patternKey'];

        if (!isset($seenPatterns[$patternKey])) {
            $seenPatterns[$patternKey] = true;
            $insertPattern->execute([$patternKey, $group['routeId'], $group['headsign']]);
            foreach ($buffer as $row) {
                $insertPatternStop->execute([$patternKey, $row['seqOrder'], $row['stopId']]);
            }
            $totals['patterns']++;
        }

        $tripNumber = $group['tripNumber'];
        if ($tripNumber === null) {
            $tripNumber = $tripId;
        }
        $insertJourney->execute([$tripId, $group['routeId'], $patternKey, $tripNumber, $group['calendarId'], $group['firstDeparture']]);
        $totals['journeys']++;

        foreach ($buffer as $row) {
            $insertPassingTime->execute([$tripId, $row['seqOrder'], $row['stopId'], $row['arrival'], $row['departure']]);
            $totals['passingTimes']++;
        }

        $rowCount += count($buffer);
        if ($rowCount % 50000 < 40) {
            printf("  processed ~%d passing_times rows\r", $rowCount);
        }
    }
    echo "\n";

    if ($skippedUnknownTrip > 0) {
        fwrite(STDERR, "  WARNING: $skippedUnknownTrip stop_times groups skipped (trip_id not found in trips.txt)\n");
    }
    if ($skippedUnknownRoute > 0) {
        fwrite(STDERR, "  WARNING: $skippedUnknownRoute stop_times groups skipped (route_id not found in routes.txt)\n");
    }
    if ($skippedDuplicateTrip > 0) {
        fwrite(STDERR, "  WARNING: $skippedDuplicateTrip duplicate/non-contiguous trip_id groups skipped\n");
    }
}

function timeToSeconds(string $hms): int
{
    [$h, $m, $s] = array_map('intval', explode(':', $hms));
    return $h * 3600 + $m * 60 + $s;
}

function createSchema(PDO $pdo): void
{
    $pdo->exec('
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
    $pdo->exec('
        CREATE TABLE lines (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE journey_patterns (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            headsign TEXT
        )
    ');
    $pdo->exec('
        CREATE TABLE journey_pattern_stops (
            journey_pattern_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE service_journeys (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            journey_pattern_id TEXT NOT NULL,
            trip_number TEXT,
            calendar_id TEXT NOT NULL,
            first_departure_seconds INTEGER
        )
    ');
    $pdo->exec('
        CREATE TABLE passing_times (
            service_journey_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL,
            arrival_seconds INTEGER,
            departure_seconds INTEGER
        )
    ');
    $pdo->exec('
        CREATE TABLE service_calendars (
            id TEXT PRIMARY KEY,
            from_date TEXT NOT NULL,
            to_date TEXT NOT NULL,
            weekday_mask INTEGER NOT NULL
        )
    ');

    $pdo->exec('
        CREATE TABLE service_calendar_exceptions (
            calendar_id TEXT NOT NULL,
            date TEXT NOT NULL,
            available INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ');
}

function loadFeedInfo(ZipArchive $zip): array
{

    if ($zip->locateName('feed_info.txt') === false) {
        return [];
    }
    foreach (readCsv($zip, 'feed_info.txt') as $row) {
        $feedVersion = '';
        if (isset($row['feed_version'])) {
            $feedVersion = $row['feed_version'];
        }
        $feedStartDate = '';
        if (isset($row['feed_start_date'])) {
            $feedStartDate = $row['feed_start_date'];
        }
        $feedEndDate = '';
        if (isset($row['feed_end_date'])) {
            $feedEndDate = $row['feed_end_date'];
        }
        return [
            'feed_version' => $feedVersion,
            'feed_start_date' => $feedStartDate,
            'feed_end_date' => $feedEndDate,
        ];
    }
    return [];
}

function insertMeta(PDO $pdo, array $feedInfo): void
{
    $publishedDate = date('Y-m-d');
    if (isset($feedInfo['feed_version']) && preg_match('/^\d{8}$/', $feedInfo['feed_version'])) {
        $publishedDate = gtfsDateToIso($feedInfo['feed_version']);
    }

    $stmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
    $stmt->execute(['schedule_source_published', $publishedDate]);
    if (isset($feedInfo['feed_start_date']) && $feedInfo['feed_start_date'] !== '') {
        $stmt->execute(['feed_start_date', gtfsDateToIso($feedInfo['feed_start_date'])]);
    }
    if (isset($feedInfo['feed_end_date']) && $feedInfo['feed_end_date'] !== '') {
        $stmt->execute(['feed_end_date', gtfsDateToIso($feedInfo['feed_end_date'])]);
    }
}

function createIndexes(PDO $pdo): void
{
    $pdo->exec('CREATE INDEX idx_passing_times_stop ON passing_times (stop_id, departure_seconds)');
    $pdo->exec('CREATE INDEX idx_passing_times_journey ON passing_times (service_journey_id)');
    $pdo->exec('CREATE INDEX idx_journeys_line ON service_journeys (line_id, trip_number, first_departure_seconds)');
    $pdo->exec('CREATE INDEX idx_journeys_calendar ON service_journeys (calendar_id)');
    $pdo->exec('CREATE INDEX idx_pattern_stops ON journey_pattern_stops (journey_pattern_id, seq_order)');
    $pdo->exec('CREATE INDEX idx_stops_normalized ON stops (name_normalized)');
    $pdo->exec('CREATE INDEX idx_lines_normalized ON lines (name_normalized)');
    $pdo->exec('CREATE INDEX idx_calendar_exceptions ON service_calendar_exceptions (calendar_id, date)');
}

function insertStops(PDO $pdo, array $stops): void
{
    $stmt = $pdo->prepare('INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stops as $id => $stop) {
        $area = '';
        if (isset($stop['area'])) {
            $area = $stop['area'];
        }
        $stmt->execute([$id, $stop['name'], normalize($stop['name']), $area, normalize($area), $stop['lat'], $stop['lon']]);
    }
}

function insertCalendars(PDO $pdo, array $calendars): void
{
    $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
    $excludeStmt = $pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 0)');
    $includeStmt = $pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 1)');
    foreach ($calendars as $id => $cal) {
        $stmt->execute([$id, $cal['from'], $cal['to'], $cal['weekdayMask']]);
        foreach ($cal['excludedDates'] as $date) {
            $excludeStmt->execute([$id, $date]);
        }
        foreach ($cal['includedDates'] as $date) {
            $includeStmt->execute([$id, $date]);
        }
    }
}

main(array_slice($argv, 1));
