<?php

namespace Models;

use Services\Calendar;

class ServiceJourney
{
    /** Igual que el gap de clustering del build (scripts/build-database.php) — dos
     *  variantes de calendario para el mismo (línea, trip_number) que en esta parada
     *  caen a menos de esto una de otra son el mismo viaje real, no dos salidas distintas. */
    private const DEDUPE_TOLERANCE_SECONDS = 90;

    /** Un bus retrasado puede seguir sin llegar mucho después de su hora
     *  programada — si el margen hacia atrás fuera pequeño, se excluiría de
     *  aquí (por hora programada) antes de que el enriquecido en vivo tenga
     *  ocasión de comprobar si en realidad sigue en camino, y desaparecería
     *  de la app justo cuando estuviera "LLEGANDO" sin haber llegado todavía. */
    private const PAST_GRACE_SECONDS = 900;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Salidas programadas en una parada, desde ahora en adelante, deduplicadas
     * entre variantes de calendario que coinciden en trip_number/hora (ver
     * README sobre por qué más de un calendario puede casar con el mismo día).
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
            'windowStart' => $now - self::PAST_GRACE_SECONDS,
            'windowEnd' => $now + $windowSeconds,
        ]);

        // +5 de margen: algunas de las filas "pasadas" que trae la ventana
        // ampliada se descartarán después (StopsController) si el enriquecido
        // en vivo confirma que el bus ya pasó de verdad — así no le quitan
        // el sitio a una salida futura real en el límite final.
        return $this->dedupeByTrip($stmt->fetchAll(), $limit + 5);
    }

    /**
     * Salidas programadas desde la parada de origen de una línea, para el día
     * de la semana en que cae $date, dentro de un rango horario — usado en
     * "Tabla Horaria".
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
     * Hora programada de llegada en una posición concreta (seq_order) de un
     * viaje — permite a RealtimeMatcher calcular "tiempo programado restante
     * desde la última parada confirmada" en vez de solo "hora original + retraso plano".
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
