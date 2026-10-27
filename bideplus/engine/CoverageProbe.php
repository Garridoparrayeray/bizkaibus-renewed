<?php

namespace Engine;

use Models\ServiceJourney;

class CoverageProbe
{
    private const STARTED_AT_LEAST_SECONDS = 180;
    private const LOOKBACK_SECONDS = 10800;
    private const STILL_RUNNING_MARGIN_SECONDS = 120;

    public static function latestSeconds(array $aVm): int
    {
        $iT = 0;
        foreach ($aVm as $aEntries) {
            foreach ($aEntries as $aEntry) {
                if (isset($aEntry['locationSeconds'])) {
                    $iT = max($iT, (int)$aEntry['locationSeconds']);
                }
            }
        }
        return $iT;
    }

    public static function measure(array $aVm, ServiceJourney $JourneyModel, \PDO $Pdo, string $sDate, int $iNowSeconds): array
    {
        $Matcher = new RealtimeMatcher($aVm, $JourneyModel, $iNowSeconds, null, $sDate);
        $aLive = [];
        foreach ($aVm as $aEntries) {
            foreach ($aEntries as $aEntry) {
                $sJourney = $Matcher->journeyIdFor($aEntry);
                if ($sJourney) {
                    $aLive[$sJourney] = $aEntry['lat'] !== null ? 'gps' : 'otro';
                }
            }
        }
        $Date = new \DateTime($sDate);
        $aLines = [];
        foreach ($Pdo->query('SELECT id, code, name FROM lines ORDER BY code')->fetchAll() as $aLine) {
            $aRows = $JourneyModel->timetableForLine($aLine['id'], $Date, $iNowSeconds - self::LOOKBACK_SECONDS, $iNowSeconds - self::STARTED_AT_LEAST_SECONDS);
            $iExpected = 0;
            $iLive = 0;
            $iGps = 0;
            $iOther = 0;
            foreach ($aRows as $aRow) {
                $aStops = $JourneyModel->stopsWithCoordinates($aRow['service_journey_id']);
                if (!$aStops || (int)end($aStops)['arrival_seconds'] <= $iNowSeconds + self::STILL_RUNNING_MARGIN_SECONDS) {
                    continue;
                }
                $iExpected++;
                if (isset($aLive[$aRow['service_journey_id']])) {
                    $iLive++;
                    if ($aLive[$aRow['service_journey_id']] === 'gps') {
                        $iGps++;
                    }
                } elseif (isset($aVm[$aRow['line_id'] . '|' . $aRow['trip_number']])) {
                    $iOther++;
                }
            }
            if ($iExpected > 0) {
                $aLines[] = ['codigo' => $aLine['code'], 'nombre' => $aLine['name'], 'esperados' => $iExpected, 'conDato' => $iLive, 'gps' => $iGps, 'otraSalida' => $iOther];
            }
        }
        return $aLines;
    }
}
