<?php

namespace Controllers;

use Core\Database;
use Core\Ids;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\LineModel;
use Models\ServiceJourney;
use Services\Calendar;
use Services\RealtimeMatcher;
use Services\SiriVehicleMonitoringClient;

class RealtimeController
{

    public function lineLive(Request $Req, array $aParams): void
    {
        $sLineId = $aParams['id'];
        $Pdo = Database::connection();

        $LineModel = new LineModel($Pdo);
        $aLine = $LineModel->find($sLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }

        $aConfig = require __DIR__ . '/../Config/config.php';
        $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
        $Matcher = new RealtimeMatcher($aVmMap, new ServiceJourney($Pdo));

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
            if ((string)$sKeyLineId !== (string)$sLineId) {
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
                $JourneyStmt->execute([$sLineId, $sTripNumber]);
                $aJourney = $JourneyStmt->fetch();
                $sHeadsign = null;
                if (isset($aJourney['headsign'])) {
                    $sHeadsign = $aJourney['headsign'];
                }

                $iDelaySeconds = $Matcher->currentDelaySeconds($Matcher->journeyIdFor($aEntry), $aEntry);

                $aVehicles[] = [
                    'vehicleRef' => $aEntry['vehicleRef'],
                    'delayMinutes' => (int)round($iDelaySeconds / 60),
                    'headsign' => $sHeadsign,
                    'currentStop' => [
                        'id' => Ids::forOutput($aStop['id']),
                        'name' => $aStop['name'],
                        'lat' => (float)$aStop['lat'],
                        'lon' => (float)$aStop['lon'],
                    ],
                ];
            }
        }

        Response::json([
            'line' => $aLine,
            'patterns' => $LineModel->patternsWithStops($sLineId),
            'vehicles' => $aVehicles,
        ]);
    }

    public function vehicle(Request $Req, array $aParams): void
    {
        $aTripKey = TripKey::parse($aParams['tripKey']);
        if ($aTripKey === null) {
            Response::error('Invalid vehicle key', 422);
            return;
        }
        [$sLineId, $sTripNumber, $iFirstDepartureSeconds] = $aTripKey;

        $Pdo = Database::connection();
        $JourneyModel = new ServiceJourney($Pdo);
        $aJourney = $JourneyModel->findByLineAndTrip($sLineId, $sTripNumber, $iFirstDepartureSeconds);
        if ($aJourney === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $aConfig = require __DIR__ . '/../Config/config.php';
        $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel);
        $aLive = $Matcher->lookup($sLineId, $sTripNumber, $iFirstDepartureSeconds, $aJourney['id']);
        $bLive = $Matcher->isLive($aJourney['id'], $aLive);
        $aPosition = $Matcher->positionFor($aJourney['id'], $aLive);

        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();

        $iNextSeqOrder = null;
        if ($aPosition !== null) {
            foreach ($aStops as $aStop) {
                if ((int)$aStop['arrival_seconds'] >= $aPosition['scheduledSeconds']) {
                    $iNextSeqOrder = (int)$aStop['seq_order'];
                    break;
                }
            }
        } elseif ($bLive) {
            foreach ($aStops as $aStop) {
                if ($aStop['stop_id'] === $aLive['currentStopId']) {
                    $iNextSeqOrder = (int)$aStop['seq_order'];
                    break;
                }
            }
        }

        $aStopsOut = array_map(function ($aStop) use ($Matcher, $aJourney, $aLive, $bLive, $aPosition, $iNextSeqOrder, $iNow) {

            $bAlreadyPassed = false;
            if ($aPosition !== null) {
                $bAlreadyPassed = (int)$aStop['arrival_seconds'] < $aPosition['scheduledSeconds'];
            } elseif ($bLive && isset($aLive['order'])) {
                $bAlreadyPassed = (int)$aStop['seq_order'] < (int)$aLive['order'];
            }

            $iEtaMinutes = null;
            if (!$bAlreadyPassed) {
                $iEta = (int)$aStop['arrival_seconds'];
                if ($bLive) {
                    [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive);
                }
                $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
            }

            return [
                'stopId' => Ids::forOutput($aStop['stop_id']),
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
                'etaMinutes' => $iEtaMinutes,
                'isPast' => $bAlreadyPassed,
                'isCurrent' => $iNextSeqOrder !== null && (int)$aStop['seq_order'] === $iNextSeqOrder,
            ];
        }, $aStops);

        $iDelaySeconds = 0;
        if ($bLive) {
            $iDelaySeconds = $Matcher->currentDelaySeconds($aJourney['id'], $aLive);
        }

        $sStatus = 'scheduled';
        if ($bLive) {
            $sStatus = 'live';
        } elseif (!empty($aStops)) {
            $iFirstArrival = (int)$aStops[0]['arrival_seconds'];
            $iLastArrival = (int)$aStops[count($aStops) - 1]['arrival_seconds'];
            if ($iNow > $iLastArrival) {
                $sStatus = 'finished';
            } elseif ($iNow >= $iFirstArrival) {
                $sStatus = 'departed';
            }
        }

        $iDelayMinutes = 0;
        if ($iDelaySeconds !== 0) {
            $iDelayMinutes = (int)round($iDelaySeconds / 60);
        }

        $sVehicleRef = null;
        if ($bLive && isset($aLive['vehicleRef'])) {
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
