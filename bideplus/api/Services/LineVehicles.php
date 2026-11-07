<?php

namespace Services;

use Core\Config;
use Core\Ids;
use Core\Live;
use Models\ServiceJourney;

// Los vehículos de una línea en su mapa: con posiciones del operador (GTFS-Realtime), donde está cada uno; con el
// directo de vehículos (SIRI y el algoritmo), la parada por la que va cada bus y su retraso.
class LineVehicles
{
    public static function onLine(\PDO $Pdo, string $sLineId, array $aPatterns): array
    {
        if (Config::hasLive('vehicle-positions')) {
            return self::positions($Pdo, $sLineId, $aPatterns);
        }
        return self::registered($Pdo, $sLineId);
    }

    // Si el mapa puede decir que no circula nada: con posiciones del operador siempre; con el directo de vehículos,
    // solo si el feed trae alguno con hora de hoy (los de Lurraldebus y Bilbobus llegan vacíos a ratos, y el de
    // Bizkaibus se queda congelado de madrugada).
    public static function tracked(): bool
    {
        if (Config::hasLive('vehicle-positions')) {
            return true;
        }
        return Config::hasLive('vehicle-monitoring') && Live::isFresh(Live::vehicles());
    }

    private static function positions(\PDO $Pdo, string $sLineId, array $aPatterns): array
    {
        $aConfig = Config::current();
        $Client = new GtfsRealtimeClient($aConfig);
        $bByTrip = ($aConfig['gtfs_rt']['matching'] ?? 'stops') === 'trip';
        return VehiclePositions::onLine($Pdo, $sLineId, $aPatterns, $Client->fetchVehiclePositions(), $Client->fetchTripUpdates(), $bByTrip);
    }

    private static function registered(\PDO $Pdo, string $sLineId): array
    {
        $aVmMap = Live::vehicles();
        $JourneyModel = new ServiceJourney($Pdo);
        $aMatchers = [Live::matcher($JourneyModel, $aVmMap)];
        // De madrugada, los buses que no sigue el de hoy van en viajes de ayer que pasan de las 24:00.
        if (self::count($aMatchers[0]->vehicles()) < self::count($aVmMap)) {
            $aMatchers[] = Live::matcher($JourneyModel, $aVmMap, 1);
        }
        $HeadsignStmt = $Pdo->prepare('
            SELECT jp.headsign
            FROM service_journeys sj
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ?
            LIMIT 1
        ');
        $StopStmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');

        $aVehicles = [];
        foreach ($aMatchers as $Matcher) {
            foreach ($Matcher->vehicles() as $sKey => $aEntries) {
                array_push($aVehicles, ...self::lineVehicles($Matcher, $sLineId, $sKey, $aEntries, $HeadsignStmt, $StopStmt));
            }
        }
        return $aVehicles;
    }

    private static function count(array $aVmMap): int
    {
        return array_sum(array_map('count', $aVmMap));
    }

    // Los buses de una clave del feed («línea|número de viaje»), si es de la línea.
    private static function lineVehicles(object $Matcher, string $sLineId, string $sKey, array $aEntries, \PDOStatement $HeadsignStmt, \PDOStatement $StopStmt): array
    {
        [$sKeyLineId, $sTripNumber] = explode('|', $sKey);
        if ((string)$sKeyLineId !== (string)$sLineId) {
            return [];
        }
        $aVehicles = [];
        foreach ($aEntries as $aEntry) {
            if ($aEntry['currentStopId'] === null) {
                continue;
            }
            $StopStmt->execute([$aEntry['currentStopId']]);
            $aStop = $StopStmt->fetch();
            if (!\is_array($aStop)) {
                continue;
            }
            $HeadsignStmt->execute([$sLineId, $sTripNumber]);
            $sHeadsign = $HeadsignStmt->fetchColumn();
            if ($sHeadsign === false) {
                $sHeadsign = null;
            }
            $iDelaySeconds = $Matcher->displayDelaySeconds($Matcher->journeyIdFor($aEntry), $aEntry);
            $aVehicles[] = [
                'vehicleRef' => $aEntry['vehicleRef'],
                'delayMinutes' => $Matcher->displayDelayMinutes($iDelaySeconds),
                'headsign' => $sHeadsign,
                'stopStatus' => null,
                'position' => null,
                'currentStop' => [
                    'id' => Ids::forOutput($aStop['id']),
                    'name' => $aStop['name'],
                    'lat' => (float)$aStop['lat'],
                    'lon' => (float)$aStop['lon'],
                ],
            ];
        }
        return $aVehicles;
    }
}
