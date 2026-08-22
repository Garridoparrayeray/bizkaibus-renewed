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

    /**
     * El headsign de journey_patterns viene del trip_headsign del GTFS, que
     * no siempre coincide con la última parada real del recorrido concreto —
     * verificado con datos reales de Metro Bilbao: 172 de 265 patrones (65%)
     * tienen headsign distinto de su última parada, incluyendo casos donde
     * dos trenes con el MISMO headsign salen a la misma hora pero uno de
     * ellos en realidad se queda corto (p.ej. "Etxebarri" a las 06:00 dos
     * veces: uno llega a Etxebarri de verdad, el otro solo hasta Indautxu).
     * En Bizkaibus esta discrepancia es aún más frecuente (95%) pero ahí es
     * esperada — el headsign es el destino comercial anunciado del
     * recorrido, no necesariamente la última parada de cada variante. Por
     * eso este dato se calcula siempre pero cada red decide en el frontend
     * si usarlo (Metro+) o seguir mostrando el headsign de GTFS (bus).
     */
    private const LAST_STOP_NAME_SUBQUERY = '(
        SELECT s2.name FROM passing_times pt2
        JOIN stops s2 ON s2.id = pt2.stop_id
        WHERE pt2.service_journey_id = sj.id
        ORDER BY pt2.seq_order DESC LIMIT 1
    )';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Salidas programadas en una parada, desde ahora en adelante, deduplicadas
     * entre variantes de calendario que coinciden en trip_number/hora (ver
     * README sobre por qué más de un calendario puede casar con el mismo día).
     *
     * $referenceStopId, cuando se pasa, añade una columna "direction" a cada
     * fila: 'toward_reference' si esta parada va antes que la de referencia
     * en la secuencia del propio trayecto (o esa parada no aparece en el
     * trayecto en absoluto, ver más abajo), 'away_from_reference' si va
     * después. Pensado para Metro+ (referencia = Abando, el centro real de
     * la red) para agrupar las salidas por sentido de circulación en dos
     * columnas, como un panel físico de andén — verificado con datos reales
     * que comparar el seq_order de la parada consultada contra el de Abando
     * dentro del MISMO journey_pattern predice el sentido con fiabilidad
     * total en los patrones que pasan por ambas. No tiene sentido para bus
     * (sin concepto de "centro" único de red), así que el parámetro es
     * opcional y por defecto no calcula nada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function upcomingAtStop(int $stopId, int $limit = 8, int $windowSeconds = 4 * 3600, ?int $referenceStopId = null): array
    {
        $now = Calendar::nowSecondsSinceMidnight();
        $weekdayBit = Calendar::todayWeekdayBit();

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
              AND (sc.weekday_mask & :weekdayBit) != 0
              AND pt.departure_seconds BETWEEN :windowStart AND :windowEnd
            ORDER BY pt.departure_seconds ASC
        ');
        $params = [
            'stopId' => $stopId,
            'weekdayBit' => $weekdayBit,
            'windowStart' => $now - self::PAST_GRACE_SECONDS,
            'windowEnd' => $now + $windowSeconds,
        ];
        if ($referenceStopId !== null) {
            $params['referenceStopId'] = $referenceStopId;
            $params['referenceStopId2'] = $referenceStopId;
        }
        $stmt->execute($params);

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
                   jp.headsign, jp.id AS journey_pattern_id, pt.departure_seconds,
                   ' . self::LAST_STOP_NAME_SUBQUERY . ' AS last_stop_name
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
        if ($value === false) {
            return null;
        }
        return (int)$value;
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
