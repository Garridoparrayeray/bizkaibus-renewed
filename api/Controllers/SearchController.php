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

        $stops = $this->addDirectionHints($stopModel, $stops);

        Response::json(['stops' => $stops, 'lines' => $lines]);
    }

    /**
     * Destinos reales que pasan por cada parada, como pista de dirección en
     * la lista de resultados — siempre, no solo cuando dos paradas comparten
     * nombre+zona exacta (antes solo se calculaba para esos casos
     * ambiguos, así que la mayoría de paradas con nombre único nunca
     * mostraban hacia dónde iban).
     *
     * @param array<int, array{id:int,name:string,area:string,lat:float,lon:float}> $stops
     * @return array<int, array{id:int,name:string,area:string,lat:float,lon:float,hint:?string}>
     */
    private function addDirectionHints(Stop $stopModel, array $stops): array
    {
        foreach ($stops as &$stop) {
            $stop['hint'] = null;
            $headsigns = $stopModel->headsignsFor((int)$stop['id']);
            if (!empty($headsigns)) {
                $stop['hint'] = 'hacia ' . implode(', ', $headsigns);
            }
        }
        return $stops;
    }
}
