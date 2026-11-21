<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Ids;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\Stop;
use Models\ServiceJourney;
use Services\Calendar;
use Services\ScheduleOnlyMatcher;
use Services\PlatformMatcher;
use Services\TripDelayMatcher;
use Core\Live;

// Paradas: la ficha (/api/stops/{id}), las cercanas (/api/nearby), las próximas salidas con el directo
// (/api/stops/{id}/departures) y las paradas de un viaje (/api/trips/{tripKey}).
class StopsController
{
    private const LATE_TRAINS_LOOKBACK_SECONDS = 3600;
    private const LIKELY_PAST_SHOWN = 2;
    // Las salidas que se miran para el directo del planificador, que solo pregunta por lo que sale en 2 h.
    private const LIVE_LEGS_LIMIT = 200;
    private const LIVE_LEGS_WINDOW_SECONDS = 3 * 3600;
    // Las próximas salidas de una parada: las de las próximas 4 horas.
    private const WINDOW_SECONDS = 4 * 3600;

    public function show(Request $Req, array $aParams): void
    {
        $Pdo = Database::connection();
        $StopModel = new Stop($Pdo);
        $sStopId = $aParams['id'];
        $aStop = $StopModel->find($sStopId);
        if ($aStop === null) {
            Response::error('Stop not found', 404);
            return;
        }
        $sStopId = (string)$aStop['id'];
        $aStop['lines'] = $StopModel->linesServing($sStopId);
        Response::json($aStop);
    }

    public function nearby(Request $Req): void
    {
        $sLat = $Req->query('lat');
        $sLon = $Req->query('lon');
        if (!is_numeric($sLat) || !is_numeric($sLon)) {
            Response::error('lat and lon must be numbers', 422);
            return;
        }
        $dLat = (float)$sLat;
        $dLon = (float)$sLon;
        $Pdo = Database::connection();
        $StopModel = new Stop($Pdo);
        if (!$StopModel->covers($dLat, $dLon)) {
            Response::error('Location outside the covered area', 422);
            return;
        }
        $iLimit = max(1, min(10, $Req->queryInt('limit', 5)));

        $JourneyModel = new ServiceJourney($Pdo);
        $bUseLastStopHeadsign = (bool)Config::feature('headsign_from_last_stop');

        $aStops = $StopModel->nearest($dLat, $dLon, $iLimit);
        foreach ($aStops as &$aStop) {
            $aStop['next'] = $this->nextScheduledDeparture($StopModel, $JourneyModel, $aStop['id'], $bUseLastStopHeadsign);
        }
        unset($aStop);

        Response::json(['stops' => $aStops]);
    }

    private function nextScheduledDeparture(Stop $StopModel, ServiceJourney $JourneyModel, string|int $StopId, bool $bUseLastStopHeadsign): array|null
    {
        $aPlatforms = $StopModel->platformsFor((string)$StopId);
        $aStopIds = [$StopId];
        if (!empty($aPlatforms)) {
            $aStopIds = array_column($aPlatforms, 'id');
        }
        $iNow = Calendar::nowSecondsSinceMidnight();

        $aBest = null;
        foreach ($aStopIds as $Id) {
            foreach (self::upcomingRows($JourneyModel, $Id, 3) as $aRow) {
                if ((int)$aRow['departure_seconds'] < $iNow) {
                    continue;
                }
                if ($aBest === null || (int)$aRow['departure_seconds'] < (int)$aBest['departure_seconds']) {
                    $aBest = $aRow;
                }
                break;
            }
        }
        if ($aBest === null) {
            return null;
        }
        $sHeadsign = $aBest['headsign'];
        if ($bUseLastStopHeadsign && !empty($aBest['last_stop_name'])) {
            $sHeadsign = $aBest['last_stop_name'];
        }
        return [
            'lineCode' => $aBest['line_code'],
            'headsign' => $sHeadsign,
            'etaMinutes' => (int)round(((int)$aBest['departure_seconds'] - $iNow) / 60),
            'scheduledTime' => Calendar::secondsToHm((int)$aBest['departure_seconds']),
            'tomorrow' => !empty($aBest['tomorrow']),
        ];
    }

