<?php

namespace Models;

class Stop
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(int $id): ?array
    {
        $stmt = $this->safeQuery(
            'SELECT id, name, area, lat, lon FROM stops WHERE id = ?',
            'SELECT id, name, \'\' AS area, lat, lon FROM stops WHERE id = ?',
            [$id]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<int, array{id:int,name:string,area:string,lat:float,lon:float}> */
    public function search(string $query, int $limit = 10): array
    {
        $normalized = Search::normalize($query);
        $like = '%' . $normalized . '%';
        $stmt = $this->safeQuery(
            'SELECT id, name, area, lat, lon FROM stops
             WHERE name_normalized LIKE ? OR area_normalized LIKE ?
             ORDER BY LENGTH(name) ASC LIMIT ?',
            'SELECT id, name, \'\' AS area, lat, lon FROM stops
             WHERE name_normalized LIKE ?
             ORDER BY LENGTH(name) ASC LIMIT ?',
            [$like, $like, $limit],
            [$like, $limit]
        );
        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:int,code:string,name:string}> líneas que pasan por esta parada */
    public function linesServing(int $stopId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT DISTINCT l.id, l.code, l.name
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            JOIN lines l ON l.id = jp.line_id
            WHERE jps.stop_id = ?
            ORDER BY l.code
        ');
        $stmt->execute([$stopId]);
        return $stmt->fetchAll();
    }

    /**
     * Prueba primero la consulta con `area`; si esa columna aún no existe
     * (p.ej. data/bizkaibus.sqlite se generó antes de añadir la
     * geocodificación — ver scripts/build-database.php), cae a la versión sin ella.
     */
    private function safeQuery(string $sql, string $fallbackSql, array $args, ?array $fallbackArgs = null): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($args);
            return $stmt;
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'no such column')) {
                throw $e;
            }
            $stmt = $this->pdo->prepare($fallbackSql);
            $stmt->execute($fallbackArgs ?? $args);
            return $stmt;
        }
    }
}
