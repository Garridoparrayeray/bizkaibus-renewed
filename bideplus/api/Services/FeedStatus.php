<?php

namespace Services;

use Core\Config;
use Core\Database;
use Core\Live;
use Models\ServiceJourney;

// Diagnóstico del directo de la red (/api/status): lo pide el servidor como lo hace la app y cuenta qué trae. Lo revisa
// cada hora .github/scripts/live-health.py.
class FeedStatus
{
    // Null si la red no tiene vehículos en directo.
    public static function report(): array|null
    {
        $aConfig = Config::current();
        if (Config::hasLive('trip-updates')) {
            return self::tripUpdatesReport($aConfig);
        }
        if (!Config::hasLive('vehicle-monitoring')) {
            return null;
        }
        $sUrl = $aConfig['siri']['vehicle_monitoring_url'] ?? reset($aConfig['siri']['vehicle_monitoring_urls']);
        $dStart = microtime(true);
        $aOut = ['network' => $aConfig['slug'], 'source' => parse_url($sUrl, PHP_URL_HOST), 'ok' => false];
        try {
            $aOut += self::counts(Live::vehicles());
            $aOut['ok'] = $aOut['vehicles'] > 0;
        } catch (\Throwable $Ex) {
            $aOut['error'] = 'La fuente no respondió';
        }
        $aOut['scheduled'] = self::scheduled();
        $aOut['oftenEmpty'] = !empty($aConfig['live_often_empty']);
        $aOut['ms'] = (int)round((microtime(true) - $dStart) * 1000);
        return $aOut;
    }

    private static function tripUpdatesReport(array $aConfig): array
    {
        $dStart = microtime(true);
        $aOut = ['network' => $aConfig['slug'], 'source' => parse_url($aConfig['gtfs_rt']['trip_updates_url'], PHP_URL_HOST), 'ok' => false];
        try {
            $Client = new GtfsRealtimeClient($aConfig);
            $aOut['feedTrips'] = \count($Client->fetchTripUpdates());
            $aOut['vehicles'] = 0;
            if (Config::hasLive('vehicle-positions')) {
                $aOut['vehicles'] = \count($Client->fetchVehiclePositions());
            }
            $Matcher = Live::tripMatcher(new ServiceJourney(Database::connection()));
            $aOut['matching'] = 'stops';
            if ($Matcher instanceof TripDelayMatcher) {
                $aOut['matching'] = 'trip';
                $aOut += $Matcher->diagnostics();
            }
            if ($Matcher instanceof TripUpdatesMatcher && $Matcher->source() !== 'trip-updates') {
                $aOut['fallback'] = $Matcher->source();
                $aOut['reconstructedTrips'] = $Matcher->trainCount();
            }
            $aOut['ok'] = $aOut['feedTrips'] > 0;
        } catch (\Throwable $Ex) {
            $aOut['error'] = 'La fuente no respondió';
        }
        $aOut['scheduled'] = self::scheduled();
        $aOut['oftenEmpty'] = !empty($aConfig['live_often_empty']);
        $aOut['ms'] = (int)round((microtime(true) - $dStart) * 1000);
        return $aOut;
    }

    // Los viajes que según el horario van ahora en marcha (null si la base no responde): sin ninguno, un feed vacío o
    // sin viajes de esta red (Euskotren publica uno para sus trenes y los dos tranvías) es lo normal.
    private static function scheduled(): int|null
    {
        try {
            return (new ServiceJourney(Database::connection()))->runningCount();
        } catch (\Throwable $Ex) {
            return null;
        }
    }

    private static function counts(array $aMap): array
    {
        $aCounts = ['vehicles' => 0, 'withGps' => 0, 'withTime' => 0, 'fresh' => 0, 'withDelay' => 0];
        foreach ($aMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                $aCounts['vehicles']++;
                $aCounts['withGps'] += (int)($aEntry['lat'] !== null);
                $aCounts['withTime'] += (int)($aEntry['recordedSeconds'] !== null);
                // Con hora de hoy, de la posición o de la lectura (como Live::isFresh): 0 con el feed congelado.
                $aCounts['fresh'] += (int)Live::isFresh([[$aEntry]]);
                $aCounts['withDelay'] += (int)($aEntry['delaySeconds'] !== 0);
            }
        }
        return $aCounts;
    }
}
