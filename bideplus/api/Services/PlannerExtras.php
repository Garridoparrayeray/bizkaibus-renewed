<?php

namespace Services;

use Core\Config;
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
    // La flota de la red, en la ficha (accessibility.fleet): con «all», todos sus vehículos son accesibles.
    public const FLEET_ALL = 'all';
    private const MIN_STOPS = 2;

    // Los refuerzos de la red en curso (Config::set) vistos en esas paradas, uno por tren, para el día de servicio
    // $iDay. Solo si su emparejador ($TripMatcher, de Live::tripMatcher) casa por andén: los demás no los dan.
    public static function atStops(string $sNetwork, ServiceJourney $JourneyModel, object|null $TripMatcher,
        array $aStopIds, int $iDay): array
    {
        if (!($TripMatcher instanceof PlatformMatcher)) {
            return [];
        }
        $iMidnight = Calendar::wallMidnight();
        $iNow = Calendar::nowSecondsSinceMidnight();
        $bAccessible = self::fleetAccessible(Config::current());
        $aSegments = null;
        $aExtras = [];
        $aSeen = [];
        foreach ($aStopIds as $StopId) {
            foreach ($TripMatcher->unscheduledRows((string)$StopId) as $aRow) {
                $iKey = $iMidnight + (int)$aRow['etaSeconds'];
                $aTrain = $TripMatcher->unscheduledTrain((string)$StopId, $iKey, $aRow['platform_stop_id'] ?? null);
                $sTrip = '';
                if ($aTrain !== null) {
                    $sTrip = self::tripOf($aTrain, (string)$StopId, $iKey);
                }
                if ($sTrip === '' || isset($aSeen[$sTrip])) {
                    continue;
                }
                $aSeen[$sTrip] = true;
                if (self::scheduled($JourneyModel, (string)($aTrain['trip_id'] ?? ''))) {
                    continue;
                }
                $aSegments ??= (new StopArrivalTrips(Config::current(), $JourneyModel))->segments();
                $aTimes = ExtraSheet::timesAlong($aTrain['sequence'], $aTrain['stops'], $aSegments);
                $sLine = (string)$aRow['line_id'];
                $aExtra = self::extraOf($sNetwork, $sLine, $sTrip, $aTrain, $aTimes, $iMidnight, $iNow, $iDay,
                    $bAccessible);
                if ($aExtra !== null) {
                    $aExtras[] = $aExtra;
                }
            }
        }
        return \array_slice($aExtras, 0, self::MAX_EXTRAS);
    }

    // Si el id que da el feed a un tren es el de un viaje del horario, por sí mismo o por su alias del GTFS: entonces
    // no es un refuerzo, es ese viaje (con su retraso), aunque el emparejado por andén no lo haya casado.
    public static function scheduled(ServiceJourney $JourneyModel, string $sTripId): bool
    {
        if ($sTripId === '') {
            return false;
        }
        if (isset($JourneyModel->journeyIdsForTripRefs([$sTripId])[$sTripId])) {
            return true;
        }
        return $JourneyModel->lineOfJourney($sTripId) !== null;
    }

    // Si todos los vehículos de la red son accesibles según su ficha (accessibility.fleet = all); null si no se sabe.
    public static function fleetAccessible(array $aConfig): bool|null
    {
        if (($aConfig['accessibility']['fleet'] ?? null) === self::FLEET_ALL) {
            return true;
        }
        return null;
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
    // ($aTimes, hora unix) en segundos desde la medianoche $iMidnight, sin las de hora negativa ni las que retroceden.
    // Null si no pasa por ninguna entre ahora ($iNow, segundos del día) y AHEAD_SECONDS después, o con menos de dos.
    public static function extraOf(string $sNetwork, string $sLine, string $sTrip, array $aTrain, array $aTimes,
        int $iMidnight, int $iNow, int|null $iDay = null, bool|null $bAccessible = null): array|null
    {
        $aStops = [];
        $bSoon = false;
        $iLast = 0;
        foreach ($aTrain['sequence'] as $StopId) {
            if (!isset($aTimes[(string)$StopId])) {
                continue;
            }
            $iSeconds = $aTimes[(string)$StopId] - $iMidnight;
            if ($iSeconds < $iLast) {
                continue;
            }
            $iLast = $iSeconds;
            $bSoon = $bSoon || ($iSeconds >= $iNow && $iSeconds <= $iNow + self::AHEAD_SECONDS);
            $aStops[] = ['stop' => (string)$StopId, 'arrive' => $iSeconds, 'depart' => $iSeconds];
        }
        if (!$bSoon || \count($aStops) < self::MIN_STOPS) {
            return null;
        }
        $sHeadsign = (string)($aTrain['headsign'] ?? '');
        $aExtra = ['network' => $sNetwork, 'line' => $sLine, 'trip' => $sTrip, 'headsign' => $sHeadsign];
        if ($iDay !== null) {
            $aExtra['day'] = $iDay;
        }
        if ($bAccessible !== null) {
            $aExtra['accessible'] = $bAccessible;
        }
        return $aExtra + ['stops' => $aStops];
    }
}
