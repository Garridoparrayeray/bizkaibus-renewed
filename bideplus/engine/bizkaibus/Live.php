<?php

namespace Engine\Bizkaibus;

use Models\ServiceJourney;

class Live
{
    public static function vehicles(array $aNetwork): array
    {
        return (new SiriVehicleMonitoringClient($aNetwork))->fetchActiveTrips();
    }

    public static function matcher(array $aVehicles, ServiceJourney|null $JourneyModel): RealtimeMatcher
    {
        return new RealtimeMatcher($aVehicles, $JourneyModel);
    }
}
