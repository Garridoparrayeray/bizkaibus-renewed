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

/** "Detalle del bus": muestra lo que hay realmente en los datos — flota,
 * retraso, parada actual, paradas restantes. Nada de modelo/amenities inventados. */
class RealtimeController
{
    /**
     * "En vivo ahora" de una línea: la ruta (paradas ordenadas de cada patrón,
     * para dibujar en el mapa) más los vehículos activos ahora mismo. La
     * posición del vehículo es su última parada confirmada — el feed no da
     * GPS continuo, solo parada + orden, así que el marcador va en una parada
     * real, nunca interpolado entre medias.
     */
    public function lineLive(Request $request, array $params): void
    {
        $lineId = (int)$params['id'];
        $pdo = Database::connection();

        $lineModel = new LineModel($pdo);
        $line = $lineModel->find($lineId);
        if ($line === null) {
            Response::error('Line not found', 404);
            return;
        }

        $config = require __DIR__ . '/../Config/config.php';
        $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();

        $journeyStmt = $pdo->prepare('
            SELECT sj.trip_number, jp.headsign
            FROM service_journeys sj
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ?
            LIMIT 1
        ');
        $stopStmt = $pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');

        $vehicles = [];
        foreach ($vmMap as $key => $entries) {
            [$keyLineId, $tripNumber] = explode('|', $key);
            if ((int)$keyLineId !== $lineId) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry['currentStopId'] === null) {
                    continue;
                }
                $stopStmt->execute([$entry['currentStopId']]);
                $stop = $stopStmt->fetch();
                if ($stop === null) {
                    continue;
                }
                $journeyStmt->execute([$lineId, $tripNumber]);
                $journey = $journeyStmt->fetch();

                $vehicles[] = [
                    'vehicleRef' => $entry['vehicleRef'],
                    'delayMinutes' => (int)round($entry['delaySeconds'] / 60),
                    'headsign' => $journey['headsign'] ?? null,
                    'currentStop' => [
                        'id' => (int)$stop['id'],
                        'name' => $stop['name'],
                        'lat' => (float)$stop['lat'],
                        'lon' => (float)$stop['lon'],
                    ],
                ];
            }
        }

        Response::json([
            'line' => $line,
            'patterns' => $lineModel->patternsWithStops($lineId),
            'vehicles' => $vehicles,
        ]);
    }

    public function vehicle(Request $request, array $params): void
    {
        [$lineId, $tripNumber, $firstDepartureSeconds] = array_pad(explode('-', $params['tripKey'], 3), 3, null);
        if ($lineId === null || $tripNumber === null || $firstDepartureSeconds === null) {
            Response::error('Invalid vehicle key', 422);
            return;
        }
        $lineId = (int)$lineId;
        $firstDepartureSeconds = (int)$firstDepartureSeconds;

        $pdo = Database::connection();
        $journeyModel = new ServiceJourney($pdo);
        $journey = $journeyModel->findByLineAndTrip($lineId, $tripNumber, $firstDepartureSeconds);
        if ($journey === null) {
            Response::error('Trip not found', 404);
            return;
        }

        $config = require __DIR__ . '/../Config/config.php';
        $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();
        $matcher = new RealtimeMatcher($vmMap, $journeyModel);
        $live = $matcher->lookup($lineId, $tripNumber, $firstDepartureSeconds);

        $stops = $journeyModel->stopsForJourney($journey['id']);
        $now = Calendar::nowSecondsSinceMidnight();

        $stopsOut = array_map(function ($stop) use ($matcher, $journey, $live, $now) {
            // Las paradas que el bus ya ha confirmado que pasó no llevan ETA —
            // su hora programada ya quedó antes de la posición en vivo, así que
            // "ahora menos una hora pasada" no tiene sentido (y puede liarse cruzando medianoche).
            $alreadyPassed = $live !== null && $live['order'] !== null && (int)$stop['seq_order'] < (int)$live['order'];

            $etaMinutes = null;
            if (!$alreadyPassed) {
                [$eta] = $matcher->etaForStop($journey['id'], (int)$stop['arrival_seconds'], $live);
                $etaMinutes = (int)round(($eta - $now) / 60);
            }

            return [
                'stopId' => (int)$stop['stop_id'],
                'name' => $stop['name'],
                'scheduledTime' => Calendar::secondsToHm((int)$stop['arrival_seconds']),
                'etaMinutes' => $etaMinutes,
                'isPast' => $alreadyPassed,
                'isCurrent' => $live !== null && $live['currentStopId'] === (int)$stop['stop_id'],
            ];
        }, $stops);

        $delaySeconds = $live['delaySeconds'] ?? 0;

        Response::json([
            'lineCode' => $journey['line_code'],
            'lineName' => $journey['line_name'],
            'headsign' => $journey['headsign'],
            'status' => $live !== null ? 'live' : 'scheduled',
            'vehicleRef' => $live['vehicleRef'] ?? null,
            'delayMinutes' => $delaySeconds !== 0 ? (int)round($delaySeconds / 60) : 0,
            'stops' => $stopsOut,
        ]);
    }
}
