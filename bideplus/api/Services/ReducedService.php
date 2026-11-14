<?php

namespace Services;

use Controllers\AlertsController;
use Core\Cache;
use Core\Config;
use Core\Database;
use Core\Live;
use Models\Search;
use Models\ServiceJourney;

/*
 * Servicio reducido de la red (huelgas, servicios mínimos), por día: por los avisos (palabras y fechas de
 * api/Config/reduced-service.php) y, hoy, por el directo si falta buena parte de los viajes que tocaban (umbrales en
 * reduced_service.live de la ficha, solo redes con directo fiable). Lo dan /api/alerts, /api/status y el planificador.
 */
class ReducedService
{
    private const SAMPLES_FILE = '/bizkaibusplus_reduced_samples_';
    // Las salidas que se miran en cada parada de muestra.
    private const ROWS_PER_STOP = 40;
    private const STOPS_CACHE_SECONDS = 86400;
    // Lo que une dos días sueltos en un aviso («17 y 18 de noviembre»); cualquier otra unión es un rango.
    private const SEPARATE_DAYS = ['y', 'eta', 'et', ','];
    // Sin nada que decir (o si no se ha podido calcular).
    private const NONE = ['today' => false, 'days' => [], 'sources' => [], 'alert' => null];

    // El estado de hoy y los días que vienen de la red actual: today, days (Y-m-d), sources (alerts, live), alert.
    public static function current(): array
    {
        $aConfig = Config::current();
        $aRules = self::rules();
        $aOut = Cache::remember('reduced_' . $aConfig['slug'], $aRules['cache_seconds'], function () use ($aConfig, $aRules) {
            $Today = new \DateTimeImmutable(Calendar::todayMadrid()->format('Y-m-d'));
            $aAlerts = self::fromAlerts((new AlertsController())->alerts(null, null), $aRules, $Today);
            return self::verdict($aAlerts, self::observedToday($aConfig), $Today->format('Y-m-d'));
        });
        return array_merge(self::NONE, $aOut);
    }

    // Junta lo de los avisos y lo del directo en lo que ve la app.
    public static function verdict(array $aAlerts, bool $bObserved, string $sToday): array
    {
        $aSources = [];
        if (\in_array($sToday, $aAlerts['days'], true)) {
            $aSources[] = 'alerts';
        }
        if ($bObserved) {
            $aSources[] = 'live';
        }
        $aDays = $aAlerts['days'];
        if ($bObserved && !\in_array($sToday, $aDays, true)) {
            array_unshift($aDays, $sToday);
        }
        return ['today' => !empty($aSources), 'days' => $aDays, 'sources' => $aSources, 'alert' => $aAlerts['alert']];
    }

    public static function rules(): array
    {
        return require __DIR__ . '/../Config/reduced-service.php';
    }

    // Los días con servicio reducido según los avisos (de hoy en adelante) y el título del primero que lo dice.
    public static function fromAlerts(array $aAlerts, array $aRules, \DateTimeImmutable $Today): array
    {
        $aDays = [];
        $sAlert = null;
        foreach ($aAlerts as $aAlert) {
            $sText = trim(($aAlert['summary'] ?? $aAlert['title'] ?? '') . ' ' . ($aAlert['description'] ?? ''));
            if (!self::speaksOfReduced(Search::normalize($sText), $aRules)) {
                continue;
            }
            $aFound = self::datesIn(Search::normalize($sText), $aRules['months'], $Today, $aRules['horizon_days']);
            if (empty($aFound) && !self::startsLater($aAlert, $Today)) {
                $aFound = [$Today->format('Y-m-d')];
            }
            $sLast = $Today->modify('+' . $aRules['horizon_days'] . ' days')->format('Y-m-d');
            $aFound = array_filter($aFound, fn(string $sDay) => $sDay >= $Today->format('Y-m-d') && $sDay <= $sLast);
            if (!empty($aFound)) {
                $sAlert ??= $sText;
            }
            array_push($aDays, ...$aFound);
        }
        $aDays = array_values(array_unique($aDays));
        sort($aDays);
        return ['days' => $aDays, 'alert' => $sAlert];
    }

    public static function speaksOfReduced(string $sNormalized, array $aRules): bool
    {
        foreach (array_merge(...array_values($aRules['exclude'])) as $sWord) {
            if (str_contains($sNormalized, $sWord)) {
                return false;
            }
        }
        foreach (array_merge(...array_values($aRules['keywords'])) as $sWord) {
            if (str_contains($sNormalized, $sWord)) {
                return true;
            }
        }
        return false;
    }

    // Un aviso sin fechas en el texto que empieza otro día: no dice nada de hoy.
    private static function startsLater(array $aAlert, \DateTimeImmutable $Today): bool
    {
        if (empty($aAlert['startTime'])) {
            return false;
        }
        $Start = (new \DateTimeImmutable($aAlert['startTime']))->setTimezone(new \DateTimeZone('Europe/Madrid'));
        return $Start->format('Y-m-d') > $Today->format('Y-m-d');
    }

