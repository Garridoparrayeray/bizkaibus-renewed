<?php

namespace Engine\Lurraldebus;

use Core\Cache;
use Models\ServiceJourney;
use Services\Calendar;

class Live
{
    public static function vehicles(array $aNetwork): array
    {
        $aCfg = $aNetwork['siri'];
        return Cache::remember('siri_vm_' . $aNetwork['network'], $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            $aMap = [];
            foreach (self::fetchAll($aCfg['vehicle_monitoring_urls'], $aCfg['http_timeout_seconds']) as $sOperator => $sBody) {
                foreach (self::parse($sBody, $sOperator) as $sKey => $aEntries) {
                    $aMap[$sKey] = array_merge($aMap[$sKey] ?? [], $aEntries);
                }
            }
            return $aMap;
        });
    }

    public static function matcher(array $aVehicles, ServiceJourney|null $JourneyModel): RealtimeMatcher
    {
        return new RealtimeMatcher($aVehicles, $JourneyModel);
    }

    private static function fetchAll(array $aUrls, int $iTimeoutSeconds): array
    {
        $Multi = curl_multi_init();
        $aHandles = [];
        foreach ($aUrls as $sOperator => $sUrl) {
            $Ch = curl_init($sUrl);
            curl_setopt_array($Ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $iTimeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $iTimeoutSeconds,
                CURLOPT_USERAGENT => 'Bide+ (bideplus.vercel.app)',
            ]);
            curl_multi_add_handle($Multi, $Ch);
            $aHandles[$sOperator] = $Ch;
        }
        do {
            $iStatus = curl_multi_exec($Multi, $iRunning);
            if ($iRunning) {
                curl_multi_select($Multi, 1.0);
            }
        } while ($iRunning && $iStatus === CURLM_OK);
        $aBodies = [];
        foreach ($aHandles as $sOperator => $Ch) {
            $sBody = curl_multi_getcontent($Ch);
            if (is_string($sBody) && $sBody !== '' && curl_getinfo($Ch, CURLINFO_HTTP_CODE) === 200) {
                $aBodies[$sOperator] = $sBody;
            }
            curl_multi_remove_handle($Multi, $Ch);
            curl_close($Ch);
        }
        curl_multi_close($Multi);
        return $aBodies;
    }

    public static function parse(string $sXmlString, string $sOperator, string|null $sToday = null): array
    {
        $Xml = @simplexml_load_string($sXmlString);
        if ($Xml === false || !isset($Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity)) {
            return [];
        }
        $sToday ??= Calendar::todayMadrid()->format('Y-m-d');
        $aMap = [];
        foreach ($Xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity as $Activity) {
            $Mvj = $Activity->MonitoredVehicleJourney ?? null;
            if ($Mvj === null || !isset($Mvj->LineRef)) {
                continue;
            }
            $sJourney = '';
            if (isset($Mvj->FramedVehicleJourneyRef->DatedVehicleJourneyRef)) {
                $sJourney = (string)$Mvj->FramedVehicleJourneyRef->DatedVehicleJourneyRef;
            } elseif (isset($Mvj->VehicleJourneyRef)) {
                $sJourney = (string)$Mvj->VehicleJourneyRef;
            } elseif (isset($Mvj->DatedVehicleJourneyRef)) {
                $sJourney = (string)$Mvj->DatedVehicleJourneyRef;
            }
            if ($sJourney === '') {
                continue;
            }
            $sLineId = $sOperator . ':' . (string)$Mvj->LineRef;
            $sTripRef = $sOperator . ':' . $sJourney;

            $iDeparture = 0;
            if (isset($Mvj->OriginAimedDepartureTime)) {
                $iDeparture = self::secondsToday((string)$Mvj->OriginAimedDepartureTime, $sToday, true) ?? 0;
            }
            $sStop = null;
            $iOrder = null;
            if (isset($Mvj->MonitoredCall)) {
                $sStop = isset($Mvj->MonitoredCall->StopPointRef) ? (string)$Mvj->MonitoredCall->StopPointRef : null;
                if (isset($Mvj->MonitoredCall->Order)) {
                    $iOrder = (int)$Mvj->MonitoredCall->Order;
                } elseif (isset($Mvj->MonitoredCall->VisitNumber)) {
                    $iOrder = (int)$Mvj->MonitoredCall->VisitNumber;
                }
            }
            $iRecorded = isset($Activity->RecordedAtTime) ? self::secondsToday((string)$Activity->RecordedAtTime, $sToday) : null;
            $dLat = null;
            $dLon = null;
            $iLocation = null;
            if (isset($Mvj->VehicleLocation->Latitude, $Mvj->VehicleLocation->Longitude)) {
                $sAt = isset($Mvj->LocationRecordedAtTime) ? (string)$Mvj->LocationRecordedAtTime : (isset($Activity->RecordedAtTime) ? (string)$Activity->RecordedAtTime : '');
                $iLocation = self::secondsToday($sAt, $sToday);
                if ($iLocation !== null) {
                    $dLat = (float)$Mvj->VehicleLocation->Latitude;
                    $dLon = (float)$Mvj->VehicleLocation->Longitude;
                }
            }
            $aMap[$sLineId . '|' . $sTripRef][] = [
                'tripRef' => $sTripRef,
                'lineId' => $sLineId,
                'departureSeconds' => $iDeparture,
                'delaySeconds' => isset($Mvj->Delay) ? self::isoSeconds((string)$Mvj->Delay) : 0,
                'vehicleRef' => isset($Mvj->VehicleRef) ? $sOperator . ':' . (string)$Mvj->VehicleRef : '',
                'currentStopId' => $sStop,
                'order' => $iOrder,
                'lat' => $dLat,
                'lon' => $dLon,
                'locationSeconds' => $iLocation,
                'recordedSeconds' => $iRecorded,
            ];
        }
        return $aMap;
    }

    private static function secondsToday(string $sIso, string $sToday, bool $bAnyDay = false): int|null
    {
        if ($sIso === '') {
            return null;
        }
        try {
            $Date = new \DateTime($sIso);
        } catch (\Exception $Ex) {
            return null;
        }
        $Date->setTimezone(new \DateTimeZone('Europe/Madrid'));
        if (!$bAnyDay && $Date->format('Y-m-d') !== $sToday) {
            return null;
        }
        return (int)$Date->format('H') * 3600 + (int)$Date->format('i') * 60 + (int)$Date->format('s');
    }

    private static function isoSeconds(string $sIso): int
    {
        if (!preg_match('/^(-?)PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $sIso, $aM)) {
            return 0;
        }
        return ($aM[1] === '-' ? -1 : 1) * ((int)($aM[2] ?? 0) * 3600 + (int)($aM[3] ?? 0) * 60 + (int)($aM[4] ?? 0));
    }
}
