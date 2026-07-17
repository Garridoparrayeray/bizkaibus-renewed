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

class TimetableController
{
    public function show(Request $request, array $params): void
    {
        $config = require __DIR__ . '/../Config/config.php';
        $pdo = Database::connection();
        $lineId = (int)$params['id'];
        $line = (new LineModel($pdo))->find($lineId);
        if ($line === null) {
            Response::error('Line not found', 404);
            return;
        }

        $dateStr = $request->query('date') ?: Calendar::todayMadrid()->format('Y-m-d');
        try {
            $date = new \DateTime($dateStr, new \DateTimeZone('Europe/Madrid'));
        } catch (\Throwable $e) {
            Response::error('Invalid date', 422);
            return;
        }

        $hourFrom = $this->hmToSeconds($request->query('hourFrom', '00:00'));
        $hourTo = $this->hmToSeconds($request->query('hourTo', '23:59'));

        $journeyModel = new ServiceJourney($pdo);
        $rows = $journeyModel->timetableForLine($lineId, $date, $hourFrom, $hourTo);

        $isToday = $date->format('Y-m-d') === Calendar::todayMadrid()->format('Y-m-d');
        if ($isToday) {
            $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();
            $rows = (new RealtimeMatcher($vmMap, $journeyModel))->enrich(array_map(
                fn($r) => $r + ['arrival_seconds' => $r['departure_seconds']],
                $rows
            ));
        } else {
            $rows = array_map(fn($r) => $r + ['status' => 'scheduled', 'delaySeconds' => 0], $rows);
        }

        $entries = array_map(function ($row) {
            return [
                'tripKey' => $row['line_id'] . '-' . $row['trip_number'] . '-' . $row['first_departure_seconds'],
                'departure' => Calendar::secondsToHm((int)$row['departure_seconds']),
                'headsign' => $row['headsign'],
                'status' => $row['status'],
                'delayMinutes' => ($row['delaySeconds'] ?? 0) !== 0 ? (int)round($row['delaySeconds'] / 60) : 0,
            ];
        }, $rows);

        Response::json([
            'line' => ['id' => $line['id'], 'code' => $line['code'], 'name' => $line['name']],
            'date' => $date->format('Y-m-d'),
            'entries' => $entries,
            'scheduleSourcePublished' => $config['schedule_source_published'],
        ]);
    }

    private function hmToSeconds(string $hm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
            return 0;
        }
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60;
    }
}
