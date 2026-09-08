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

/**
 * "Detalle del bus": muestra lo que hay realmente en los datos, es decir
 * flota, retraso, parada actual y paradas restantes. Nada de modelo ni
 * amenities inventados: si SIRI no lo publica, la app no lo muestra.
 */
class RealtimeController
{
    /**
     * "En vivo ahora" de una línea: la ruta (paradas ordenadas de cada patrón,
     * para dibujar en el mapa) más los vehículos activos ahora mismo. La
     * posición del vehículo es su última parada confirmada, porque el feed no
     * da GPS continuo, solo parada más orden. El marcador va siempre en una
     * parada real, nunca interpolado entre medias.
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
                $headsign = null;
                if (isset($journey['headsign'])) {
                    $headsign = $journey['headsign'];
                }

                $vehicles[] = [
                    'vehicleRef' => $entry['vehicleRef'],
                    'delayMinutes' => (int)round($entry['delaySeconds'] / 60),
                    'headsign' => $headsign,
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

    /**
     * Detalle en vivo de un vehículo concreto, ruta /vehicles/{tripKey}: su
     * recorrido completo con la hora programada de cada parada, más el
     * enriquecido en tiempo real cuando SIRI-VM lo confirma en circulación.
     * Las paradas que el vehículo ya dejó atrás no llevan ETA, marcadas con
     * isPast a partir del order confirmado por el propio feed.
     */
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
            // Las paradas que el bus ya ha confirmado que pasó no llevan ETA:
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

        $delaySeconds = 0;
        if (isset($live['delaySeconds'])) {
            $delaySeconds = $live['delaySeconds'];
        }

        $status = 'scheduled';
        if ($live !== null) {
            $status = 'live';
        }

        $delayMinutes = 0;
        if ($delaySeconds !== 0) {
            $delayMinutes = (int)round($delaySeconds / 60);
        }

        $vehicleRef = null;
        if (isset($live['vehicleRef'])) {
            $vehicleRef = $live['vehicleRef'];
        }

        Response::json([
            'lineCode' => $journey['line_code'],
            'lineName' => $journey['line_name'],
            'headsign' => $journey['headsign'],
            'status' => $status,
            'vehicleRef' => $vehicleRef,
            'delayMinutes' => $delayMinutes,
            'stops' => $stopsOut,
        ]);
    }
}
