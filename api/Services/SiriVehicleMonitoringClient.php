<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * SIRI-VM "vehicle monitoring" feed (misleadingly named bizkaibus-trip-updates.xml
 * at the source). Gives live delay + current stop/order per active trip.
 *
 * VehicleJourneyRef strings (e.g. "trp_A3513_907_OP44LV_61500_O44LV3513_351302_6")
 * do NOT exact-match static service_journeys.id — verified empirically: only the
 * line and trip-number tokens are reliable, the calendar-code token and
 * everything after it use a different vocabulary live vs. static.
 *
 * trip_number alone is NOT unique either — it's a vehicle/duty block id reused
 * across dozens of different departures through the day (verified: line 2322's
 * trip_number "1137" appears at 06:23, 07:36, 08:36, 09:33... all day). Its
 * embedded departure-second token narrows that down, but isn't byte-exact
 * against the static schedule either — verified against real paired samples,
 * e.g. live "72900" against a static "72928" for the same (line, trip) is the
 * same physical departure, just off by tens of seconds. So matching groups by
 * (line_id, trip_number) and picks the closest first_departure_seconds within
 * a small tolerance, rather than requiring exact equality anywhere.
 */
class SiriVehicleMonitoringClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<string, array<int, array{departureSeconds:int, delaySeconds:int, vehicleRef:string, currentStopId:?int, order:?int}>> keyed by "{lineId}|{tripNumber}" */
    public function fetchActiveTrips(): array
    {
        $cfg = $this->config['siri'];
        return Cache::remember('siri_vm', $cfg['cache_ttl_seconds'], function () use ($cfg) {
            try {
                $xmlString = Http::get($cfg['vehicle_monitoring_url'], $cfg['http_timeout_seconds']);
            } catch (\Throwable $e) {
                return [];
            }
            return self::parse($xmlString);
        });
    }

    private static function parse(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }
        $activities = $xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity ?? [];

        $map = [];
        foreach ($activities as $activity) {
            $mvj = $activity->MonitoredVehicleJourney ?? null;
            if ($mvj === null || !isset($mvj->VehicleJourneyRef)) {
                continue;
            }
            $ref = (string)$mvj->VehicleJourneyRef;
            if (!preg_match('/^trp_[A-Za-z]+(\d+)_(\d+)_[A-Za-z0-9]+_(\d+)/', $ref, $m)) {
                continue;
            }
            [, $lineId, $tripNumber, $departureSeconds] = $m;

            $key = $lineId . '|' . $tripNumber;
            $map[$key][] = [
                'departureSeconds' => (int)$departureSeconds,
                'delaySeconds' => self::parseIsoDuration((string)($mvj->Delay ?? 'PT0S')),
                'vehicleRef' => (string)($mvj->VehicleRef ?? ''),
                'currentStopId' => isset($mvj->MonitoredCall->StopPointRef) ? (int)$mvj->MonitoredCall->StopPointRef : null,
                'order' => isset($mvj->MonitoredCall->Order) ? (int)$mvj->MonitoredCall->Order : null,
            ];
        }
        return $map;
    }

    /** Parses a small subset of ISO 8601 durations as used here (PT0S, PT5M, PT1H2M, signed). */
    private static function parseIsoDuration(string $iso): int
    {
        if (!preg_match('/^(-?)PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return 0;
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $hours = (int)($m[2] ?? 0);
        $minutes = (int)($m[3] ?? 0);
        $seconds = (int)($m[4] ?? 0);
        return $sign * ($hours * 3600 + $minutes * 60 + $seconds);
    }
}
