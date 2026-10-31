<?php

namespace Core;

// Clave de un viaje: línea, número de viaje y primera salida en segundos («3514-12-27600»). Es la que usan
// la ficha del viaje, los enlaces compartidos y los avisos de llegada.
class TripKey
{
    public static function build(string|int $LineId, string $sTripNumber, int $iFirstDepartureSeconds): string
    {
        return $LineId . '-' . $sTripNumber . '-' . $iFirstDepartureSeconds;
    }

    public static function parse(string $sKey): array|null
    {
        if (!preg_match('/^(.+)-([^-]+)-(\d+)$/', $sKey, $aMatches)) {
            return null;
        }
        return [$aMatches[1], $aMatches[2], (int)$aMatches[3]];
    }
}
