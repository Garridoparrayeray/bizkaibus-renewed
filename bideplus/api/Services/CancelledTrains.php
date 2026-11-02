<?php

namespace Services;

use Models\ServiceJourney;

// Los trenes que Renfe cancela en su directo, como incidencias: cada uno sale en las estaciones por las que aún tenía
// que pasar y desaparece de cada una cuando llega su hora.
class CancelledTrains
{
    public static function alerts(TripDelayMatcher $Matcher, ServiceJourney $JourneyModel, int $iNowSeconds): array
    {
        $aAlerts = [];
        foreach ($Matcher->cancelledJourneyIds() as $sJourneyId) {
            $aStops = $JourneyModel->stopsForJourney($sJourneyId);
            $aLine = $JourneyModel->lineOfJourney($sJourneyId);
            if (empty($aStops) || $aLine === null) {
                continue;
            }
            $aAhead = [];
            foreach ($aStops as $aStop) {
                if ((int)$aStop['arrival_seconds'] >= $iNowSeconds) {
                    $aAhead[] = (string)$aStop['stop_id'];
                }
            }
            if (empty($aAhead)) {
                continue;
            }
            $aFirst = $aStops[0];
            $aLast = end($aStops);
            $sTime = Calendar::secondsToHm((int)$aFirst['departure_seconds']);
            $aAlerts[(int)$aFirst['departure_seconds'] . '|' . $sJourneyId] = [
                'summary' => $aLine['code'] . ' · tren de las ' . $sTime . ' cancelado',
                'description' => 'Renfe ha cancelado el tren de las ' . $sTime . ' de ' . $aFirst['name'] . ' a ' . $aLast['name'] . '.',
                'startTime' => null,
                'endTime' => null,
                'lineRefs' => [(string)$aLine['id']],
                'stopRefs' => $aAhead,
                'scope' => 'stop',
            ];
        }
        ksort($aAlerts, SORT_NATURAL);
        return array_values($aAlerts);
    }
}
