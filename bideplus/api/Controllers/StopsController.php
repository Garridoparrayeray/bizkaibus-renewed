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
use Services\TripDelayMatcher;
use Core\Live;

// Paradas: la ficha (/api/stops/{id}), las cercanas (/api/nearby), las próximas salidas con el directo
// (/api/stops/{id}/departures) y las paradas de un viaje (/api/trips/{tripKey}).
class StopsController
{
    private const LATE_TRAINS_LOOKBACK_SECONDS = 3600;
    private const LIKELY_PAST_SHOWN = 2;

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
        if ($dLat < 42.3 || $dLat > 43.7 || $dLon < -3.7 || $dLon > -1.0) {
            Response::error('Location outside the covered area', 422);
            return;
        }
        $iLimit = max(1, min(10, $Req->queryInt('limit', 5)));

        $Pdo = Database::connection();
        $StopModel = new Stop($Pdo);
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
        if (isset($aConfig['direction_reference_stop_id'])) {
            [$aRows, $aDirectionLabels] = self::withDirections($JourneyModel, $aRows, $sStopId, $aStop['name'], $aConfig['direction_reference_stop_id']);
        }
        $aRows = self::withLive([$Matcher, $Yesterday], $TripMatcher, $sStopId, $aRows);
        return $aOut + [
            'platforms' => [],
            'directionLabels' => $aDirectionLabels,
            'departures' => self::limited(self::departureItems($aRows, $bUseLastStopHeadsign), $iLimit),
            'attribution' => $aConfig['attribution'],
        ];
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
    private static function upcomingRowsToday(ServiceJourney $JourneyModel, string|int $StopId, int $iLimit, int|null $iPast): array
    {
        $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, 4 * 3600, 0, $iPast);
        if (!empty($aRows)) {
            return $aRows;
        }
        $iNext = $JourneyModel->nextDepartureSeconds($StopId);
        if ($iNext !== null) {
            $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, $iNext - Calendar::nowSecondsSinceMidnight() + 4 * 3600, 0, $iPast);
        }
        if (count($aRows) < $iLimit + 5) {
            $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, 24 * 3600, 0, $iPast);
        }
        // Hoy ya no sale ninguna más: detrás de las que acaban de salir (puede venir alguna con retraso), las primeras de
        // mañana con su horario.
        $iNow = Calendar::nowSecondsSinceMidnight();
        foreach ($aRows as $aRow) {
            if ((int)$aRow['departure_seconds'] >= $iNow) {
                return $aRows;
            }
        }
        $aTomorrow = $JourneyModel->upcomingAtStop($StopId, $iLimit, 24 * 3600, 1);
        return array_merge($aRows, array_map(fn(array $aRow) => ['tomorrow' => true] + $aRow, $aTomorrow));
    }

    private static function pastSeconds(object|null $TripMatcher): int|null
    {
        if ($TripMatcher instanceof TripDelayMatcher && $TripMatcher->hasData()) {
            return self::LATE_TRAINS_LOOKBACK_SECONDS;
        }
        return null;
    }

    // El directo sobre las salidas del horario: el algoritmo de los autobuses ([emparejador de hoy, el de ayer cuando
    // se pida]) y, en los trenes y tranvías, las horas del feed del operador (sin los que ya han salido).
    // Las de mañana van con el horario tal cual: el directo de hoy no es el de esos viajes.
    private static function withLive(array $aMatchers, object|null $TripMatcher, string $sStopId, array $aRows): array
    {
        $aToday = array_values(array_filter($aRows, fn(array $aRow) => empty($aRow['tomorrow'])));
        $aTomorrow = array_values(array_filter($aRows, fn(array $aRow) => !empty($aRow['tomorrow'])));
        return array_merge(self::withLiveToday($aMatchers, $TripMatcher, $sStopId, $aToday), (new ScheduleOnlyMatcher())->enrich($aTomorrow));
    }

    private static function withLiveToday(array $aMatchers, object|null $TripMatcher, string $sStopId, array $aRows): array
    {
        if (empty($aRows)) {
            return [];
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
        return [$aRows, array_map(fn($aNames) => implode(' · ', $aNames), $aTermini)];
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
                'tripKey' => TripKey::build($aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds']),
                'scheduledTime' => Calendar::secondsToHm((int)$aRow['arrival_seconds']),
                'etaMinutes' => $iEtaMinutes,
                'etaAt' => time() + ($aRow['etaSeconds'] - $iNow),
                'status' => $aRow['status'],
                'atOrigin' => !empty($aRow['atOrigin']),
                'tomorrow' => !empty($aRow['tomorrow']),
                'delayMinutes' => self::delayMinutes($aRow['delaySeconds']),
                'direction' => $aRow['direction'] ?? null,
                'platformId' => $sPlatformId,
                'passedTime' => self::passedTime($aRow),
            ];
        }
        return $aDepartures;
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
