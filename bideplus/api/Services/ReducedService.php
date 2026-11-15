<?php

namespace Services;

use Controllers\AlertsController;
use Core\Config;
use Core\Database;
use Core\Live;
use Core\Networks;
use Models\Search;
use Models\ServiceJourney;

/*
 * Servicio reducido de la red (huelgas, servicios mínimos), por día: por los avisos (palabras y fechas de
 * api/Config/reduced-service.php) y, hoy, por el directo si falta buena parte de los viajes que tocaban (umbrales en
 * reduced_service.live de la ficha, solo redes con directo fiable). Lo dan /api/reduced, /api/alerts y /api/status.
 */
class ReducedService
{
    private const STORE_FILE = '/bizkaibusplus_reduced_';
    // Sin nada que decir (o si no se ha podido calcular). lines: null es toda la red; si no, los ids de sus líneas (dos
    // pueden tener el mismo código: la C1 de Bilbao y la de Donostia), y lineCodes, sus códigos para el aviso.
    private const NONE = ['today' => false, 'days' => [], 'sources' => [], 'alert' => null, 'cause' => null, 'lines' => null, 'lineCodes' => null];
    private const CAUSE_STRIKE = 'strike';
    private const CAUSE_REDUCED = 'reduced';
    private const CAUSE_LIVE = 'live';
    // Las claves de la ficha donde va la dirección de los avisos de una red, para saber si otra red los comparte.
    private const ALERT_URL_KEYS = [['gtfs_rt', 'alerts_url'], ['siri', 'alerts_url'], ['siri', 'alerts_urls'], ['metro_alerts', 'url'], ['renfe_alerts', 'url']];

    // El estado de la red actual: lo guardado si es reciente; si no, se calcula (una petición a la vez: las demás se
    // quedan con lo guardado) y se guarda.
    public static function current(): array
    {
        $aConfig = Config::current();
        $aRules = self::rules();
        $sFile = sys_get_temp_dir() . self::STORE_FILE . $aConfig['slug'] . '.json';
        $aStored = self::readJson($sFile);
        if (isset($aStored['at']) && time() - $aStored['at'] < $aRules['cache_seconds']) {
            return array_merge(self::NONE, $aStored['value']);
        }
        $Lock = fopen($sFile . '.lock', 'c');
        if ($Lock === false || !flock($Lock, LOCK_EX | LOCK_NB)) {
            return array_merge(self::NONE, $aStored['value'] ?? []);
        }
        try {
            $aStored = self::computed($aConfig, $aRules, $aStored);
            self::writeJson($sFile, $aStored);
        } catch (\Throwable $Ex) {
            error_log('Bide+ servicio reducido (' . $aConfig['slug'] . '): ' . $Ex->getMessage());
        } finally {
            flock($Lock, LOCK_UN);
            fclose($Lock);
        }
        return array_merge(self::NONE, $aStored['value'] ?? []);
    }

    // Si la fuente de avisos no trae nada, vale lo último que trajo durante keep_last_good_seconds (un fallo pasajero
    // no quita el aviso).
    private static function computed(array $aConfig, array $aRules, array $aStored): array
    {
        $iNow = time();
        $Today = self::serviceDay($iNow, $aRules);
        $aAlerts = (new AlertsController())->alerts(null, null, true);
        $aVerdict = self::fromAlerts($aAlerts, $aRules, $Today, $iNow, self::networkLines(), self::sharesAlerts($aConfig));
        $iAlertsAt = $iNow;
        if (empty($aAlerts) && isset($aStored['alertsAt']) && $iNow - $aStored['alertsAt'] < $aRules['keep_last_good_seconds']) {
            $aVerdict = $aStored['alerts'];
            $aVerdict['days'] = array_values(array_filter($aVerdict['days'], fn(string $sDay) => $sDay >= $Today->format('Y-m-d')));
            $iAlertsAt = $aStored['alertsAt'];
        }
        $aValue = self::verdict($aVerdict, self::observedToday($aConfig), $Today->format('Y-m-d'));
        return ['at' => $iNow, 'value' => $aValue, 'alerts' => $aVerdict, 'alertsAt' => $iAlertsAt];
    }

