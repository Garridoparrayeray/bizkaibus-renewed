<?php

namespace Controllers;

use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Models\Stop;

class SearchController
{
    public function search(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        if (mb_strlen($q) < 2) {
            Response::json(['stops' => [], 'lines' => []]);
            return;
        }

        $pdo = Database::connection();
        $stopModel = new Stop($pdo);
        $stops = $stopModel->search($q, 60);
        $lines = (new LineModel($pdo))->search($q, 30);

        $stops = $this->addHintsForAmbiguousStops($stopModel, $stops);

        Response::json(['stops' => $stops, 'lines' => $lines]);
    }

    /**
     * Muchas paradas comparten nombre+zona exacta (p.ej. dos andenes de la
     * misma marquesina, uno por sentido) — indistinguibles a simple vista en
     * la lista de resultados. Solo para esos casos ambiguos se añade una
     * pista con destinos reales que pasan por cada una, sin penalizar con
     * consultas extra las búsquedas normales sin duplicados.
     *
     * @param array<int, array{id:int,name:string,area:string,lat:float,lon:float}> $stops
     * @return array<int, array{id:int,name:string,area:string,lat:float,lon:float,hint:?string}>
     */
    private function addHintsForAmbiguousStops(Stop $stopModel, array $stops): array
    {
        $counts = [];
        foreach ($stops as $stop) {
            $key = $stop['name'] . '|' . $stop['area'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        foreach ($stops as &$stop) {
            $key = $stop['name'] . '|' . $stop['area'];
            $stop['hint'] = null;
            if ($counts[$key] > 1) {
                $headsigns = $stopModel->headsignsFor((int)$stop['id']);
                if (!empty($headsigns)) {
                    $stop['hint'] = 'hacia ' . implode(', ', $headsigns);
                }
            }
        }
        return $stops;
    }
}