    public function departures(Request $Req, array $aParams): void
    {
        $aData = $this->departuresData($aParams['id'], $Req->queryInt('limit', 8));
        if ($aData === null) {
            Response::error('Stop not found', 404);
            return;
        }
        Response::json($aData);
    }

    // Próximas salidas de una parada (o de los andenes de una estación) en la red actual, o null si no existe. Lo usan
    // la API y los avisos de llegada, que así cuentan los minutos exactamente igual que la app.
    public function departuresData(string $sStopId, int $iLimit): array|null
    {
        $Pdo = Database::connection();
        $StopModel = new Stop($Pdo);
        $aStop = $StopModel->find($sStopId);
        if ($aStop === null) {
            return null;
        }
        $sStopId = (string)$aStop['id'];
        $aConfig = Config::current();
        $JourneyModel = new ServiceJourney($Pdo);
        $Matcher = Live::matcher($JourneyModel);
        // El de los viajes de ayer, solo si de madrugada hace falta (y uno para todos los andenes).
        $YesterdayMatcher = null;
        $Yesterday = function () use (&$YesterdayMatcher, $JourneyModel): object {
            $YesterdayMatcher ??= Live::matcher($JourneyModel, null, 1);
            return $YesterdayMatcher;
        };
        $TripMatcher = Live::tripMatcher($JourneyModel);
        $bUseLastStopHeadsign = (bool)Config::feature('headsign_from_last_stop');
        $aOut = ['stop' => ['id' => $aStop['id'], 'name' => $aStop['name']]];

        // Estación con andenes (Metro, Euskotren): las salidas de cada andén, cada una con el suyo.
        $aPlatforms = $StopModel->platformsFor($sStopId);
        // Una estación cuyas paradas no son andenes (SNCF las separa por tipo de tren): todas sus salidas juntas, por hora.
        if (!empty($aPlatforms) && !Config::feature('platforms')) {
            $aDepartures = [];
            foreach ($aPlatforms as $aPlatform) {
                $aRows = self::withLive([$Matcher, $Yesterday], $TripMatcher, (string)$aPlatform['id'], self::upcomingRows($JourneyModel, $aPlatform['id'], $iLimit, self::pastSeconds($TripMatcher)));
                array_push($aDepartures, ...self::departureItems($aRows, $bUseLastStopHeadsign));
            }
            usort($aDepartures, fn(array $aA, array $aB) => [$aA['tomorrow'], $aA['etaAt']] <=> [$aB['tomorrow'], $aB['etaAt']]);
            return $aOut + ['platforms' => [], 'directionLabels' => null, 'departures' => self::limited($aDepartures, $iLimit), 'attribution' => $aConfig['attribution']];
        }
        if (!empty($aPlatforms)) {
            $aDepartures = [];
            foreach ($aPlatforms as $aPlatform) {
                $aRows = self::withLive([$Matcher, $Yesterday], $TripMatcher, (string)$aPlatform['id'], self::upcomingRows($JourneyModel, $aPlatform['id'], $iLimit, self::pastSeconds($TripMatcher)));
                array_push($aDepartures, ...self::limited(self::departureItems($aRows, $bUseLastStopHeadsign, $aPlatform), $iLimit));
            }
            return $aOut + ['platforms' => $aPlatforms, 'departures' => $aDepartures, 'attribution' => $aConfig['attribution']];
        }

        $aRows = self::upcomingRows($JourneyModel, $sStopId, $iLimit, self::pastSeconds($TripMatcher));
        $aDirectionLabels = null;
        $aPatternDirections = [];
        if (isset($aConfig['direction_reference_stop_id'])) {
            [$aRows, $aDirectionLabels, $aPatternDirections] = self::withDirections($JourneyModel, $aRows, $sStopId, $aStop['name'], $aConfig['direction_reference_stop_id']);
        }
        $aRows = self::withLive([$Matcher, $Yesterday], $TripMatcher, $sStopId, $aRows);
        $aRows = self::withExtraDirections($aRows, $aPatternDirections);
        return $aOut + [
            'platforms' => [],
            'directionLabels' => $aDirectionLabels,
            'departures' => self::limited(self::departureItems($aRows, $bUseLastStopHeadsign), $iLimit),
            'attribution' => $aConfig['attribution'],
        ];
    }

