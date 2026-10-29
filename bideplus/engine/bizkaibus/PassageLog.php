<?php

namespace Engine\Bizkaibus;

use Core\Store;
use Models\ServiceJourney;

class PassageLog
{
    private const TTL_SECONDS = 36 * 3600;
    private const HISTORY_TTL_SECONDS = 3 * 24 * 3600;
    private const SCAN_BATCH = 5000;
    private const SCAN_MAX_CALLS = 40;
    private const READ_TIMEOUT_MS = 600;
    private const WRITE_TIMEOUT_MS = 3000;

    public static function key(string $sNetwork, string $sDate): string
    {
        return 'bide:' . $sNetwork . ':pass:' . $sDate;
    }

    public static function stopsKey(string $sNetwork, string $sDate): string
    {
        return 'bide:' . $sNetwork . ':stops:' . $sDate;
    }

    public static function stopField(string $sTripRef, int $iOrder): string
    {
        return $sTripRef . '#' . $iOrder;
    }

    public static function stopFields(array $aVmMap): array
    {
        $aFields = [];
        foreach (self::fields($aVmMap) as $sTripRef => $sValue) {
            $aPassage = self::decode($sValue);
            $aFields[self::stopField((string)$sTripRef, $aPassage['order'])] = (string)$aPassage['seconds'];
        }
        return $aFields;
    }

    public static function stopsFromHash(array $aHash): array
    {
        $aTrips = [];
        foreach ($aHash as $sField => $sSeconds) {
            if (!preg_match('/^(.+)#(\d+)$/', (string)$sField, $aM) || !ctype_digit((string)$sSeconds)) {
                continue;
            }
            $aTrips[$aM[1]][(int)$aM[2]] = (int)$sSeconds;
        }
        foreach ($aTrips as &$aStops) {
            ksort($aStops);
        }
        unset($aStops);
        ksort($aTrips);
        return $aTrips;
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
        $sStopsKey = self::stopsKey($sNetwork, $sDate);
        $aSet = ['HSET', $sKey];
        foreach ($aFields as $sField => $sValue) {
            $aSet[] = $sField;
            $aSet[] = $sValue;
        }
        $aSetStops = ['HSET', $sStopsKey];
        foreach (self::stopFields($aVmMap) as $sField => $sValue) {
            $aSetStops[] = $sField;
            $aSetStops[] = $sValue;
        }
        $aResult = Store::pipeline([
            $aSet,
            $aSetStops,
            ['EXPIRE', $sKey, (string)self::TTL_SECONDS],
            ['EXPIRE', $sStopsKey, (string)self::HISTORY_TTL_SECONDS],
        ], self::WRITE_TIMEOUT_MS);
        if ($aResult === null || $aResult[0] === null) {
            return null;
        }
        return \count($aFields);
    }

    public static function history(string $sNetwork, string $sDate, string $sLineId): array|null
    {
        if (!Store::enabled() || !preg_match('/^[A-Za-z0-9]+$/', $sLineId)) {
            return null;
        }
        $sKey = self::stopsKey($sNetwork, $sDate);
        $sCursor = '0';
        $aHash = [];
        for ($iCall = 0; $iCall < self::SCAN_MAX_CALLS; $iCall++) {
            $aResult = Store::pipeline([['HSCAN', $sKey, $sCursor, 'MATCH', 'trp_A' . $sLineId . '_*', 'COUNT', (string)self::SCAN_BATCH]], 5000);
            if ($aResult === null || !is_array($aResult[0]) || \count($aResult[0]) !== 2) {
                return null;
            }
            [$sCursor, $aPairs] = $aResult[0];
            for ($i = 0; $i + 1 < \count($aPairs); $i += 2) {
                $aHash[$aPairs[$i]] = $aPairs[$i + 1];
            }
            if ((string)$sCursor === '0') {
                break;
            }
        }
        return self::stopsFromHash($aHash);
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
