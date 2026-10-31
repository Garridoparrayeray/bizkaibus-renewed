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
use Services\GtfsRealtimeClient;
use Core\Live;
use Engine\Metro\TripUpdatesMatcher;

// Paradas: la ficha (/api/stops/{id}), las cercanas (/api/nearby), las próximas salidas con el directo
// (/api/stops/{id}/departures) y las paradas de un viaje (/api/trips/{tripKey}).
class StopsController
{
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
        $TripMatcher = self::tripMatcher($JourneyModel);
        $bUseLastStopHeadsign = (bool)Config::feature('headsign_from_last_stop');
        $aOut = ['stop' => ['id' => $aStop['id'], 'name' => $aStop['name']]];

        // Estación con andenes (Metro, Euskotren): las salidas de cada andén, cada una con el suyo.
        $aPlatforms = $StopModel->platformsFor($sStopId);
        if (!empty($aPlatforms)) {
            $aDepartures = [];
            foreach ($aPlatforms as $aPlatform) {
                $aRows = self::withLive($Matcher, $TripMatcher, (string)$aPlatform['id'], self::upcomingRows($JourneyModel, $aPlatform['id'], $iLimit));
                array_push($aDepartures, ...array_slice(self::departureItems($aRows, $bUseLastStopHeadsign, $aPlatform), 0, $iLimit));
            }
            return $aOut + ['platforms' => $aPlatforms, 'departures' => $aDepartures, 'attribution' => $aConfig['attribution']];
        }

        $aRows = self::upcomingRows($JourneyModel, $sStopId, $iLimit);
        $aDirectionLabels = null;
        if (isset($aConfig['direction_reference_stop_id'])) {
            [$aRows, $aDirectionLabels] = self::withDirections($JourneyModel, $aRows, $sStopId, $aStop['name'], $aConfig['direction_reference_stop_id']);
        }
        $aRows = self::withLive($Matcher, $TripMatcher, $sStopId, $aRows);
        return $aOut + [
            'platforms' => [],
            'directionLabels' => $aDirectionLabels,
            'departures' => array_slice(self::departureItems($aRows, $bUseLastStopHeadsign), 0, $iLimit),
            'attribution' => $aConfig['attribution'],
        ];
    }

    // Salidas del horario de las próximas 4 horas o, si no hay ninguna (de noche), de las próximas 24.
    private static function upcomingRows(ServiceJourney $JourneyModel, string|int $StopId, int $iLimit): array
    {
        $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, 4 * 3600);
        if (empty($aRows)) {
            $aRows = $JourneyModel->upcomingAtStop($StopId, $iLimit, 24 * 3600);
        }
        return $aRows;
    }

    // En las redes con GTFS-Realtime (Metro), el emparejador de su feed; si no, null.
    private static function tripMatcher(ServiceJourney $JourneyModel): TripUpdatesMatcher|null
    {
        if (!Config::hasLive('trip-updates')) {
            return null;
        }
        $aConfig = Config::current();
        $aTrips = (new GtfsRealtimeClient($aConfig))->fetchTripUpdates();
        return new TripUpdatesMatcher($aTrips, $JourneyModel, $aConfig['gtfs_rt']['match_tolerance_seconds']);
    }

    // El directo sobre las salidas del horario: el algoritmo de los autobuses y, en Metro, las horas del feed (sin los
    // trenes que ya han salido).
    private static function withLive(object $Matcher, TripUpdatesMatcher|null $TripMatcher, string $sStopId, array $aRows): array
    {
        $aRows = $Matcher->enrich($aRows);
        if ($TripMatcher === null) {
            return $aRows;
        }
        $aRows = $TripMatcher->enrich($sStopId, $aRows);
        if ($TripMatcher->hasData()) {
            $aRows = $TripMatcher->withoutDeparted($aRows, Calendar::nowSecondsSinceMidnight());
        }
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
                'delayMinutes' => self::delayMinutes($aRow['delaySeconds']),
                'direction' => $aRow['direction'] ?? null,
                'platformId' => $sPlatformId,
            ];
        }
        return $aDepartures;
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