    // El directo de unos viajes al salir de sus paradas, en la red actual, para el planificador, con los mismos
    // emparejadores (el por viaje puede venir hecho) y la misma ventana que las próximas salidas: por línea y hora del
    // horario de su día de servicio (las de ayer de madrugada, como 25:10). $aLegs: parada => [[línea, hora, viaje]].
    public static function liveLegs(array $aLegs, ServiceJourney|null $JourneyModel = null,
        object|null $TripMatcher = null): array
    {
        $JourneyModel ??= new ServiceJourney(Database::connection());
        $Matcher = Live::matcher($JourneyModel);
        $YesterdayMatcher = null;
        $Yesterday = function () use (&$YesterdayMatcher, $JourneyModel): object {
            $YesterdayMatcher ??= Live::matcher($JourneyModel, null, 1);
            return $YesterdayMatcher;
        };
        $TripMatcher ??= Live::tripMatcher($JourneyModel);
        $iPast = self::pastSeconds($TripMatcher);
        $aLive = [];
        foreach ($aLegs as $StopId => $aWanted) {
            $aTrips = [];
            foreach ($aWanted as [$sLine, $iSeconds, $sTrip]) {
                $aTrips[$sLine . '|' . $iSeconds] = $sTrip;
            }
            $aYesterday = $JourneyModel->upcomingAtStop((string)$StopId, self::LIVE_LEGS_LIMIT, self::LIVE_LEGS_WINDOW_SECONDS, -1, $iPast);
            $aRows = array_merge(array_map(fn(array $aRow) => ['yesterday' => true] + $aRow, $aYesterday),
                $JourneyModel->upcomingAtStop((string)$StopId, self::LIVE_LEGS_LIMIT, self::LIVE_LEGS_WINDOW_SECONDS, 0, $iPast));
            $aRows = array_values(array_filter($aRows, fn(array $aRow) => isset($aTrips[self::legKey($aRow)])));
            if (empty($aRows)) {
                continue;
            }
            usort($aRows, fn(array $aA, array $aB) => (int)$aA['departure_seconds'] <=> (int)$aB['departure_seconds']);
            foreach (self::withLiveToday([$Matcher, $Yesterday], $TripMatcher, (string)$StopId, $aRows) as $aRow) {
                $sKey = self::legKey($aRow);
                if (isset($aTrips[$sKey])) {
                    $aLive[$aTrips[$sKey]] ??= $aRow;
                }
            }
        }
        return $aLive;
    }

    // «línea|hora» de una salida, con la hora de su día de servicio (las de ayer vienen en horas de hoy: 01:10 es 25:10).
    private static function legKey(array $aRow): string
    {
        $iSeconds = (int)($aRow['departure_seconds'] ?? 0);
        if (!empty($aRow['yesterday'])) {
            $iSeconds += Calendar::DAY_SECONDS;
        }
        return ($aRow['line_code'] ?? '') . '|' . $iSeconds;
    }

    // De madrugada, delante de las de hoy van los viajes de ayer que pasan de las 24:00, en horas de hoy (26:00 es 02:00).
    private static function upcomingRows(ServiceJourney $JourneyModel, string|int $StopId, int $iLimit, int|null $iPast = null): array
    {
        $aRows = self::upcomingRowsToday($JourneyModel, $StopId, $iLimit, $iPast);
        $aYesterday = $JourneyModel->upcomingAtStop($StopId, $iLimit, 4 * 3600, -1, $iPast);
        if (empty($aYesterday)) {
            return $aRows;
        }
        $aRows = array_merge(array_map(fn(array $aRow) => ['yesterday' => true] + $aRow, $aYesterday), $aRows);
        usort($aRows, fn(array $aA, array $aB) => (int)$aA['departure_seconds'] <=> (int)$aB['departure_seconds']);
        return $aRows;
    }

