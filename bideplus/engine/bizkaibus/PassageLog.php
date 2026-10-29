<?php

namespace Engine\Bizkaibus;

use Core\Store;
use Models\ServiceJourney;

class PassageLog
{
    private const TTL_SECONDS = 36 * 3600;
    private const READ_TIMEOUT_MS = 600;
    private const WRITE_TIMEOUT_MS = 3000;

    public static function key(string $sNetwork, string $sDate): string
    {
        return 'bide:' . $sNetwork . ':pass:' . $sDate;
    }

    public static function fields(array $aVmMap): array
    {
        $aFields = [];
        foreach ($aVmMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                if (empty($aEntry['tripRef']) || !isset($aEntry['order'])) {
                    continue;
                }
                $iSeconds = $aEntry['recordedSeconds'] ?? $aEntry['locationSeconds'] ?? null;
                if ($iSeconds === null) {
                    continue;
                }
                $aFields[(string)$aEntry['tripRef']] = (int)$aEntry['order'] . ':' . (int)$iSeconds;
            }
        }
        return $aFields;
    }

    public static function decode(mixed $sValue): array|null
    {
        if (!is_string($sValue) || !preg_match('/^(\d+):(\d+)$/', $sValue, $aM)) {
            return null;
        }
        return ['order' => (int)$aM[1], 'seconds' => (int)$aM[2]];
    }

    public static function record(array $aVmMap, string $sNetwork, string $sDate): int|null
    {
        $aFields = self::fields($aVmMap);
        if (empty($aFields)) {
            return 0;
        }
        $sKey = self::key($sNetwork, $sDate);
        $aSet = ['HSET', $sKey];
        foreach ($aFields as $sField => $sValue) {
            $aSet[] = $sField;
            $aSet[] = $sValue;
        }
        $aResult = Store::pipeline([$aSet, ['EXPIRE', $sKey, (string)self::TTL_SECONDS]], self::WRITE_TIMEOUT_MS);
        if ($aResult === null || $aResult[0] === null) {
            return null;
        }
        return \count($aFields);
    }

    public static function forJourneys(ServiceJourney $JourneyModel, array $aJourneyIds, string $sNetwork, string $sDate): array
    {
        if (empty($aJourneyIds) || !Store::enabled()) {
            return [];
        }
        $aRefsByJourney = $JourneyModel->tripRefsForJourneys(array_values(array_unique($aJourneyIds)));
        $aFields = [];
        $aJourneyByField = [];
        foreach ($aRefsByJourney as $sJourneyId => $aRefs) {
            foreach ($aRefs as $sRef) {
                $aFields[] = $sRef;
                $aJourneyByField[] = (string)$sJourneyId;
            }
        }
        $aResult = Store::pipeline([array_merge(['HMGET', self::key($sNetwork, $sDate)], $aFields)], self::READ_TIMEOUT_MS);
        if ($aResult === null || !is_array($aResult[0])) {
            return [];
        }
        return self::merge($aJourneyByField, $aResult[0]);
    }

    public static function merge(array $aJourneyByField, array $aValues): array
    {
        $aPassages = [];
        foreach ($aValues as $iIndex => $sValue) {
            $aPassage = self::decode($sValue);
            if ($aPassage === null || !isset($aJourneyByField[$iIndex])) {
                continue;
            }
            $sJourneyId = $aJourneyByField[$iIndex];
            if (!isset($aPassages[$sJourneyId]) || $aPassage['order'] > $aPassages[$sJourneyId]['order']) {
                $aPassages[$sJourneyId] = $aPassage;
            }
        }
        return $aPassages;
    }
}