    // Junta lo de los avisos y lo del directo en lo que ve la app.
    public static function verdict(array $aAlerts, bool $bObserved, string $sToday): array
    {
        $aSources = [];
        if (\in_array($sToday, $aAlerts['days'], true)) {
            $aSources[] = 'alerts';
        }
        $aDays = $aAlerts['days'];
        $sCause = $aAlerts['cause'];
        $aLines = $aAlerts['lines'];
        if ($bObserved) {
            $aSources[] = 'live';
            $sCause ??= self::CAUSE_LIVE;
            $aLines = null;
            $aDays = array_values(array_unique(array_merge([$sToday], $aDays)));
        }
        $aCodes = null;
        if ($aLines !== null) {
            $aCodes = self::codesOf($aLines);
        }
        return ['today' => !empty($aSources), 'days' => $aDays, 'sources' => $aSources, 'alert' => $aAlerts['alert'], 'cause' => $sCause, 'lines' => $aLines, 'lineCodes' => $aCodes];
    }

    public static function rules(): array
    {
        return require __DIR__ . '/../Config/reduced-service.php';
    }

    // El día de servicio: de madrugada, hasta service_day_start_seconds, todavía el de ayer.
    public static function serviceDay(int $iNow, array $aRules): \DateTimeImmutable
    {
        $Now = (new \DateTimeImmutable('@' . $iNow))->setTimezone(new \DateTimeZone(Calendar::TIME_ZONE));
        $Day = new \DateTimeImmutable($Now->format('Y-m-d'));
        if ($iNow - Calendar::wallMidnight($iNow) < $aRules['service_day_start_seconds']) {
            return $Day->modify('-1 day');
        }
        return $Day;
    }

    /*
     * Los días con servicio reducido según los avisos (de hoy en adelante), el primer aviso que lo dice, la causa y
     * las líneas. Un aviso con líneas cuenta si alguna es de la red ($aLines: id o código => ids); sin líneas, si
     * la fuente no la comparte otra red. Sin fechas en el texto, vale hoy si el aviso está en vigor.
     */
    public static function fromAlerts(array $aAlerts, array $aRules, \DateTimeImmutable $Today, int $iNow, array $aLines, bool $bShared): array
    {
        $aOut = ['days' => [], 'alert' => null, 'cause' => null, 'lines' => []];
        $sToday = $Today->format('Y-m-d');
        $sLast = $Today->modify('+' . $aRules['horizon_days'] . ' days')->format('Y-m-d');
        foreach ($aAlerts as $aAlert) {
            $aCodes = self::alertLines($aAlert, $aLines, $bShared);
            if ($aCodes === false) {
                continue;
            }
            $sText = trim(($aAlert['summary'] ?? $aAlert['title'] ?? '') . '. ' . ($aAlert['description'] ?? ''), ' .');
            $aRead = self::read(Search::normalize($sText), $aRules, $Today);
            if ($aRead === null) {
                continue;
            }
            $aDays = array_values(array_filter($aRead['days'], fn(string $sDay) => $sDay >= $sToday && $sDay <= $sLast));
            if (!$aRead['dated'] && self::inForce($aAlert, $iNow)) {
                $aDays = [$sToday];
            }
            if (empty($aDays)) {
                continue;
            }
            array_push($aOut['days'], ...$aDays);
            $aOut['alert'] ??= $sText;
            if ($aOut['cause'] !== self::CAUSE_STRIKE) {
                $aOut['cause'] = $aRead['cause'];
            }
            if ($aCodes === null || $aOut['lines'] === null) {
                $aOut['lines'] = null;
            } else {
                array_push($aOut['lines'], ...$aCodes);
            }
        }
        $aOut['days'] = array_values(array_unique($aOut['days']));
        sort($aOut['days']);
        if ($aOut['lines'] !== null) {
            $aOut['lines'] = array_values(array_unique($aOut['lines']));
        }
        return $aOut;
    }

    // Las líneas de la red a las que afecta un aviso (null: a toda), o false si no es de esta red.
    private static function alertLines(array $aAlert, array $aLines, bool $bShared): array|null|false
    {
        $aRefs = array_map('strval', $aAlert['lineRefs'] ?? []);
        if (empty($aRefs)) {
            if ($bShared && empty($aAlert['stopRefs'])) {
                return false;
            }
            return null;
        }
        $aIds = array_values(array_unique(array_merge(...array_map(fn(string $sRef) => $aLines[$sRef] ?? [], $aRefs))));
        if (empty($aIds)) {
            return false;
        }
        return $aIds;
    }

