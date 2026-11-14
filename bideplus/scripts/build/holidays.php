<?php
// Festivos de una red para build-database.php: los del calendario laboral oficial en datos abiertos y, si no se puede
// bajar, los que salen de las bases de las redes de respaldo (Calendars::referenceHolidays).

declare(strict_types=1);

namespace Build;

use DateTime;

class Holidays
{
    private const SUNDAY_WEEKDAY = 7;

    // Los festivos de lunes a sábado desde $sFrom, este año y el que viene: del calendario oficial de gtfs.holidays o,
    // el año que no está, de las redes de respaldo, con un aviso. $Fetch baja una dirección (null si falla); $aConfig,
    // la de api/Config/holidays.php (otra en las pruebas).
    public static function forNetwork(string $sSlug, array $aSettings, string $sFrom, callable|null $Fetch = null, array|null $aConfig = null): array
    {
        $aConfig ??= require __DIR__ . '/../../api/Config/holidays.php';
        $aCalendar = $aConfig['calendars'][$aSettings['calendar']];
        $Fetch ??= fn(string $sUrl) => self::download($sUrl, $aConfig['http_timeout_seconds']);
        $iYear = (int)substr($sFrom, 0, 4);
        $aHolidays = [];
        foreach ([$iYear, $iYear + 1] as $iOne) {
            $aRows = self::calendarRows($aSettings['calendar'], $aCalendar, $iOne, $aConfig['cache_seconds'], $Fetch);
            if ($aRows !== null) {
                array_push($aHolidays, ...self::fromCalendar($aRows, $aCalendar, $aSettings['territory'], $aSettings['municipality'], $sFrom));
                continue;
            }
            fwrite(STDERR, "AVISO: sin el calendario laboral de $iOne; los festivos de ese año salen de las redes de respaldo\n");
            $aFallback = self::fallback($sSlug, $aSettings['fallback'], $sFrom);
            array_push($aHolidays, ...array_filter($aFallback, fn(string $sDate) => str_starts_with($sDate, $iOne . '-')));
        }
        $aHolidays = array_values(array_unique($aHolidays));
        sort($aHolidays);
        return $aHolidays;
    }

    // Los de toda la comunidad, los del territorio de la red y, si la red es de un municipio, los de ese municipio.
    // Los de otros municipios no: no valen para toda la red.
    public static function fromCalendar(array $aRows, array $aCalendar, string|null $sTerritory, string|null $sMunicipality, string $sFrom): array
    {
        $aDates = [];
        foreach ($aRows as $aRow) {
            $sRowTerritory = trim((string)($aRow[$aCalendar['territory']] ?? ''));
            $sPlace = trim((string)($aRow[$aCalendar['municipality']] ?? ''));
            $bApplies = $sRowTerritory === $aCalendar['everywhere']
                || ($sTerritory !== null && $sRowTerritory === $sTerritory && ($sPlace === $sTerritory || $sPlace === $sMunicipality));
            $Date = DateTime::createFromFormat('!Y/n/j', trim((string)($aRow[$aCalendar['date']] ?? '')));
            if (!$bApplies || $Date === false) {
                continue;
            }
            if ($Date->format('Y-m-d') >= $sFrom && (int)$Date->format('N') !== self::SUNDAY_WEEKDAY) {
                $aDates[] = $Date->format('Y-m-d');
            }
        }
        return array_values(array_unique($aDates));
    }

    // El calendario de un año: lo guardado si es reciente; si no, lo bajado; si no se puede, lo guardado aunque sea
    // viejo; null si no hay nada.
    private static function calendarRows(string $sName, array $aCalendar, int $iYear, int $iCacheSeconds, callable $Fetch): array|null
    {
        $sFile = sys_get_temp_dir() . '/bizkaibusplus_holidays_' . $sName . '_' . $iYear . '.json';
        $aCached = self::rowsOf((string)@file_get_contents($sFile));
        if ($aCached !== null && time() - filemtime($sFile) < $iCacheSeconds) {
            return $aCached;
        }
        $sBody = (string)$Fetch(str_replace('{year}', (string)$iYear, $aCalendar['url']));
        $aRows = self::rowsOf($sBody);
        if ($aRows !== null) {
            file_put_contents($sFile, $sBody);
            return $aRows;
        }
        return $aCached;
    }

    private static function rowsOf(string $sBody): array|null
    {
        $aRows = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $sBody), true);
        if (!\is_array($aRows) || empty($aRows) || !array_is_list($aRows)) {
            return null;
        }
        return $aRows;
    }

    // De las bases de las redes de respaldo, sin la propia (sus festivos puestos por aquí se votarían a sí mismos).
    private static function fallback(string $sSlug, array $aFallback, string $sFrom): array
    {
        $aPaths = [];
        foreach (array_diff($aFallback['networks'], [$sSlug]) as $sNetwork) {
            $sPath = \Core\Networks::get($sNetwork)['db_path'];
            if (is_file($sPath)) {
                $aPaths[] = $sPath;
            } else {
                fwrite(STDERR, "AVISO: falta la base de $sNetwork, red de respaldo de los festivos\n");
            }
        }
        if (\count($aPaths) < $aFallback['quorum']) {
            fwrite(STDERR, "AVISO: no hay bastantes redes de respaldo para sacar los festivos; no se pone ninguno\n");
            return [];
        }
        return Calendars::referenceHolidays($aPaths, $aFallback['quorum'], $sFrom);
    }

    private static function download(string $sUrl, int $iTimeout): string|null
    {
        $Ch = curl_init($sUrl);
        curl_setopt_array($Ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $iTimeout, CURLOPT_FAILONERROR => true]);
        $sBody = curl_exec($Ch);
        if (!\is_string($sBody)) {
            return null;
        }
        return $sBody;
    }
}
