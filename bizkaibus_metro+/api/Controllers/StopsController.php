<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
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
        $aStop = $StopModel->find((int)$aParams['id']);
        if ($aStop === null) {
            Response::error('Stop not found', 404);
            return;
        }
        $aStop['lines'] = $StopModel->linesServing((int)$aParams['id']);
        Response::json($aStop);
    }

    public function departures(Request $Req, array $aParams): void
    {
        $Pdo = Database::connection();
        $iStopId = (int)$aParams['id'];

        $aStop = (new Stop($Pdo))->find($iStopId);
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
        $aRows = $JourneyModel->upcomingAtStop($iStopId, $iLimit, 4 * 3600, $iReferenceStopId);

        $aVmMap = [];
        if (isset($aConfig['siri'])) {
            $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
        }

        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel);
        $aEnriched = $Matcher->enrich($aRows);

        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }
        $bIsMetro = $sNetwork === 'metro';

        $iNow = Calendar::nowSecondsSinceMidnight();
        $aDepartures = array_map(function ($aRow) use ($iNow, $bIsMetro) {
            $sHeadsign = $aRow['headsign'];
            if ($bIsMetro && !empty($aRow['last_stop_name'])) {
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
            return [
                'lineId' => (int)$aRow['line_id'],
                'lineCode' => $aRow['line_code'],
                'lineName' => $aRow['line_name'],
                'headsign' => $sHeadsign,
                'tripKey' => $aRow['line_id'] . '-' . $aRow['trip_number'] . '-' . $aRow['first_departure_seconds'],
                'scheduledTime' => Calendar::secondsToHm((int)$aRow['arrival_seconds']),
                'etaMinutes' => (int)round(($aRow['etaSeconds'] - $iNow) / 60),
                'status' => $aRow['status'],
                'delayMinutes' => $iDelayMinutes,
                'direction' => $sDirection,
            ];
        }, $aEnriched);

        $aDepartures = array_values(array_filter($aDepartures, fn($aD) => $aD['etaMinutes'] >= -2));
        $aDepartures = array_slice($aDepartures, 0, $iLimit);

        Response::json([
            'stop' => ['id' => $aStop['id'], 'name' => $aStop['name']],
            'departures' => $aDepartures,
            'attribution' => $aConfig['attribution'],
        ]);
    }

    public function tripStops(Request $Req, array $aParams): void
    {
        $aTripKeyParts = array_pad(explode('-', $aParams['tripKey'], 3), 3, null);
        [$sLineIdRaw, $sTripNumber, $sFirstDepartureSecondsRaw] = $aTripKeyParts;
        if ($sLineIdRaw === null || $sTripNumber === null || $sFirstDepartureSecondsRaw === null) {
            Response::error('Invalid trip key', 422);
            return;
        }
        $iLineId = (int)$sLineIdRaw;
        $iFirstDepartureSeconds = (int)$sFirstDepartureSecondsRaw;
        $iTargetStopId = $Req->queryInt('stopId');

        $Pdo = Database::connection();
        $JourneyModel = new ServiceJourney($Pdo);
        $aJourney = $JourneyModel->findByLineAndTrip($iLineId, $sTripNumber, $iFirstDepartureSeconds);
        if ($aJourney === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $aStopsOut = array_map(function ($aStop) use ($iTargetStopId) {
            return [
                'stopId' => (int)$aStop['stop_id'],
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
                'isTarget' => $iTargetStopId !== null && (int)$aStop['stop_id'] === $iTargetStopId,
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
