<?php

namespace Services;

class Calendar
{
    public static function todayMadrid(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
    }

    public static function nowSecondsSinceMidnight(): int
    {
        $now = self::todayMadrid();
        return ((int)$now->format('H')) * 3600 + ((int)$now->format('i')) * 60 + (int)$now->format('s');
    }

    /** Día de la semana ISO (1=lunes..7=domingo) como bit de máscara, igual que service_calendars.weekday_mask. */
    public static function todayWeekdayBit(): int
    {
        return self::weekdayBitFor(self::todayMadrid());
    }

    public static function weekdayBitFor(\DateTime $date): int
    {
        $iso = (int)$date->format('N');
        return 1 << ($iso - 1);
    }

    /** Segundos desde medianoche (puede superar 86400 en viajes que cruzan medianoche) a "HH:MM". */
    public static function secondsToHm(int $seconds): string
    {
        $h = intdiv($seconds, 3600) % 24;
        $m = intdiv($seconds % 3600, 60);
        return sprintf('%02d:%02d', $h, $m);
    }
}
