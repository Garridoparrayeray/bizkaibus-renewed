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
use Services\TrainSheet;
use Services\TripSheet;

// Directo: la ficha de un viaje (/api/vehicles/{tripKey}) y el diagnóstico de la fuente (/api/status). Cada cosa
// la hace su servicio; el cálculo de llegadas está en engine/.
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
        // De madrugada, el de ayer si pasa de las 24:00 y aún no ha terminado.
        [$sLineId, $sTripNumber, $iFirstDeparture] = $aTripKey;
        $aLate = $JourneyModel->lateVariants($sLineId, $sTripNumber, $iFirstDeparture, TripSheet::LATE_GRACE_SECONDS);
        if (Config::hasLive('trip-updates')) {
            if (!empty($aLate)) {
                Response::json(TrainSheet::build($JourneyModel, $aLate[0], $Req->query('stopId'), 1));
                return;
            }
            Response::json(TrainSheet::build($JourneyModel, $aVariants[0], $Req->query('stopId')));
            return;
        }
        Response::json(BusSheet::build($JourneyModel, $aTripKey, $aVariants, $Req->query('stopId'), $aLate));
    }
}
