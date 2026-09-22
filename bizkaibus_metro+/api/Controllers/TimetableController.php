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
        $sLineId = $aParams['id'];
        $aLine = (new LineModel($Pdo))->find($sLineId);
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

        $bCrossesMidnight = $iHourTo < $iHourFrom;
        $iNextDayHourTo = $iHourTo;
        if ($bCrossesMidnight) {
            $iHourTo += 24 * 3600;
        }

        $sStopIdRaw = $Req->query('stopId');
        $sStopId = null;
        if ($sStopIdRaw !== null && $sStopIdRaw !== '') {
            $sStopId = $sStopIdRaw;
        }

        $JourneyModel = new ServiceJourney($Pdo);
        $aRows = $JourneyModel->timetableForLine($sLineId, $Date, $iHourFrom, $iHourTo, $sStopId);

        if ($bCrossesMidnight) {
            $NextDate = (clone $Date)->modify('+1 day');
            $aNextDayRows = $JourneyModel->timetableForLine($sLineId, $NextDate, 0, $iNextDayHourTo, $sStopId);
            foreach ($aNextDayRows as &$aNextDayRow) {
                $aNextDayRow['departure_seconds'] = (int)$aNextDayRow['departure_seconds'] + 24 * 3600;
            }
            unset($aNextDayRow);
            $aRows = array_merge($aRows, $aNextDayRows);
            usort($aRows, fn($aA, $aB) => (int)$aA['departure_seconds'] <=> (int)$aB['departure_seconds']);
        }

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

        $sToday = Calendar::todayMadrid()->format('Y-m-d');
        $sDate = $Date->format('Y-m-d');
        $iNow = Calendar::nowSecondsSinceMidnight();

        $aEntries = array_map(function ($aRow) use ($bIsMetro, $sToday, $sDate, $iNow) {
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
            $sStatus = $aRow['status'];
            if ($sStatus === 'scheduled' && ($sDate < $sToday || ($sDate === $sToday && (int)$aRow['departure_seconds'] < $iNow))) {
                $sStatus = 'departed';
            }
            return [
                'tripKey' => $aRow['line_id'] . '-' . $aRow['trip_number'] . '-' . $aRow['first_departure_seconds'],
                'departure' => Calendar::secondsToHm((int)$aRow['departure_seconds']),
                'headsign' => $sHeadsign,
                'status' => $sStatus,
                'delayMinutes' => $iDelayMinutes,
            ];
        }, $aRows);

        $PublishedStmt = $Pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $PublishedStmt->execute(['schedule_source_published']);
        $sPublished = $PublishedStmt->fetchColumn();
        if (!$sPublished) {
            $sPublished = $aConfig['schedule_source_published'];
        }

        $FeedEndStmt = $Pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $FeedEndStmt->execute(['feed_end_date']);
        $sPublishedUntil = $FeedEndStmt->fetchColumn();
        if (!$sPublishedUntil) {
            $sPublishedUntil = $Pdo->query('SELECT MAX(to_date) FROM service_calendars')->fetchColumn();
        }

        Response::json([
            'line' => ['id' => $aLine['id'], 'code' => $aLine['code'], 'name' => $aLine['name']],
            'date' => $Date->format('Y-m-d'),
            'entries' => $aEntries,
            'scheduleSourcePublished' => $sPublished,
            'publishedUntil' => $sPublishedUntil ?: null,
            'beyondPublished' => $sPublishedUntil ? $sDate > $sPublishedUntil : false,
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
