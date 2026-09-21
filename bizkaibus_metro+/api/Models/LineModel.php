<?php

namespace Models;

use Core\Config;
use Core\Ids;

class LineModel
{
    public function __construct(private \PDO $Pdo)
    {
    }

    public function find(int|string $iId): array|null
    {
        $Stmt = $this->Pdo->prepare('SELECT id, code, name FROM lines WHERE id = ?');
        $Stmt->execute([$iId]);
        $aRow = $Stmt->fetch();
        if (!$aRow) {
            return null;
        }
        $aRow['id'] = Ids::forOutput($aRow['id']);
        return $aRow;
    }

    public function all(): array
    {
        $aRows = $this->Pdo->query('SELECT id, code, name FROM lines ORDER BY code')->fetchAll();
        foreach ($aRows as &$aRow) {
            $aRow['id'] = Ids::forOutput($aRow['id']);
        }
        return Config::withoutHiddenLines($aRows);
    }

    public function search(string $sQuery, int $iLimit = 10): array
    {
        $sNormalized = Search::normalize($sQuery);
        $Stmt = $this->Pdo->prepare(
            'SELECT id, code, name FROM lines WHERE name_normalized LIKE ? OR LOWER(code) LIKE ? ORDER BY code LIMIT ?'
        );
        $sLike = '%' . $sNormalized . '%';
        $Stmt->bindValue(1, $sLike, \PDO::PARAM_STR);
        $Stmt->bindValue(2, $sLike, \PDO::PARAM_STR);
        $Stmt->bindValue(3, $iLimit, \PDO::PARAM_INT);
        $Stmt->execute();
        $aRows = $Stmt->fetchAll();
        foreach ($aRows as &$aRow) {
            $aRow['id'] = Ids::forOutput($aRow['id']);
        }
        return Config::withoutHiddenLines($aRows);
    }

    public function patterns(int|string $iLineId): array
    {
        $Stmt = $this->Pdo->prepare('SELECT id, headsign FROM journey_patterns WHERE line_id = ?');
        $Stmt->execute([$iLineId]);
        return $Stmt->fetchAll();
    }

    public function patternsWithStops(int|string $iLineId): array
    {
        $Stmt = $this->Pdo->prepare('SELECT id, headsign FROM journey_patterns WHERE line_id = ?');
        $Stmt->execute([$iLineId]);
        $aPatterns = $Stmt->fetchAll();

        $StopsStmt = $this->Pdo->prepare('
            SELECT s.id, s.name, s.lat, s.lon
            FROM journey_pattern_stops jps
            JOIN stops s ON s.id = jps.stop_id
            WHERE jps.journey_pattern_id = ?
            ORDER BY jps.seq_order
        ');
        foreach ($aPatterns as &$aPattern) {
            $StopsStmt->execute([$aPattern['id']]);
            $aStops = $StopsStmt->fetchAll();
            foreach ($aStops as &$aStop) {
                $aStop['id'] = Ids::forOutput($aStop['id']);
            }
            $aPattern['stops'] = $aStops;
        }
        return $aPatterns;
    }
}
