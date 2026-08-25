<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Alertas de servicio SIRI-SX (bizkaibus-service-alerts.xml). Feed pequeño
 * (~180KB), se pide en vivo y se cachea poco tiempo: nunca es estático.
 */
class SiriAlertsClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Todas las alertas activas del feed, cacheadas según siri.cache_ttl_seconds.
     * Si SIRI-SX falla, devuelve lista vacía en vez de propagar el error.
     *
     * @return array<int, array{summary:string, description:string, startTime:?string, endTime:?string, lineRefs:string[]}>
     */
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

    /**
     * Las mismas alertas de fetchAlerts(), reagrupadas por line_id de línea
     * afectada, para que AlertsController pueda filtrar por ?line= sin volver
     * a pedir el feed.
     *
     * @return array<string, array<int, array{summary:string, description:string, startTime:?string, endTime:?string}>>
     */
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

    /** Traduce el XML SIRI-SX (PtSituationElement) a la forma plana que usa el resto de la app. */
    private static function parse(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }
        $situations = [];
        if (isset($xml->ServiceDelivery->SituationExchangeDelivery->Situations->PtSituationElement)) {
            $situations = $xml->ServiceDelivery->SituationExchangeDelivery->Situations->PtSituationElement;
        }

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

            $summaryElements = null;
            if (isset($situation->Summary)) {
                $summaryElements = $situation->Summary;
            }
            $descriptionElements = null;
            if (isset($situation->Description)) {
                $descriptionElements = $situation->Description;
            }

            $startTime = null;
            if (isset($situation->ValidityPeriod->StartTime)) {
                $startTime = (string)$situation->ValidityPeriod->StartTime;
            }
            $endTime = null;
            if (isset($situation->ValidityPeriod->EndTime)) {
                $endTime = (string)$situation->ValidityPeriod->EndTime;
            }

            $alerts[] = [
                'summary' => self::textByLang($summaryElements, 'es'),
                'description' => self::textByLang($descriptionElements, 'es'),
                'startTime' => $startTime,
                'endTime' => $endTime,
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
            $elLang = '';
            if (isset($attrs['lang'])) {
                $elLang = (string)$attrs['lang'];
            }
            if ($elLang === $lang) {
                return (string)$el;
            }
        }
        // si no hay en el idioma pedido, se usa el primero que haya
        foreach ($elements as $el) {
            return (string)$el;
        }
        return '';
    }
}
