<?php

namespace Services;

use Core\Cache;
use Core\Database;
use Core\Http;

class RenfeAlertsClient
{
    private const SUMMARY_MAX_LENGTH = 70;
    private const MIN_STATION_NAME_LENGTH = 4;
    private const GENERIC_SUMMARY = 'Aviso de Renfe';

    private array $aConfig;

    public function __construct(array $aConfig)
    {
        $this->aConfig = $aConfig;
    }

    public function fetchAlerts(): array
    {
        $aCfg = $this->aConfig['renfe_alerts'];
        $Pdo = Database::connection();
        $aStops = $Pdo->query('SELECT id, name FROM stops')->fetchAll(\PDO::FETCH_KEY_PAIR);

        $aAll = Cache::remember('renfe_alerts', $aCfg['cache_ttl_seconds'], function () use ($aCfg, $aStops) {
            return self::parse(Http::get($aCfg['url'], $aCfg['http_timeout_seconds']), $aStops);
        });

        $aOwnLines = array_flip($Pdo->query('SELECT id FROM lines')->fetchAll(\PDO::FETCH_COLUMN));
        $aAlerts = [];
        foreach ($aAll as $aAlert) {
            $aAlert['stopRefs'] = array_values(array_filter(array_map('strval', $aAlert['stopRefs'] ?? []), fn($sId) => isset($aStops[$sId])));
            $aAlert['lineRefs'] = array_values(array_filter(array_map('strval', $aAlert['lineRefs'] ?? []), fn($sRef) => isset($aOwnLines[$sRef])));
            if (empty($aAlert['stopRefs']) && empty($aAlert['lineRefs'])) {
                continue;
            }
            if (!empty($aAlert['stopRefs'])) {
                $aAlert['scope'] = 'stop';
                $aAlert['lineRefs'] = [];
            } else {
                $aAlert['scope'] = 'line';
            }
            $aAlerts[] = $aAlert;
        }
        return $aAlerts;
    }

    public function alertsByLine(): array
    {
        $aByLine = [];
        foreach ($this->fetchAlerts() as $aAlert) {
            foreach ($aAlert['lineRefs'] as $sLineRef) {
                $aByLine[$sLineRef][] = self::publicFields($aAlert);
            }
        }
        return $aByLine;
    }

    public function alertsForStop(string $sStopId): array
    {
        $sStopId = (string)$sStopId;
        $aRoutes = array_map('strval', $this->routesServing($sStopId));
        $aResult = [];
        foreach ($this->fetchAlerts() as $aAlert) {
            $bAboutStop = $aAlert['scope'] === 'stop' && in_array($sStopId, $aAlert['stopRefs'], true);
            $bAboutLine = $aAlert['scope'] === 'line' && !empty(array_intersect($aAlert['lineRefs'], $aRoutes));
            if ($bAboutStop || $bAboutLine) {
                $aResult[] = self::publicFields($aAlert);
            }
        }
        return $aResult;
    }

