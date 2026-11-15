<?php

namespace Services;

use Core\Config;
use Core\Database;
use Core\Live;
use Models\ServiceJourney;

// Los refuerzos del planificador: los trenes del feed que el horario no tiene (los «sin horario» de PlatformMatcher)
// que pasan por las paradas pedidas, con el recorrido y las horas completados como en su ficha (ExtraSheet), en el
// formato de los viajes extra de la operación «live» del núcleo. Solo los que pasan en las próximas 2 h.
class PlannerExtras
{
    public const AHEAD_SECONDS = 2 * 3600;
    // Los que caben en el núcleo a la vez.
    public const MAX_EXTRAS = 64;
    // Delante del id del feed, para que no se confunda con el de un viaje del horario.
    public const TRIP_PREFIX = 'rt:';
    private const MIN_STOPS = 2;

    // Los refuerzos de la red en curso (Config::set) vistos en esas paradas, uno por tren.
    public static function atStops(string $sNetwork, array $aStopIds): array
    {
        $JourneyModel = new ServiceJourney(Database::connection());
        $iMidnight = Calendar::wallMidnight();
        $Matcher = Live::tripMatcher($JourneyModel, $iMidnight);
        if (!($Matcher instanceof PlatformMatcher)) {
            return [];
        }
        $iNow = Calendar::nowSecondsSinceMidnight();
        $aSegments = null;
        $aExtras = [];
        foreach ($aStopIds as $StopId) {
            foreach ($Matcher->unscheduledRows((string)$StopId) as $aRow) {
                $iKey = $iMidnight + (int)$aRow['etaSeconds'];
                $aTrain = $Matcher->unscheduledTrain((string)$StopId, $iKey, $aRow['platform_stop_id'] ?? null);
                if ($aTrain === null) {
                    continue;
                }
                $sTrip = self::tripOf($aTrain, (string)$StopId, $iKey);
                if (isset($aExtras[$sTrip])) {
                    continue;
                }
                $aSegments ??= (new StopArrivalTrips(Config::current(), $JourneyModel))->segments();
                $aTimes = ExtraSheet::timesAlong($aTrain['sequence'], $aTrain['stops'], $aSegments);
                $aExtra = self::extraOf($sNetwork, (string)$aRow['line_id'], $sTrip, $aTrain, $aTimes, $iMidnight, $iNow);
                if ($aExtra !== null) {
                    $aExtras[$sTrip] = $aExtra;
                }
            }
        }
        return \array_slice(array_values($aExtras), 0, self::MAX_EXTRAS);
    }

    // El id del tren en el feed o, si no lo trae, uno hecho con la parada y la hora en que se vio.
    private static function tripOf(array $aTrain, string $sStopId, int $iKey): string
    {
        if (($aTrain['trip_id'] ?? '') !== '') {
            return self::TRIP_PREFIX . $aTrain['trip_id'];
        }
        return self::TRIP_PREFIX . $sStopId . '@' . $iKey;
    }

    // Un tren sin horario como viaje extra del núcleo: sus paradas en el orden de su recorrido con la hora de cada una
    // ($aTimes, hora unix) en segundos desde la medianoche $iMidnight. Null si no pasa por ninguna entre ahora ($iNow,
    // segundos del día) y AHEAD_SECONDS después, o si no se sabe la hora de dos paradas de su recorrido.
    public static function extraOf(string $sNetwork, string $sLine, string $sTrip, array $aTrain, array $aTimes, int $iMidnight, int $iNow): array|null
    {
        $aStops = [];
        $bSoon = false;
        foreach ($aTrain['sequence'] as $StopId) {
            if (!isset($aTimes[(string)$StopId])) {
                continue;
            }
            $iSeconds = $aTimes[(string)$StopId] - $iMidnight;
            $bSoon = $bSoon || ($iSeconds >= $iNow && $iSeconds <= $iNow + self::AHEAD_SECONDS);
            $aStops[] = ['stop' => (string)$StopId, 'arrive' => $iSeconds, 'depart' => $iSeconds];
        }
        if (!$bSoon || \count($aStops) < self::MIN_STOPS) {
            return null;
        }
        return ['network' => $sNetwork, 'line' => $sLine, 'trip' => $sTrip, 'headsign' => (string)($aTrain['headsign'] ?? ''), 'stops' => $aStops];
    }
}
