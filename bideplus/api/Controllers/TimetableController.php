<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Live;
use Core\Request;
use Core\Response;
use Core\TripKey;
use Models\LineModel;
use Models\ServiceJourney;
use Models\Stop;
use Services\Calendar;
use Services\ScheduleRange;

// Horario de una línea para un día y una franja (/api/lines/{id}/timetable), con las salidas que pasan de
// medianoche y, si se pide, solo las de una parada.
class TimetableController
{
    private const DAY_SECONDS = 86400;

    public function show(Request $Req, array $aParams): void
    {
        $Pdo = Database::connection();
        $aLine = (new LineModel($Pdo))->find($aParams['id']);
        if ($aLine === null) {
            Response::error('Line not found', 404);
            return;
        }
        $Date = self::date($Req->query('date'));
        if ($Date === null) {
            Response::error('Invalid date', 422);
            return;
        }

        $JourneyModel = new ServiceJourney($Pdo);
        $iHourFrom = Calendar::hmToSeconds($Req->query('hourFrom', '00:00'));
        $iHourTo = Calendar::hmToSeconds($Req->query('hourTo', '23:59'));
        $aRows = self::rowsInRange($JourneyModel, $aParams['id'], $Date, $iHourFrom, $iHourTo, self::stopId($Pdo, $Req->query('stopId')));
        $aRows = self::withStatus($JourneyModel, $aRows, $Date);

        $sDate = $Date->format('Y-m-d');
        $iPastBefore = self::pastBefore($sDate);
        $bUseLastStopHeadsign = (bool)Config::feature('headsign_from_last_stop');
        $aEntries = array_map(fn($aRow) => self::entry($aRow, $iPastBefore, $bUseLastStopHeadsign), $aRows);

        Response::json([
            'line' => ['id' => $aLine['id'], 'code' => $aLine['code'], 'name' => $aLine['name']],
            'date' => $sDate,
            'entries' => $aEntries,
        ] + self::publication($Pdo, $sDate));
    }

    // La fecha pedida (AAAA-MM-DD) o hoy, a medianoche y en hora de Madrid. Null si no es una fecha.
    private static function date(string|null $sDate): \DateTime|null
    {
        if (!$sDate) {
            $sDate = Calendar::todayMadrid()->format('Y-m-d');
        }
        try {
            return new \DateTime($sDate, new \DateTimeZone('Europe/Madrid'));
        } catch (\Throwable $Ex) {
            return null;
        }
    }

    // La parada por la que filtrar, con el id tal como está en la base de datos (si existe).
    private static function stopId(\PDO $Pdo, string|null $sStopId): string|null
    {
        if ($sStopId === null || $sStopId === '') {
            return null;
        }
        $aStop = (new Stop($Pdo))->find($sStopId);
        if ($aStop === null) {
            return $sStopId;
        }
        return (string)$aStop['id'];
    }

    // Salidas entre dos horas. Si la franja pasa de medianoche (22:00 a 02:00), se suman las de la madrugada del día
    // siguiente, con la hora contada desde hoy (26:00 para las 2:00) para ordenarlas.
    private static function rowsInRange(ServiceJourney $JourneyModel, string $sLineId, \DateTime $Date, int $iFrom, int $iTo, string|null $sStopId): array
    {
        if ($iTo >= $iFrom) {
            return $JourneyModel->timetableForLine($sLineId, $Date, $iFrom, $iTo, $sStopId);
        }
        $aRows = $JourneyModel->timetableForLine($sLineId, $Date, $iFrom, $iTo + self::DAY_SECONDS, $sStopId);
        $NextDate = (clone $Date)->modify('+1 day');
        foreach ($JourneyModel->timetableForLine($sLineId, $NextDate, 0, $iTo, $sStopId) as $aRow) {
            $aRow['departure_seconds'] = (int)$aRow['departure_seconds'] + self::DAY_SECONDS;
            $aRows[] = $aRow;
        }
        usort($aRows, fn($aA, $aB) => (int)$aA['departure_seconds'] <=> (int)$aB['departure_seconds']);
        return $aRows;
    }

    // Hoy, en las redes con autobuses en directo, cada salida lleva su estado y retraso; si no, solo el horario.
    private static function withStatus(ServiceJourney $JourneyModel, array $aRows, \DateTime $Date): array
    {
        $bIsToday = $Date->format('Y-m-d') === Calendar::todayMadrid()->format('Y-m-d');
        if ($bIsToday && Config::hasLive('vehicle-monitoring')) {
            return Live::matcher($JourneyModel)->enrich(array_map(fn($aRow) => $aRow + ['arrival_seconds' => $aRow['departure_seconds']], $aRows));
        }
        return array_map(fn($aRow) => $aRow + ['status' => 'scheduled', 'delaySeconds' => 0], $aRows);
    }

    // Hasta qué hora (segundos desde medianoche) las salidas del día pedido ya pasaron: todas en un día anterior,
    // las de antes de ahora hoy y ninguna en un día futuro.
    private static function pastBefore(string $sDate): int
    {
        $sToday = Calendar::todayMadrid()->format('Y-m-d');
        if ($sDate < $sToday) {
            return PHP_INT_MAX;
        }
        if ($sDate === $sToday) {
            return Calendar::nowSecondsSinceMidnight();
        }
        return -1;
    }

    private static function entry(array $aRow, int $iPastBefore, bool $bUseLastStopHeadsign): array
    {
        $sHeadsign = $aRow['headsign'];
        if ($bUseLastStopHeadsign && !empty($aRow['last_stop_name'])) {
            $sHeadsign = $aRow['last_stop_name'];
        }
        // Una salida del horario que ya pasó se da por salida.
        $sStatus = $aRow['status'];
        if ($sStatus === 'scheduled' && (int)$aRow['departure_seconds'] < $iPastBefore) {
            $sStatus = 'departed';
        }
        return [
            'tripKey' => TripKey::build($aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds']),
            'departure' => Calendar::secondsToHm((int)$aRow['departure_seconds']),
            'headsign' => $sHeadsign,
            'status' => $sStatus,
            'delayMinutes' => (int)round(($aRow['delaySeconds'] ?? 0) / 60),
        ];
    }

    // Cuándo se publicó el horario y de qué fecha a qué fecha vale, para avisar si el día pedido se sale.
    private static function publication(\PDO $Pdo, string $sDate): array
    {
        $PublishedStmt = $Pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $PublishedStmt->execute(['schedule_source_published']);
        $sPublished = $PublishedStmt->fetchColumn();
        if (!$sPublished) {
            $sPublished = Config::current()['schedule_source_published'];
        }
        $aRange = ScheduleRange::forDatabase($Pdo);
        return [
            'scheduleSourcePublished' => $sPublished,
            'publishedFrom' => $aRange['from'],
            'publishedUntil' => $aRange['to'],
            'beyondPublished' => $aRange['to'] !== null && $sDate > $aRange['to'],
            'beforePublished' => $aRange['from'] !== null && $sDate < $aRange['from'],
        ];
    }
}
