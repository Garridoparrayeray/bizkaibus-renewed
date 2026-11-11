<?php

namespace Core;

// Clave de un viaje: línea, número de viaje y primera salida en segundos («3514-12-27600»). Es la que usan
// la ficha del viaje, los enlaces compartidos y los avisos de llegada. Un guion dentro del número de viaje (Tuvisa:
// «L1S1_001-DOM») va como «~», para que la clave se pueda volver a partir.
class TripKey
{
    private const TRIP_DASH = '~';
    // Un tren que el horario no tiene (refuerzo) no tiene número de viaje: su clave es la parada y la hora a la que
    // llega («extra-8-1794410000»), y la ficha lo vuelve a buscar en el feed por esa hora.
    public const EXTRA_LINE = 'extra';

    public static function build(string|int $LineId, string $sTripNumber, int $iFirstDepartureSeconds): string
    {
        return $LineId . '-' . str_replace('-', self::TRIP_DASH, $sTripNumber) . '-' . $iFirstDepartureSeconds;
    }

    public static function buildExtra(string $sStopId, int $iArrivalTime): string
    {
        return self::EXTRA_LINE . '-' . $sStopId . '-' . $iArrivalTime;
    }

    // [parada, hora de llegada] si es la clave de un refuerzo, o null.
    public static function parseExtra(string $sKey): array|null
    {
        $aKey = self::parse($sKey);
        if ($aKey === null || $aKey[0] !== self::EXTRA_LINE) {
            return null;
        }
        return [$aKey[1], $aKey[2]];
    }

    public static function parse(string $sKey): array|null
    {
        if (!preg_match('/^(.+)-([^-]+)-(\d+)$/', $sKey, $aMatches)) {
            return null;
        }
        return [$aMatches[1], str_replace(self::TRIP_DASH, '-', $aMatches[2]), (int)$aMatches[3]];
    }
}
