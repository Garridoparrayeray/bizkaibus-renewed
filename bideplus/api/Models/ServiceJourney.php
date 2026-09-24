<?php

namespace Models;

use Services\Calendar;

class ServiceJourney
{
    private const DEDUPE_TOLERANCE_SECONDS = 90;

    private const PAST_GRACE_SECONDS = 900;

    private const LAST_STOP_NAME_SUBQUERY = '(
        SELECT s2.name FROM passing_times pt2
        JOIN stops s2 ON s2.id = pt2.stop_id
        WHERE pt2.service_journey_id = sj.id
        ORDER BY pt2.seq_order DESC LIMIT 1
    )';

    public function __construct(private \PDO $Pdo)
    {
    }

    public function upcomingAtStop(int|string $iStopId, int $iLimit = 8, int $iWindowSeconds = 4 * 3600): array
    {
        $iNow = Calendar::nowSecondsSinceMidnight();
        $sToday = Calendar::todayMadrid()->format('Y-m-d');

        $Stmt = $this->Pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.id AS service_journey_id, sj.first_departure_seconds,
                   l.code AS line_code, l.name AS line_name, jp.id AS journey_pattern_id, jp.headsign,
                   pt.arrival_seconds, pt.departure_seconds,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE pt.stop_id = :stopId
              AND sc.id != \'PRUEBA\'
              AND EXISTS (
                  SELECT 1 FROM passing_times pt2
                  WHERE pt2.service_journey_id = pt.service_journey_id AND pt2.seq_order > pt.seq_order
              )
              AND pt.departure_seconds BETWEEN :windowStart AND :windowEnd
              AND (
                  (sc.weekday_mask & :weekdayBit) != 0
                  OR EXISTS (
                      SELECT 1 FROM service_calendar_exceptions sce_inc
                      WHERE sce_inc.calendar_id = sc.id AND sce_inc.date = :today2 AND sce_inc.available = 1
                  )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM service_calendar_exceptions sce
                  WHERE sce.calendar_id = sc.id AND sce.date = :today AND sce.available = 0
              )
            ORDER BY pt.departure_seconds ASC
        ');
        $Stmt->execute([
            'stopId' => $iStopId,
            'weekdayBit' => Calendar::todayWeekdayBit(),
            'today' => $sToday,
            'today2' => $sToday,
            'windowStart' => $iNow - self::PAST_GRACE_SECONDS,
            'windowEnd' => $iNow + $iWindowSeconds,
        ]);

        return $this->dedupeByTrip($Stmt->fetchAll(), $iLimit + 5);
    }

    public function directionsByPattern(int|string $iStopId, int|string $iReferenceStopId): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT jp.id AS pattern_id,
                   j.seq_order AS stop_seq,
                   (SELECT jr.seq_order FROM journey_pattern_stops jr
                    WHERE jr.journey_pattern_id = jp.id AND jr.stop_id = :ref LIMIT 1) AS ref_seq,
                   last_stop.name AS last_name,
                   last_stop.lon AS last_lon
            FROM journey_patterns jp
            JOIN journey_pattern_stops j ON j.journey_pattern_id = jp.id AND j.stop_id = :stop
            JOIN stops last_stop ON last_stop.id = (
                SELECT jl.stop_id FROM journey_pattern_stops jl
                WHERE jl.journey_pattern_id = jp.id ORDER BY jl.seq_order DESC LIMIT 1
            )
        ');
        $Stmt->execute(['ref' => $iReferenceStopId, 'stop' => $iStopId]);
        $aRows = $Stmt->fetchAll();
        if (empty($aRows)) {
            return [];
        }

        $LonStmt = $this->Pdo->prepare('SELECT lon FROM stops WHERE id = ?');
        $LonStmt->execute([$iStopId]);
        $dStopLon = (float)$LonStmt->fetchColumn();
        $LonStmt->execute([$iReferenceStopId]);
        $dReferenceLon = (float)$LonStmt->fetchColumn();

        $aDirections = [];
        foreach ($aRows as $aRow) {
            $bSameSideAsReference = ((float)$aRow['last_lon'] - $dStopLon) * ($dReferenceLon - $dStopLon) > 0;
            if ($aRow['ref_seq'] !== null && (int)$aRow['stop_seq'] !== (int)$aRow['ref_seq']) {
                $bToward = (int)$aRow['stop_seq'] < (int)$aRow['ref_seq'];
            } elseif ((string)$iStopId === (string)$iReferenceStopId) {
                $bToward = (float)$aRow['last_lon'] > $dStopLon;
            } else {
                $bToward = $bSameSideAsReference;
            }
            $aDirections[$aRow['pattern_id']] = [
                'direction' => $bToward ? 'toward_reference' : 'away_from_reference',
                'terminus' => $aRow['last_name'],
            ];
        }
        return $aDirections;
    }

    public function timetableForLine(int|string $iLineId, \DateTime $Date, int $iHourFromSeconds, int $iHourToSeconds, int|string|null $iStopId = null): array
    {
        $iWeekdayBit = Calendar::weekdayBitFor($Date);
        $sDateStr = $Date->format('Y-m-d');

        $sStopCondition = 'pt.seq_order = 1';
        if ($iStopId !== null) {
            $sStopCondition = 'pt.stop_id IN (SELECT id FROM stops WHERE id = :stopId OR station_id = :stopIdAsStation)
                AND EXISTS (
                    SELECT 1 FROM passing_times pt2
                    WHERE pt2.service_journey_id = pt.service_journey_id AND pt2.seq_order > pt.seq_order
                )';
        }

        $Stmt = $this->Pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.id AS service_journey_id,
                   jp.headsign, jp.id AS journey_pattern_id, pt.departure_seconds,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = :lineId
              AND ' . $sStopCondition . '
              AND sc.id != \'PRUEBA\'
              AND (
                  (sc.weekday_mask & :weekdayBit) != 0
                  OR EXISTS (
                      SELECT 1 FROM service_calendar_exceptions sce_inc
                      WHERE sce_inc.calendar_id = sc.id AND sce_inc.date = :dateStr2 AND sce_inc.available = 1
                  )
              )
              AND NOT EXISTS (
                  SELECT 1 FROM service_calendar_exceptions sce
                  WHERE sce.calendar_id = sc.id AND sce.date = :dateStr AND sce.available = 0
              )
              AND pt.departure_seconds BETWEEN :hourFrom AND :hourTo
            ORDER BY pt.departure_seconds ASC
        ');
        $aParams = [
            'lineId' => $iLineId,
            'weekdayBit' => $iWeekdayBit,
            'dateStr' => $sDateStr,
            'dateStr2' => $sDateStr,
            'hourFrom' => $iHourFromSeconds,
            'hourTo' => $iHourToSeconds,
        ];
        if ($iStopId !== null) {
            $aParams['stopId'] = $iStopId;
            $aParams['stopIdAsStation'] = $iStopId;
        }
        $Stmt->execute($aParams);

        return $this->dedupeByTrip($Stmt->fetchAll(), 2000);
    }

    public function findByLineAndTrip(int|string $iLineId, string $sTripNumber, int $iFirstDepartureSeconds): array|null
    {
        $Stmt = $this->Pdo->prepare('
            SELECT sj.id, sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.journey_pattern_id,
                   l.code AS line_code, l.name AS line_name, jp.headsign,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
            FROM service_journeys sj
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ? AND sj.first_departure_seconds = ?
            LIMIT 1
        ');
        $Stmt->execute([$iLineId, $sTripNumber, $iFirstDepartureSeconds]);
        $aRow = $Stmt->fetch();
        if (!$aRow) {
            return null;
        }
        return $aRow;
    }

    public function arrivalSecondsAtOrder(string $sServiceJourneyId, int $iSeqOrder): int|null
    {
        $Stmt = $this->Pdo->prepare('
            SELECT arrival_seconds FROM passing_times
            WHERE service_journey_id = ? AND seq_order = ?
        ');
        $Stmt->execute([$sServiceJourneyId, $iSeqOrder]);
        $sValue = $Stmt->fetchColumn();
        if ($sValue === false) {
            return null;
        }
        return (int)$sValue;
    }

    public function stopsForJourney(string $sServiceJourneyId): array
    {
        $Stmt = $this->Pdo->prepare('
            SELECT pt.seq_order, s.id AS stop_id, s.name, pt.arrival_seconds, pt.departure_seconds
            FROM passing_times pt
            JOIN stops s ON s.id = pt.stop_id
            WHERE pt.service_journey_id = ?
            ORDER BY pt.seq_order
        ');
        $Stmt->execute([$sServiceJourneyId]);
        return $Stmt->fetchAll();
    }

    private function dedupeByTrip(array $aRows, int $iLimit): array
    {
        $aLastKeptDeparture = [];
        $aResult = [];
        foreach ($aRows as $aRow) {
            $sKey = $aRow['line_id'] . '|' . $aRow['trip_number'];
            $iDeparture = (int)$aRow['departure_seconds'];
            if (isset($aLastKeptDeparture[$sKey]) && abs($iDeparture - $aLastKeptDeparture[$sKey]) <= self::DEDUPE_TOLERANCE_SECONDS) {
                continue;
            }
            $aLastKeptDeparture[$sKey] = $iDeparture;
            $aResult[] = $aRow;
            if (\count($aResult) >= $iLimit) {
                break;
            }
        }
        return $aResult;
    }
}
