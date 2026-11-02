<?php

namespace Services;

use Core\Ids;
use Models\ServiceJourney;
use Models\Stop;

// Vehículos en el mapa de la línea, con la posición GPS que publica el operador (GTFS-Realtime). Si la red empareja por
// viaje (Renfe, Tuvisa, Dbus), el viaje de cada vehículo es el del horario y de ahí salen su línea, su destino y su
// retraso. Si no (Metro), un tren es de la línea si va hacia una de sus estaciones, y su destino es la última estación
// de su previsión.
class VehiclePositions
{
    private const ON_TIME_SECONDS = 60;

    public static function onLine(\PDO $Pdo, string $sLineId, array $aPatterns, array $aVehicles, array $aTrips, bool $bByTrip): array
    {
        $StopModel = new Stop($Pdo);
        $aTripsById = [];
        foreach ($aTrips as $aTrip) {
            $aTripsById[$aTrip['tripId']] = $aTrip;
        }
        if ($bByTrip) {
            $aHeadsigns = (new ServiceJourney($Pdo))->lastStopNamesOnLine($sLineId, array_column($aVehicles, 'tripId'));
        } else {
            $aHeadsigns = self::headsignsByStops($StopModel, $aPatterns, $aVehicles, $aTripsById);
        }
        $aStops = $StopModel->findMany(array_column($aVehicles, 'stopId'));

        $aOut = [];
        foreach ($aVehicles as $aVehicle) {
            $aStop = $aStops[(string)$aVehicle['stopId']] ?? null;
            if ($aStop === null || !array_key_exists($aVehicle['tripId'], $aHeadsigns)) {
                continue;
            }
            $aOut[] = [
                'vehicleRef' => $aVehicle['vehicleId'],
                'delayMinutes' => self::delayMinutes($aTripsById[$aVehicle['tripId']]['delay'] ?? null),
                'headsign' => $aHeadsigns[$aVehicle['tripId']],
                'stopStatus' => $aVehicle['stopStatus'],
                'position' => ['lat' => $aVehicle['lat'], 'lon' => $aVehicle['lon']],
                'currentStop' => ['id' => Ids::forOutput($aStop['id']), 'name' => $aStop['name'], 'lat' => (float)$aStop['lat'], 'lon' => (float)$aStop['lon']],
            ];
        }
        return $aOut;
    }

    // Sin emparejar por viaje (Metro): los trenes que van hacia una estación de la línea.
    private static function headsignsByStops(Stop $StopModel, array $aPatterns, array $aVehicles, array $aTripsById): array
    {
        $aLineStops = [];
        foreach ($aPatterns as $aPattern) {
            foreach ($aPattern['stops'] as $aStop) {
                $aLineStops[(string)$aStop['id']] = true;
            }
        }
        $aLastStops = [];
        foreach ($aVehicles as $aVehicle) {
            $aPrediction = $aTripsById[$aVehicle['tripId']]['stops'] ?? [];
            if (!empty($aPrediction)) {
                $aLastStops[$aVehicle['tripId']] = (string)end($aPrediction)[0];
            }
        }
        $aNames = $StopModel->findMany(array_values($aLastStops));

        $aHeadsigns = [];
        foreach ($aVehicles as $aVehicle) {
            if (!isset($aLineStops[(string)$aVehicle['stopId']])) {
                continue;
            }
            $sLastStop = $aLastStops[$aVehicle['tripId']] ?? '';
            $aHeadsigns[$aVehicle['tripId']] = $aNames[$sLastStop]['name'] ?? null;
        }
        return $aHeadsigns;
    }

    private static function delayMinutes(int|null $iDelaySeconds): int|null
    {
        if ($iDelaySeconds === null) {
            return null;
        }
        if (abs($iDelaySeconds) < self::ON_TIME_SECONDS) {
            return 0;
        }
        return (int)round($iDelaySeconds / 60);
    }
}
