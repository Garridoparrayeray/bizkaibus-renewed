<?php
/**
 * Builds data/bizkaibus.sqlite from the official NeTEx export (bizkaibus.zip).
 *
 * Usage:
 *   php scripts/build-database.php [--source=<path-or-url>] [--output=<path>]
 *
 * Default source is the live canonical zip published by Open Data Bizkaia.
 * The static export's own operating-period calendars are known to be stale
 * (see README) — this script derives a "which weekdays does this calendar
 * run on" mask empirically from each calendar's own bit pattern, rather than
 * trusting its FromDate/ToDate validity window, so the app can still show a
 * plausible recurring weekly schedule.
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('Europe/Madrid');

const DEFAULT_SOURCE = 'https://ctb-netex.s3.eu-south-2.amazonaws.com/bizkaibus.zip';
const NETEX_NS = 'http://www.netex.org.uk/netex';
const GEOCACHE_PATH = __DIR__ . '/geocache.json';
const NOMINATIM_CONTACT = 'garridoparrayeraytx@gmail.com';

function main(array $argv): void
{
    $options = parseArgs($argv);
    $source = $options['source'] ?? DEFAULT_SOURCE;
    $output = $options['output'] ?? __DIR__ . '/../data/bizkaibus.sqlite';
    $skipGeocode = isset($options['skip-geocode']);

    echo "== BizkaiBus+ database build ==\n";
    $zipPath = resolveZipPath($source);
    echo "Reading NeTEx export from: $source\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fwrite(STDERR, "Could not open zip: $zipPath\n");
        exit(1);
    }

    echo "Parsing stops.xml...\n";
    $stops = loadStops($zip);
    echo '  ' . count($stops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $stops = geocodeStops($stops, $skipGeocode);

    echo "Parsing common.xml...\n";
    $calendars = loadCalendars($zip);
    echo '  ' . count($calendars) . " service calendars\n";

    $lineFiles = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/^line-\d+\.xml$/', $name)) {
            $lineFiles[] = $name;
        }
    }
    sort($lineFiles);
    echo 'Found ' . count($lineFiles) . " line files\n";

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

    $lineTotals = ['lines' => 0, 'patterns' => 0, 'journeys' => 0, 'passingTimes' => 0];
    foreach ($lineFiles as $index => $fileName) {
        $xmlString = $zip->getFromName($fileName);
        if ($xmlString === false) {
            fwrite(STDERR, "  WARNING: could not read $fileName, skipping\n");
            continue;
        }
        try {
            processLineFile($pdo, $xmlString, $stops, $calendars, $lineTotals);
        } catch (Throwable $e) {
            fwrite(STDERR, "  WARNING: failed to process $fileName: {$e->getMessage()}\n");
        }
        printf("  [%d/%d] %s\r", $index + 1, count($lineFiles), $fileName);
    }
    echo "\n";

    $pdo->commit();

    echo "Building indexes...\n";
    createIndexes($pdo);

    $zip->close();

    echo "\n== Summary ==\n";
    $geocodedCount = $pdo->query("SELECT COUNT(*) FROM stops WHERE area != ''")->fetchColumn();
    printf("  stops geocoded (municipality/suburb/neighbourhood): %d / %d\n", $geocodedCount, count($stops));
    printf("  lines:          %d\n", $lineTotals['lines']);
    printf("  patterns:       %d\n", $lineTotals['patterns']);
    printf("  service_journeys: %d\n", $lineTotals['journeys']);
    printf("  passing_times:  %d\n", $lineTotals['passingTimes']);

    $calRange = $pdo->query('SELECT MIN(from_date), MAX(to_date) FROM service_calendars')->fetch(PDO::FETCH_NUM);
    printf("  calendar validity window in source export: %s .. %s\n", $calRange[0], $calRange[1]);
    echo "  (today's schedule is derived from each calendar's explicit active-date entries,\n";
    echo "   generalized to a recurring weekday pattern — not from this window. See README.)\n";

    echo "\nDatabase written to: $output\n";
    printf("File size: %.1f MB\n", filesize($output) / 1024 / 1024);
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--(source|output)=(.+)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        } elseif ($arg === '--skip-geocode') {
            $out['skip-geocode'] = true;
        }
    }
    return $out;
}

/**
 * The NeTEx export has NO municipality/locality field at all (verified: zero
 * Locality/TopographicPlace/Municipality/PostalAddress tags anywhere in
 * stops.xml) — only a street-level Name and coordinates. That means searching
 * "Getxo" finds nothing even though dozens of stops are physically there,
 * because none of them happen to have "Getxo" in their own street name.
 *
 * Fixed by reverse-geocoding via OpenStreetMap/Nominatim, which resolves a
 * coordinate down to neighbourhood/suburb/town (e.g. "Erromo, Areeta / Las
 * Arenas, Getxo" — confirmed against a real stop coordinate). Reverse-geocoding
 * all 2263 stops individually would be slow and wasteful; stops cluster
 * tightly, so we round to 2 decimal places (~1.1km) first — that collapses
 * 2263 stops to ~650 unique lookups. Results are cached in geocache.json
 * (committed — it's derived from a real external source, not a secret, and
 * re-fetching costs ~12 minutes against Nominatim's 1 req/s policy) so this
 * only actually hits the network for coordinates never seen before.
 *
 * @return array<int, array{name:string, lat:float, lon:float, area:string}>
 */
