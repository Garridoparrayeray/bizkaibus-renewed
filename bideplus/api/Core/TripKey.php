<?php

namespace Core;

// Clave de un viaje: línea, número de viaje y primera salida en segundos («3514-12-27600»). Es la que usan
// la ficha del viaje, los enlaces compartidos y los avisos de llegada. Un guion dentro del número de viaje (Tuvisa:
// «L1S1_001-DOM») va como «~», para que la clave se pueda volver a partir.
class TripKey
{
    private const TRIP_DASH = '~';

    public static function build(string|int $LineId, string $sTripNumber, int $iFirstDepartureSeconds): string
    {
        return $LineId . '-' . str_replace('-', self::TRIP_DASH, $sTripNumber) . '-' . $iFirstDepartureSeconds;
    }

    public static function parse(string $sKey): array|null
    {
        if (!preg_match('/^(.+)-([^-]+)-(\d+)$/', $sKey, $aMatches)) {
            return null;
        }
        return [$aMatches[1], str_replace(self::TRIP_DASH, '-', $aMatches[2]), (int)$aMatches[3]];
    }
}
