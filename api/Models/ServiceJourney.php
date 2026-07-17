<?php

namespace Models;

use Services\Calendar;

class ServiceJourney
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Scheduled departures at a stop, from now forward, deduped across
     * calendar variants that happen to share the same trip_number/time
     * (see README on why more than one calendar can match the same day).
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcomingAtStop(int $stopId, int $limit = 8, int $windowSeconds = 4 * 3600): array
    {
        $now = Calendar::nowSecondsSinceMidnight();
        $weekdayBit = Calendar::todayWeekdayBit();

        $stmt = $this->pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.id AS service_journey_id, sj.first_departure_seconds,
                   l.code AS line_code, l.name AS line_name, jp.headsign,
                   pt.arrival_seconds, pt.departure_seconds
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE pt.stop_id = :stopId
              AND sc.id != \'PRUEBA\'
              AND (sc.weekday_mask & :weekdayBit) != 0
              AND pt.departure_seconds BETWEEN :windowStart AND :windowEnd
            ORDER BY pt.departure_seconds ASC
        ');
        $stmt->execute([
            'stopId' => $stopId,
            'weekdayBit' => $weekdayBit,
            'windowStart' => $now - 120,
            'windowEnd' => $now + $windowSeconds,
        ]);

        return $this->dedupeByTrip($stmt->fetchAll(), $limit);
    }

    /**
     * Programmed departures from a line's origin stop, for the weekday that
     * $date falls on, within an hour range — backs "Tabla Horaria".
     *
     * @return array<int, array<string, mixed>>
     */
    public function timetableForLine(int $lineId, \DateTime $date, int $hourFromSeconds, int $hourToSeconds): array
    {
        $weekdayBit = Calendar::weekdayBitFor($date);

        $stmt = $this->pdo->prepare('
            SELECT sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.id AS service_journey_id,
                   jp.headsign, jp.id AS journey_pattern_id, pt.departure_seconds
            FROM passing_times pt
            JOIN service_journeys sj ON sj.id = pt.service_journey_id
            JOIN service_calendars sc ON sc.id = sj.calendar_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = :lineId
              AND pt.seq_order = 1
              AND sc.id != \'PRUEBA\'
              AND (sc.weekday_mask & :weekdayBit) != 0
              AND pt.departure_seconds BETWEEN :hourFrom AND :hourTo
            ORDER BY pt.departure_seconds ASC
        ');
        $stmt->execute([
            'lineId' => $lineId,
            'weekdayBit' => $weekdayBit,
            'hourFrom' => $hourFromSeconds,
            'hourTo' => $hourToSeconds,
        ]);

        return $this->dedupeByTrip($stmt->fetchAll(), 200);
    }

    public function findByLineAndTrip(int $lineId, string $tripNumber, int $firstDepartureSeconds): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT sj.id, sj.line_id, sj.trip_number, sj.first_departure_seconds, sj.journey_pattern_id,
                   l.code AS line_code, l.name AS line_name, jp.headsign
            FROM service_journeys sj
            JOIN lines l ON l.id = sj.line_id
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ? AND sj.first_departure_seconds = ?
            LIMIT 1
        ');
        $stmt->execute([$lineId, $tripNumber, $firstDepartureSeconds]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Scheduled arrival at a specific position (seq_order) within a journey —
     * lets RealtimeMatcher compute "remaining scheduled time from the bus's
     * last confirmed stop" instead of just "original time + flat delay".
     */
    public function arrivalSecondsAtOrder(string $serviceJourneyId, int $seqOrder): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT arrival_seconds FROM passing_times
            WHERE service_journey_id = ? AND seq_order = ?
        ');
        $stmt->execute([$serviceJourneyId, $seqOrder]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    /** @return array<int, array{seq_order:int, stop_id:int, name:string, arrival_seconds:int, departure_seconds:int}> */
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
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = $row['line_id'] . '|' . $row['trip_number'] . '|' . $row['departure_seconds'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $row;
            if (\count($result) >= $limit) {
                break;
            }
        }
        return $result;
    }
}
