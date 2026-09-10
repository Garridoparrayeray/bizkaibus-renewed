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

    public function __construct(private \PDO $pdo)
    {
    }

    public function upcomingAtStop(int $stopId, int $limit = 8, int $windowSeconds = 4 * 3600, int|null $referenceStopId = null): array
    {
        $now = Calendar::nowSecondsSinceMidnight();
        $weekdayBit = Calendar::todayWeekdayBit();
        $today = Calendar::todayMadrid()->format('Y-m-d');

        $directionSelect = '';
        if ($referenceStopId !== null) {
            $directionSelect = ',
                CASE WHEN (
                    SELECT jps_ref.seq_order FROM journey_pattern_stops jps_ref
                    WHERE jps_ref.journey_pattern_id = jp.id AND jps_ref.stop_id = :referenceStopId
                    LIMIT 1
                ) IS NULL THEN \'toward_reference\'
                WHEN pt.seq_order < (
                    SELECT jps_ref.seq_order FROM journey_pattern_stops jps_ref
                    WHERE jps_ref.journey_pattern_id = jp.id AND jps_ref.stop_id = :referenceStopId2
                    LIMIT 1
                ) THEN \'toward_reference\'
                ELSE \'away_from_reference\' END AS direction';
        }

        $stmt = $this->pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.id AS service_journey_id, sj.first_departure_seconds,
                   l.code AS line_code, l.name AS line_name, jp.headsign,
                   pt.arrival_seconds, pt.departure_seconds,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name' . $directionSelect . '
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE pt.stop_id = :stopId
              AND sc.id != \'PRUEBA\'
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
              AND pt.departure_seconds BETWEEN :windowStart AND :windowEnd
            ORDER BY pt.departure_seconds ASC
        ');
        $params = [
            'stopId' => $stopId,
            'weekdayBit' => $weekdayBit,
            'today' => $today,
            'today2' => $today,
            'windowStart' => $now - self::PAST_GRACE_SECONDS,
            'windowEnd' => $now + $windowSeconds,
        ];
        if ($referenceStopId !== null) {
            $params['referenceStopId'] = $referenceStopId;
            $params['referenceStopId2'] = $referenceStopId;
        }
        $stmt->execute($params);

        return $this->dedupeByTrip($stmt->fetchAll(), $limit + 5);
    }

    public function timetableForLine(int $lineId, \DateTime $date, int $hourFromSeconds, int $hourToSeconds, int|null $stopId = null): array
    {
        $weekdayBit = Calendar::weekdayBitFor($date);
        $dateStr = $date->format('Y-m-d');
        $stopCondition = 'pt.seq_order = 1';
        if ($stopId !== null) {
            $stopCondition = 'pt.stop_id = :stopId';
        }

        $stmt = $this->pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.id AS service_journey_id,
                   jp.headsign, jp.id AS journey_pattern_id, pt.departure_seconds,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = :lineId
              AND ' . $stopCondition . '
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
        $params = [
            'lineId' => $lineId,
            'weekdayBit' => $weekdayBit,
            'dateStr' => $dateStr,
            'dateStr2' => $dateStr,
            'hourFrom' => $hourFromSeconds,
            'hourTo' => $hourToSeconds,
        ];
        if ($stopId !== null) {
            $params['stopId'] = $stopId;
        }
        $stmt->execute($params);

        return $this->dedupeByTrip($stmt->fetchAll(), 2000);
    }

    public function findByLineAndTrip(int $lineId, string $tripNumber, int $firstDepartureSeconds): array|null
    {
        $stmt = $this->pdo->prepare('
            SELECT sj.id, sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.journey_pattern_id,
                   l.code AS line_code, l.name AS line_name, jp.headsign,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
            FROM service_journeys sj
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ? AND sj.first_departure_seconds = ?
            LIMIT 1
        ');
        $stmt->execute([$lineId, $tripNumber, $firstDepartureSeconds]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $row;
    }

    public function arrivalSecondsAtOrder(string $serviceJourneyId, int $seqOrder): int|null
    {
        $stmt = $this->pdo->prepare('
            SELECT arrival_seconds FROM passing_times
            WHERE service_journey_id = ? AND seq_order = ?
        ');
        $stmt->execute([$serviceJourneyId, $seqOrder]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }
        return (int)$value;
    }

    public function stopsForJourney(string $serviceJourneyId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT pt.seq_order, s.id AS stop_id, s.name, pt.arrival_seconds, pt.departure_seconds
            FROM passing_times pt
            JOIN stops s ON s.id = pt.stop_id
            WHERE pt.service_journey_id = ?
            ORDER BY pt.seq_order
        ');
        $stmt->execute([$serviceJourneyId]);
        return $stmt->fetchAll();
    }

    private function dedupeByTrip(array $rows, int $limit): array
    {
        $lastKeptDeparture = [];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['line_id'] . '|' . $row['trip_number'];
            $departure = (int)$row['departure_seconds'];
            if (isset($lastKeptDeparture[$key]) && abs($departure - $lastKeptDeparture[$key]) <= self::DEDUPE_TOLERANCE_SECONDS) {
                continue;
            }
            $lastKeptDeparture[$key] = $departure;
            $result[] = $row;
            if (\count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }
}
