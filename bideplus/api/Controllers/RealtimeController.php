<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Http;
use Core\Ids;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\LineModel;
use Models\ServiceJourney;
use Services\Calendar;
use Services\GtfsRealtimeClient;
use Engine\RealtimeMatcher;
use Services\SiriVehicleMonitoringClient;
use Engine\TripUpdatesMatcher;

class RealtimeController
{

    /*
     * Diagnóstico de la fuente de los buses en directo: pide a Open Data Euskadi desde el servidor (como lo hace la app)
     * y cuenta qué trae. Sirve para ver si el feed responde desde el despliegue y si trae GPS, hora del dato y retraso.
     */
    /*
     * Estado de un viaje en su ficha. Con datos en vivo es «live». Sin ellos solo se sabe lo que dice el horario: no se
     * afirma que el bus ya salió (la hora pasada no prueba que haya salido), solo que el viaje terminó si ya pasó su
     * última parada. Si no, se queda en «scheduled» y la app lo muestra como «Solo horario».
     */
    public static function tripStatus(bool $bLive, array $aStops, int $iNowSeconds): string
    {
        if ($bLive) {
            return 'live';
        }
        if (!empty($aStops) && $iNowSeconds > (int)$aStops[count($aStops) - 1]['arrival_seconds']) {
            return 'finished';
        }
        return 'scheduled';
    }

    public function status(Request $Req, array $aParams): void
    {
        $aConfig = Config::current();
        if (!Config::hasLive('vehicle-monitoring') || Config::hasLive('trip-updates')) {
            Response::error('This network has no SIRI feed', 404);
            return;
        }
        $sUrl = $aConfig['siri']['vehicle_monitoring_url'];
        $fStart = microtime(true);
        $aOut = ['network' => $aConfig['slug'], 'source' => parse_url($sUrl, PHP_URL_HOST), 'ok' => false];
        try {
            $sBody = Http::get($sUrl, $aConfig['siri']['http_timeout_seconds']);
            $aOut['bytes'] = \strlen($sBody);
            $aMap = SiriVehicleMonitoringClient::parse($sBody);
            $iVehicles = 0;
            $iGps = 0;
            $iTime = 0;
            $iDelay = 0;
            foreach ($aMap as $aEntries) {
                foreach ($aEntries as $aEntry) {
                    $iVehicles++;
                    if ($aEntry['lat'] !== null) {
                        $iGps++;
                    }
                    if ($aEntry['recordedSeconds'] !== null) {
                        $iTime++;
                    }
                    if ($aEntry['delaySeconds'] !== 0) {
                        $iDelay++;
                    }
                }
            }
            $aOut += ['vehicles' => $iVehicles, 'withGps' => $iGps, 'withTime' => $iTime, 'withDelay' => $iDelay];
            $aOut['ok'] = $iVehicles > 0;
        } catch (\Throwable $Ex) {
            $aOut['error'] = 'La fuente no respondió';
        }
        $aOut['ms'] = (int)round((microtime(true) - $fStart) * 1000);
        Response::json($aOut);
    }

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

        $aVmMap = [];
        if (Config::hasLive('vehicle-monitoring')) {
            $aVmMap = (new SiriVehicleMonitoringClient(Config::current()))->fetchActiveTrips();
        }
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
                if (!\is_array($aStop)) {
                    continue;
                }
                $JourneyStmt->execute([$sLineId, $sTripNumber]);
                $aJourney = $JourneyStmt->fetch();
                $sHeadsign = null;
                if (isset($aJourney['headsign'])) {
                    $sHeadsign = $aJourney['headsign'];
                }

                $iDelaySeconds = $Matcher->displayDelaySeconds($Matcher->journeyIdFor($aEntry), $aEntry);

