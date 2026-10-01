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
        $aUrls = array_filter([$aCfg['vehicle_monitoring_url'], $aCfg['vehicle_monitoring_fallback_url'] ?? null]);
        return Cache::remember('siri_vm_' . ($this->aConfig['network'] ?? 'bus'), $aCfg['cache_ttl_seconds'], function () use ($aUrls, $aCfg) {
            return self::parse(Http::getFirst($aUrls, $aCfg['http_timeout_seconds']));
        });
    }

    public static function parse(string $sXmlString, string|null $sToday = null): array
    {
        $Xml = @simplexml_load_string($sXmlString);
        if ($Xml === false) {
            return [];
        }
        $aActivities = [];
        if (isset($Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity)) {
            $aActivities = $Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity;
        }
        if ($sToday === null) {
            $sToday = Calendar::todayMadrid()->format('Y-m-d');
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
            $sCurrentStopId = null;
            if (isset($Mvj->MonitoredCall->StopPointRef)) {
                $sCurrentStopId = (string)$Mvj->MonitoredCall->StopPointRef;
            }
            $iOrder = null;
            if (isset($Mvj->MonitoredCall->Order)) {
                $iOrder = (int)$Mvj->MonitoredCall->Order;
            } elseif (isset($Mvj->MonitoredCall->VisitNumber)) {
                $iOrder = (int)$Mvj->MonitoredCall->VisitNumber;
            }

            $dLat = null;
            $dLon = null;
            $iLocationSeconds = null;
            if (isset($Mvj->VehicleLocation->Latitude, $Mvj->VehicleLocation->Longitude)) {
                $sRecordedAt = '';
                if (isset($Mvj->LocationRecordedAtTime)) {
                    $sRecordedAt = (string)$Mvj->LocationRecordedAtTime;
                } elseif (isset($Activity->RecordedAtTime)) {
                    $sRecordedAt = (string)$Activity->RecordedAtTime;
                }
                $iLocationSeconds = self::secondsSinceMidnightToday($sRecordedAt, $sToday);
                if ($iLocationSeconds !== null) {
                    $dLat = (float)$Mvj->VehicleLocation->Latitude;
                    $dLon = (float)$Mvj->VehicleLocation->Longitude;
                }
            }

            $sKey = $sLineId . '|' . $sTripNumber;
            $aMap[$sKey][] = [
                'tripRef' => $sRef,
                'departureSeconds' => (int)$sDepartureSeconds,
                'delaySeconds' => self::parseIsoDuration($sDelayIso),
                'vehicleRef' => $sVehicleRef,
                'currentStopId' => $sCurrentStopId,
                'order' => $iOrder,
                'lat' => $dLat,
                'lon' => $dLon,
                'locationSeconds' => $iLocationSeconds,
            ];
        }
        return $aMap;
    }

    private static function secondsSinceMidnightToday(string $sIsoDateTime, string $sToday): int|null
    {
        if ($sIsoDateTime === '') {
            return null;
        }
        try {
            $Date = new \DateTime($sIsoDateTime);
        } catch (\Exception $Ex) {
            return null;
        }
        $Date->setTimezone(new \DateTimeZone('Europe/Madrid'));
        if ($Date->format('Y-m-d') !== $sToday) {
            return null;
        }
        return ((int)$Date->format('H')) * 3600 + ((int)$Date->format('i')) * 60 + (int)$Date->format('s');
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
