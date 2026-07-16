<?php

namespace Controllers;

use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Services\ScheduleTextClient;

class LinesController
{
    public function index(Request $request): void
    {
        $pdo = Database::connection();
        Response::json(['lines' => (new LineModel($pdo))->all()]);
    }

    public function show(Request $request, array $params): void
    {
        $pdo = Database::connection();
        $lineModel = new LineModel($pdo);
        $line = $lineModel->find((int)$params['id']);
        if ($line === null) {
            Response::error('Line not found', 404);
            return;
        }
        $line['patterns'] = $lineModel->patterns((int)$params['id']);
        Response::json($line);
    }

    public function scheduleText(Request $request, array $params): void
    {
        $config = require __DIR__ . '/../Config/config.php';
        $blocks = (new ScheduleTextClient($config))->fetchForLine((int)$params['id']);
        Response::json([
            'lineId' => (int)$params['id'],
            'schedule' => $blocks,
            'source' => 'Bizkaibus (horario oficial vigente, texto libre, no estructurado por parada)',
        ]);
    }
}