                $aVehicles[] = [
                    'vehicleRef' => $aEntry['vehicleRef'],
                    'delayMinutes' => $Matcher->displayDelayMinutes($iDelaySeconds),
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

        $aCurrentConfig = Config::current();
        if (Config::hasLive('trip-updates')) {
            $this->vehicleFromTripUpdates($aCurrentConfig, $JourneyModel, $aJourney, $Req->query('stopId'));
            return;
        }

        // Sin fuente en directo, el mapa queda vacío y el viaje sale con su horario oficial.
        $aVmMap = [];
        if (Config::hasLive('vehicle-monitoring')) {
            $aVmMap = (new SiriVehicleMonitoringClient($aCurrentConfig))->fetchActiveTrips();
        }
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
            $iEtaAt = null;
            if (!$bAlreadyPassed) {
                $iEta = (int)$aStop['arrival_seconds'];
                if ($bLive) {
                    [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order']);
                }
                $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
                $iEtaAt = time() + ($iEta - $iNow);
            }

            return [
                'stopId' => Ids::forOutput($aStop['stop_id']),
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$aStop['arrival_seconds']),
                'etaMinutes' => $iEtaMinutes,
                'etaAt' => $iEtaAt,
                'isPast' => $bAlreadyPassed,
                'isCurrent' => $iNextSeqOrder !== null && (int)$aStop['seq_order'] === $iNextSeqOrder,
            ];
        }, $aStops);

        $iDelaySeconds = 0;
        if ($bLive) {
            $iDelaySeconds = $Matcher->displayDelaySeconds($aJourney['id'], $aLive);
        }

        $sStatus = self::tripStatus($bLive, $aStops, $iNow);

        $iDelayMinutes = $Matcher->displayDelayMinutes($iDelaySeconds);

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

    /*
     * Redes con GTFS-Realtime (Metro Bilbao): el feed da, por cada tren, la hora prevista en las paradas que le
     * quedan. Se empareja el viaje con el feed como en las salidas de una parada (mismo reparto) y se devuelve
     * el recorrido con esas horas. Las paradas que ya no aparecen en el feed, antes de la primera que sí, ya
     * están pasadas.
     */
    private function vehicleFromTripUpdates(array $aConfig, ServiceJourney $JourneyModel, array $aJourney, string|null $sPreferredStopId): void
    {
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();
        $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();

        $aStopIds = array_map(fn($aStop) => (string)$aStop['stop_id'], $aStops);
        $sAnchorStopId = null;
        if ($sPreferredStopId !== null && in_array($sPreferredStopId, $aStopIds, true)) {
            $sAnchorStopId = $sPreferredStopId;
        }
        if ($sAnchorStopId === null) {
            foreach ($aStops as $aStop) {
                if ((int)$aStop['arrival_seconds'] >= $iNow - 60) {
                    $sAnchorStopId = (string)$aStop['stop_id'];
                    break;
                }
            }
        }

        $aFeedStops = null;
        $TripMatcher = new TripUpdatesMatcher((new GtfsRealtimeClient($aConfig))->fetchTripUpdates(), $JourneyModel, $aConfig['gtfs_rt']['match_tolerance_seconds'], $iMidnight);
        if ($sAnchorStopId !== null && $TripMatcher->hasData()) {
            $aRows = $JourneyModel->upcomingAtStop($sAnchorStopId, 40, 3 * 3600);
            foreach ($aRows as $iRow => $aRow) {
                $aRows[$iRow]['status'] = 'scheduled';
            }
            $aFeedStops = $TripMatcher->liveTrip($sAnchorStopId, $aRows, $aJourney['id']);
        }

        $aPredicted = [];
        $iFirstPredictedOrder = null;
        if ($aFeedStops !== null) {
            foreach ($aFeedStops as [$sFeedStopId, $iTime]) {
                $aPredicted[$sFeedStopId] = $iTime - $iMidnight;
            }
            foreach ($aStops as $aStop) {
                if (isset($aPredicted[(string)$aStop['stop_id']])) {
                    $iFirstPredictedOrder = (int)$aStop['seq_order'];
                    break;
                }
            }
        }
        $bLive = $iFirstPredictedOrder !== null;

        $iDelaySeconds = 0;
        $bCurrentSet = false;
        $aStopsOut = [];
        foreach ($aStops as $aStop) {
            $sStopId = (string)$aStop['stop_id'];
            $iScheduled = (int)$aStop['arrival_seconds'];
            $iEta = null;
            $bAlreadyPassed = false;
            if ($bLive) {
                if (isset($aPredicted[$sStopId]) && $aPredicted[$sStopId] < $iNow - TripUpdatesMatcher::LIVE_DEPARTED_AFTER_SECONDS) {
                    // El feed mantiene una parada hasta ~110 s después de su hora prevista: el tren ya la ha dejado.
                    $bAlreadyPassed = true;
                } elseif (isset($aPredicted[$sStopId])) {
                    $iEta = $aPredicted[$sStopId];
                    if (!$bCurrentSet) {
                        $iDelaySeconds = $iEta - $iScheduled;
                    }
                } else {
                    $bAlreadyPassed = (int)$aStop['seq_order'] < $iFirstPredictedOrder;
                    if (!$bAlreadyPassed) {
                        $iEta = $iScheduled + $iDelaySeconds;
                    }
                }
            } else {
                $bAlreadyPassed = $iScheduled < $iNow - 60;
                if (!$bAlreadyPassed) {
                    $iEta = $iScheduled;
                }
            }

            $bIsCurrent = false;
            if (!$bAlreadyPassed && !$bCurrentSet) {
                $bIsCurrent = true;
                $bCurrentSet = true;
            }

            $iEtaMinutes = null;
            $iEtaAt = null;
            if ($iEta !== null) {
                $iEtaMinutes = (int)round(($iEta - $iNow) / 60);
                $iEtaAt = time() + ($iEta - $iNow);
            }
            $aStopsOut[] = [
                'stopId' => Ids::forOutput($aStop['stop_id']),
                'name' => $aStop['name'],
                'scheduledTime' => Calendar::secondsToHm($iScheduled),
                'etaMinutes' => $iEtaMinutes,
                'etaAt' => $iEtaAt,
                'isPast' => $bAlreadyPassed,
                'isCurrent' => $bIsCurrent,
            ];
        }

        $sStatus = self::tripStatus($bLive, $aStops, $iNow);

        $iDelayMinutes = 0;
        if (abs($iDelaySeconds) >= 60) {
            $iDelayMinutes = (int)round($iDelaySeconds / 60);
        }

        Response::json([
            'lineCode' => $aJourney['line_code'],
            'lineName' => $aJourney['line_name'],
            'headsign' => $aJourney['headsign'],
            'status' => $sStatus,
            'vehicleRef' => null,
            'feedTimes' => true,
            'delayMinutes' => $iDelayMinutes,
            'stops' => $aStopsOut,
        ]);
    }
}
