<?php

namespace Services;

/*
 * Rango de fechas del horario que el operador ha publicado de verdad: del primer al último día que cubre su GTFS.
 * Se toma de feed_info (feed_start_date y feed_end_date) cuando el operador la da; si no, de los calendarios
 * (de la primera fecha de inicio a la última de fin) y, si tampoco, de las fechas sueltas de service_calendar_exceptions.
 * Fuera de ese rango la app solo puede suponer que el día se parece a uno normal.
 */
class ScheduleRange
{
    /** @return array{from: string|null, to: string|null} fechas «Y-m-d» */
    public static function forDatabase(\PDO $Pdo): array
    {
        $aMeta = [];
        foreach ($Pdo->query("SELECT key, value FROM meta WHERE key IN ('feed_start_date', 'feed_end_date')")->fetchAll() as $aRow) {
            $aMeta[$aRow['key']] = (string)$aRow['value'];
        }
        $sFrom = self::validDate($aMeta['feed_start_date'] ?? null);
        $sTo = self::validDate($aMeta['feed_end_date'] ?? null);

        if ($sFrom === null || $sTo === null) {
            $aCalendars = $Pdo->query("SELECT MIN(NULLIF(from_date, '')) AS first_day, MAX(NULLIF(to_date, '')) AS last_day FROM service_calendars")->fetch();
            $sFrom ??= self::validDate($aCalendars['first_day'] ?? null);
            $sTo ??= self::validDate($aCalendars['last_day'] ?? null);
        }
        if ($sFrom === null || $sTo === null) {
            $aDates = $Pdo->query('SELECT MIN(date) AS first_day, MAX(date) AS last_day FROM service_calendar_exceptions WHERE available = 1')->fetch();
            $sFrom ??= self::validDate($aDates['first_day'] ?? null);
            $sTo ??= self::validDate($aDates['last_day'] ?? null);
        }
        return ['from' => $sFrom, 'to' => $sTo];
    }

    private static function validDate(mixed $mValue): string|null
    {
        if (!is_string($mValue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mValue)) {
            return null;
        }
        return $mValue;
    }
}
