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
        $stops = (new Stop($pdo))->search($q, 60);
        $lines = (new LineModel($pdo))->search($q, 30);

        Response::json(['stops' => $stops, 'lines' => $lines]);
    }
}
