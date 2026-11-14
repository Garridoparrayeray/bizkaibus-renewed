<?php

namespace Services;

// Fechas y horas en hora de Madrid: hoy, segundos desde medianoche y el bit del día de la semana.
class Calendar
{
    public const DAY_SECONDS = 86400;
    public const TIME_ZONE = 'Europe/Madrid';

    public static function todayMadrid(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone(self::TIME_ZONE));
    }

    public static function yesterdayMadrid(): \DateTime
    {
        return self::todayMadrid()->modify('-1 day');
    }

    // La medianoche del día de $iNow (por defecto, hoy) por el reloj de pared: esa hora menos los segundos desde las
    // 00:00. El día del cambio de hora no es la medianoche real (DateTime('today')), pero cuadra con las horas del
    // horario, que son de pared.
    public static function wallMidnight(int|null $iNow = null): int
    {
        $iNow ??= time();
        return $iNow - self::secondsSinceMidnight((new \DateTime('@' . $iNow))->setTimezone(new \DateTimeZone(self::TIME_ZONE)));
    }

    public static function nowSecondsSinceMidnight(): int
    {
        return self::secondsSinceMidnight(self::todayMadrid());
    }

    private static function secondsSinceMidnight(\DateTime $Time): int
    {
        return ((int)$Time->format('H')) * 3600 + ((int)$Time->format('i')) * 60 + (int)$Time->format('s');
    }

    public static function todayWeekdayBit(): int
    {
        return self::weekdayBitFor(self::todayMadrid());
    }

    public static function weekdayBitFor(\DateTime $Date): int
    {
        $iIso = (int)$Date->format('N');
        return 1 << ($iIso - 1);
    }

    // «HH:MM» → segundos desde medianoche. Lo que no tenga esa forma cuenta como 0.
    public static function hmToSeconds(string $sHm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $sHm, $aMatch)) {
            return 0;
        }
        return ((int)$aMatch[1]) * 3600 + ((int)$aMatch[2]) * 60;
    }

    public static function secondsToHm(int $iSeconds): string
    {
        $iH = intdiv($iSeconds, 3600) % 24;
        $iM = intdiv($iSeconds % 3600, 60);
        return sprintf('%02d:%02d', $iH, $iM);
    }
}
