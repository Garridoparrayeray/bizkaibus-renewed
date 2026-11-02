<?php

namespace Services;

use Core\Ids;
use Models\ServiceJourney;

// Trenes en el mapa de la línea, con la posición GPS que publica el operador (GTFS-Realtime). Renfe dice el viaje de
// cada tren, que es el del horario, y de ahí su línea, su destino y su retraso. En Metro, un tren es de la línea si va
// hacia una de sus estaciones, y su destino es la última estación de su previsión.
class TrainPositions
{
    private const ON_TIME_SECONDS = 60;

    public static function onLine(\PDO $Pdo, string $sLineId, array $aPatterns, array $aVehicles, array $aTrips, bool $bByTrip): array
    {
        $aTripsById = [];
        foreach ($aTrips as $aTrip) {
            $aTripsById[$aTrip['tripId']] = $aTrip;
        }
        if ($bByTrip) {
            $aHeadsigns = self::headsignsByTrip(new ServiceJourney($Pdo), $sLineId, array_column($aVehicles, 'tripId'));
        } else {
            $aHeadsigns = self::headsignsByStops($Pdo, $aPatterns, $aVehicles, $aTripsById);
        }
        $aStops = self::stops($Pdo, array_column($aVehicles, 'stopId'));

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

    // Renfe: el viaje de cada tren y, si es de esta línea, su última estación.
    private static function headsignsByTrip(ServiceJourney $JourneyModel, string $sLineId, array $aTripIds): array
    {
        $aHeadsigns = [];
        foreach ($JourneyModel->journeyIdsForTripRefs($aTripIds) as $sTripId => $sJourneyId) {
            $aLine = $JourneyModel->lineOfJourney((string)$sJourneyId);
            if ($aLine === null || (string)$aLine['id'] !== $sLineId) {
                continue;
            }
            $aJourneyStops = $JourneyModel->stopsForJourney((string)$sJourneyId);
            $sHeadsign = null;
            if (!empty($aJourneyStops)) {
                $sHeadsign = end($aJourneyStops)['name'];
            }
            $aHeadsigns[(string)$sTripId] = $sHeadsign;
        }
        return $aHeadsigns;
    }

    // Metro: los trenes que van hacia una estación de la línea.
    private static function headsignsByStops(\PDO $Pdo, array $aPatterns, array $aVehicles, array $aTripsById): array
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
        $aNames = self::stops($Pdo, array_values($aLastStops));

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

    private static function stops(\PDO $Pdo, array $aStopIds): array
    {
        $aIds = array_values(array_unique(array_filter(array_map('strval', $aStopIds), fn($sId) => $sId !== '')));
        if (empty($aIds)) {
            return [];
        }
        $Stmt = $Pdo->prepare('SELECT id, name, lat, lon FROM stops WHERE id IN (' . implode(',', array_fill(0, \count($aIds), '?')) . ')');
        $Stmt->execute($aIds);
        $aStops = [];
        foreach ($Stmt->fetchAll() as $aStop) {
            $aStops[(string)$aStop['id']] = $aStop;
        }
        return $aStops;
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