    // En vigor ahora: desde su inicio (si lo tiene) hasta su final (si lo tiene).
    private static function inForce(array $aAlert, int $iNow): bool
    {
        if (!empty($aAlert['startTime']) && (new \DateTimeImmutable($aAlert['startTime']))->getTimestamp() > $iNow) {
            return false;
        }
        return empty($aAlert['endTime']) || (new \DateTimeImmutable($aAlert['endTime']))->getTimestamp() >= $iNow;
    }

    // Lee un aviso ya normalizado frase a frase y, dentro, trozo a trozo (entre comas): null si no habla de servicio
    // reducido; si no, sus días (los de los trozos que no lo anulan), si trae algo con forma de fecha y la causa.
    public static function read(string $sText, array $aRules, \DateTimeImmutable $Today): array|null
    {
        $sText = preg_replace('#https?://\S+#', ' ', $sText);
        $aDays = [];
        $aCancelled = [];
        $bSpeaks = false;
        $bDated = false;
        $sCause = null;
        foreach (preg_split('/[;!?\n]+|\.(?!\d)/u', $sText) as $sSentence) {
            $sFound = self::cause($sSentence, $aRules);
            $bSentenceOff = self::mentions($sSentence, $aRules['exclude_sentence'], $aRules['negations']);
            foreach (preg_split('/,\s*(?=\p{L})/u', $sSentence) as $sClause) {
                $aParsed = self::datesIn($sClause, $aRules, $Today);
                $bDated = $bDated || $aParsed['shaped'];
                if ($bSentenceOff || self::mentions($sClause, $aRules['exclude'], $aRules['negations'])) {
                    array_push($aCancelled, ...$aParsed['days']);
                    continue;
                }
                if ($sFound !== null && (self::cause($sClause, $aRules) !== null || !empty($aParsed['days']))) {
                    $bSpeaks = true;
                    $sCause ??= $sFound;
                    array_push($aDays, ...$aParsed['days']);
                }
            }
        }
        if (!$bSpeaks) {
            return null;
        }
        return ['days' => array_values(array_diff(array_unique($aDays), $aCancelled)), 'dated' => $bDated, 'cause' => $sCause];
    }

    // La causa de la que habla un texto: huelga antes que otro servicio reducido; null si de ninguna.
    private static function cause(string $sText, array $aRules): string|null
    {
        foreach ([self::CAUSE_STRIKE, self::CAUSE_REDUCED] as $sCause) {
            foreach (array_merge(...array_values($aRules['causes'][$sCause])) as $sWord) {
                if (str_contains($sText, $sWord)) {
                    return $sCause;
                }
            }
        }
        return null;
    }

    // Si el texto dice alguna de las palabras sin una negación justo antes.
    private static function mentions(string $sText, array $aWords, array $aNegations): bool
    {
        $sNegations = implode('|', array_map(fn(string $sWord) => preg_quote($sWord, '/'), $aNegations));
        foreach (array_merge(...array_values($aWords)) as $sWord) {
            $iAt = mb_strpos($sText, $sWord);
            if ($iAt !== false && !preg_match('/\b(' . $sNegations . ')\s+(\S+\s+)?$/u', mb_substr($sText, 0, $iAt))) {
                return true;
            }
        }
        return false;
    }

    /*
     * Las fechas de un texto ya normalizado y si trae algo con forma de fecha (aunque sea de otro año o ya pasada).
     * Lee «17 de noviembre», «17, 18 y 19 de noviembre», «del 30 al 2 de enero», «azaroaren 17, 18 eta 19an»,
     * «abenduaren 30etik urtarrilaren 2ra», «17-11-2026», «17.11.2026» y, tras «el», «du»…, «17/11».
     */
    public static function datesIn(string $sText, array $aRules, \DateTimeImmutable $Today): array
    {
        preg_match_all('#\d{1,2}[/.\-]\d{1,2}[/.\-]\d{2,4}|\d{1,2}/\d{1,2}|\d+\p{L}*|\p{L}+|[,\-]#u', $sText, $aMatches);
        $aState = ['days' => [], 'shaped' => false, 'pending' => [], 'join' => '', 'last' => null, 'month' => null, 'year' => '', 'word' => '', 'open' => false];
        foreach ($aMatches[0] as $sToken) {
            self::readToken($sToken, $aRules, $Today, $aState);
        }
        return ['days' => array_values(array_unique($aState['days'])), 'shaped' => $aState['shaped']];
    }

