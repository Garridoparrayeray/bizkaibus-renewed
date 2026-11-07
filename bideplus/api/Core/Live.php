<?php

namespace Core;

use Models\ServiceJourney;
use Services\GtfsRealtimeClient;
use Services\ScheduleOnlyMatcher;
use Services\TripDelayMatcher;
use Services\TripUpdatesMatcher;

// Elige el motor de llegadas de la red activa (clave «engine» de su ficha). Sin motor, sin directo o sin engine/ a
// mano (el algoritmo es un repositorio aparte), devuelve uno sin datos en vivo: el horario oficial pasa tal cual.
class Live
{
    private static function engineClass(string $sName): string|null
    {
        $sEngine = Config::current()['engine'] ?? null;
        if ($sEngine === null || !Config::hasLive('vehicle-monitoring')) {
            return null;
        }
        $sClass = 'Engine\\' . ucfirst($sEngine) . '\\' . $sName;
        if (!class_exists($sClass)) {
            return null;
        }
        return $sClass;
    }

    public static function vehicles(): array
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null) {
            return [];
        }
        return $sClass::vehicles(Config::current());
    }

    // Si el directo trae algún vehículo con hora de hoy. De 23:58 a 05:00, el feed de Bizkaibus repite los buses de antes
    // de medianoche: trae vehículos, pero ninguno sirve (ni para el mapa ni para decir que a un viaje le falta bus).
    public static function isFresh(array $aVehicles): bool
    {
        foreach ($aVehicles as $aEntries) {
            foreach ($aEntries as $aEntry) {
                if (isset($aEntry['locationSeconds']) || isset($aEntry['recordedSeconds'])) {
                    return true;
                }
            }
        }
        return false;
    }

    // Apunta por dónde va cada vehículo del directo en todas las redes con motor (lo llama un cron cada minuto, una sola
    // vez para todas): [red => vehículos apuntados, o null si falló]. Al acabar se vuelve a la red de la llamada.
    public static function recordAll(): array
    {
        $sCurrent = Config::network();
        $aRecorded = [];
        try {
            foreach (Networks::all() as $sSlug => $aNetwork) {
                Config::set($sSlug);
                $sClass = self::engineClass('Live');
                if ($sClass === null || !method_exists($sClass, 'record')) {
                    continue;
                }
                $aRecorded[$sSlug] = 0;
                // record() vuelve a pedir el feed, pero lo encuentra en la caché de esta lectura.
                if (!empty(self::vehicles())) {
                    $aRecorded[$sSlug] = $sClass::record(Config::current());
                }
            }
        } finally {
            Config::set($sCurrent);
        }
        return $aRecorded;
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
            $aVehicles = [];
            if (Config::hasLive('vehicle-positions')) {
                $aVehicles = (new GtfsRealtimeClient($aConfig))->fetchVehiclePositions();
            }
            $TripMatcher = new TripDelayMatcher($aTrips, $JourneyModel, $iMidnight, $aVehicles);
            if ($TripMatcher->matchedJourneys() > 0 || empty($aTrips) || !isset($aConfig['gtfs_rt']['match_tolerance_seconds'])) {
                return $TripMatcher;
            }
        }
        return new TripUpdatesMatcher($aTrips, $JourneyModel, $aConfig['gtfs_rt']['match_tolerance_seconds'], $iMidnight);
    }

    // El emparejador de los autobuses para los viajes de hoy o, con $iDaysAgo = 1, para los de ayer que pasan de las
    // 24:00: ese trabaja con las horas de su horario (26:00 para las 02:00) y solo con sus buses.
    public static function matcher(ServiceJourney|null $JourneyModel, array|null $aVehicles = null, int $iDaysAgo = 0): object
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null) {
            return new ScheduleOnlyMatcher();
        }
        return $sClass::matcher($aVehicles ?? self::vehicles(), $JourneyModel, $iDaysAgo);
    }
}
