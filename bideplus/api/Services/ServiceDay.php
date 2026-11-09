<?php

namespace Services;

// Días enteros (en segundos) que separan una hora de hoy de la del horario más cercana: 0 casi siempre, 24 h para las
// 02:00 de un viaje anotado a las 26:00. Lo usan TripDelayMatcher y VehicleDelays.
final class ServiceDay
{
    public const SECONDS = 86400;

    public static function shift(int $iScheduledSeconds, int $iTodaySeconds): int
    {
        return self::SECONDS * (int)round(($iScheduledSeconds - $iTodaySeconds) / self::SECONDS);
    }

    // Sin horas del directo, la referencia es la hora de ahora (solo con una medianoche de verdad: de 0 a 24 h).
    public static function shiftFromNow(int $iScheduledSeconds, int $iNowSeconds): int
    {
        if ($iNowSeconds < 0 || $iNowSeconds >= self::SECONDS) {
            return 0;
        }
        return self::shift($iScheduledSeconds, $iNowSeconds);
    }
}
