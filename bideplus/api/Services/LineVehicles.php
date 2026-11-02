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
        $Matcher = Live::matcher(new ServiceJourney($Pdo), $aVmMap);
        $HeadsignStmt = $Pdo->prepare('
            SELECT jp.headsign
            FROM service_journeys sj
            JOIN journey_patterns jp ON jp.id = sj.journey_pattern_id
            WHERE sj.line_id = ? AND sj.trip_number = ?
            LIMIT 1
        ');
        $StopStmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id = ?');

        $aVehicles = [];
        foreach ($aVmMap as $sKey => $aEntries) {
            // Las claves del feed son «línea|número de viaje».
            [$sKeyLineId, $sTripNumber] = explode('|', $sKey);
            if ((string)$sKeyLineId !== (string)$sLineId) {
                continue;
            }
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
        }
        return $aVehicles;
    }
}
