<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Models\ServiceJourney;
use Services\Calendar;
use Services\GtfsScheduleText;
use Services\ScheduleRange;
use Services\ScheduleTextClient;

// Líneas de la red: la lista (/api/lines), una línea con sus paradas (/api/lines/{id}) y su horario oficial por
// periodos (/api/lines/{id}/schedule-text): el texto del operador si lo publica (schedule_text), o el de su GTFS.
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
        $sLineId = $aParams['id'];
        $aLine = $LineModel->find($sLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $aLine['patterns'] = $LineModel->patterns($sLineId);
        Response::json($aLine);
    }

    // El recorrido de la línea para su mapa: cada sentido con sus paradas, en orden y con sus coordenadas.
    public function route(Request $Req, array $aParams): void
    {
        $LineModel = new LineModel(Database::connection());
        $aLine = $LineModel->find($aParams['id']);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        Response::json(['line' => $aLine, 'patterns' => $LineModel->patternsWithStops($aParams['id'])]);
    }

    public function scheduleText(Request $Req, array $aParams): void
    {
        if (Config::feature('schedule_text')) {
            Response::json([
                'lineId' => (int)$aParams['id'],
                'schedule' => (new ScheduleTextClient(Config::current()))->fetchForLine((int)$aParams['id']),
                'source' => 'operator',
            ]);
            return;
        }
        $Pdo = Database::connection();
        if ((new LineModel($Pdo))->find($aParams['id']) === null) {
            Response::error('Line not found', 404);
            return;
        }
        $Text = new GtfsScheduleText(new ServiceJourney($Pdo), (bool)Config::feature('headsign_from_last_stop'));
        Response::json([
            'lineId' => $aParams['id'],
            'schedule' => $Text->forLine($aParams['id'], Calendar::todayMadrid(), ScheduleRange::forDatabase($Pdo)['to']),
            'source' => 'gtfs',
        ]);
    }
}