function geocodeStops(array $stops, bool $skip): array
{
    if ($skip) {
        foreach ($stops as &$stop) {
            $stop['area'] = '';
        }
        return $stops;
    }

    $cache = is_file(GEOCACHE_PATH) ? json_decode((string)file_get_contents(GEOCACHE_PATH), true) : [];
    $cache = is_array($cache) ? $cache : [];

    $clusterKeys = [];
    foreach ($stops as $id => $stop) {
        $key = round($stop['lat'], 2) . ',' . round($stop['lon'], 2);
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
            usleep(1_100_000); // Nominatim usage policy: max 1 request/second
        }
    }
    if (!empty($missing)) {
        echo "\n";
    }
    file_put_contents(GEOCACHE_PATH, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    foreach ($stops as $id => &$stop) {
        $stop['area'] = $cache[$clusterKeys[$id]] ?? '';
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
    $address = $data['address'] ?? [];
    $parts = array_filter([
        $address['neighbourhood'] ?? null,
        $address['suburb'] ?? null,
        $address['town'] ?? $address['city'] ?? $address['village'] ?? null,
    ]);
    return implode(', ', array_unique($parts));
}

function resolveZipPath(string $source): string
{
    if (preg_match('#^https?://#i', $source)) {
        $tmp = tempnam(sys_get_temp_dir(), 'bbnetex') . '.zip';
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

/** @return array<int, array{name:string, lat:float, lon:float}> */
function loadStops(ZipArchive $zip): array
{
    $xml = simplexml_load_string($zip->getFromName('stops.xml'));
    $stops = [];
    foreach ($xml->dataObjects->SiteFrame->stopPlaces->StopPlace as $sp) {
        $id = (int)$sp['id'];
        $name = (string)$sp->Name;
        $lat = (float)$sp->Centroid->Location->Latitude;
        $lon = (float)$sp->Centroid->Location->Longitude;
        $stops[$id] = ['name' => $name, 'lat' => $lat, 'lon' => $lon];
    }
    return $stops;
}

/**
 * NeTEx gives two calendar mechanisms here: UicOperatingPeriod.ValidDayBits (a
 * bit-per-day range) and dayTypeAssignments/DayTypeAssignment (explicit
 * per-date isAvailable overrides). In this export the bit strings are always
 * entirely zero (verified: 0 ones across all 30 periods) — the *only* real
 * calendar data is the explicit per-date assignments, so those are what we
 * use to derive each calendar's recurring weekday pattern.
 *
 * @return array<string, array{from:string, to:string, weekdayMask:int, activeDateCount:int}>
 */
function loadCalendars(ZipArchive $zip): array
{
    $xml = simplexml_load_string($zip->getFromName('common.xml'));
    $frame = $xml->dataObjects->CompositeFrame->frames->ServiceCalendarFrame;

    $ranges = [];
    foreach ($frame->operatingPeriods->UicOperatingPeriod as $op) {
        $id = (string)$op['id'];
        $fromDt = new DateTime((string)$op->FromDate);
        $fromDt->setTimezone(new DateTimeZone('Europe/Madrid'));
        $toDt = new DateTime((string)$op->ToDate);
        $toDt->setTimezone(new DateTimeZone('Europe/Madrid'));
        $ranges[$id] = ['from' => $fromDt->format('Y-m-d'), 'to' => $toDt->format('Y-m-d')];
    }

    // dayTypeId -> [date => bool isAvailable], from explicit DayTypeAssignment entries only
    // (entries with no <Date> just link a DayType to its — unused — OperatingPeriod bits).
    $activeDates = [];
    if (isset($frame->dayTypeAssignments->DayTypeAssignment)) {
        foreach ($frame->dayTypeAssignments->DayTypeAssignment as $dta) {
            if (!isset($dta->Date)) {
                continue;
            }
            $dayTypeId = (string)$dta->DayTypeRef['ref'];
            $date = substr((string)$dta->Date, 0, 10);
            $isAvailable = strtolower((string)($dta->isAvailable ?? 'true')) !== 'false';
            $activeDates[$dayTypeId][$date] = $isAvailable;
        }
    }

    $calendars = [];
    foreach ($ranges as $id => $range) {
        $dates = $activeDates[$id] ?? [];
        $weekdayMask = computeWeekdayMask($dates);
        $calendars[$id] = [
            'from' => $range['from'],
            'to' => $range['to'],
            'weekdayMask' => $weekdayMask,
            'activeDateCount' => count(array_filter($dates)),
        ];
    }
    return $calendars;
}

/** Derive which ISO weekdays (1=Mon..7=Sun) this calendar's explicit active dates fall on. */
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

function processLineFile(PDO $pdo, string $xmlString, array $stops, array $calendars, array &$totals): void
{
    $xml = simplexml_load_string($xmlString);
    $frames = $xml->dataObjects->CompositeFrame->frames;
    $serviceFrame = $frames->ServiceFrame ?? null;
    $timetableFrame = $frames->TimetableFrame ?? null;
    if (!$serviceFrame || !$timetableFrame) {
        return;
    }

    $lineEl = $serviceFrame->lines->Line[0] ?? $serviceFrame->lines->Line ?? null;
    if (!$lineEl) {
        return;
    }
    $lineId = (int)$lineEl['id'];
    $lineName = (string)$lineEl->Name;

    // Stop-point-ref -> physical stop id, for this line file.
    $stopAssignment = [];
    if (isset($serviceFrame->stopAssignments->PassengerStopAssignment)) {
        foreach ($serviceFrame->stopAssignments->PassengerStopAssignment as $psa) {
            $localRef = (string)$psa->ScheduledStopPointRef['ref'];
            $physicalId = (int)$psa->StopPlaceRef['ref'];
            $stopAssignment[$localRef] = $physicalId;
        }
    }

    // journey pattern id -> ['stopId' => order-indexed physical stop ids, 'pointMap' => [stopPointInPatternId => [order, stopId]]]
    $patterns = [];
    if (isset($serviceFrame->journeyPatterns->ServiceJourneyPattern)) {
        foreach ($serviceFrame->journeyPatterns->ServiceJourneyPattern as $jp) {
            $patternId = (string)$jp['id'];
            $pointMap = [];
            $orderedStopIds = [];
            foreach ($jp->pointsInSequence->StopPointInJourneyPattern as $sp) {
                $pointId = (string)$sp['id'];
                $order = (int)$sp['order'];
                $localRef = (string)$sp->ScheduledStopPointRef['ref'];
                $stopId = $stopAssignment[$localRef] ?? null;
                if ($stopId === null) {
                    continue;
                }
                $pointMap[$pointId] = ['order' => $order, 'stopId' => $stopId];
                $orderedStopIds[$order] = $stopId;
            }
            if (empty($orderedStopIds)) {
                continue;
            }
            ksort($orderedStopIds);
            $lastStopId = end($orderedStopIds);
            $headsign = $stops[$lastStopId]['name'] ?? '';
            $patterns[$patternId] = ['pointMap' => $pointMap, 'headsign' => $headsign];
        }
    }

    if (empty($patterns)) {
        return;
    }

    $insertPattern = $pdo->prepare('INSERT OR IGNORE INTO journey_patterns (id, line_id, headsign) VALUES (?, ?, ?)');
    $insertPatternStop = $pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $insertJourney = $pdo->prepare('INSERT OR IGNORE INTO service_journeys (id, line_id, journey_pattern_id, trip_number, calendar_id, first_departure_seconds) VALUES (?, ?, ?, ?, ?, ?)');
    $insertPassingTime = $pdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
    $insertLine = $pdo->prepare('INSERT OR IGNORE INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');

    // determine this line's public-code prefix letter from a sample ServiceJourney id (e.g. "A" in trp_A2163_848_...)
    $codePrefix = 'A';
    if (isset($timetableFrame->vehicleJourneys->ServiceJourney)) {
        $firstId = (string)($timetableFrame->vehicleJourneys->ServiceJourney[0]['id'] ?? '');
        if (preg_match('/^trp_([A-Za-z]*)\d+_/', $firstId, $m)) {
            $codePrefix = $m[1] !== '' ? $m[1] : 'A';
        }
    }
    $insertLine->execute([$lineId, $codePrefix . $lineId, $lineName, normalize($lineName)]);
    $totals['lines']++;

    foreach ($patterns as $patternId => $pattern) {
        $insertPattern->execute([$patternId, $lineId, $pattern['headsign']]);
        foreach ($pattern['pointMap'] as $point) {
            $insertPatternStop->execute([$patternId, $point['order'], $point['stopId']]);
        }
        $totals['patterns']++;
    }

    if (!isset($timetableFrame->vehicleJourneys->ServiceJourney)) {
        return;
    }

    foreach ($timetableFrame->vehicleJourneys->ServiceJourney as $sj) {
        $journeyId = (string)$sj['id'];
        $patternRef = (string)($sj->JourneyPatternRef['ref'] ?? '');
        $pattern = $patterns[$patternRef] ?? null;
        if ($pattern === null) {
            continue;
        }

        $calendarId = null;
        if (isset($sj->dayTypes->DayTypeRef)) {
            $calendarId = (string)$sj->dayTypes->DayTypeRef[0]['ref'];
        }
        if ($calendarId === null || !isset($calendars[$calendarId])) {
            continue;
        }

        $tripNumber = null;
        if (preg_match('/^trp_[A-Za-z]*\d+_(\d+)_/', $journeyId, $m)) {
            $tripNumber = $m[1];
        }

        if (!isset($sj->passingTimes->TimetabledPassingTime)) {
            continue;
        }

        // trip_number is a vehicle/duty block id, reused across many different
        // departures in the same day — NOT a unique per-trip key. The live SIRI-VM
        // feed identifies a trip by (line, trip_number, first-stop departure), so
        // we resolve and store that first-stop departure here too, up front.
        $passingRows = [];
        $firstDepartureSeconds = null;
        $firstOrder = PHP_INT_MAX;
        foreach ($sj->passingTimes->TimetabledPassingTime as $tpt) {
            $pointRef = (string)($tpt->StopPointInJourneyPatternRef['ref'] ?? '');
            $point = $pattern['pointMap'][$pointRef] ?? null;
            if ($point === null) {
                continue;
            }
            $arrival = isset($tpt->ArrivalTime) ? timeToSeconds((string)$tpt->ArrivalTime) : null;
            $departure = isset($tpt->DepartureTime) ? timeToSeconds((string)$tpt->DepartureTime) : $arrival;
            if ($arrival === null) {
                $arrival = $departure;
            }
            $passingRows[] = [$point['order'], $point['stopId'], $arrival, $departure];
            if ($point['order'] < $firstOrder) {
                $firstOrder = $point['order'];
                $firstDepartureSeconds = $departure;
            }
        }
        if (empty($passingRows)) {
            continue;
        }

        $insertJourney->execute([$journeyId, $lineId, $patternRef, $tripNumber, $calendarId, $firstDepartureSeconds]);
        $totals['journeys']++;

        foreach ($passingRows as [$order, $stopId, $arrival, $departure]) {
            $insertPassingTime->execute([$journeyId, $order, $stopId, $arrival, $departure]);
            $totals['passingTimes']++;
        }
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
}

function insertStops(PDO $pdo, array $stops): void
{
    $stmt = $pdo->prepare('INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stops as $id => $stop) {
        $area = $stop['area'] ?? '';
        $stmt->execute([$id, $stop['name'], normalize($stop['name']), $area, normalize($area), $stop['lat'], $stop['lon']]);
    }
}

function insertCalendars(PDO $pdo, array $calendars): void
{
    $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
    foreach ($calendars as $id => $cal) {
        $stmt->execute([$id, $cal['from'], $cal['to'], $cal['weekdayMask']]);
    }
}

main(array_slice($argv, 1));
