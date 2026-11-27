<?php

namespace Services;

use Core\Config;
use Core\Database;
use Core\Live;
use Models\ServiceJourney;

// Diagnóstico del directo de la red (/api/status): lo pide el servidor como lo hace la app y cuenta qué trae, con el
// servicio reducido de hoy. Lo revisa cada hora .github/scripts/live-health.py.
class FeedStatus
{
    // Null si la red no tiene vehículos en directo.
    public static function report(): array|null
    {
        $aConfig = Config::current();
        if (Config::hasFeedLive()) {
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
        $aOut['reducedService'] = ReducedService::current();
        $aOut += self::publishedUntil() + CronHeartbeat::ages($aConfig['slug'], time());
        $aOut['ms'] = (int)round((microtime(true) - $dStart) * 1000);
        return $aOut;
    }

    // Hasta cuándo vale el horario publicado de la red (null si la base no lo dice o no responde).
    public static function publishedUntil(): array
    {
        try {
            return ['publishedUntil' => ScheduleRange::forDatabase(Database::connection())['to']];
        } catch (\Throwable $Ex) {
            return ['publishedUntil' => null];
        }
    }

    // GTFS-Realtime: con horas previstas (feed trip-updates) o, sin ellas, con el retraso que da el GPS de cada
    // vehículo sobre su horario (feed vehicle-positions, emparejado por posición).
    private static function tripUpdatesReport(array $aConfig): array
    {
        $dStart = microtime(true);
        $sFeed = 'trip-updates';
        if (!Config::hasLive('trip-updates')) {
            $sFeed = 'vehicle-positions';
        }
        $sUrl = $aConfig['gtfs_rt'][str_replace('-', '_', $sFeed) . '_url'];
        $aOut = ['network' => $aConfig['slug'], 'source' => parse_url($sUrl, PHP_URL_HOST), 'feed' => $sFeed,
            'ok' => false];
        try {
            $Client = new GtfsRealtimeClient($aConfig);
            $aOut['feedTrips'] = 0;
            if ($sFeed === 'trip-updates') {
                $aOut['feedTrips'] = \count($Client->fetchTripUpdates());
            }
            $aOut['vehicles'] = 0;
            if (Config::hasLive('vehicle-positions')) {
                $aOut['vehicles'] = \count($Client->fetchVehiclePositions());
            }
            $Matcher = Live::tripMatcher(new ServiceJourney(Database::connection()));
            $aOut['matching'] = 'stops';
            if ($Matcher instanceof TripDelayMatcher && $sFeed === 'vehicle-positions') {
                $aOut['matching'] = 'positions';
            } elseif ($Matcher instanceof TripDelayMatcher) {
                $aOut['matching'] = 'trip';
            }
            if ($Matcher instanceof TripDelayMatcher) {
                $aOut += $Matcher->diagnostics();
            }
            if ($Matcher instanceof PlatformMatcher) {
                $aOut = array_merge($aOut, $Matcher->diagnostics());
            }
            $bVehicles = $sFeed === 'vehicle-positions' && $aOut['vehicles'] > 0;
            $aOut['ok'] = $aOut['feedTrips'] > 0 || ($aOut['trains'] ?? 0) > 0 || $bVehicles;
        } catch (\Throwable $Ex) {
            $aOut['error'] = 'La fuente no respondió';
        }
        $aOut['scheduled'] = self::scheduled();
        $aOut['oftenEmpty'] = !empty($aConfig['live_often_empty']);
        $aOut['operatorDelays'] = !empty($aConfig['live_delays_are_operators']);
        $aOut['reducedService'] = ReducedService::current();
        // La última vez que vino lleno solo se apunta en las redes con motor (vehicle-monitoring).
        $aAges = array_diff_key(CronHeartbeat::ages($aConfig['slug'], time()), ['lastFullSeconds' => true]);
        $aOut += self::publishedUntil() + $aAges;
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
