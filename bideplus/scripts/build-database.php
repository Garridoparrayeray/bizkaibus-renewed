<?php

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('Europe/Madrid');

const MIN_OCCURRENCES_FOR_WEEKLY_PATTERN = 2;

require_once __DIR__ . '/../api/Core/Networks.php';
require_once __DIR__ . '/build/geocode.php';
require_once __DIR__ . '/build/download.php';
require_once __DIR__ . '/build/gtfs.php';
require_once __DIR__ . '/build/calendars.php';
require_once __DIR__ . '/build/journeys.php';
require_once __DIR__ . '/build/schema.php';

// Valor de una variable de entorno, o el de por defecto si no está definida (o está vacía, con $bEmptyIsUnset).
function envOrDefault(string $sName, string|int $Default, bool $bEmptyIsUnset = false): string|int
{
    $sValue = getenv($sName);
    if ($sValue === false || ($bEmptyIsUnset && $sValue === '')) {
        return $Default;
    }
    return $sValue;
}

define('GEOCACHE_PATH', envOrDefault('GEOCACHE_PATH', __DIR__ . '/geocache.json', true));
const NOMINATIM_CONTACT = 'garridoparrayeraytx@gmail.com';
define('GEOCODE_MAX_NEW_LOOKUPS_PER_RUN', (int)envOrDefault('GEOCODE_MAX_NEW', 300));
define('GEOCODE_MAX_EMPTY_RETRIES_PER_RUN', (int)envOrDefault('GEOCODE_MAX_RETRIES', 40));
define('GEOCODE_PAUSE_MICROSECONDS', (int)envOrDefault('GEOCODE_PAUSE_US', 1_100_000));

function main(array $aArgv): void
{
    $GLOBALS['BUILD_ARGV'] = $aArgv;
    $aOptions = parseArgs($aArgv);
    $sNetwork = $aOptions['network'] ?? \Core\Networks::defaultSlug();
    if (!\Core\Networks::exists($sNetwork)) {
        fwrite(STDERR, "Unknown --network=\"$sNetwork\" (expected " . implode('|', \Core\Networks::slugs()) . ")\n");
        exit(1);
    }
    $aNetwork = \Core\Networks::get($sNetwork);
    $aGtfs = $aNetwork['gtfs'];

    $sSource = $aOptions['source'] ?? ($aGtfs['source'] ?? null);
    $sFallbackSource = $aGtfs['fallback_source'] ?? null;
    $sOutput = $aOptions['output'] ?? $aNetwork['db_path'];
    $bSkipGeocode = isset($aOptions['skip-geocode']) || !empty($aGtfs['skip_geocode']);
    $sAgencyId = $aGtfs['agency_id'] ?? null;
    $bMergeCalendars = !empty($aGtfs['merge_calendars']);

    $sLabel = \Core\Networks::text($aNetwork, 'name');
    echo "== $sLabel database build (GTFS, network=$sNetwork) ==\n\n";

    if ($sSource === null && !empty($aGtfs['sources'])) {
        echo "Merging " . count($aGtfs['sources']) . " GTFS exports (" . implode(', ', array_keys($aGtfs['sources'])) . ")\n";
        $aPaths = [];
        foreach ($aGtfs['sources'] as $sKey => $sUrl) {
            $aPaths[$sKey] = resolveZipPath($sUrl);
        }
        $sZipPath = mergeGtfsZips($aPaths);
    } else {
        echo "Reading GTFS export from: $sSource\n";
        $sZipPath = resolveZipPath($sSource, $sFallbackSource);
    }
    $Zip = new ZipArchive();
    if ($Zip->open($sZipPath) !== true) {
        fwrite(STDERR, "Could not open zip: $sZipPath\n");
        exit(1);
    }

    echo "Parsing routes.txt...\n";
    $aRoutes = loadRoutes($Zip, $sAgencyId, $aGtfs['route_id_prefixes'] ?? null);
    echo '  ' . count($aRoutes) . " routes\n";

    echo "Parsing stops.txt...\n";
    $aStopLoaders = ['bus' => 'loadStopsBus', 'metro' => 'loadStopsMetro', 'euskotren' => 'loadStopsEuskotren', 'renfe' => 'loadStopsRenfe', 'generic' => 'loadStopsMetro'];
    if (!isset($aStopLoaders[$aGtfs['stops']])) {
        fwrite(STDERR, "Unknown gtfs.stops=\"{$aGtfs['stops']}\" (expected " . implode('|', array_keys($aStopLoaders)) . ")\n");
        exit(1);
    }
    $aStops = $aStopLoaders[$aGtfs['stops']]($Zip);
    echo '  ' . count($aStops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $aStops = geocodeStops($aStops, $bSkipGeocode);

    echo "Parsing calendar.txt / calendar_dates.txt...\n";
    $aCalendars = loadCalendars($Zip, $bMergeCalendars);
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

    $InsertLine = $Pdo->prepare('INSERT OR IGNORE INTO lines (id, code, name, name_normalized, color, text_color) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($aRoutes as $iRouteId => $aRoute) {
        $InsertLine->execute([$iRouteId, $aRoute['code'], $aRoute['name'], normalize($aRoute['name']), $aRoute['color'] ?? null, $aRoute['textColor'] ?? null]);
    }

    echo "Processing stop_times.txt (the big one, ~1.1M rows, two bounded-memory passes)...\n";
    $aTotals = ['patterns' => 0, 'journeys' => 0, 'passingTimes' => 0, 'tripAliases' => 0, 'shapes' => 0];
    $aWindow = [];
    if (!empty($aGtfs['feed_window']) && !empty($aFeedInfo['feed_start_date']) && !empty($aFeedInfo['feed_end_date'])) {
        $aWindow = datesBetween(gtfsDateToIso($aFeedInfo['feed_start_date']), gtfsDateToIso($aFeedInfo['feed_end_date']));
    }
    processStopTimes($Pdo, $Zip, $aTrips, $aRoutes, $aCalendars, $aTotals, $bMergeCalendars, $aWindow);

    $Pdo->commit();

    $iPruned = pruneUnservedStops($Pdo);
    if ($iPruned > 0) {
        echo "  pruned $iPruned stops with zero real service (e.g. tram/funicular-only stations filtered out by agencyId)\n";
    }

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
    printf("  trip_aliases:     %d\n", $aTotals['tripAliases']);
    printf("  shapes:           %d\n", $aTotals['shapes']);

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

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    main(array_slice($argv, 1));
}
