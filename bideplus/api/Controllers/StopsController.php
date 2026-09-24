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
use Services\RealtimeMatcher;
use Services\SiriVehicleMonitoringClient;

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
        $sNetworkForNearby = Config::current()['network'] ?? 'bus';
        $bUseLastStopHeadsign = in_array($sNetworkForNearby, ['metro', 'renfe'], true);

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
        $aStopIds = empty($aPlatforms) ? [$StopId] : array_column($aPlatforms, 'id');
        $iNow = Calendar::nowSecondsSinceMidnight();

        $aBest = null;
        foreach ($aStopIds as $Id) {
            foreach ($JourneyModel->upcomingAtStop($Id, 3, 4 * 3600) as $aRow) {
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
        $Pdo = Database::connection();
        $sStopId = $aParams['id'];
        $StopModel = new Stop($Pdo);

        $aStop = $StopModel->find($sStopId);
        if ($aStop === null) {
            Response::error('Stop not found', 404);
            return;
        }

        $iLimit = $Req->queryInt('limit', 8);
        $JourneyModel = new ServiceJourney($Pdo);
        $aConfig = Config::current();
        $iReferenceStopId = null;
        if (isset($aConfig['direction_reference_stop_id'])) {
            $iReferenceStopId = $aConfig['direction_reference_stop_id'];
        }

        $aVmMap = [];
        if (isset($aConfig['siri'])) {
            $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
        }
        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel);

        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }
        $bUseLastStopHeadsign = in_array($sNetwork, ['metro', 'renfe'], true);

        $aPlatforms = $StopModel->platformsFor($sStopId);

        if (!empty($aPlatforms)) {
            $aDepartures = [];
            foreach ($aPlatforms as $aPlatform) {
                $aRows = $JourneyModel->upcomingAtStop($aPlatform['id'], $iLimit, 4 * 3600);
                $aEnriched = $Matcher->enrich($aRows);
                $aPlatformDepartures = $this->buildDepartureItems($aEnriched, $bUseLastStopHeadsign, $aPlatform);
                foreach (array_slice($aPlatformDepartures, 0, $iLimit) as $aDeparture) {
                    $aDepartures[] = $aDeparture;
                }
            }

            Response::json([
                'stop' => ['id' => $aStop['id'], 'name' => $aStop['name']],
                'platforms' => $aPlatforms,
                'departures' => $aDepartures,
                'attribution' => $aConfig['attribution'],
            ]);
            return;
        }

        $aRows = $JourneyModel->upcomingAtStop($sStopId, $iLimit, 4 * 3600);

        $aDirectionLabels = null;
        if ($iReferenceStopId !== null) {
            $aDirections = $JourneyModel->directionsByPattern($sStopId, $iReferenceStopId);
            foreach ($aRows as &$aRow) {
                $aRow['direction'] = $aDirections[$aRow['journey_pattern_id']]['direction'] ?? 'toward_reference';
            }
            unset($aRow);
            $aTermini = ['toward_reference' => [], 'away_from_reference' => []];
            foreach ($aDirections as $aInfo) {
                if ($aInfo['terminus'] !== $aStop['name'] && !in_array($aInfo['terminus'], $aTermini[$aInfo['direction']], true)) {
                    $aTermini[$aInfo['direction']][] = $aInfo['terminus'];
                }
            }
            $aDirectionLabels = array_map(fn($aNames) => implode(' · ', $aNames), $aTermini);
        }

        $aEnriched = $Matcher->enrich($aRows);
        $aDepartures = array_slice($this->buildDepartureItems($aEnriched, $bUseLastStopHeadsign), 0, $iLimit);

        Response::json([
            'stop' => ['id' => $aStop['id'], 'name' => $aStop['name']],
            'platforms' => [],
            'directionLabels' => $aDirectionLabels,
            'departures' => $aDepartures,
            'attribution' => $aConfig['attribution'],
        ]);
    }

    private function buildDepartureItems(array $aEnriched, bool $bUseLastStopHeadsign, array|null $aPlatform = null): array
    {
        $iNow = Calendar::nowSecondsSinceMidnight();
        $aDepartures = array_map(function ($aRow) use ($iNow, $bUseLastStopHeadsign, $aPlatform) {
            $sHeadsign = $aRow['headsign'];
            if ($bUseLastStopHeadsign && !empty($aRow['last_stop_name'])) {
                $sHeadsign = $aRow['last_stop_name'];
            }
            $iDelayMinutes = 0;
            if ($aRow['delaySeconds'] !== 0) {
                $iDelayMinutes = (int)round($aRow['delaySeconds'] / 60);
            }
            $sDirection = null;
            if (isset($aRow['direction'])) {
                $sDirection = $aRow['direction'];
            }
            $sPlatformId = null;
            if ($aPlatform !== null) {
                $sPlatformId = $aPlatform['id'];
            }
            return [
                'lineId' => Ids::forOutput($aRow['line_id']),
                'lineCode' => $aRow['line_code'],
                'lineName' => $aRow['line_name'],
                'headsign' => $sHeadsign,
                'tripKey' => $aRow['line_id'] . '-' . $aRow['trip_number'] . '-' . $aRow['first_departure_seconds'],
                'scheduledTime' => Calendar::secondsToHm((int)$aRow['arrival_seconds']),
                'etaMinutes' => (int)round(($aRow['etaSeconds'] - $iNow) / 60),
                'status' => $aRow['status'],
                'delayMinutes' => $iDelayMinutes,
                'direction' => $sDirection,
                'platformId' => $sPlatformId,
            ];
        }, $aEnriched);

        return array_values(array_filter($aDepartures, fn($aD) => $aD['etaMinutes'] >= -2));
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
        $aConfig = Config::current();
        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }
        if ($sNetwork === 'metro' && !empty($aStops)) {
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
