<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Models\Stop;

class SearchController
{
    /**
     * Búsqueda combinada de paradas y líneas, ruta /search. Exige al menos
     * dos caracteres para no lanzar consultas LIKE contra toda la tabla en
     * cada pulsación del buscador. El hint "hacia X" solo se añade en bus
     * (ver addDirectionHints); en metro se deja explícitamente a null.
     */
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

        // La pista "hacia X" solo tiene sentido en Bizkaibus, donde muchas
        // paradas comparten nombre. En Metro Bilbao cada una de las 42
        // estaciones tiene nombre único, así que la pista no desambigua
        // nada y solo añade ruido a cada resultado.
        $config = Config::current();
        $network = 'bus';
        if (isset($config['network'])) {
            $network = $config['network'];
        }
        if ($network === 'bus') {
            $stops = $this->addDirectionHints($stopModel, $stops);
        } else {
            foreach ($stops as &$stop) {
                $stop['hint'] = null;
            }
        }

        Response::json(['stops' => $stops, 'lines' => $lines]);
    }

    /**
     * Destinos reales que pasan por cada parada, como pista de dirección en
     * la lista de resultados. Se calcula siempre, no solo cuando dos paradas
     * comparten nombre y zona exacta: antes solo se calculaba para esos casos
     * ambiguos, así que la mayoría de paradas con nombre único nunca
     * mostraban hacia dónde iban.
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
