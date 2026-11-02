<?php

namespace Core;

use Engine\Metro\TripUpdatesMatcher;
use Models\ServiceJourney;
use Services\GtfsRealtimeClient;
use Services\TripDelayMatcher;

// Elige el motor de llegadas de la red activa (clave «engine» de su ficha). Sin motor o sin directo,
// devuelve uno sin datos en vivo: el horario oficial pasa tal cual.
class Live
{
    private static function engineClass(string $sName): string|null
    {
        $sEngine = Config::current()['engine'] ?? null;
        if ($sEngine === null || !Config::hasLive('vehicle-monitoring')) {
            return null;
        }
        return 'Engine\\' . ucfirst($sEngine) . '\\' . $sName;
    }

    public static function vehicles(): array
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null) {
            return [];
        }
        return $sClass::vehicles(Config::current());
    }

    // Apunta por dónde va cada vehículo del directo (lo llama un cron cada minuto). Null si la red no tiene registro o falló.
    public static function record(): int|null
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null || !method_exists($sClass, 'record')) {
            return null;
        }
        return $sClass::record(Config::current());
    }

    // Hora de paso por cada parada de los viajes de una línea en un día (los últimos 3 días). Null si no hay registro.
    public static function history(string $sDate, string $sLineId): array|null
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null || !method_exists($sClass, 'history')) {
            return null;
        }
        return $sClass::history(Config::current(), $sDate, $sLineId);
    }

    // Horas previstas (GTFS-Realtime) de la red, o null si no tiene: Renfe se empareja por viaje y aplica el retraso de
    // cada tren; Metro, Euskotren y los tranvías, por paradas y hora.
    public static function tripMatcher(ServiceJourney $JourneyModel, int|null $iMidnight = null): object|null
    {
        if (!Config::hasLive('trip-updates')) {
            return null;
        }
        $aConfig = Config::current();
        $aTrips = (new GtfsRealtimeClient($aConfig))->fetchTripUpdates();
        if (($aConfig['gtfs_rt']['matching'] ?? 'stops') === 'trip') {
            return new TripDelayMatcher($aTrips, $JourneyModel, $iMidnight);
        }
        return new TripUpdatesMatcher($aTrips, $JourneyModel, $aConfig['gtfs_rt']['match_tolerance_seconds'], $iMidnight);
    }

    public static function matcher(ServiceJourney|null $JourneyModel, array|null $aVehicles = null): object
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null) {
            return new \Engine\Bizkaibus\RealtimeMatcher([], $JourneyModel);
        }
        return $sClass::matcher($aVehicles ?? self::vehicles(), $JourneyModel);
    }
}
