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
            $aStop['hint'] = isset($aHeadsigns[$aStop['id']]) ? 'hacia ' . implode(', ', $aHeadsigns[$aStop['id']]) : 'final de línea';
        }
        return $aStops;
    }
}
