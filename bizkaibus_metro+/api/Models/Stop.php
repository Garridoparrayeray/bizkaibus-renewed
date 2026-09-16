<?php

namespace Models;

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

    /**
     * Andenes reales de una estación (Euskotren, formato NeTEx: ver
     * loadStopsEuskotren en build-database.php). Vacío para bus/metro, cuyo
     * GTFS no tiene ese nivel de detalle — sus paradas nunca aparecen como
     * station_id de otra fila.
     *
     * @return array<int,array{id:string,label:string}>
     */
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

    public function linesServing(int|string $iStopId): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT DISTINCT l.id, l.code, l.name
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            JOIN lines l ON l.id = jp.line_id
            WHERE jps.stop_id = ?
            ORDER BY l.code
        ');
        $Stmt->execute([$iStopId]);
        $aRows = $Stmt->fetchAll();
        foreach ($aRows as &$aRow) {
            $aRow['id'] = Ids::forOutput($aRow['id']);
        }
        return $aRows;
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