    // Un paso de datesIn(): fecha completa, día y mes con barra, número (día o año), mes, unión u otra palabra.
    private static function readToken(string $sToken, array $aRules, \DateTimeImmutable $Today, array &$aState): void
    {
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})(?:[/.\-](\d{2,4}))?$#', $sToken, $aM)) {
            if (isset($aM[3]) || \in_array($aState['word'], $aRules['date_words'], true) || self::isRange($aState['join'], $aRules)) {
                self::addDate((int)$aM[1], (int)$aM[2], $aM[3] ?? '', '', $aRules, $Today, $aState);
            }
            $aState['month'] = null;
            return;
        }
        if (preg_match('/^(\d+)(\p{L}*)$/u', $sToken, $aM)) {
            self::readNumber((int)$aM[1], \strlen($aM[1]), $aM[2], $aRules, $Today, $aState);
            return;
        }
        $aMonth = self::monthOf($sToken, $aRules);
        if ($aMonth !== null) {
            self::readMonth($aMonth, $aRules, $Today, $aState);
            return;
        }
        if (\in_array($sToken, array_merge($aRules['range_words'], $aRules['list_words']), true)) {
            $aState['join'] = $sToken;
            $aState['word'] = $sToken;
            return;
        }
        if (!\in_array($sToken, $aRules['month_links'], true)) {
            $aState['open'] = empty($aState['pending']) && ($aState['open'] || self::opens($aState['word'] . ' ' . $sToken, $aRules));
            $aState['pending'] = [];
            $aState['month'] = null;
            $aState['join'] = '';
        }
        $aState['word'] = $sToken;
    }

    // Un número: un año («2026», «2026ko»), un día que sigue a un mes en euskera o uno que espera a su mes.
    private static function readNumber(int $iNumber, int $iDigits, string $sSuffix, array $aRules, \DateTimeImmutable $Today, array &$aState): void
    {
        if ($iDigits === 4) {
            $aState['year'] = (string)$iNumber;
            return;
        }
        if ($iDigits > 2) {
            $aState['pending'] = [];
            return;
        }
        if ($aState['month'] !== null) {
            self::addDate($iNumber, $aState['month'], $aState['year'], $sSuffix, $aRules, $Today, $aState);
            return;
        }
        $aState['pending'][] = [$iNumber, $aState['join'], $sSuffix];
        $aState['join'] = '';
        $aState['word'] = (string)$iNumber;
    }

    // Un mes: los días que lo esperaban son suyos (en un rango que empieza más tarde, el primero es del mes anterior);
    // en euskera, también los que vienen detrás.
    private static function readMonth(array $aMonth, array $aRules, \DateTimeImmutable $Today, array &$aState): void
    {
        [$iMonth, $bAfter] = $aMonth;
        $aState['shaped'] = true;
        $aPending = $aState['pending'];
        $aState['pending'] = [];
        foreach ($aPending as $iAt => [$iDay, $sJoin, $sSuffix]) {
            $iDayMonth = $iMonth;
            $bLaterStart = isset($aPending[$iAt + 1]) && self::isRange($aPending[$iAt + 1][1], $aRules) && $aPending[$iAt + 1][0] < $iDay;
            if ($bLaterStart) {
                $iDayMonth = ($iMonth + 10) % 12 + 1;
            }
            $aState['join'] = $sJoin;
            self::addDate($iDay, $iDayMonth, $aState['year'], $sSuffix, $aRules, $Today, $aState);
        }
        $aState['month'] = null;
        if ($bAfter) {
            $aState['month'] = $iMonth;
        }
        if (!empty($aPending)) {
            $aState['join'] = '';
        }
    }

    // Apunta una fecha: con un rango delante («al», «-» o un día con «tik»), todos los días desde la anterior; tras
    // «a partir», «desde»…, hasta donde se mira.
    private static function addDate(int $iDay, int $iMonth, string $sYear, string $sSuffix, array $aRules, \DateTimeImmutable $Today, array &$aState): void
    {
        $aState['shaped'] = true;
        $Date = self::dateOf($iDay, $iMonth, $sYear, $Today);
        if ($Date === null) {
            return;
        }
        $From = $Date;
        if ($aState['last'] !== null && self::isRange($aState['join'], $aRules)) {
            $From = $aState['last']->modify('+1 day');
        }
        $To = $Date;
        if ($aState['open']) {
            $To = $Today->modify('+' . $aRules['horizon_days'] . ' days');
            $aState['open'] = false;
        }
        for ($Day = $From; $Day <= $To && \count($aState['days']) <= 2 * $aRules['horizon_days']; $Day = $Day->modify('+1 day')) {
            $aState['days'][] = $Day->format('Y-m-d');
        }
        $aState['last'] = $Date;
        $aState['join'] = '';
        if (\in_array($sSuffix, $aRules['range_suffixes'], true)) {
            $aState['join'] = $aRules['range_words'][0];
        }
    }

    private static function isRange(string $sJoin, array $aRules): bool
    {
        return \in_array($sJoin, $aRules['range_words'], true);
    }

    private static function opens(string $sWords, array $aRules): bool
    {
        foreach ($aRules['open_words'] as $sOpen) {
            if (preg_match('/(^|\s)' . preg_quote($sOpen, '/') . '(\s|$)/u', $sWords)) {
                return true;
            }
        }
        return false;
    }

    // [número de mes, si en su idioma los días van detrás], o null si la palabra no es un mes.
    private static function monthOf(string $sToken, array $aRules): array|null
    {
        foreach ($aRules['months'] as $sLang => $aNames) {
            foreach ($aNames as $iAt => $sName) {
                if (preg_match('/^' . $sName . '(' . implode('|', $aRules['month_suffixes'][$sLang] ?? []) . ')?$/u', $sToken)) {
                    return [$iAt + 1, \in_array($sLang, $aRules['days_after_month'], true)];
                }
            }
        }
        return null;
    }

    // La fecha con ese día y mes más cercana a hoy (o la del año que dice el texto).
    private static function dateOf(int $iDay, int $iMonth, string $sYear, \DateTimeImmutable $Today): \DateTimeImmutable|null
    {
        $aYears = [(int)$Today->format('Y') - 1, (int)$Today->format('Y'), (int)$Today->format('Y') + 1];
        if ($sYear !== '') {
            $aYears = [(int)$sYear + 2000 * (int)(\strlen($sYear) === 2)];
        }
        $Best = null;
        foreach ($aYears as $iYear) {
            if (!checkdate($iMonth, $iDay, $iYear)) {
                continue;
            }
            $Date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $iYear, $iMonth, $iDay));
            if ($Best === null || abs($Date->getTimestamp() - $Today->getTimestamp()) < abs($Best->getTimestamp() - $Today->getTimestamp())) {
                $Best = $Date;
            }
        }
        return $Best;
    }

    // Las líneas de la red: id => [id] y código => los ids con ese código.
    private static function networkLines(): array
    {
        $aLines = [];
        foreach (Database::connection()->query('SELECT id, code FROM lines')->fetchAll(\PDO::FETCH_NUM) as [$sId, $sCode]) {
            $aLines[(string)$sId] = [(string)$sId];
            $aLines[(string)$sCode][] = (string)$sId;
        }
        return $aLines;
    }

    private static function codesOf(array $aIds): array
    {
        if (empty($aIds)) {
            return [];
        }
        $Stmt = Database::connection()->prepare('SELECT DISTINCT code FROM lines WHERE id IN (' . implode(',', array_fill(0, \count($aIds), '?')) . ') ORDER BY code');
        $Stmt->execute($aIds);
        return $Stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // Si otra red lee los mismos avisos (Euskotren y los dos tranvías, Renfe y Renfe LD).
    public static function sharesAlerts(array $aConfig): bool
    {
        $aOwn = self::alertUrls($aConfig);
        foreach (Networks::all() as $sSlug => $aOther) {
            if ($sSlug !== $aConfig['slug'] && !empty(array_intersect($aOwn, self::alertUrls($aOther)))) {
                return true;
            }
        }
        return false;
    }

    private static function alertUrls(array $aConfig): array
    {
        $aUrls = [];
        foreach (self::ALERT_URL_KEYS as [$sGroup, $sKey]) {
            // array_values: las de Lurraldebus van por operador y array_push no admite claves con nombre.
            array_push($aUrls, ...array_values((array)($aConfig[$sGroup][$sKey] ?? [])));
        }
        return array_values(array_filter($aUrls));
    }

    // Lo que dice el directo de hoy: una muestra nueva y si las de la última ventana confirman que falta servicio.
    private static function observedToday(array $aConfig): bool
    {
        $aLive = $aConfig['reduced_service']['live'] ?? null;
        if ($aLive === null || !empty($aConfig['live_often_empty'])) {
            return false;
        }
        $iNow = time();
        $sFile = sys_get_temp_dir() . self::STORE_FILE . 'samples_' . $aConfig['slug'] . '.json';
        $aSamples = self::withSample(self::readJson($sFile), self::sample($aLive), $iNow, $aLive);
        self::writeJson($sFile, $aSamples);
        return self::confirmed($aSamples, $iNow, $aLive);
    }

    // Añade una muestra ([expected, missing]) y quita las viejas. Con el feed vacío (null) no se añade nada: un fallo
    // del feed no dice que haya huelga ni que deje de haberla.
    public static function withSample(array $aSamples, array|null $aSample, int $iNow, array $aLive): array
    {
        $aKept = array_values(array_filter($aSamples, fn(array $aOne) => $aOne[0] > $iNow - 2 * $aLive['confirm_seconds']));
        if ($aSample !== null) {
            $aKept[] = [$iNow, $aSample[0], $aSample[1]];
        }
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
    private static function sample(array $aLive): array|null
    {
        $JourneyModel = new ServiceJourney(Database::connection());
        if (Config::hasLive('trip-updates')) {
            $TripMatcher = Live::tripMatcher($JourneyModel);
            if ($TripMatcher === null || !$TripMatcher->hasData()) {
                return null;
            }
            return self::missingAtStops($JourneyModel, $TripMatcher, self::sampleStops($aLive), $aLive, Calendar::nowSecondsSinceMidnight());
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
     * En las paradas de muestra, las salidas de los próximos window_seconds cuyo viaje ya ha salido de cabecera según
     * el horario (uno que aún no ha salido puede no estar en el feed) y cuántas no salen en directo: quitadas por el
     * emparejado, sin dato o canceladas. De cada parada se miran rows_per_stop salidas.
     */
    public static function missingAtStops(ServiceJourney $JourneyModel, object $TripMatcher, array $aStops, array $aLive, int $iNow): array
    {
        $iExpected = 0;
        $iMissing = 0;
        foreach ($aStops as $sStopId) {
            $aRows = (new ScheduleOnlyMatcher())->enrich(array_values(array_filter(
                $JourneyModel->upcomingAtStop($sStopId, $aLive['rows_per_stop'], $aLive['window_seconds']),
                fn(array $aRow) => (int)$aRow['first_departure_seconds'] <= $iNow && (int)$aRow['arrival_seconds'] >= $iNow
            )));
            $aSeen = [];
            foreach ($TripMatcher->enrich($sStopId, $aRows) as $aRow) {
                if (($aRow['status'] ?? '') === 'live' && empty($aRow['unscheduled'])) {
                    $aSeen[$aRow['service_journey_id'] . '|' . $aRow['arrival_seconds']] = true;
                }
            }
            foreach ($aRows as $aRow) {
                $iExpected++;
                $iMissing += (int)!isset($aSeen[$aRow['service_journey_id'] . '|' . $aRow['arrival_seconds']]);
            }
        }
        return [$iExpected, $iMissing];
    }

    // Las paradas con más pasos de la red, sacadas de su base una vez al día.
    private static function sampleStops(array $aLive): array
    {
        return \Core\Cache::remember('reduced_stops_' . Config::network(), Calendar::DAY_SECONDS, function () use ($aLive) {
            $Stmt = Database::connection()->prepare('SELECT stop_id FROM passing_times GROUP BY stop_id ORDER BY COUNT(*) DESC LIMIT ?');
            $Stmt->execute([$aLive['sample_stops']]);
            return array_map('strval', $Stmt->fetchAll(\PDO::FETCH_COLUMN));
        });
    }

    private static function readJson(string $sFile): array
    {
        $aData = json_decode((string)@file_get_contents($sFile), true);
        if (!\is_array($aData)) {
            return [];
        }
        return $aData;
    }

    // A un temporal y luego en su sitio: quien lee a la vez ve el archivo de antes o el nuevo, nunca uno a medias.
    private static function writeJson(string $sFile, array $aData): void
    {
        $sTemp = $sFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($sTemp, json_encode($aData, JSON_UNESCAPED_UNICODE)) !== false) {
            rename($sTemp, $sFile);
        }
    }
}
