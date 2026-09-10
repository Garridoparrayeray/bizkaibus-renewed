<?php

namespace Models;

class LineModel
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, name FROM lines WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    /** @return array<int, array{id:int,code:string,name:string}> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, code, name FROM lines ORDER BY code')->fetchAll();
    }

    /** @return array<int, array{id:int,code:string,name:string}> */
    public function search(string $query, int $limit = 10): array
    {
        $normalized = Search::normalize($query);
        $stmt = $this->pdo->prepare(
            'SELECT id, code, name FROM lines WHERE name_normalized LIKE ? OR LOWER(code) LIKE ? ORDER BY code LIMIT ?'
        );
        $like = '%' . $normalized . '%';
        $stmt->bindValue(1, $like, \PDO::PARAM_STR);
        $stmt->bindValue(2, $like, \PDO::PARAM_STR);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:string,headsign:?string}> patrones/direcciones distintos de una línea */
    public function patterns(int $lineId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, headsign FROM journey_patterns WHERE line_id = ?');
        $stmt->execute([$lineId]);
        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:string, headsign:?string, stops: array<int, array{id:int,name:string,lat:float,lon:float}>}> */
    public function patternsWithStops(int $lineId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, headsign FROM journey_patterns WHERE line_id = ?');
        $stmt->execute([$lineId]);
        $patterns = $stmt->fetchAll();

        $stopsStmt = $this->pdo->prepare('
            SELECT s.id, s.name, s.lat, s.lon
            FROM journey_pattern_stops jps
            JOIN stops s ON s.id = jps.stop_id
            WHERE jps.journey_pattern_id = ?
            ORDER BY jps.seq_order
        ');
        foreach ($patterns as &$pattern) {
            $stopsStmt->execute([$pattern['id']]);
            $pattern['stops'] = $stopsStmt->fetchAll();
        }
        return $patterns;
    }
}
