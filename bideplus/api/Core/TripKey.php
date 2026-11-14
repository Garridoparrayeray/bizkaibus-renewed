<?php

namespace Core;

// Clave de un viaje: línea, número de viaje y primera salida en segundos («3514-12-27600»). Es la que usan
// la ficha del viaje, los enlaces compartidos y los avisos de llegada. Un guion dentro del número de viaje (Tuvisa:
// «L1S1_001-DOM») va como «~», para que la clave se pueda volver a partir.
class TripKey
{
    private const TRIP_DASH = '~';
    // Un tren que el horario no tiene (refuerzo) no tiene número de viaje: su clave es la parada, su andén si se sabe
    // (la parada siguiente) y la hora a la que llega («extra-8@7-1794410000»); la ficha lo vuelve a buscar en el feed.
    public const EXTRA_LINE = 'extra';
    private const PLATFORM_MARK = '@';

    public static function build(string|int $LineId, string $sTripNumber, int $iFirstDepartureSeconds): string
    {
        return $LineId . '-' . str_replace('-', self::TRIP_DASH, $sTripNumber) . '-' . $iFirstDepartureSeconds;
    }

    public static function buildExtra(string $sStopId, int $iArrivalTime, string|null $sPlatformStopId = null): string
    {
        $sStop = $sStopId;
        if ($sPlatformStopId !== null) {
            $sStop .= self::PLATFORM_MARK . $sPlatformStopId;
        }
        return self::EXTRA_LINE . '-' . $sStop . '-' . $iArrivalTime;
    }

    // [parada, hora de llegada, andén o null] si es la clave de un refuerzo, o null.
    public static function parseExtra(string $sKey): array|null
    {
        $aKey = self::parse($sKey);
        if ($aKey === null || $aKey[0] !== self::EXTRA_LINE) {
            return null;
        }
        $aStop = explode(self::PLATFORM_MARK, $aKey[1], 2);
        return [$aStop[0], $aKey[2], $aStop[1] ?? null];
    }

    public static function parse(string $sKey): array|null
    {
        if (!preg_match('/^(.+)-([^-]+)-(\d+)$/', $sKey, $aMatches)) {
            return null;
        }
        return [$aMatches[1], str_replace(self::TRIP_DASH, '-', $aMatches[2]), (int)$aMatches[3]];
    }
}
