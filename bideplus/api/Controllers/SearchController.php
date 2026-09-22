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

        $aConfig = Config::current();
        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }
        if ($sNetwork === 'bus') {
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
            $aStop['hint'] = isset($aHeadsigns[$aStop['id']]) ? 'hacia ' . implode(', ', $aHeadsigns[$aStop['id']]) : null;
        }
        return $aStops;
    }
}
