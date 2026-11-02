<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\LineModel;
use Models\ServiceJourney;
use Services\BusSheet;
use Services\FeedStatus;
use Services\LineVehicles;
use Services\TrainSheet;

// Directo: los vehículos de una línea en el mapa (/api/lines/{id}/live), la ficha de un viaje (/api/vehicles/{tripKey})
// y el diagnóstico de la fuente (/api/status). Cada cosa la hace su servicio; el cálculo de llegadas está en engine/.
class RealtimeController
{
    public function status(Request $Req, array $aParams): void
    {
        $aReport = FeedStatus::report();
        if ($aReport === null) {
            Response::error('This network has no vehicle feed', 404);
            return;
        }
        Response::json($aReport);
    }

    public function lineLive(Request $Req, array $aParams): void
    {
        $sLineId = $aParams['id'];
        $Pdo = Database::connection();
        $LineModel = new LineModel($Pdo);
        $aLine = $LineModel->find($sLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $aPatterns = $LineModel->patternsWithStops($sLineId);
        Response::json([
            'line' => $aLine,
            'patterns' => $aPatterns,
            'vehicles' => LineVehicles::onLine($Pdo, $sLineId, $aPatterns),
            // Sin posiciones de la red (Euskotren, tranvías), el mapa no dice que no circula nada.
            'tracked' => Config::hasLive('vehicle-positions') || Config::hasLive('vehicle-monitoring'),
        ]);
    }

    // Ficha de un viaje (?stopId=): sus paradas con la llegada prevista a cada una, la parada por la que va, su estado
    // y su retraso. Con horas previstas del operador, con las del feed; con vehículos en directo, con el algoritmo.
    public function vehicle(Request $Req, array $aParams): void
    {
        $aTripKey = TripKey::parse($aParams['tripKey']);
        if ($aTripKey === null) {
            Response::error('Invalid vehicle key', 422);
            return;
        }
        $JourneyModel = new ServiceJourney(Database::connection());
        $aVariants = $JourneyModel->variantsByLineAndTrip(...$aTripKey);
        if (empty($aVariants)) {
            Response::error('Trip not found', 404);
            return;
        }
        if (Config::hasLive('trip-updates')) {
            Response::json(TrainSheet::build($JourneyModel, $aVariants[0], $Req->query('stopId')));
            return;
        }
        Response::json(BusSheet::build($JourneyModel, $aTripKey, $aVariants, $Req->query('stopId')));
    }
}
