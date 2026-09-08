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
    /** Ficha de una parada/estación, ruta /stops/{id}, con las líneas que la sirven. */
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

    /**
     * Próximas salidas de una parada, ruta /stops/{id}/departures: la
     * pantalla principal de la app. Enriquece el horario programado con
     * SIRI-VM cuando la red lo tiene (bus); en metro se queda en "scheduled".
     * Cuando la config trae direction_reference_stop_id (Abando en Metro+),
     * cada fila lleva además 'direction' para agrupar por sentido de
     * circulación, como un panel físico de andén.
     */
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

        // El headsign de GTFS es el destino comercial anunciado del
        // recorrido (correcto para bus, donde no siempre coincide con la
        // última parada de una variante concreta). Para metro, sin ramales
        // comerciales complejos, la última parada real del propio trayecto
        // es más fiable: verificado que el GTFS de Metro Bilbao repite el
        // mismo headsign en trenes que en realidad terminan en paradas
        // distintas (ver ServiceJourney::LAST_STOP_NAME_SUBQUERY).
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
     * la hora de paso por cada una, sin tiempo real. Es el equivalente de
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

        // Ver StopsController::departures() sobre por qué metro usa la
        // última parada real en vez del headsign de GTFS.
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