    /*
     * Las fechas de un texto ya normalizado (un rango, de $iHorizon días como mucho): «17 de noviembre», «del 17 al 19
     * de noviembre», «17 y 18 de noviembre», «azaroaren 17an», «17 novembre», «17/11» y «du 17/11 au 19/11». Sin año,
     * el de la fecha más cercana a hoy.
     */
    public static function datesIn(string $sText, array $aMonths, \DateTimeImmutable $Today, int $iHorizon): array
    {
        $sText = preg_replace('#https?://\S+#', ' ', $sText);
        $aRanges = [];
        foreach ($aMonths as $aNames) {
            $sNames = implode('|', $aNames);
            preg_match_all('/(?<!\d)(\d{1,2})(?:\s*(al|au|a|-|y|et|,)\s*(\d{1,2}))?\s+(?:de\s+)?(' . $sNames . ')\w*(?:\s+(?:de\s+)?(\d{4}))?/u', $sText, $aMatches, PREG_SET_ORDER);
            foreach ($aMatches as $aM) {
                $iMonth = array_search($aM[4], $aNames, true) + 1;
                $aRanges[] = [[(int)$aM[1], $iMonth, $aM[5] ?? ''], [self::secondDay($aM, 3, 1), $iMonth, $aM[5] ?? ''], $aM[2]];
            }
            preg_match_all('/\b(' . $sNames . ')\w*\s+(\d{1,2})(?!\d)(?:\w*?(\s*-\s*|\s+eta\s+|e?tik\s+)(\d{1,2}))?/u', $sText, $aMatches, PREG_SET_ORDER);
            foreach ($aMatches as $aM) {
                $iMonth = array_search($aM[1], $aNames, true) + 1;
                $aRanges[] = [[(int)$aM[2], $iMonth, ''], [self::secondDay($aM, 4, 2), $iMonth, ''], trim($aM[3] ?? '')];
            }
        }
        preg_match_all('#(?<![\d/])(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?(?![\d/])(?:\s*(?:al|au|a|-)\s*(\d{1,2})/(\d{1,2})(?:/(\d{2,4}))?)?#', $sText, $aMatches, PREG_SET_ORDER);
        foreach ($aMatches as $aM) {
            $aEnd = [(int)$aM[1], (int)$aM[2], $aM[3] ?? ''];
            if (!empty($aM[4])) {
                $aEnd = [(int)$aM[4], (int)$aM[5], $aM[6] ?? ''];
            }
            $aRanges[] = [[(int)$aM[1], (int)$aM[2], $aM[3] ?? ''], $aEnd, '-'];
        }
        return self::daysOf($aRanges, $Today, $iHorizon);
    }

    // El día del final de un rango («del 17 al 19»), o el del principio si el texto da uno solo.
    private static function secondDay(array $aMatch, int $iSecond, int $iFirst): int
    {
        if (($aMatch[$iSecond] ?? '') === '') {
            return (int)$aMatch[$iFirst];
        }
        return (int)$aMatch[$iSecond];
    }

    // [inicio, fin, unión] => fechas: «y», «eta», «et» y «,» son dos días sueltos; lo demás, del inicio al fin.
    private static function daysOf(array $aRanges, \DateTimeImmutable $Today, int $iHorizon): array
    {
        $aDays = [];
        foreach ($aRanges as [$aFrom, $aTo, $sJoin]) {
            $From = self::dateOf($aFrom, $Today);
            $To = self::dateOf($aTo, $Today);
            if ($From === null || $To === null) {
                continue;
            }
            $aPicked = [$From, $To];
            if (!\in_array($sJoin, self::SEPARATE_DAYS, true)) {
                $aPicked = [];
                for ($Day = $From; $Day <= $To && \count($aPicked) <= $iHorizon; $Day = $Day->modify('+1 day')) {
                    $aPicked[] = $Day;
                }
            }
            foreach ($aPicked as $Day) {
                $aDays[] = $Day->format('Y-m-d');
            }
        }
        return array_values(array_unique($aDays));
    }

