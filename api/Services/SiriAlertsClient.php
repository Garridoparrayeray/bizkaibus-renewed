<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * SIRI-SX service alerts (bizkaibus-service-alerts.xml). Small feed (~180KB),
 * fetched live and cached briefly — never bundled/static.
 */
class SiriAlertsClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<int, array{summary:string, description:string, startTime:?string, endTime:?string, lineRefs:string[]}> */
    public function fetchAlerts(): array
    {
        $cfg = $this->config['siri'];
        return Cache::remember('siri_alerts', $cfg['cache_ttl_seconds'], function () use ($cfg) {
            try {
                $xmlString = Http::get($cfg['alerts_url'], $cfg['http_timeout_seconds']);
            } catch (\Throwable $e) {
                return [];
            }
            return self::parse($xmlString);
        });
    }

    /** @return array<string, array<int, array{summary:string, description:string, startTime:?string, endTime:?string}>> */
    public function alertsByLine(): array
    {
        $byLine = [];
        foreach ($this->fetchAlerts() as $alert) {
            foreach ($alert['lineRefs'] as $lineRef) {
                $byLine[$lineRef][] = [
                    'summary' => $alert['summary'],
                    'description' => $alert['description'],
                    'startTime' => $alert['startTime'],
                    'endTime' => $alert['endTime'],
                ];
            }
        }
        return $byLine;
    }

    private static function parse(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }
        $situations = $xml->ServiceDelivery->SituationExchangeDelivery->Situations->PtSituationElement ?? [];

        $alerts = [];
        foreach ($situations as $situation) {
            $lineRefs = [];
            if (isset($situation->Affects->VehicleJourneys->AffectedVehicleJourney)) {
                foreach ($situation->Affects->VehicleJourneys->AffectedVehicleJourney as $vj) {
                    if (isset($vj->LineRef)) {
                        $lineRefs[] = (string)$vj->LineRef;
                    }
                }
            }

            $alerts[] = [
                'summary' => self::textByLang($situation->Summary ?? null, 'es'),
                'description' => self::textByLang($situation->Description ?? null, 'es'),
                'startTime' => isset($situation->ValidityPeriod->StartTime) ? (string)$situation->ValidityPeriod->StartTime : null,
                'endTime' => isset($situation->ValidityPeriod->EndTime) ? (string)$situation->ValidityPeriod->EndTime : null,
                'lineRefs' => array_values(array_unique($lineRefs)),
            ];
        }
        return $alerts;
    }

    private static function textByLang($elements, string $lang): string
    {
        if ($elements === null) {
            return '';
        }
        foreach ($elements as $el) {
            $attrs = $el->attributes('http://www.w3.org/XML/1998/namespace');
            if ((string)($attrs['lang'] ?? '') === $lang) {
                return (string)$el;
            }
        }
        // fall back to the first one available
        foreach ($elements as $el) {
            return (string)$el;
        }
        return '';
    }
}