    private function routesServing(string $sStopId): array
    {
        $Stmt = Database::connection()->prepare('
            SELECT DISTINCT jp.line_id
            FROM journey_pattern_stops jps
            JOIN journey_patterns jp ON jp.id = jps.journey_pattern_id
            WHERE jps.stop_id = ?
        ');
        $Stmt->execute([$sStopId]);
        return $Stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private static function publicFields(array $aAlert): array
    {
        return [
            'summary' => $aAlert['summary'],
            'description' => $aAlert['description'],
            'startTime' => $aAlert['startTime'],
            'endTime' => $aAlert['endTime'],
            'scope' => $aAlert['scope'],
        ];
    }

    private static function parse(string $sJson, array $aStops): array
    {
        $aFeed = json_decode($sJson, true);
        if (!isset($aFeed['entity']) || !\is_array($aFeed['entity'])) {
            return [];
        }

        $aNormalizedStops = [];
        foreach ($aStops as $sId => $sName) {
            $aNormalizedStops[(string)$sId] = self::normalize($sName);
        }

        $aAlerts = [];
        foreach ($aFeed['entity'] as $aEntity) {
            if (!isset($aEntity['alert'])) {
                continue;
            }
            $aAlert = $aEntity['alert'];

            $aLineRefs = [];
            $aStopRefs = [];
            foreach ($aAlert['informedEntity'] ?? [] as $aInformed) {
                if (isset($aInformed['routeId'])) {
                    $aLineRefs[] = (string)$aInformed['routeId'];
                }
                if (isset($aInformed['stopId'])) {
                    $aStopRefs[] = (string)$aInformed['stopId'];
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

            $sHeader = self::stationHeader($aParts);
            $aStopRefs = array_values(array_intersect($aStopRefs, array_map('strval', array_keys($aNormalizedStops))));
            if (empty($aStopRefs) && $sHeader !== null) {
                $aStopRefs = self::stopsByHeader(self::normalize($sHeader), $aNormalizedStops);
            }
            if (empty($aStopRefs)) {
                $aStopRefs = self::stopsByText($sText, $aNormalizedStops);
            }

            if ($sHeader !== null && mb_stripos($sText, $sHeader) !== 0) {
                $sSummary = $sHeader;
                $sDescription = $sText;
            } else {
                [$sSummary, $sDescription] = self::splitSummary($sText);
            }

            if ($sSummary === self::GENERIC_SUMMARY && !empty($aStopRefs)) {
                $aNames = array_map(fn($sId) => $aStops[$sId], array_slice($aStopRefs, 0, 2));
                $sSummary = implode(', ', $aNames);
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
                'stopRefs' => array_values(array_unique($aStopRefs)),
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

    private static function stationHeader(array $aParts): string|null
    {
        foreach ($aParts as $sPart) {
            if (preg_match('/^([A-ZÁÉÍÓÚÑÜ][A-ZÁÉÍÓÚÑÜ0-9\-\/ ]{2,40})[.:]/u', self::withoutHashtags($sPart), $aM)) {
                return trim($aM[1]);
            }
        }
        return null;
    }

    private static function normalize(string $sText): string
    {
        $sText = mb_strtolower($sText, 'UTF-8');
        $sText = strtr($sText, ['á' => 'a', 'à' => 'a', 'é' => 'e', 'è' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $sText));
    }

    private static function stopsByHeader(string $sHeader, array $aNormalizedStops): array
    {
        $aExact = [];
        $aPrefix = [];
        foreach ($aNormalizedStops as $sId => $sName) {
            if ($sName === $sHeader) {
                $aExact[] = (string)$sId;
            } elseif (str_starts_with($sName, $sHeader . ' ') || str_starts_with($sHeader, $sName . ' ')) {
                $aPrefix[] = (string)$sId;
            }
        }
        if (!empty($aExact)) {
            return $aExact;
        }
        return $aPrefix;
    }

    private static function stopsByText(string $sText, array $aNormalizedStops): array
    {
        $aBeforeAffected = preg_split('/\bafecta a\b/ui', $sText);
        $sPadded = ' ' . self::normalize($aBeforeAffected[0]) . ' ';

        uasort($aNormalizedStops, fn($sA, $sB) => strlen($sB) <=> strlen($sA));
        $aFound = [];
        foreach ($aNormalizedStops as $sId => $sName) {
            if (strlen($sName) < self::MIN_STATION_NAME_LENGTH) {
                continue;
            }
            if (str_contains($sPadded, ' ' . $sName . ' ')) {
                $aFound[] = (string)$sId;
                $sPadded = str_replace(' ' . $sName . ' ', ' # ', $sPadded);
            }
        }
        return $aFound;
    }

    private static function splitSummary(string $sText): array
    {
        if (preg_match('/^(.{3,' . self::SUMMARY_MAX_LENGTH . '}?)[.:]\s+(.+)$/us', $sText, $aM)) {
            return [trim($aM[1]), trim($aM[2])];
        }
        return [self::GENERIC_SUMMARY, $sText];
    }
}
