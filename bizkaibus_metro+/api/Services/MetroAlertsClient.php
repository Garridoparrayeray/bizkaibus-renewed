<?php

namespace Services;

use Core\Cache;
use Core\Http;

class MetroAlertsClient
{
    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchAlerts(): array
    {
        $aCfg = $this->aConfig['metro_alerts'];
        return Cache::remember('metro_alerts', $aCfg['cache_ttl_seconds'], function () use ($aCfg) {
            try {
                $sBody = Http::get($aCfg['url'], $aCfg['http_timeout_seconds']);
            } catch (\Throwable $Ex) {
                return [];
            }
            return self::parse($sBody);
        });
    }

    private static function parse(string $sBody): array
    {
        $aDecoded = json_decode($sBody, true);
        if (!isset($aDecoded['data']) || !is_array($aDecoded['data'])) {
            return [];
        }

        $aAlerts = [];
        foreach ($aDecoded['data'] as $aRow) {
            $bIsPublished = false;
            if (isset($aRow['is_published'])) {
                $bIsPublished = (string)$aRow['is_published'] === '1';
            }
            if (!$bIsPublished) {
                continue;
            }
            if (!empty($aRow['finished_at'])) {
                continue;
            }

            $sSummary = '';
            if (isset($aRow['title_es'])) {
                $sSummary = (string)$aRow['title_es'];
            }

            $sStartTime = null;
            if (!empty($aRow['publish_start_date'])) {
                $sStartTime = (string)$aRow['publish_start_date'];
            }
            $sEndTime = null;
            if (!empty($aRow['publish_end_date'])) {
                $sEndTime = (string)$aRow['publish_end_date'];
            }

            $aAlerts[] = [
                'summary' => $sSummary,
                'description' => '',
                'startTime' => $sStartTime,
                'endTime' => $sEndTime,
            ];
        }
        return $aAlerts;
    }
}
