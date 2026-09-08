<?php

namespace Controllers;

use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Services\ScheduleTextClient;

class LinesController
{
    /** Catálogo completo de líneas de la red activa, ruta /lines. */
    public function index(Request $request): void
    {
        $pdo = Database::connection();
        Response::json(['lines' => (new LineModel($pdo))->all()]);
    }

    /**
     * Detalle de una línea, ruta /lines/{id}, incluyendo sus journey_patterns
     * (variantes de recorrido). El frontend necesita esos patrones para
     * pintar el mapa de la línea y el selector de sentido antes de pedir un
     * horario.
     */
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

    /**
     * Horario oficial vigente en texto libre, ruta /lines/{id}/schedule-text.
     * Solo aplica a bus. Es un scrape del endpoint legado de Bizkaibus
     * (GetLineasHorarios): no viene del GTFS ni está estructurado por parada,
     * así que se devuelve tal cual, como bloques de texto de referencia.
     */
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
