<?php

namespace Models;

class Stop
{
    public function __construct(private \PDO $Pdo)
    {
    }

    public function find(int $iId): array|null
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
        return $aRow;
    }

    public function search(string $sQuery, int $iLimit = 10): array
    {
        $sNormalized = Search::normalize($sQuery);
        $sLike = '%' . $sNormalized . '%';
        $Stmt = $this->safeQuery(
            'SELECT id, name, area, lat, lon FROM stops
             WHERE name_normalized LIKE ? OR area_normalized LIKE ?
             ORDER BY LENGTH(name) ASC LIMIT ?',
            'SELECT id, name, \'\' AS area, lat, lon FROM stops
             WHERE name_normalized LIKE ?
             ORDER BY LENGTH(name) ASC LIMIT ?',
            [$sLike, $sLike, $iLimit],
            [$sLike, $iLimit]
        );
        return $Stmt->fetchAll();
    }

    public function headsignsFor(int $iStopId, int $iLimit = 2): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT DISTINCT jp.headsign
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            WHERE jps.stop_id = ? AND jp.headsign IS NOT NULL AND jp.headsign != \'\'
            LIMIT ?
        ');
        $Stmt->bindValue(1, $iStopId, \PDO::PARAM_INT);
        $Stmt->bindValue(2, $iLimit, \PDO::PARAM_INT);
        $Stmt->execute();
        return $Stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function linesServing(int $iStopId): array
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
        return $Stmt->fetchAll();
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
