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
