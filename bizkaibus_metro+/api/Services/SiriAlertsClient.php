<?php

namespace Services;

use Core\Cache;
use Core\Http;

class SiriAlertsClient
{
    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchAlerts(): array
    {
        $aCfg = $this->aConfig['siri'];
        return Cache::remember('siri_alerts', $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            try {
                $sXmlString = Http::get($aCfg['alerts_url'], $aCfg['http_timeout_seconds']);
            } catch (\Throwable $Ex) {
                return [];
            }
            return self::parse($sXmlString);
        });
    }

    public function alertsByLine(): array
    {
        $aByLine = [];
        foreach ($this->fetchAlerts() as $aAlert) {
            foreach ($aAlert['lineRefs'] as $sLineRef) {
                $aByLine[$sLineRef][] = [
                    'summary' => $aAlert['summary'],
                    'description' => $aAlert['description'],
                    'startTime' => $aAlert['startTime'],
                    'endTime' => $aAlert['endTime'],
                ];
            }
        }
        return $aByLine;
    }

    private static function parse(string $sXmlString): array
    {
        $Xml = @simplexml_load_string($sXmlString);
        if ($Xml === false) {
            return [];
        }
        $aSituations = [];
        if (isset($Xml->ServiceDelivery->SituationExchangeDelivery->Situations->PtSituationElement)) {
            $aSituations = $Xml->ServiceDelivery->SituationExchangeDelivery->Situations->PtSituationElement;
        }

        $aAlerts = [];
        foreach ($aSituations as $Situation) {
            $aLineRefs = [];
            if (isset($Situation->Affects->VehicleJourneys->AffectedVehicleJourney)) {
                foreach ($Situation->Affects->VehicleJourneys->AffectedVehicleJourney as $Vj) {
                    if (isset($Vj->LineRef)) {
                        $aLineRefs[] = (string)$Vj->LineRef;
                    }
                }
            }

            $SummaryElements = null;
            if (isset($Situation->Summary)) {
                $SummaryElements = $Situation->Summary;
            }
            $DescriptionElements = null;
            if (isset($Situation->Description)) {
                $DescriptionElements = $Situation->Description;
            }

            $sStartTime = null;
            if (isset($Situation->ValidityPeriod->StartTime)) {
                $sStartTime = (string)$Situation->ValidityPeriod->StartTime;
            }
            $sEndTime = null;
            if (isset($Situation->ValidityPeriod->EndTime)) {
                $sEndTime = (string)$Situation->ValidityPeriod->EndTime;
            }

            $aAlerts[] = [
                'summary' => self::textByLang($SummaryElements, 'es'),
                'description' => self::textByLang($DescriptionElements, 'es'),
                'startTime' => $sStartTime,
                'endTime' => $sEndTime,
                'lineRefs' => array_values(array_unique($aLineRefs)),
            ];
        }
        return $aAlerts;
    }

    private static function textByLang($Elements, string $sLang): string
    {
        if ($Elements === null) {
            return '';
        }
        foreach ($Elements as $El) {
            $Attrs = $El->attributes('http://www.w3.org/XML/1998/namespace');
            $sElLang = '';
            if (isset($Attrs['lang'])) {
                $sElLang = (string)$Attrs['lang'];
            }
            if ($sElLang === $sLang) {
                return (string)$El;
            }
        }

        foreach ($Elements as $El) {
            return (string)$El;
        }
        return '';
    }
}
