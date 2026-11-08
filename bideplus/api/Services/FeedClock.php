<?php

namespace Services;

use Core\Config;

// Feeds que escriben la hora de aquí como si fuera UTC (Álavabus): todas sus horas llegan adelantadas lo que Madrid se
// aparta de UTC (una hora en invierno, dos en verano). Se corrige si la ficha de la red lo dice (gtfs_rt.local_clock) y
// las horas del feed lo confirman: si el operador lo arregla, la mediana vuelve a cero y no se toca nada.
final class FeedClock
{
    public static function offset(array $aTrips, array $aJourneyIds, JourneyStops $Stops, int $iMidnight, int $iZoneSeconds): int
    {
        if (empty(Config::current()['gtfs_rt']['local_clock'])) {
            return 0;
        }
        $aGaps = [];
        foreach ($aTrips as $aTrip) {
            $sJourneyId = $aJourneyIds[$aTrip['tripId']] ?? null;
            if ($sJourneyId !== null && !empty($aTrip['stops'])) {
                $iGap = self::firstGap($aTrip['stops'], $Stops->of((string)$sJourneyId), $iMidnight);
                if ($iGap !== null) {
                    $aGaps[] = $iGap;
                }
            }
        }
        if (empty($aGaps)) {
            return 0;
        }
        sort($aGaps);
        $iMedian = $aGaps[intdiv(\count($aGaps), 2)];
        if (abs($iMedian - $iZoneSeconds) < abs($iMedian)) {
            return $iZoneSeconds;
        }
        return 0;
    }

    // Lo que se aparta del horario la primera parada del feed que está en el recorrido del viaje.
    private static function firstGap(array $aFeedStops, array $aStops, int $iMidnight): int|null
    {
        $aArrivals = [];
        foreach ($aStops as $aStop) {
            $aArrivals[(string)$aStop['stop_id']] = (int)$aStop['arrival_seconds'];
        }
        foreach ($aFeedStops as [$sStopId, $iTime]) {
            if (isset($aArrivals[(string)$sStopId])) {
                return (int)$iTime - $iMidnight - $aArrivals[(string)$sStopId];
            }
        }
        return null;
    }
}
