<?php

namespace Services;

use Core\Cache;
use Core\Http;

class SiriVehicleMonitoringClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

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
        $activities = [];
        if (isset($xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity)) {
            $activities = $xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity;
        }

        $map = [];
        foreach ($activities as $activity) {
            $mvj = null;
            if (isset($activity->MonitoredVehicleJourney)) {
                $mvj = $activity->MonitoredVehicleJourney;
            }
            if ($mvj === null || !isset($mvj->VehicleJourneyRef)) {
                continue;
            }
            $ref = (string)$mvj->VehicleJourneyRef;
            if (!preg_match('/^trp_[A-Za-z]+(\d+)_(\d+)_[A-Za-z0-9]+_(\d+)/', $ref, $m)) {
                continue;
            }
            [, $lineId, $tripNumber, $departureSeconds] = $m;

            $delayIso = 'PT0S';
            if (isset($mvj->Delay)) {
                $delayIso = (string)$mvj->Delay;
            }
            $vehicleRef = '';
            if (isset($mvj->VehicleRef)) {
                $vehicleRef = (string)$mvj->VehicleRef;
            }
            $currentStopId = null;
            if (isset($mvj->MonitoredCall->StopPointRef)) {
                $currentStopId = (int)$mvj->MonitoredCall->StopPointRef;
            }
            $order = null;
            if (isset($mvj->MonitoredCall->Order)) {
                $order = (int)$mvj->MonitoredCall->Order;
            }

            $key = $lineId . '|' . $tripNumber;
            $map[$key][] = [
                'departureSeconds' => (int)$departureSeconds,
                'delaySeconds' => self::parseIsoDuration($delayIso),
                'vehicleRef' => $vehicleRef,
                'currentStopId' => $currentStopId,
                'order' => $order,
            ];
        }
        return $map;
    }

    private static function parseIsoDuration(string $iso): int
    {
        if (!preg_match('/^(-?)PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return 0;
        }
        $sign = 1;
        if ($m[1] === '-') {
            $sign = -1;
        }
        $hours = 0;
        if (isset($m[2])) {
            $hours = (int)$m[2];
        }
        $minutes = 0;
        if (isset($m[3])) {
            $minutes = (int)$m[3];
        }
        $seconds = 0;
        if (isset($m[4])) {
            $seconds = (int)$m[4];
        }
        return $sign * ($hours * 3600 + $minutes * 60 + $seconds);
    }
}
