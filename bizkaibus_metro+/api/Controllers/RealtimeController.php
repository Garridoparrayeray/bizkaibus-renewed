<?php

namespace Controllers;

use Core\Database;
use Core\Request;
use Core\Response;
use Models\LineModel;
use Models\ServiceJourney;
use Services\Calendar;
use Services\RealtimeMatcher;
use Services\SiriVehicleMonitoringClient;

class RealtimeController
{

    public function lineLive(Request $Req, array $aParams): void
    {
        $iLineId = (int)$aParams['id'];
        $Pdo = Database::connection();

        $LineModel = new LineModel($Pdo);
        $aLine = $LineModel->find($iLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }

        $aConfig = require __DIR__ . '/../Config/config.php';
        $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();

        $JourneyStmt = $Pdo->prepare('
            SELECT sj.trip_number, jp.headsign
            FROM service_journeys sj
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ?
            LIMIT 1
        ');
        $StopStmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');

        $aVehicles = [];
        foreach ($aVmMap as $sKey => $aEntries) {
            [$sKeyLineId, $sTripNumber] = explode('|', $sKey);
            if ((int)$sKeyLineId !== $iLineId) {
                continue;
            }
            foreach ($aEntries as $aEntry) {
                if ($aEntry['currentStopId'] === null) {
                    continue;
                }
                $StopStmt->execute([$aEntry['currentStopId']]);
                $aStop = $StopStmt->fetch();
                if ($aStop === null) {
                    continue;
                }
                $JourneyStmt->execute([$iLineId, $sTripNumber]);
                $aJourney = $JourneyStmt->fetch();
                $sHeadsign = null;
                if (isset($aJourney['headsign'])) {
                    $sHeadsign = $aJourney['headsign'];
                }

                $aVehicles[] = [
                    'vehicleRef' => $aEntry['vehicleRef'],
                    'delayMinutes' => (int)round($aEntry['delaySeconds'] / 60),
                    'headsign' => $sHeadsign,
                    'currentStop' => [
                        'id' => (int)$aStop['id'],
                        'name' => $aStop['name'],
                        'lat' => (float)$aStop['lat'],
                        'lon' => (float)$aStop['lon'],
                    ],
                ];
            }
        }

        Response::json([
            'line' => $aLine,
            'patterns' => $LineModel->patternsWithStops($iLineId),
            'vehicles' => $aVehicles,
        ]);
    }

    public function vehicle(Request $Req, array $aParams): void
    {
        [$sLineIdRaw, $sTripNumber, $sFirstDepartureSecondsRaw] = array_pad(explode('-', $aParams['tripKey'], 3), 3, null);
        if ($sLineIdRaw === null || $sTripNumber === null || $sFirstDepartureSecondsRaw === null) {
            Response::error('Invalid vehicle key', 422);
            return;
        }
        $iLineId = (int)$sLineIdRaw;
        $iFirstDepartureSeconds = (int)$sFirstDepartureSecondsRaw;

        $Pdo = Database::connection();
        $JourneyModel = new ServiceJourney($Pdo);
        $aJourney = $JourneyModel->findByLineAndTrip($iLineId, $sTripNumber, $iFirstDepartureSeconds);
        if ($aJourney === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $aConfig = require __DIR__ . '/../Config/config.php';
        $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel);
        $aLive = $Matcher->lookup($iLineId, $sTripNumber, $iFirstDepartureSeconds);

        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();

        $aStopsOut = array_map(function ($aStop) use ($Matcher, $aJourney, $aLive, $iNow) {

            $bAlreadyPassed = $aLive !== null && $aLive['order'] !== null && (int)$aStop['seq_order'] < (int)$aLive['order'];

            $iEtaMinutes = null;
            if (!$bAlreadyPassed) {
                [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive);
                $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
            }

            return [
                'stopId' => (int)$aStop['stop_id'],
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
                'etaMinutes' => $iEtaMinutes,
                'isPast' => $bAlreadyPassed,
                'isCurrent' => $aLive !== null && $aLive['currentStopId'] === (int)$aStop['stop_id'],
            ];
        }, $aStops);

        $iDelaySeconds = 0;
        if (isset($aLive['delaySeconds'])) {
            $iDelaySeconds = $aLive['delaySeconds'];
        }

        $sStatus = 'scheduled';
        if ($aLive !== null) {
            $sStatus = 'live';
        }

        $iDelayMinutes = 0;
        if ($iDelaySeconds !== 0) {
            $iDelayMinutes = (int)round($iDelaySeconds / 60);
        }

        $sVehicleRef = null;
        if (isset($aLive['vehicleRef'])) {
            $sVehicleRef = $aLive['vehicleRef'];
        }

        Response::json([
            'lineCode' => $aJourney['line_code'],
            'lineName' => $aJourney['line_name'],
            'headsign' => $aJourney['headsign'],
            'status' => $sStatus,
            'vehicleRef' => $sVehicleRef,
            'delayMinutes' => $iDelayMinutes,
            'stops' => $aStopsOut,
        ]);
    }
}
