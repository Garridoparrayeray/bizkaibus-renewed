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
        $Now = self::todayMadrid();
        return ((int)$Now->format('H')) * 3600 + ((int)$Now->format('i')) * 60 + (int)$Now->format('s');
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

    public static function secondsToHm(int $iSeconds): string
    {
        $iH = intdiv($iSeconds, 3600) % 24;
        $iM = intdiv($iSeconds % 3600, 60);
        return sprintf('%02d:%02d', $iH, $iM);
    }
}
