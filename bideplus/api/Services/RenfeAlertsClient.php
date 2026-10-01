<?php

namespace Services;

use Core\Cache;
use Core\Database;
use Core\Http;

class RenfeAlertsClient
{
    private const SUMMARY_MAX_LENGTH = 70;

    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchAlerts(): array
    {
        $aCfg = $this->aConfig['renfe_alerts'];
        $aAll = Cache::remember('renfe_alerts', $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            return self::parse(Http::get($aCfg['url'], $aCfg['http_timeout_seconds']));
        });

        $aOwnLines = array_flip(Database::connection()->query('SELECT id FROM lines')->fetchAll(\PDO::FETCH_COLUMN));
        $aAlerts = [];
        foreach ($aAll as $aAlert) {
            $aLineRefs = array_values(array_filter($aAlert['lineRefs'], fn($sRef) => isset($aOwnLines[$sRef])));
            if (empty($aLineRefs)) {
                continue;
            }
            $aAlert['lineRefs'] = $aLineRefs;
            $aAlerts[] = $aAlert;
        }
        return $aAlerts;
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

    private static function parse(string $sJson): array
    {
        $aFeed = json_decode($sJson, true);
        if (!isset($aFeed['entity']) || !is_array($aFeed['entity'])) {
            return [];
        }

        $aAlerts = [];
        foreach ($aFeed['entity'] as $aEntity) {
            if (!isset($aEntity['alert'])) {
                continue;
            }
            $aAlert = $aEntity['alert'];

            $aLineRefs = [];
            foreach ($aAlert['informedEntity'] ?? [] as $aInformed) {
                if (isset($aInformed['routeId'])) {
                    $aLineRefs[] = (string)$aInformed['routeId'];
                }
            }

            $sRaw = self::rawText($aAlert['descriptionText'] ?? []);
            if ($sRaw === '') {
                $sRaw = self::rawText($aAlert['headerText'] ?? []);
            }
            $aParts = explode(' // ', $sRaw);
            $sText = self::withoutHashtags(end($aParts));
            if ($sText === '') {
                continue;
            }
            $sStation = self::stationPrefix(self::withoutHashtags($aParts[0]));
            if ($sStation !== null && mb_stripos($sText, $sStation) !== 0) {
                $sSummary = $sStation;
                $sDescription = $sText;
            } else {
                [$sSummary, $sDescription] = self::splitSummary($sText);
            }

            $sStartTime = null;
            $sEndTime = null;
            if (isset($aAlert['activePeriod'][0]['start'])) {
                $sStartTime = gmdate('c', (int)$aAlert['activePeriod'][0]['start']);
            }
            if (isset($aAlert['activePeriod'][0]['end'])) {
                $sEndTime = gmdate('c', (int)$aAlert['activePeriod'][0]['end']);
            }

            $aAlerts[] = [
                'summary' => $sSummary,
                'description' => $sDescription,
                'startTime' => $sStartTime,
                'endTime' => $sEndTime,
                'lineRefs' => array_values(array_unique($aLineRefs)),
            ];
        }
        return $aAlerts;
    }

    private static function rawText(array $aTranslated): string
    {
        $aTranslations = $aTranslated['translation'] ?? [];
        foreach ($aTranslations as $aTranslation) {
            if (($aTranslation['language'] ?? '') === 'es') {
                return trim((string)$aTranslation['text']);
            }
        }
        if (isset($aTranslations[0]['text'])) {
            return trim((string)$aTranslations[0]['text']);
        }
        return '';
    }

    private static function withoutHashtags(string $sText): string
    {
        return trim(preg_replace('/^(#\S+\s+)+/u', '', trim($sText)));
    }

    private static function stationPrefix(string $sText): string|null
    {
        if (preg_match('/^([A-ZÁÉÍÓÚÑÜ][A-ZÁÉÍÓÚÑÜ0-9\-\/ ]{2,40})[.:]/u', $sText, $aM)) {
            return trim($aM[1]);
        }
        return null;
    }

    private static function splitSummary(string $sText): array
    {
        if (preg_match('/^(.{3,' . self::SUMMARY_MAX_LENGTH . '}?)[.:]\s+(.+)$/us', $sText, $aM)) {
            return [trim($aM[1]), trim($aM[2])];
        }
        return ['Aviso de Renfe', $sText];
    }
}
