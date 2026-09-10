<?php

namespace Services;

use Core\Cache;
use Core\Http;

class SiriVehicleMonitoringClient
{
    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchActiveTrips(): array
    {
        $aCfg = $this->aConfig['siri'];
        return Cache::remember('siri_vm', $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            try {
                $sXmlString = Http::get($aCfg['vehicle_monitoring_url'], $aCfg['http_timeout_seconds']);
            } catch (\Throwable $Ex) {
                return [];
            }
            return self::parse($sXmlString);
        });
    }

    private static function parse(string $sXmlString): array
    {
        $Xml = @simplexml_load_string($sXmlString);
        if ($Xml === false) {
            return [];
        }
        $aActivities = [];
        if (isset($Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity)) {
            $aActivities = $Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity;
        }

        $aMap = [];
        foreach ($aActivities as $Activity) {
            $Mvj = null;
            if (isset($Activity->MonitoredVehicleJourney)) {
                $Mvj = $Activity->MonitoredVehicleJourney;
            }
            if ($Mvj === null || !isset($Mvj->VehicleJourneyRef)) {
                continue;
            }
            $sRef = (string)$Mvj->VehicleJourneyRef;
            if (!preg_match('/^trp_[A-Za-z]+(\d+)_(\d+)_[A-Za-z0-9]+_(\d+)/', $sRef, $aM)) {
                continue;
            }
            [, $sLineId, $sTripNumber, $sDepartureSeconds] = $aM;

            $sDelayIso = 'PT0S';
            if (isset($Mvj->Delay)) {
                $sDelayIso = (string)$Mvj->Delay;
            }
            $sVehicleRef = '';
            if (isset($Mvj->VehicleRef)) {
                $sVehicleRef = (string)$Mvj->VehicleRef;
            }
            $iCurrentStopId = null;
            if (isset($Mvj->MonitoredCall->StopPointRef)) {
                $iCurrentStopId = (int)$Mvj->MonitoredCall->StopPointRef;
            }
            $iOrder = null;
            if (isset($Mvj->MonitoredCall->Order)) {
                $iOrder = (int)$Mvj->MonitoredCall->Order;
            }

            $sKey = $sLineId . '|' . $sTripNumber;
            $aMap[$sKey][] = [
                'departureSeconds' => (int)$sDepartureSeconds,
                'delaySeconds' => self::parseIsoDuration($sDelayIso),
                'vehicleRef' => $sVehicleRef,
                'currentStopId' => $iCurrentStopId,
                'order' => $iOrder,
            ];
        }
        return $aMap;
    }

    private static function parseIsoDuration(string $sIso): int
    {
        if (!preg_match('/^(-?)PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $sIso, $aM)) {
            return 0;
        }
        $iSign = 1;
        if ($aM[1] === '-') {
            $iSign = -1;
        }
        $iHours = 0;
        if (isset($aM[2])) {
            $iHours = (int)$aM[2];
        }
        $iMinutes = 0;
        if (isset($aM[3])) {
            $iMinutes = (int)$aM[3];
        }
        $iSeconds = 0;
        if (isset($aM[4])) {
            $iSeconds = (int)$aM[4];
        }
        return $iSign * ($iHours * 3600 + $iMinutes * 60 + $iSeconds);
    }
}
