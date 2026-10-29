<?php

namespace Engine\Bizkaibus;

use Models\ServiceJourney;
use Services\Calendar;

class Live
{
    public static function vehicles(array $aNetwork): array
    {
        return (new SiriVehicleMonitoringClient($aNetwork))->fetchActiveTrips();
    }

    public static function matcher(array $aVehicles, ServiceJourney|null $JourneyModel): RealtimeMatcher
    {
        $Matcher = new RealtimeMatcher($aVehicles, $JourneyModel);
        if ($JourneyModel !== null) {
            $sNetwork = \Core\Config::current()['network'] ?? 'bus';
            $sDate = Calendar::todayMadrid()->format('Y-m-d');
            $Matcher->setPassageLoader(fn(array $aJourneyIds) => PassageLog::forJourneys($JourneyModel, $aJourneyIds, $sNetwork, $sDate));
        }
        return $Matcher;
    }

    public static function record(array $aNetwork): int|null
    {
        return PassageLog::record(self::vehicles($aNetwork), $aNetwork['network'] ?? 'bus', Calendar::todayMadrid()->format('Y-m-d'));
    }
}
