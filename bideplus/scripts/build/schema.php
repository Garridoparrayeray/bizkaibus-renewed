<?php
// Esquema de la base de datos y escritura de paradas, calendarios e información del feed. Lo usa build-database.php.

declare(strict_types=1);

function createSchema(PDO $Pdo): void
{
    $Pdo->exec('
        CREATE TABLE stops (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL,
            area TEXT NOT NULL DEFAULT \'\',
            area_normalized TEXT NOT NULL DEFAULT \'\',
            lat REAL NOT NULL,
            lon REAL NOT NULL,
            station_id TEXT,
            platform_label TEXT
        )
    ');
    $Pdo->exec('
        CREATE TABLE lines (
            id TEXT PRIMARY KEY,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL,
            color TEXT,
            text_color TEXT
        )
    ');
    $Pdo->exec('
        CREATE TABLE journey_patterns (
            id TEXT PRIMARY KEY,
            line_id TEXT NOT NULL,
            headsign TEXT
        )
    ');
    $Pdo->exec('
        CREATE TABLE journey_pattern_stops (
            journey_pattern_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id TEXT NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE service_journeys (
            id TEXT PRIMARY KEY,
            line_id TEXT NOT NULL,
            journey_pattern_id TEXT NOT NULL,
            trip_number TEXT,
            calendar_id TEXT NOT NULL,
            first_departure_seconds INTEGER,
            shape_id TEXT
        )
    ');
    $Pdo->exec('
        CREATE TABLE passing_times (
            service_journey_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id TEXT NOT NULL,
            arrival_seconds INTEGER,
            departure_seconds INTEGER,
            dist_m REAL
        )
    ');
    $Pdo->exec('
        CREATE TABLE shapes (
            id TEXT PRIMARY KEY,
            points TEXT NOT NULL
        )
    ');
    $Pdo->exec('
        CREATE TABLE trip_aliases (
            trip_id TEXT PRIMARY KEY,
            service_journey_id TEXT NOT NULL
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

function pruneUnservedStops(PDO $Pdo): int
{
    $iDeletedQuays = $Pdo->exec('
        DELETE FROM stops
        WHERE station_id IS NOT NULL
          AND id NOT IN (SELECT DISTINCT stop_id FROM passing_times)
    ');
    $iDeletedStations = $Pdo->exec('
        DELETE FROM stops
        WHERE station_id IS NULL
          AND id NOT IN (SELECT DISTINCT stop_id FROM passing_times)
          AND id NOT IN (SELECT DISTINCT station_id FROM stops WHERE station_id IS NOT NULL)
    ');
    return $iDeletedQuays + $iDeletedStations;
}

function createIndexes(PDO $Pdo): void
{
    $Pdo->exec('CREATE INDEX idx_passing_times_stop ON passing_times (stop_id, departure_seconds)');
    $Pdo->exec('CREATE INDEX idx_passing_times_journey ON passing_times (service_journey_id)');
    $Pdo->exec('CREATE INDEX idx_journeys_line ON service_journeys (line_id, trip_number, first_departure_seconds)');
    $Pdo->exec('CREATE INDEX idx_journeys_calendar ON service_journeys (calendar_id)');
    $Pdo->exec('CREATE INDEX idx_pattern_stops ON journey_pattern_stops (journey_pattern_id, seq_order)');
    $Pdo->exec('CREATE INDEX idx_stops_normalized ON stops (name_normalized)');
    $Pdo->exec('CREATE INDEX idx_stops_station ON stops (station_id)');
    $Pdo->exec('CREATE INDEX idx_lines_normalized ON lines (name_normalized)');
    $Pdo->exec('CREATE INDEX idx_calendar_exceptions ON service_calendar_exceptions (calendar_id, date)');
}

function insertStops(PDO $Pdo, array $aStops): void
{
    $Stmt = $Pdo->prepare('
        INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon, station_id, platform_label)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($aStops as $iId => $aStop) {
        $sArea = '';
        if (isset($aStop['area'])) {
            $sArea = $aStop['area'];
        }
        $sStationId = null;
        if (isset($aStop['stationId'])) {
            $sStationId = $aStop['stationId'];
        }
        $sPlatformLabel = null;
        if (isset($aStop['platformLabel'])) {
            $sPlatformLabel = $aStop['platformLabel'];
        }
        $Stmt->execute([
            $iId, $aStop['name'], normalize($aStop['name']), $sArea, normalize($sArea),
            $aStop['lat'], $aStop['lon'], $sStationId, $sPlatformLabel,
        ]);
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
