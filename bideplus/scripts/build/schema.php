<?php
// Esquema de la base de datos y escritura de paradas, calendarios e información del feed. Lo usa build-database.php.

declare(strict_types=1);

namespace Build;

use PDO;
use Models\Search;

class Schema
{
    public static function createSchema(PDO $Pdo): void
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

    public static function insertMeta(PDO $Pdo, array $aFeedInfo): void
    {
        $sPublishedDate = date('Y-m-d');
        if (isset($aFeedInfo['feed_version']) && preg_match('/^\d{8}$/', $aFeedInfo['feed_version'])) {
            $sPublishedDate = Gtfs::gtfsDateToIso($aFeedInfo['feed_version']);
        }

        $Stmt = $Pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
        $Stmt->execute(['schedule_source_published', $sPublishedDate]);
        if (isset($aFeedInfo['feed_start_date']) && $aFeedInfo['feed_start_date'] !== '') {
            $Stmt->execute(['feed_start_date', Gtfs::gtfsDateToIso($aFeedInfo['feed_start_date'])]);
        }
        if (isset($aFeedInfo['feed_end_date']) && $aFeedInfo['feed_end_date'] !== '') {
            $Stmt->execute(['feed_end_date', Gtfs::gtfsDateToIso($aFeedInfo['feed_end_date'])]);
        }
    }

