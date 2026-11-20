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
        $aLine = $LineModel->find($aParams['id']);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $aLine['patterns'] = $LineModel->patterns($aLine['id']);
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
        Response::json(['line' => $aLine, 'patterns' => $LineModel->patternsWithStops($aLine['id'])]);
    }

    public function scheduleText(Request $Req, array $aParams): void
    {
        // El del operador; si su fuente no responde y no hay copia, el del GTFS (antes salía vacío, y el CDN lo guardaba).
        if (Config::feature('schedule_text')) {
            $iFailures = \Core\Cache::$iEmptyFailures;
            $aSchedule = (new ScheduleTextClient(Config::current()))->fetchForLine((int)$aParams['id']);
            if (\Core\Cache::$iEmptyFailures === $iFailures) {
                Response::json(['lineId' => (int)$aParams['id'], 'schedule' => $aSchedule, 'source' => 'operator']);
                return;
            }
        }
        $Pdo = Database::connection();
        $aLine = (new LineModel($Pdo))->find($aParams['id']);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $sLineId = (string)$aLine['id'];
        $Text = new GtfsScheduleText(new ServiceJourney($Pdo), (bool)Config::feature('headsign_from_last_stop'));
        Response::json([
            'lineId' => $sLineId,
            'schedule' => $Text->forLine($sLineId, Calendar::todayMadrid(), ScheduleRange::forDatabase($Pdo)['to']),
            'source' => 'gtfs',
        ]);
    }
}