    // Salidas de las próximas 4 horas o, si no hay (de noche), 4 horas desde la primera que queda o las próximas 24.
    // Las del día siguiente que caen dentro de esas horas (las 00:xx que muchas redes ponen en el día siguiente y no
    // como 24:xx) van mezcladas con las de hoy («nextDay»); «tomorrow» queda para las de más allá.
    private static function upcomingRowsToday(ServiceJourney $JourneyModel, string|int $StopId, int $iLimit, int|null $iPast, int|null $iNow = null): array
    {
        $iNow ??= Calendar::nowSecondsSinceMidnight();
        $iWindow = self::WINDOW_SECONDS;
        $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, $iWindow, 0, $iPast);
        // Con alguna que sale de ahora en adelante; solo las que ya salieron (el margen de 15 min para las que van tarde)
        // no bastan: sin esto, pasado el último bus del día la parada se quedaba vacía 15 min en vez de dar las de mañana.
        if (self::anyComing($aRows, $iNow)) {
            return self::withNextDay($JourneyModel, $StopId, $iLimit, $iNow, $iNow + $iWindow, $aRows);
        }
        $iNext = $JourneyModel->nextDepartureSeconds($StopId);
        if ($iNext !== null) {
            $iWindow = $iNext - $iNow + self::WINDOW_SECONDS;
            $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, $iWindow, 0, $iPast);
        }
        if (count($aRows) < $iLimit + 5) {
            $iWindow = Calendar::DAY_SECONDS;
            $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, $iWindow, 0, $iPast);
        }
        if (self::anyComing($aRows, $iNow)) {
            return self::withNextDay($JourneyModel, $StopId, $iLimit, $iNow, $iNow + min($iWindow, self::WINDOW_SECONDS), $aRows);
        }
        // Hoy ya no sale ninguna más: detrás de las que acaban de salir (puede venir alguna con retraso), las primeras de
        // mañana con su horario; las que salen dentro de las 4 horas, como las de hoy (a las 23:58, la de las 00:03 es
        // «5 min», no «mañana»).
        $aTomorrow = [];
        foreach ($JourneyModel->upcomingAtStop($StopId, $iLimit, Calendar::DAY_SECONDS, 1) as $aRow) {
            $sFlag = 'tomorrow';
            if ((int)$aRow['departure_seconds'] <= $iNow + self::WINDOW_SECONDS) {
                $sFlag = 'nextDay';
            }
            $aTomorrow[] = [$sFlag => true] + $aRow;
        }
        return array_merge($aRows, $aTomorrow);
    }

    // Detrás de las de hoy, las del día siguiente que salen antes de $iWindowEnd (en horas de hoy: 86.580 son las 00:03
    // de mañana), sin repetir una que el horario de hoy ya trae como 24:xx. Sin esto, cerca de medianoche las 00:xx de
    // Euskotren, Bilbobus, Dbus, Lurraldebus o Álavabus no salían hasta acabarse las de hoy.
    private static function withNextDay(ServiceJourney $JourneyModel, string|int $StopId, int $iLimit, int $iNow, int $iWindowEnd, array $aRows): array
    {
        if ($iWindowEnd <= Calendar::DAY_SECONDS) {
            return $aRows;
        }
        $aSeen = [];
        foreach ($aRows as $aRow) {
            $aSeen[$aRow['line_code'] . '|' . (int)$aRow['departure_seconds']] = true;
        }
        $bAdded = false;
        foreach ($JourneyModel->upcomingAtStop($StopId, $iLimit, $iWindowEnd - $iNow, 1) as $aRow) {
            if ((int)$aRow['departure_seconds'] <= $iWindowEnd && !isset($aSeen[$aRow['line_code'] . '|' . (int)$aRow['departure_seconds']])) {
                $aRows[] = ['nextDay' => true] + $aRow;
                $bAdded = true;
            }
        }
        if ($bAdded) {
            usort($aRows, fn(array $aA, array $aB) => (int)$aA['departure_seconds'] <=> (int)$aB['departure_seconds']);
        }
        return $aRows;
    }

    // Si alguna de las salidas es de ahora en adelante.
    private static function anyComing(array $aRows, int $iNow): bool
    {
        foreach ($aRows as $aRow) {
            if ((int)$aRow['departure_seconds'] >= $iNow) {
                return true;
            }
        }
        return false;
    }

    private static function pastSeconds(object|null $TripMatcher): int|null
    {
        $bByJourney = $TripMatcher instanceof TripDelayMatcher || $TripMatcher instanceof PlatformMatcher;
        if ($bByJourney && $TripMatcher->hasData()) {
            return self::LATE_TRAINS_LOOKBACK_SECONDS;
        }
        return null;
    }

    // El directo sobre las salidas del horario: el algoritmo de los autobuses ([emparejador de hoy, el de ayer cuando
    // se pida]) y, en los trenes y tranvías, las horas del feed (sin los que ya han salido). Las del día siguiente
    // (también las 00:xx de la ventana) van con su horario: el directo de hoy no es el de esos viajes.
    private static function withLive(array $aMatchers, object|null $TripMatcher, string $sStopId, array $aRows): array
    {
        $aToday = array_values(array_filter($aRows, fn(array $aRow) => empty($aRow['tomorrow']) && empty($aRow['nextDay'])));
        $aTomorrow = array_values(array_filter($aRows, fn(array $aRow) => !empty($aRow['tomorrow']) || !empty($aRow['nextDay'])));
        return array_merge(self::withLiveToday($aMatchers, $TripMatcher, $sStopId, $aToday), (new ScheduleOnlyMatcher())->enrich($aTomorrow));
    }

    private static function withLiveToday(array $aMatchers, object|null $TripMatcher, string $sStopId, array $aRows): array
    {
        if (empty($aRows)) {
            return self::unscheduledOnly($TripMatcher, $sStopId);
        }
        $aRows = self::withEngine($aMatchers, $aRows);
        if ($TripMatcher === null) {
            return $aRows;
        }
        $aRows = $TripMatcher->enrich($sStopId, $aRows);
        if ($TripMatcher->hasData()) {
            $aRows = $TripMatcher->withoutDeparted($aRows, Calendar::nowSecondsSinceMidnight());
        }
        return $aRows;
    }

    // Sin ninguna salida en el horario, los trenes del feed que aun así pasan por la parada (refuerzos).
    private static function unscheduledOnly(object|null $TripMatcher, string $sStopId): array
    {
        if (!($TripMatcher instanceof PlatformMatcher)) {
            return [];
        }
        return $TripMatcher->withoutDeparted($TripMatcher->unscheduledRows($sStopId), Calendar::nowSecondsSinceMidnight());
    }

    // El algoritmo de los autobuses sobre las salidas. Las de ayer, con el emparejador de ayer y las horas de su horario
    // (26:00), que vuelven en las de hoy (02:00); sin ellas, todo pasa por el de hoy como siempre.
    private static function withEngine(array $aMatchers, array $aRows): array
    {
        [$Matcher, $Yesterday] = $aMatchers;
        $aYesterday = array_filter($aRows, fn(array $aRow) => !empty($aRow['yesterday']));
        if (empty($aYesterday)) {
            return $Matcher->enrich($aRows);
        }
        $aToday = array_diff_key($aRows, $aYesterday);
        $aLate = $Yesterday()->enrich(self::shifted(array_values($aYesterday), Calendar::DAY_SECONDS));
        $aEnriched = array_combine(array_keys($aToday), $Matcher->enrich(array_values($aToday)))
            + array_combine(array_keys($aYesterday), self::shifted($aLate, -Calendar::DAY_SECONDS));
        ksort($aEnriched);
        return array_values($aEnriched);
    }

    // Las salidas con sus horas movidas $iSeconds.
    private static function shifted(array $aRows, int $iSeconds): array
    {
        foreach ($aRows as &$aRow) {
            foreach (['arrival_seconds', 'departure_seconds', 'etaSeconds'] as $sField) {
                if (isset($aRow[$sField])) {
                    $aRow[$sField] = (int)$aRow[$sField] + $iSeconds;
                }
            }
        }
        unset($aRow);
        return $aRows;
    }

    // Redes con parada de referencia (Metro): cada salida va hacia ella o se aleja, y los dos sentidos se titulan con
    // los finales de línea de cada uno («Etxebarri · Basauri»).
    private static function withDirections(ServiceJourney $JourneyModel, array $aRows, string $sStopId, string $sStopName, string|int $ReferenceStopId): array
    {
        $aDirections = $JourneyModel->directionsByPattern($sStopId, $ReferenceStopId);
        foreach ($aRows as &$aRow) {
            $aRow['direction'] = $aDirections[$aRow['journey_pattern_id']]['direction'] ?? 'toward_reference';
        }
        unset($aRow);
        $aTermini = ['toward_reference' => [], 'away_from_reference' => []];
        foreach ($aDirections as $aInfo) {
            if ($aInfo['terminus'] !== $sStopName && !in_array($aInfo['terminus'], $aTermini[$aInfo['direction']], true)) {
                $aTermini[$aInfo['direction']][] = $aInfo['terminus'];
            }
        }
        return [$aRows, array_map(fn($aNames) => implode(' · ', $aNames), $aTermini), $aDirections];
    }

    // Los trenes que el horario no tiene (refuerzos) van en el sentido del recorrido donde caben.
    private static function withExtraDirections(array $aRows, array $aPatternDirections): array
    {
        foreach ($aRows as &$aRow) {
            if (!empty($aRow['unscheduled']) && !isset($aRow['direction'])) {
                $aRow['direction'] = $aPatternDirections[$aRow['journey_pattern_id']]['direction'] ?? null;
            }
        }
        unset($aRow);
        return $aRows;
    }

    private static function departureItems(array $aRows, bool $bUseLastStopHeadsign, array|null $aPlatform = null): array
    {
        $iNow = Calendar::nowSecondsSinceMidnight();
        // Por hora de llegada prevista, no por la teórica: un bus adelantado llega antes que uno anterior en el horario.
        // Los que probablemente ya pasaron (estimación, sin datos del bus) van al final, nunca delante de uno que viene.
        usort($aRows, fn($aA, $aB) => [(int)($aA['status'] === 'likely_departed'), $aA['etaSeconds']] <=> [(int)($aB['status'] === 'likely_departed'), $aB['etaSeconds']]);
        $sPlatformId = null;
        if ($aPlatform !== null) {
            $sPlatformId = $aPlatform['id'];
        }
        $aDepartures = [];
        foreach ($aRows as $aRow) {
            $iEtaMinutes = (int)round(($aRow['etaSeconds'] - $iNow) / 60);
            // Hasta 2 minutos después de su hora se sigue enseñando: puede estar saliendo.
            if ($iEtaMinutes < -2) {
                continue;
            }
            $aDepartures[] = [
                'lineId' => Ids::forOutput($aRow['line_id']),
                'lineCode' => $aRow['line_code'],
                'lineName' => $aRow['line_name'],
                'headsign' => self::headsign($aRow, $bUseLastStopHeadsign),
                'tripKey' => self::tripKey($aRow),
                'unscheduled' => !empty($aRow['unscheduled']),
                'scheduledTime' => Calendar::secondsToHm((int)($aRow['departure_seconds'] ?? $aRow['arrival_seconds'])),
                'etaMinutes' => $iEtaMinutes,
                'etaAt' => time() + ($aRow['etaSeconds'] - $iNow),
                'status' => $aRow['status'],
                'atOrigin' => !empty($aRow['atOrigin']),
                'tomorrow' => !empty($aRow['tomorrow']),
                // Del día de servicio siguiente pero dentro de las 4 horas (las 00:xx): la ficha del viaje va con su
                // horario, como la de mañana, porque la de hoy de esa clave ya terminó.
                'nextDay' => !empty($aRow['nextDay']),
                'delayMinutes' => self::delayMinutes($aRow['delaySeconds']),
                'direction' => $aRow['direction'] ?? null,
                'platformId' => $sPlatformId,
                'passedTime' => self::passedTime($aRow),
                // «previous_trip»: sin bus todavía, sale tarde porque su bus viene tarde de la vuelta anterior.
                'reason' => $aRow['reason'] ?? null,
            ];
        }
        return $aDepartures;
    }

    // Un tren que el horario no tiene (refuerzo) lleva la clave de su parada, su andén y su hora: ExtraSheet lo vuelve
    // a buscar.
    private static function tripKey(array $aRow): string
    {
        if (!empty($aRow['unscheduled'])) {
            return TripKey::buildExtra((string)$aRow['stop_id'], Calendar::wallMidnight() + $aRow['etaSeconds'], $aRow['platform_stop_id'] ?? null);
        }
        return TripKey::build($aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds']);
    }

    // Las primeras que vienen y, detrás, las que puede que ya hayan pasado: el límite no las deja fuera.
    private static function limited(array $aItems, int $iLimit): array
    {
        $aComing = [];
        $aLikelyPast = [];
        foreach ($aItems as $aItem) {
            if ($aItem['status'] === 'likely_departed') {
                $aLikelyPast[] = $aItem;
                continue;
            }
            $aComing[] = $aItem;
        }
        return array_merge(array_slice($aComing, 0, $iLimit), array_slice($aLikelyPast, 0, self::LIKELY_PAST_SHOWN));
    }

    // La hora a la que el algoritmo calcula que pasó un bus sin datos que «puede haber pasado».
    private static function passedTime(array $aRow): string|null
    {
        if (!isset($aRow['likelyPassedSeconds'])) {
            return null;
        }
        return Calendar::secondsToHm((int)$aRow['likelyPassedSeconds']);
    }

    private static function headsign(array $aRow, bool $bUseLastStopHeadsign): string|null
    {
        if ($bUseLastStopHeadsign && !empty($aRow['last_stop_name'])) {
            return $aRow['last_stop_name'];
        }
        return $aRow['headsign'];
    }

    // Null si no se sabe (estimación sin datos del bus); 0 si va en hora.
    private static function delayMinutes(int|float|null $iDelaySeconds): int|null
    {
        if ($iDelaySeconds === null) {
            return null;
        }
        return (int)round($iDelaySeconds / 60);
    }

    public function tripStops(Request $Req, array $aParams): void
    {
        $aTripKey = TripKey::parse($aParams['tripKey']);
        if ($aTripKey === null) {
            Response::error('Invalid trip key', 422);
            return;
        }
        [$sLineId, $sTripNumber, $iFirstDepartureSeconds] = $aTripKey;
        $sTargetStopId = $Req->query('stopId');

        $Pdo = Database::connection();
        $JourneyModel = new ServiceJourney($Pdo);
        $aJourney = $JourneyModel->findByLineAndTrip($sLineId, $sTripNumber, $iFirstDepartureSeconds);
        if ($aJourney === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $aStopsOut = array_map(function ($aStop) use ($sTargetStopId) {
            return [
                'stopId' => Ids::forOutput($aStop['stop_id']),
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
                'isTarget' => $sTargetStopId !== null && $aStop['stop_id'] === $sTargetStopId,
            ];
        }, $aStops);

        $sHeadsign = $aJourney['headsign'];
        if (Config::feature('headsign_from_last_stop') && !empty($aStops)) {
            $sHeadsign = end($aStops)['name'];
        }

        Response::json([
            'lineCode' => $aJourney['line_code'],
            'lineName' => $aJourney['line_name'],
            'headsign' => $sHeadsign,
            'stops' => $aStopsOut,
        ]);
    }
}