    private static function dateOf(array $aParts, \DateTimeImmutable $Today): \DateTimeImmutable|null
    {
        [$iDay, $iMonth, $sYear] = $aParts;
        $iYear = (int)$Today->format('Y');
        if ($sYear !== '') {
            $iYear = (int)$sYear + 2000 * (int)(\strlen($sYear) === 2);
        } elseif ($iMonth * 31 + $iDay < (int)$Today->format('n') * 31 + (int)$Today->format('j') - 183) {
            $iYear++;
        }
        if (!checkdate($iMonth, $iDay, $iYear)) {
            return null;
        }
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $iYear, $iMonth, $iDay));
    }

    // Lo que dice el directo de hoy: una muestra nueva y si las de la última ventana confirman que falta servicio.
    private static function observedToday(array $aConfig): bool
    {
        $aLive = $aConfig['reduced_service']['live'] ?? null;
        if ($aLive === null || !empty($aConfig['live_often_empty'])) {
            return false;
        }
        $iNow = time();
        $sFile = sys_get_temp_dir() . self::SAMPLES_FILE . $aConfig['slug'] . '.json';
        $aSamples = json_decode((string)@file_get_contents($sFile), true);
        if (!\is_array($aSamples)) {
            $aSamples = [];
        }
        try {
            $aSamples = self::withSample($aSamples, self::sample($aConfig, $aLive), $iNow, $aLive);
        } catch (\Throwable $Ex) {
            return false;
        }
        file_put_contents($sFile, json_encode($aSamples), LOCK_EX);
        return self::confirmed($aSamples, $iNow, $aLive);
    }

    // Añade una muestra ([expected, missing], o null con el feed vacío, que borra la serie) y quita las viejas.
    public static function withSample(array $aSamples, array|null $aSample, int $iNow, array $aLive): array
    {
        if ($aSample === null) {
            return [];
        }
        $aKept = array_values(array_filter($aSamples, fn(array $aOne) => $aOne[0] > $iNow - 2 * $aLive['confirm_seconds']));
        $aKept[] = [$iNow, $aSample[0], $aSample[1]];
        return $aKept;
    }

    /*
     * Falta servicio si hay muestras desde hace al menos confirm_seconds, todas desde entonces con min_expected viajes
     * que tocaban o más y una fracción de ellos sin pasar de missing_share o más. Una sola muestra o un hueco del feed
     * no bastan.
     */
    public static function confirmed(array $aSamples, int $iNow, array $aLive): bool
    {
        $aOld = array_filter($aSamples, fn(array $aOne) => $aOne[0] <= $iNow - $aLive['confirm_seconds']);
        if (empty($aOld)) {
            return false;
        }
        $iFrom = max(array_column($aOld, 0));
        foreach ($aSamples as [$iAt, $iExpected, $iMissing]) {
            if ($iAt >= $iFrom && ($iExpected < $aLive['min_expected'] || $iMissing < $iExpected * $aLive['missing_share'])) {
                return false;
            }
        }
        return true;
    }

    // [viajes que tocaban, cuántos no se ven], o null si el directo llega vacío (un fallo del feed, no una huelga).
    private static function sample(array $aConfig, array $aLive): array|null
    {
        $JourneyModel = new ServiceJourney(Database::connection());
        if (Config::hasLive('trip-updates')) {
            $TripMatcher = Live::tripMatcher($JourneyModel);
            if ($TripMatcher === null || !$TripMatcher->hasData()) {
                return null;
            }
            return self::missingAtStops($JourneyModel, $TripMatcher, self::sampleStops($aConfig, $aLive), $aLive['window_seconds'], Calendar::nowSecondsSinceMidnight());
        }
        $iVehicles = 0;
        foreach (Live::vehicles() as $aEntries) {
            $iVehicles += \count(array_filter($aEntries, fn(array $aEntry) => Live::isFresh([[$aEntry]])));
        }
        if ($iVehicles === 0) {
            return null;
        }
        $iRunning = $JourneyModel->runningCount();
        return [$iRunning, max(0, $iRunning - $iVehicles)];
    }

    /*
     * En las paradas de muestra, las salidas de los próximos $iWindow segundos cuyo viaje ya ha salido de cabecera
     * según el horario (uno que aún no ha salido puede no estar en el feed) y cuántas no salen en directo: quitadas por
     * el emparejado, sin dato o canceladas.
     */
    public static function missingAtStops(ServiceJourney $JourneyModel, object $TripMatcher, array $aStops, int $iWindow, int $iNow): array
    {
        $iExpected = 0;
        $iMissing = 0;
        foreach ($aStops as $sStopId) {
            $aRows = (new ScheduleOnlyMatcher())->enrich(array_values(array_filter(
                $JourneyModel->upcomingAtStop($sStopId, self::ROWS_PER_STOP, $iWindow),
                fn(array $aRow) => (int)$aRow['first_departure_seconds'] <= $iNow && (int)$aRow['arrival_seconds'] >= $iNow
            )));
            $aLive = [];
            foreach ($TripMatcher->enrich($sStopId, $aRows) as $aRow) {
                if (($aRow['status'] ?? '') === 'live' && empty($aRow['unscheduled'])) {
                    $aLive[$aRow['service_journey_id'] . '|' . $aRow['arrival_seconds']] = true;
                }
            }
            foreach ($aRows as $aRow) {
                $iExpected++;
                $iMissing += (int)!isset($aLive[$aRow['service_journey_id'] . '|' . $aRow['arrival_seconds']]);
            }
        }
        return [$iExpected, $iMissing];
    }

    // Las paradas con más pasos de la red, sacadas de su base una vez al día.
    private static function sampleStops(array $aConfig, array $aLive): array
    {
        return Cache::remember('reduced_stops_' . $aConfig['slug'], self::STOPS_CACHE_SECONDS, function () use ($aLive) {
            $Stmt = Database::connection()->prepare('SELECT stop_id FROM passing_times GROUP BY stop_id ORDER BY COUNT(*) DESC LIMIT ?');
            $Stmt->execute([$aLive['sample_stops']]);
            return array_map('strval', $Stmt->fetchAll(\PDO::FETCH_COLUMN));
        });
    }
}
