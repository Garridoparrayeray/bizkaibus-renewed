<?php

namespace Services;

use Core\Config;
use Core\Ids;
use Models\ServiceJourney;
use Models\Stop;

// El horario de hoy de una parada para su página (Views/app-page.php): cada línea y destino con sus horas, del horario
// programado. Lo leen los buscadores, que no llegan a /api/; la app añade luego el directo.
class StopDaySchedule
{
    // El día de servicio llega a las 30:00: los viajes de la noche que pasan de las 24:00 son aún de hoy.
    private const SERVICE_DAY_END_SECONDS = 30 * 3600;
    // Ninguna parada llega a tantas salidas en un día (la que más, unas 1500): solo pone un tope a la consulta.
    private const MAX_DEPARTURES = 4000;

    // [['lineId', 'lineCode', 'headsign', 'times' => ['06:15', …]], …] por línea y destino.
    public static function today(\PDO $Pdo, string $sStopId): array
    {
        $JourneyModel = new ServiceJourney($Pdo);
        $aStopIds = array_column((new Stop($Pdo))->platformsFor($sStopId), 'id');
        if (empty($aStopIds)) {
            $aStopIds = [$sStopId];
        }
        $bLastStopHeadsign = (bool)Config::feature('headsign_from_last_stop');
        $iNow = Calendar::nowSecondsSinceMidnight();
        $aGroups = [];
        foreach ($aStopIds as $StopId) {
            foreach ($JourneyModel->upcomingAtStop($StopId, self::MAX_DEPARTURES, self::SERVICE_DAY_END_SECONDS - $iNow, 0, $iNow) as $aRow) {
                $sHeadsign = (string)$aRow['headsign'];
                if ($bLastStopHeadsign && !empty($aRow['last_stop_name'])) {
                    $sHeadsign = $aRow['last_stop_name'];
                }
                $sKey = $aRow['line_id'] . '|' . $sHeadsign;
                $aGroups[$sKey] ??= ['lineId' => Ids::forOutput($aRow['line_id']), 'code' => $aRow['line_code'], 'headsign' => $sHeadsign, 'seconds' => []];
                $aGroups[$sKey]['seconds'][] = (int)$aRow['departure_seconds'];
            }
        }
        $aGroups = Config::withoutHiddenLines(array_values($aGroups));
        usort($aGroups, fn(array $aA, array $aB) => strnatcmp($aA['code'] . $aA['headsign'], $aB['code'] . $aB['headsign']));
        foreach ($aGroups as &$aGroup) {
            $aSeconds = array_unique($aGroup['seconds']);
            sort($aSeconds);
            $aGroup['times'] = array_map(fn(int $iSeconds) => Calendar::secondsToHm($iSeconds), $aSeconds);
            unset($aGroup['seconds']);
        }
        return $aGroups;
    }
}
