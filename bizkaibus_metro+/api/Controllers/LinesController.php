<?php

namespace Controllers;

use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Services\ScheduleTextClient;

class LinesController
{

    public function index(Request $Req): void
    {
        $Pdo = Database::connection();
        Response::json(['lines' => (new LineModel($Pdo))->all()]);
    }

    public function show(Request $Req, array $aParams): void
    {
        $Pdo = Database::connection();
        $LineModel = new LineModel($Pdo);
        $aLine = $LineModel->find((int)$aParams['id']);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $aLine['patterns'] = $LineModel->patterns((int)$aParams['id']);
        Response::json($aLine);
    }

    public function scheduleText(Request $Req, array $aParams): void
    {
        $aConfig = require __DIR__ . '/../Config/config.php';
        $aBlocks = (new ScheduleTextClient($aConfig))->fetchForLine((int)$aParams['id']);
        Response::json([
            'lineId' => (int)$aParams['id'],
            'schedule' => $aBlocks,
            'source' => 'Bizkaibus (horario oficial vigente, texto libre, no estructurado por parada)',
        ]);
    }
}
