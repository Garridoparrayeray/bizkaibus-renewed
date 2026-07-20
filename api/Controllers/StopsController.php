<?php

namespace Controllers;

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
        $rows = $journeyModel->upcomingAtStop($stopId, $limit);

        $config = require __DIR__ . '/../Config/config.php';
        $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();

        $matcher = new RealtimeMatcher($vmMap, $journeyModel);
        $enriched = $matcher->enrich($rows);

        $now = Calendar::nowSecondsSinceMidnight();
        $departures = array_map(function ($row) use ($now) {
            return [
                'lineId' => (int)$row['line_id'],
                'lineCode' => $row['line_code'],
                'lineName' => $row['line_name'],
                'headsign' => $row['headsign'],
                'tripKey' => $row['line_id'] . '-' . $row['trip_number'] . '-' . $row['first_departure_seconds'],
                'scheduledTime' => Calendar::secondsToHm((int)$row['arrival_seconds']),
                'etaMinutes' => (int)round(($row['etaSeconds'] - $now) / 60),
                'status' => $row['status'],
                'delayMinutes' => $row['delaySeconds'] !== 0 ? (int)round($row['delaySeconds'] / 60) : 0,
            ];
        }, $enriched);

        Response::json([
            'stop' => ['id' => $stop['id'], 'name' => $stop['name']],
            'departures' => $departures,
            'attribution' => $config['attribution'],
        ]);
    }
}
