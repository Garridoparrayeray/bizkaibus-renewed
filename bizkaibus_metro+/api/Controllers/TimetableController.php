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

    public function show(Request $Req, array $aParams): void
    {
        $aConfig = Config::current();
        $Pdo = Database::connection();
        $iLineId = (int)$aParams['id'];
        $aLine = (new LineModel($Pdo))->find($iLineId);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }

        $sDateStr = $Req->query('date');
        if (!$sDateStr) {
            $sDateStr = Calendar::todayMadrid()->format('Y-m-d');
        }
        try {
            $Date = new \DateTime($sDateStr, new \DateTimeZone('Europe/Madrid'));
        } catch (\Throwable $Ex) {
            Response::error('Invalid date', 422);
            return;
        }

        $iHourFrom = $this->hmToSeconds($Req->query('hourFrom', '00:00'));
        $iHourTo = $this->hmToSeconds($Req->query('hourTo', '23:59'));

        if ($iHourTo < $iHourFrom) {
            $iHourTo += 24 * 3600;
        }

        $sStopIdRaw = $Req->query('stopId');
        $iStopId = null;
        if ($sStopIdRaw !== null && $sStopIdRaw !== '') {
            $iStopId = (int)$sStopIdRaw;
        }

        $JourneyModel = new ServiceJourney($Pdo);
        $aRows = $JourneyModel->timetableForLine($iLineId, $Date, $iHourFrom, $iHourTo, $iStopId);

        $bIsToday = $Date->format('Y-m-d') === Calendar::todayMadrid()->format('Y-m-d');
        if ($bIsToday && isset($aConfig['siri'])) {
            $aVmMap = (new SiriVehicleMonitoringClient($aConfig))->fetchActiveTrips();
            $aRows = (new RealtimeMatcher($aVmMap, $JourneyModel))->enrich(array_map(
                fn($aR) => $aR + ['arrival_seconds' => $aR['departure_seconds']],
                $aRows
            ));
        } else {
            $aRows = array_map(fn($aR) => $aR + ['status' => 'scheduled', 'delaySeconds' => 0], $aRows);
        }

        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }
        $bIsMetro = $sNetwork === 'metro';

        $aEntries = array_map(function ($aRow) use ($bIsMetro) {
            $sHeadsign = $aRow['headsign'];
            if ($bIsMetro && !empty($aRow['last_stop_name'])) {
                $sHeadsign = $aRow['last_stop_name'];
            }
            $iDelaySeconds = 0;
            if (isset($aRow['delaySeconds'])) {
                $iDelaySeconds = $aRow['delaySeconds'];
            }
            $iDelayMinutes = 0;
            if ($iDelaySeconds !== 0) {
                $iDelayMinutes = (int)round($iDelaySeconds / 60);
            }
            return [
                'tripKey' => $aRow['line_id'] . '-' . $aRow['trip_number'] . '-' . $aRow['first_departure_seconds'],
                'departure' => Calendar::secondsToHm((int)$aRow['departure_seconds']),
                'headsign' => $sHeadsign,
                'status' => $aRow['status'],
                'delayMinutes' => $iDelayMinutes,
            ];
        }, $aRows);

        $PublishedStmt = $Pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $PublishedStmt->execute(['schedule_source_published']);
        $sPublished = $PublishedStmt->fetchColumn();
        if (!$sPublished) {
            $sPublished = $aConfig['schedule_source_published'];
        }

        Response::json([
            'line' => ['id' => $aLine['id'], 'code' => $aLine['code'], 'name' => $aLine['name']],
            'date' => $Date->format('Y-m-d'),
            'entries' => $aEntries,
            'scheduleSourcePublished' => $sPublished,
        ]);
    }

    private function hmToSeconds(string $sHm): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $sHm, $aM)) {
            return 0;
        }
        return ((int)$aM[1]) * 3600 + ((int)$aM[2]) * 60;
    }
}
