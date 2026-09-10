<?php

namespace Models;

class LineModel
{
    public function __construct(private \PDO $Pdo)
    {
    }

    public function find(int $iId): array|null
    {
        $Stmt = $this->Pdo->prepare('SELECT id, code, name FROM lines WHERE id = ?');
        $Stmt->execute([$iId]);
        $aRow = $Stmt->fetch();
        if (!$aRow) {
            return null;
        }
        return $aRow;
    }

    public function all(): array
    {
        return $this->Pdo->query('SELECT id, code, name FROM lines ORDER BY code')->fetchAll();
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
        return $Stmt->fetchAll();
    }

    public function patterns(int $iLineId): array
    {
        $Stmt = $this->Pdo->prepare('SELECT id, headsign FROM journey_patterns WHERE line_id = ?');
        $Stmt->execute([$iLineId]);
        return $Stmt->fetchAll();
    }

    public function patternsWithStops(int $iLineId): array
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
            $aPattern['stops'] = $StopsStmt->fetchAll();
        }
        return $aPatterns;
    }
}
