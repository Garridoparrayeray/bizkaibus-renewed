<?php

namespace Services;

// Quita las salidas que ya pasaron: con un margen corto si iban en directo y algo más si solo eran horario. Lo
// comparten los dos emparejadores de horas previstas: TripUpdatesMatcher (por paradas) y TripDelayMatcher (por viaje).
trait DepartedRows
{
    public const LIVE_DEPARTED_AFTER_SECONDS = 45;
    private const SCHEDULED_DEPARTED_AFTER_SECONDS = 60;

    public function withoutDeparted(array $aRows, int $iNowSeconds): array
    {
        return array_values(array_filter($aRows, function ($aRow) use ($iNowSeconds) {
            $iGrace = self::SCHEDULED_DEPARTED_AFTER_SECONDS;
            if (($aRow['status'] ?? 'scheduled') === 'live') {
                $iGrace = self::LIVE_DEPARTED_AFTER_SECONDS;
            }
            return (int)$aRow['etaSeconds'] - $iNowSeconds >= -$iGrace;
        }));
    }
}
