<?php

namespace Models;

use Core\Config;
use Core\Ids;

// Consultas de líneas en la base de datos: buscar, listar, sus recorridos (patterns) y sus paradas.
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
        $aWords = Search::words($sQuery);
        if (empty($aWords)) {
            return [];
        }
        [$sWhere, $aArgs] = Search::wordsCondition($aWords, "(LOWER(code) || ' ' || name_normalized)");
        $Stmt = $this->Pdo->prepare('SELECT id, code, name FROM lines WHERE ' . $sWhere . ' ORDER BY code LIMIT ?');
        foreach ($aArgs as $i => $sArg) {
            $Stmt->bindValue($i + 1, $sArg, \PDO::PARAM_STR);
        }
        $Stmt->bindValue(count($aArgs) + 1, $iLimit, \PDO::PARAM_INT);
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
        foreach ($aPatterns as &$aPattern) {
            $aPattern['stops'] = $this->patternStops($aPattern['id']);
        }
        return $aPatterns;
    }

    // Los recorridos con más viajes de una línea, uno por destino y hasta $iMax, con sus paradas en orden: los de su
    // página. Con $aEnds, solo los que van de una a otra de esas dos paradas (por nombre), en cualquier sentido.
    public function mainRoutes(int|string $iLineId, int $iMax, array|null $aEnds = null): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT jp.id, jp.headsign, COUNT(sj.id) AS trips
            FROM journey_patterns jp LEFT JOIN service_journeys sj ON sj.journey_pattern_id = jp.id
            WHERE jp.line_id = ?
            GROUP BY jp.id ORDER BY trips DESC, jp.id
        ');
        $Stmt->execute([$iLineId]);
        if ($aEnds !== null) {
            sort($aEnds);
        }
        $aRoutes = [];
        foreach ($Stmt->fetchAll() as $aPattern) {
            if (count($aRoutes) >= $iMax || isset($aRoutes[$aPattern['headsign']])) {
                continue;
            }
            $aStops = $this->patternStops($aPattern['id']);
            $aPatternEnds = [$aStops[0]['name'] ?? '', end($aStops)['name'] ?? ''];
            sort($aPatternEnds);
            if (count($aStops) < 2 || ($aEnds !== null && $aPatternEnds !== $aEnds)) {
                continue;
            }
            $aRoutes[$aPattern['headsign']] = ['headsign' => $aPattern['headsign'], 'stops' => $aStops];
        }
        return array_values($aRoutes);
    }

    private function patternStops(int|string $sPatternId): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT s.id, s.name, s.lat, s.lon
            FROM journey_pattern_stops jps
            JOIN stops s ON s.id = jps.stop_id
            WHERE jps.journey_pattern_id = ?
            ORDER BY jps.seq_order
        ');
        $Stmt->execute([$sPatternId]);
        $aStops = $Stmt->fetchAll();
        foreach ($aStops as &$aStop) {
            $aStop['id'] = Ids::forOutput($aStop['id']);
        }
        return $aStops;
    }
}
