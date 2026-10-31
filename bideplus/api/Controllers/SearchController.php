<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Models\Stop;

// Buscador (/api/search?q=): paradas y líneas. En las redes con stop_hints, cada parada dice hacia dónde van sus
// autobuses («hacia Gernika»), para distinguir las dos aceras.
class SearchController
{

    public function search(Request $Req): void
    {
        $sQ = trim((string)$Req->query('q', ''));
        if (mb_strlen($sQ) < 2) {
            Response::json(['stops' => [], 'lines' => []]);
            return;
        }

        $Pdo = Database::connection();
        $StopModel = new Stop($Pdo);
        $aStops = $StopModel->search($sQ, 60);
        $aLines = (new LineModel($Pdo))->search($sQ, 30);

        if (Config::feature('stop_hints')) {
            $aStops = $this->addDirectionHints($StopModel, $aStops);
        } else {
            foreach ($aStops as &$aStop) {
                $aStop['hint'] = null;
            }
        }

        Response::json(['stops' => $aStops, 'lines' => $aLines]);
    }

    private function addDirectionHints(Stop $StopModel, array $aStops): array
    {
        $aHeadsigns = $StopModel->headsignsForMany(array_column($aStops, 'id'));
        foreach ($aStops as &$aStop) {
            // Sin recorridos que salgan de ella, la parada solo es final de línea.
            $aStop['hint'] = 'final de línea';
            if (isset($aHeadsigns[$aStop['id']])) {
                $aStop['hint'] = 'hacia ' . implode(', ', $aHeadsigns[$aStop['id']]);
            }
        }
        return $aStops;
    }
}
