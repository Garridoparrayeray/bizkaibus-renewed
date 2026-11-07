<?php

namespace Services;

use Core\Ids;

// Lo común a la ficha de un viaje (/api/vehicles/{tripKey}): su estado, su forma y cada parada con su llegada prevista.
class TripSheet
{
    // Antes de su hora prevista se da la parada por pasada solo si han pasado más de 60 s (sin datos en directo).
    public const PASSED_MARGIN_SECONDS = 60;
    // De madrugada, la ficha de un viaje de ayer que pasa de las 24:00 sigue siendo la de ayer hasta 10 min después de
    // su última parada (luego, la del de hoy, que sale esta noche).
    public const LATE_GRACE_SECONDS = 10 * 60;

    // Con datos en vivo es «live». Sin ellos solo se sabe lo que dice el horario: no se afirma que el vehículo ya salió
    // (la hora pasada no lo prueba), solo que el viaje terminó si ya pasó su última parada. Si no, «scheduled».
    public static function status(bool $bLive, array $aStops, int $iNowSeconds): string
    {
        if ($bLive) {
            return 'live';
        }
        if (!empty($aStops) && $iNowSeconds > (int)$aStops[count($aStops) - 1]['arrival_seconds']) {
            return 'finished';
        }
        return 'scheduled';
    }

    // $aExtra va tras el vehículo (con horas del feed, feedTimes).
    public static function sheet(array $aJourney, string $sStatus, string|null $sVehicleRef, int|null $iDelayMinutes, array $aStops, array $aExtra = []): array
    {
        return [
            'lineCode' => $aJourney['line_code'],
            'lineName' => $aJourney['line_name'],
            'headsign' => $aJourney['headsign'],
            'status' => $sStatus,
            'vehicleRef' => $sVehicleRef,
        ] + $aExtra + [
            'delayMinutes' => $iDelayMinutes,
            'stops' => $aStops,
        ];
    }

    // Una parada de la ficha. $iEta es la llegada prevista en segundos desde medianoche (null si ya pasó).
    public static function stopEntry(array $aStop, int|null $iEta, bool $bPast, bool $bCurrent, int $iNow): array
    {
        $iEtaMinutes = null;
        $iEtaAt = null;
        if ($iEta !== null) {
            $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
            $iEtaAt = time() + ($iEta - $iNow);
        }
        return [
            'stopId' => Ids::forOutput($aStop['stop_id']),
            'name' => $aStop['name'],
            'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
            'etaMinutes' => $iEtaMinutes,
            'etaAt' => $iEtaAt,
            'isPast' => $bPast,
            'isCurrent' => $bCurrent,
        ];
    }
}
