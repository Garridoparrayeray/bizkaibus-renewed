<?php

namespace Services;

use Core\Config;

// Feeds que escriben la hora de aquí como si fuera UTC (Álavabus): sus horas llegan adelantadas lo que Madrid se aparta
// de UTC (una hora en invierno, dos en verano), pero no las de todos sus viajes. Si la ficha de la red lo dice
// (gtfs_rt.local_clock), cada viaje se lee de las dos maneras y vale la que más se acerca a su horario: un retraso de
// verdad de más de media hora es raro y, si lo hay, lo contradice el GPS.
final class FeedClock
{
    // Lo que hay que quitar a las horas de este viaje: 0 o lo que Madrid se aparta de UTC.
    public static function shift(array $aFeedStops, array $aStops, int $iMidnight, int $iZoneSeconds): int
    {
        if (empty(Config::current()['gtfs_rt']['local_clock'])) {
            return 0;
        }
        $iGap = self::firstGap($aFeedStops, $aStops, $iMidnight);
        if ($iGap !== null && abs($iGap - $iZoneSeconds) < abs($iGap)) {
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
