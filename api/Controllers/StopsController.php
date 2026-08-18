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
        $rows = $journeyModel->upcomingAtStop($stopId, $limit);

        $config = Config::current();
        $vmMap = [];
        if (isset($config['siri'])) {
            $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();
        }

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

        // La consulta trae también buses "pasados por hora programada" para
        // darle al retraso en vivo ocasión de confirmar si siguen en camino
        // (ver ServiceJourney::PAST_GRACE_SECONDS). Los que de verdad ya se
        // fueron (ni siquiera el en vivo los sostiene) se descartan aquí.
        $departures = array_values(array_filter($departures, fn($d) => $d['etaMinutes'] >= -2));
        $departures = array_slice($departures, 0, $limit);

        Response::json([
            'stop' => ['id' => $stop['id'], 'name' => $stop['name']],
            'departures' => $departures,
            'attribution' => $config['attribution'],
        ]);
    }

    /**
     * Secuencia completa de paradas/estaciones de un trayecto programado, con
     * la hora de paso por cada una — sin tiempo real. Es el equivalente de
     * RealtimeController::vehicle() para redes sin SIRI (Metro Bilbao): en vez
     * de "dónde está el vehículo ahora", responde "por dónde pasa este
     * trayecto hasta llegar a mi parada". El stopId de referencia llega por
     * query param ?stopId= y marca esa fila con isTarget=true.
     */
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

        Response::json([
            'lineCode' => $journey['line_code'],
            'lineName' => $journey['line_name'],
            'headsign' => $journey['headsign'],
            'stops' => $stopsOut,
        ]);
    }
}
