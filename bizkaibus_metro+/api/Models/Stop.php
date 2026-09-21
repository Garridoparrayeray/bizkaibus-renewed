<?php

namespace Models;

use Core\Config;
use Core\Ids;

class Stop
{
    public function __construct(private \PDO $Pdo)
    {
    }

    public function find(int|string $iId): array|null
    {
        $Stmt = $this->safeQuery(
            'SELECT id, name, area, lat, lon FROM stops WHERE id = ?',
            'SELECT id, name, \'\' AS area, lat, lon FROM stops WHERE id = ?',
            [$iId]
        );
        $aRow = $Stmt->fetch();
        if (!$aRow) {
            return null;
        }
        $aRow['id'] = Ids::forOutput($aRow['id']);
        return $aRow;
    }

    public function search(string $sQuery, int $iLimit = 10): array
    {
        $sNormalized = Search::normalize($sQuery);
        $sLike = '%' . $sNormalized . '%';
        $Stmt = $this->safeQuery(
            'SELECT id, name, area, lat, lon FROM stops
             WHERE (name_normalized LIKE ? OR area_normalized LIKE ?) AND station_id IS NULL
             ORDER BY LENGTH(name) ASC LIMIT ?',
            'SELECT id, name, \'\' AS area, lat, lon FROM stops
             WHERE name_normalized LIKE ?
             ORDER BY LENGTH(name) ASC LIMIT ?',
            [$sLike, $sLike, $iLimit],
            [$sLike, $iLimit]
        );
        $aRows = $Stmt->fetchAll();
        foreach ($aRows as &$aRow) {
            $aRow['id'] = Ids::forOutput($aRow['id']);
        }
        return $aRows;
    }

    public function platformsFor(string $sStationId): array
    {
        $Stmt = $this->Pdo->prepare('SELECT id, platform_label FROM stops WHERE station_id = ? ORDER BY id');
        $Stmt->execute([$sStationId]);
        $aRows = $Stmt->fetchAll();
        return array_map(fn($aRow) => ['id' => Ids::forOutput($aRow['id']), 'label' => $aRow['platform_label']], $aRows);
    }

    public function headsignsFor(int|string $iStopId, int $iLimit = 2): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT DISTINCT jp.headsign
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            WHERE jps.stop_id = ? AND jp.headsign IS NOT NULL AND jp.headsign != \'\'
            LIMIT ?
        ');
        $Stmt->bindValue(1, $iStopId);
        $Stmt->bindValue(2, $iLimit, \PDO::PARAM_INT);
        $Stmt->execute();
        return $Stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function nearest(float $dLat, float $dLon, int $iLimit = 5, int $iRadiusMeters = 2000): array
    {
        $dLatDelta = $iRadiusMeters / 111320;
        $dLonDelta = $iRadiusMeters / (111320 * max(cos(deg2rad($dLat)), 0.01));
        $Stmt = $this->Pdo->prepare('
            SELECT id, name, area, lat, lon FROM stops
            WHERE station_id IS NULL AND lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?
        ');
        $Stmt->execute([$dLat - $dLatDelta, $dLat + $dLatDelta, $dLon - $dLonDelta, $dLon + $dLonDelta]);

        $aNearby = [];
        foreach ($Stmt->fetchAll() as $aRow) {
            $iDistance = (int)round(self::distanceMeters($dLat, $dLon, (float)$aRow['lat'], (float)$aRow['lon']));
            if ($iDistance > $iRadiusMeters) {
                continue;
            }
            $aRow['id'] = Ids::forOutput($aRow['id']);
            $aRow['distanceM'] = $iDistance;
            $aNearby[] = $aRow;
        }
        usort($aNearby, fn($aA, $aB) => $aA['distanceM'] <=> $aB['distanceM']);
        return array_slice($aNearby, 0, $iLimit);
    }

    private static function distanceMeters(float $dLat1, float $dLon1, float $dLat2, float $dLon2): float
    {
        $dPhi1 = deg2rad($dLat1);
        $dPhi2 = deg2rad($dLat2);
        $dA = sin(($dPhi2 - $dPhi1) / 2) ** 2 + cos($dPhi1) * cos($dPhi2) * sin(deg2rad($dLon2 - $dLon1) / 2) ** 2;
        return 2 * 6371000 * asin(min(1, sqrt($dA)));
    }

    public function headsignsForMany(array $aStopIds, int $iLimitPerStop = 2): array
    {
        if (empty($aStopIds)) {
            return [];
        }
        $sPlaceholders = implode(',', array_fill(0, count($aStopIds), '?'));
        $Stmt = $this->Pdo->prepare('
            SELECT DISTINCT jps.stop_id, jp.headsign
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            WHERE jps.stop_id IN (' . $sPlaceholders . ') AND jp.headsign IS NOT NULL AND jp.headsign != \'\'
        ');
        $Stmt->execute(array_values($aStopIds));
        $aByStop = [];
        foreach ($Stmt->fetchAll() as $aRow) {
            $aByStop[$aRow['stop_id']][] = $aRow['headsign'];
        }
        return array_map(fn($aHeadsigns) => array_slice($aHeadsigns, 0, $iLimitPerStop), $aByStop);
    }

    public function linesServing(int|string $iStopId): array
    {
        $Stmt = $this->safeQuery(
            'SELECT DISTINCT l.id, l.code, l.name
             FROM journey_pattern_stops jps
             JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
             JOIN lines l ON l.id = jp.line_id
             WHERE jps.stop_id = ? OR jps.stop_id IN (SELECT id FROM stops WHERE station_id = ?)
             ORDER BY l.code',
            'SELECT DISTINCT l.id, l.code, l.name
             FROM journey_pattern_stops jps
             JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
             JOIN lines l ON l.id = jp.line_id
             WHERE jps.stop_id = ?
             ORDER BY l.code',
            [$iStopId, $iStopId],
            [$iStopId]
        );
        $aRows = $Stmt->fetchAll();
        foreach ($aRows as &$aRow) {
            $aRow['id'] = Ids::forOutput($aRow['id']);
        }
        return Config::withoutHiddenLines($aRows);
    }

    private function safeQuery(string $sSql, string $sFallbackSql, array $aArgs, array|null $aFallbackArgs = null): \PDOStatement
    {
        try {
            $Stmt = $this->Pdo->prepare($sSql);
            $Stmt->execute($aArgs);
            return $Stmt;
        } catch (\PDOException $Ex) {
            if (!str_contains($Ex->getMessage(), 'no such column')) {
                throw $Ex;
            }
            $Stmt = $this->Pdo->prepare($sFallbackSql);
            if (isset($aFallbackArgs)) {
                $Stmt->execute($aFallbackArgs);
            } else {
                $Stmt->execute($aArgs);
            }
            return $Stmt;
        }
    }
}