    // Nombra "Origen – Destino" las líneas que el operador publica sin nombre, con las paradas extremas de su patrón con
    // más viajes.
    public static function nameLinesFromStops(PDO $Pdo): int
    {
        $aLines = $Pdo->query("SELECT id FROM lines WHERE name = ''")->fetchAll(PDO::FETCH_COLUMN);
        $Pattern = $Pdo->prepare('
            SELECT journey_pattern_id FROM service_journeys WHERE line_id = ?
            GROUP BY journey_pattern_id ORDER BY COUNT(*) DESC LIMIT 1
        ');
        $Ends = $Pdo->prepare('
            SELECT s.name FROM journey_pattern_stops p JOIN stops s ON s.id = p.stop_id
            WHERE p.journey_pattern_id = ? ORDER BY p.seq_order
        ');
        $Update = $Pdo->prepare('UPDATE lines SET name = ?, name_normalized = ? WHERE id = ?');
        $iNamed = 0;
        foreach ($aLines as $sLineId) {
            $Pattern->execute([$sLineId]);
            $sPatternId = $Pattern->fetchColumn();
            if ($sPatternId === false) {
                continue;
            }
            $Ends->execute([$sPatternId]);
            $aNames = $Ends->fetchAll(PDO::FETCH_COLUMN);
            if (count($aNames) < 2) {
                continue;
            }
            $sName = $aNames[0] . ' – ' . end($aNames);
            $Update->execute([$sName, Search::normalize($sName), $sLineId]);
            $iNamed++;
        }
        return $iNamed;
    }

    // Deja solo los viajes en los que se sube en alguna de esas paradas, y de cada uno, esas paradas y su destino (para
    // saber a dónde va): el resto de paradas se quitan después con pruneUnservedStops. Devuelve los viajes que quedan.
    public static function keepStops(PDO $Pdo, array $aStopIds): int
    {
        $Pdo->exec('CREATE TEMP TABLE keep_stops (stop_id TEXT PRIMARY KEY)');
        $Insert = $Pdo->prepare('INSERT OR IGNORE INTO keep_stops VALUES (?)');
        foreach ($aStopIds as $sStopId) {
            $Insert->execute([(string)$sStopId]);
        }
        $Pdo->exec('
            DELETE FROM service_journeys WHERE id NOT IN (
                SELECT pt.service_journey_id FROM passing_times pt JOIN keep_stops k ON k.stop_id = pt.stop_id
                WHERE EXISTS (SELECT 1 FROM passing_times later WHERE later.service_journey_id = pt.service_journey_id AND later.seq_order > pt.seq_order)
            )
        ');
        $Pdo->exec('DELETE FROM passing_times WHERE service_journey_id NOT IN (SELECT id FROM service_journeys)');
        $Pdo->exec('DELETE FROM trip_aliases WHERE service_journey_id NOT IN (SELECT id FROM service_journeys)');
        $Pdo->exec('
            DELETE FROM passing_times WHERE stop_id NOT IN (SELECT stop_id FROM keep_stops)
              AND seq_order < (SELECT MAX(last.seq_order) FROM passing_times last WHERE last.service_journey_id = passing_times.service_journey_id)
        ');
        $Pdo->exec('DELETE FROM journey_patterns WHERE id NOT IN (SELECT journey_pattern_id FROM service_journeys)');
        $Pdo->exec('DELETE FROM journey_pattern_stops WHERE journey_pattern_id NOT IN (SELECT id FROM journey_patterns)');
        $Pdo->exec('
            DELETE FROM journey_pattern_stops WHERE stop_id NOT IN (SELECT stop_id FROM keep_stops)
              AND seq_order < (SELECT MAX(last.seq_order) FROM journey_pattern_stops last WHERE last.journey_pattern_id = journey_pattern_stops.journey_pattern_id)
        ');
        $Pdo->exec('DELETE FROM lines WHERE id NOT IN (SELECT line_id FROM service_journeys)');
        $Pdo->exec('DELETE FROM service_calendars WHERE id NOT IN (SELECT calendar_id FROM service_journeys)');
        $Pdo->exec('DELETE FROM service_calendar_exceptions WHERE calendar_id NOT IN (SELECT id FROM service_calendars)');
        $Pdo->exec('DELETE FROM shapes WHERE id NOT IN (SELECT shape_id FROM service_journeys WHERE shape_id IS NOT NULL)');
        $Pdo->exec('
            UPDATE service_journeys SET first_departure_seconds = (
                SELECT pt.departure_seconds FROM passing_times pt WHERE pt.service_journey_id = service_journeys.id
                ORDER BY pt.seq_order LIMIT 1
            )
        ');
        $Pdo->exec('DROP TABLE keep_stops');
        return (int)$Pdo->query('SELECT COUNT(*) FROM service_journeys')->fetchColumn();
    }

    // Un mismo tren publicado en varios viajes (uno por origen o por destino): con el mismo número de tren (los primeros
    // $iNumberLength caracteres del id) y calendario, el viaje contenido en otro más largo se junta con él.
    public static function mergeSameTrains(PDO $Pdo, int $iNumberLength): int
    {
        $Rows = $Pdo->query('SELECT service_journey_id, stop_id, arrival_seconds FROM passing_times ORDER BY service_journey_id, seq_order');
        $aStopsOf = [];
        foreach ($Rows as $aRow) {
            $aStopsOf[$aRow['service_journey_id']][$aRow['stop_id']] = $aRow['arrival_seconds'];
        }
        $aGroups = [];
        foreach ($Pdo->query('SELECT id, calendar_id FROM service_journeys') as $aRow) {
            $aGroups[substr($aRow['id'], 0, $iNumberLength) . '#' . $aRow['calendar_id']][] = $aRow['id'];
        }
        $aDuplicates = [];
        foreach ($aGroups as $aIds) {
            usort($aIds, function ($sA, $sB) use ($aStopsOf) {
                $iByLength = count($aStopsOf[$sB] ?? []) <=> count($aStopsOf[$sA] ?? []);
                if ($iByLength !== 0) {
                    return $iByLength;
                }
                return strcmp($sA, $sB);
            });
            $aKept = [];
            foreach ($aIds as $sId) {
                $aOwn = $aStopsOf[$sId] ?? [];
                foreach ($aKept as $sKeptId) {
                    if (!empty($aOwn) && $aOwn === array_intersect_key($aStopsOf[$sKeptId], $aOwn)) {
                        $aDuplicates[$sId] = $sKeptId;
                        continue 2;
                    }
                }
                $aKept[] = $sId;
            }
        }
        $Alias = $Pdo->prepare('INSERT OR REPLACE INTO trip_aliases (trip_id, service_journey_id) VALUES (?, ?)');
        $Repoint = $Pdo->prepare('UPDATE trip_aliases SET service_journey_id = ? WHERE service_journey_id = ?');
        $Delete = $Pdo->prepare('DELETE FROM service_journeys WHERE id = ?');
        $DeleteTimes = $Pdo->prepare('DELETE FROM passing_times WHERE service_journey_id = ?');
        foreach ($aDuplicates as $sDuplicateId => $sKeptId) {
            $Repoint->execute([$sKeptId, $sDuplicateId]);
            $Alias->execute([$sDuplicateId, $sKeptId]);
            $DeleteTimes->execute([$sDuplicateId]);
            $Delete->execute([$sDuplicateId]);
        }
        $Pdo->exec('DELETE FROM journey_patterns WHERE id NOT IN (SELECT journey_pattern_id FROM service_journeys)');
        $Pdo->exec('DELETE FROM journey_pattern_stops WHERE journey_pattern_id NOT IN (SELECT id FROM journey_patterns)');
        $Pdo->exec('DELETE FROM lines WHERE id NOT IN (SELECT line_id FROM service_journeys)');
        return count($aDuplicates);
    }

    public static function pruneUnservedStops(PDO $Pdo): int
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

    public static function createIndexes(PDO $Pdo): void
    {
        $Pdo->exec('CREATE INDEX idx_passing_times_stop ON passing_times (stop_id, departure_seconds)');
        $Pdo->exec('CREATE INDEX IF NOT EXISTS idx_passing_times_journey ON passing_times (service_journey_id, seq_order)');
        $Pdo->exec('CREATE INDEX idx_journeys_line ON service_journeys (line_id, trip_number, first_departure_seconds)');
        $Pdo->exec('CREATE INDEX idx_journeys_calendar ON service_journeys (calendar_id)');
        $Pdo->exec('CREATE INDEX idx_pattern_stops ON journey_pattern_stops (journey_pattern_id, seq_order)');
        $Pdo->exec('CREATE INDEX idx_stops_normalized ON stops (name_normalized)');
        $Pdo->exec('CREATE INDEX idx_stops_station ON stops (station_id)');
        $Pdo->exec('CREATE INDEX idx_lines_normalized ON lines (name_normalized)');
        $Pdo->exec('CREATE INDEX idx_calendar_exceptions ON service_calendar_exceptions (calendar_id, date)');
    }

    public static function insertStops(PDO $Pdo, array $aStops): void
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
                $iId, $aStop['name'], Search::normalize($aStop['name']), $sArea, Search::normalize($sArea),
                $aStop['lat'], $aStop['lon'], $sStationId, $sPlatformLabel,
            ]);
        }
    }

    public static function insertCalendars(PDO $Pdo, array $aCalendars): void
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
}
