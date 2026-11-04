<?php

namespace Services;

use Models\ServiceJourney;

// Los trenes que Renfe cancela en su directo, como incidencias: cada uno sale en las estaciones por las que aún tenía
// que pasar y desaparece de cada una cuando llega su hora.
class CancelledTrains
{
    public static function alerts(TripDelayMatcher $Matcher, ServiceJourney $JourneyModel, int $iNowSeconds): array
    {
        $aCancelled = [];
        foreach ($Matcher->cancelledJourneyIds() as $sJourneyId) {
            $aStops = $JourneyModel->stopsForJourney($sJourneyId);
            $aLine = $JourneyModel->lineOfJourney($sJourneyId);
            if (empty($aStops) || $aLine === null) {
                continue;
            }
            $aAhead = self::stopsAhead($aStops, $iNowSeconds);
            if (!empty($aAhead)) {
                $aCancelled[] = ['departure' => (int)$aStops[0]['departure_seconds'], 'alert' => self::alert($aLine, $aStops, $aAhead)];
            }
        }
        usort($aCancelled, fn($aA, $aB) => $aA['departure'] <=> $aB['departure']);
        return array_column($aCancelled, 'alert');
    }

    private static function stopsAhead(array $aStops, int $iNowSeconds): array
    {
        $aAhead = [];
        foreach ($aStops as $aStop) {
            if ((int)$aStop['arrival_seconds'] >= $iNowSeconds) {
                $aAhead[] = (string)$aStop['stop_id'];
            }
        }
        return $aAhead;
    }

    private static function alert(array $aLine, array $aStops, array $aAhead): array
    {
        $sTime = Calendar::secondsToHm((int)$aStops[0]['departure_seconds']);
        $sFrom = $aStops[0]['name'];
        $sTo = end($aStops)['name'];
        return [
            'summary' => $aLine['code'] . ' · tren de las ' . $sTime . ' cancelado',
            'description' => 'Renfe ha cancelado el tren de las ' . $sTime . ' de ' . $sFrom . ' a ' . $sTo . '.',
            'startTime' => null,
            'endTime' => null,
            'lineRefs' => [(string)$aLine['id']],
            'stopRefs' => $aAhead,
            'scope' => 'stop',
        ];
    }
}
