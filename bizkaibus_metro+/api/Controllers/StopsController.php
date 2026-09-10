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

    public function show(Request $request, array $params): void
    {
        $pdo = Database::connection();
        $stopModel = new Stop($pdo);
        $stop = $stopModel->find((int)$params['id']);
        if ($stop === null) {
            Response::error('Stop not found', 404);
            return;
        }
        $stop['lines'] = $stopModel->linesServing((int)$params['id']);
        Response::json($stop);
    }

    public function departures(Request $request, array $params): void
    {
        $pdo = Database::connection();
        $stopId = (int)$params['id'];

        $stop = (new Stop($pdo))->find($stopId);
        if ($stop === null) {
            Response::error('Stop not found', 404);
            return;
        }

        $limit = $request->queryInt('limit', 8);
        $journeyModel = new ServiceJourney($pdo);
        $config = Config::current();
        $referenceStopId = null;
        if (isset($config['direction_reference_stop_id'])) {
            $referenceStopId = $config['direction_reference_stop_id'];
        }
        $rows = $journeyModel->upcomingAtStop($stopId, $limit, 4 * 3600, $referenceStopId);

        $vmMap = [];
        if (isset($config['siri'])) {
            $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();
        }

        $matcher = new RealtimeMatcher($vmMap, $journeyModel);
        $enriched = $matcher->enrich($rows);

        $network = 'bus';
        if (isset($config['network'])) {
            $network = $config['network'];
        }
        $isMetro = $network === 'metro';

        $now = Calendar::nowSecondsSinceMidnight();
        $departures = array_map(function ($row) use ($now, $isMetro) {
            $headsign = $row['headsign'];
            if ($isMetro && !empty($row['last_stop_name'])) {
                $headsign = $row['last_stop_name'];
            }
            $delayMinutes = 0;
            if ($row['delaySeconds'] !== 0) {
                $delayMinutes = (int)round($row['delaySeconds'] / 60);
            }
            $direction = null;
            if (isset($row['direction'])) {
                $direction = $row['direction'];
            }
            return [
                'lineId' => (int)$row['line_id'],
                'lineCode' => $row['line_code'],
                'lineName' => $row['line_name'],
                'headsign' => $headsign,
                'tripKey' => $row['line_id'] . '-' . $row['trip_number'] . '-' . $row['first_departure_seconds'],
                'scheduledTime' => Calendar::secondsToHm((int)$row['arrival_seconds']),
                'etaMinutes' => (int)round(($row['etaSeconds'] - $now) / 60),
                'status' => $row['status'],
                'delayMinutes' => $delayMinutes,
                'direction' => $direction,
            ];
        }, $enriched);

        $departures = array_values(array_filter($departures, fn($d) => $d['etaMinutes'] >= -2));
        $departures = array_slice($departures, 0, $limit);

        Response::json([
            'stop' => ['id' => $stop['id'], 'name' => $stop['name']],
            'departures' => $departures,
            'attribution' => $config['attribution'],
        ]);
    }

    public function tripStops(Request $request, array $params): void
    {
        $tripKeyParts = array_pad(explode('-', $params['tripKey'], 3), 3, null);
        [$lineId, $tripNumber, $firstDepartureSeconds] = $tripKeyParts;
        if ($lineId === null || $tripNumber === null || $firstDepartureSeconds === null) {
            Response::error('Invalid trip key', 422);
            return;
        }
        $lineId = (int)$lineId;
        $firstDepartureSeconds = (int)$firstDepartureSeconds;
        $targetStopId = $request->queryInt('stopId');

        $pdo = Database::connection();
        $journeyModel = new ServiceJourney($pdo);
        $journey = $journeyModel->findByLineAndTrip($lineId, $tripNumber, $firstDepartureSeconds);
        if ($journey === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $stops = $journeyModel->stopsForJourney($journey['id']);
        $stopsOut = array_map(function ($stop) use ($targetStopId) {
            return [
                'stopId' => (int)$stop['stop_id'],
                'name' => $stop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$stop['arrival_seconds']),
                'isTarget' => $targetStopId !== null && (int)$stop['stop_id'] === $targetStopId,
            ];
        }, $stops);

        $headsign = $journey['headsign'];
        $config = Config::current();
        $network = 'bus';
        if (isset($config['network'])) {
            $network = $config['network'];
        }
        if ($network === 'metro' && !empty($stops)) {
            $headsign = end($stops)['name'];
        }

        Response::json([
            'lineCode' => $journey['line_code'],
            'lineName' => $journey['line_name'],
            'headsign' => $headsign,
            'stops' => $stopsOut,
        ]);
    }
}
