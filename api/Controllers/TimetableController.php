<?php

namespace Controllers;

use Core\Config;
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
        $config = Config::current();
        $pdo = Database::connection();
        $lineId = (int)$params['id'];
        $line = (new LineModel($pdo))->find($lineId);
        if ($line === null) {
            Response::error('Line not found', 404);
            return;
        }

        $dateStr = $request->query('date');
        if (!$dateStr) {
            $dateStr = Calendar::todayMadrid()->format('Y-m-d');
        }
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
        if ($isToday && isset($config['siri'])) {
            $vmMap = (new SiriVehicleMonitoringClient($config))->fetchActiveTrips();
            $rows = (new RealtimeMatcher($vmMap, $journeyModel))->enrich(array_map(
                fn($r) => $r + ['arrival_seconds' => $r['departure_seconds']],
                $rows
            ));
        } else {
            $rows = array_map(fn($r) => $r + ['status' => 'scheduled', 'delaySeconds' => 0], $rows);
        }

        // Ver StopsController::departures() sobre por qué metro usa la
        // última parada real en vez del headsign de GTFS.
        $network = 'bus';
        if (isset($config['network'])) {
            $network = $config['network'];
        }
        $isMetro = $network === 'metro';

        $entries = array_map(function ($row) use ($isMetro) {
            $headsign = $row['headsign'];
            if ($isMetro && !empty($row['last_stop_name'])) {
                $headsign = $row['last_stop_name'];
            }
            $delaySeconds = 0;
            if (isset($row['delaySeconds'])) {
                $delaySeconds = $row['delaySeconds'];
            }
            $delayMinutes = 0;
            if ($delaySeconds !== 0) {
                $delayMinutes = (int)round($delaySeconds / 60);
            }
            return [
                'tripKey' => $row['line_id'] . '-' . $row['trip_number'] . '-' . $row['first_departure_seconds'],
                'departure' => Calendar::secondsToHm((int)$row['departure_seconds']),
                'headsign' => $headsign,
                'status' => $row['status'],
                'delayMinutes' => $delayMinutes,
            ];
        }, $rows);

        $publishedStmt = $pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $publishedStmt->execute(['schedule_source_published']);
        $published = $publishedStmt->fetchColumn();
        if (!$published) {
            $published = $config['schedule_source_published'];
        }

        Response::json([
            'line' => ['id' => $line['id'], 'code' => $line['code'], 'name' => $line['name']],
            'date' => $date->format('Y-m-d'),
            'entries' => $entries,
            'scheduleSourcePublished' => $published,
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
