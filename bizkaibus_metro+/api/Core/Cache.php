<?php

namespace Core;

class Cache
{
    private const FAILURE_RETRY_SECONDS = 60;

    public static function remember(string $sKey, int $iTtlSeconds, callable $Producer): mixed
    {
        $sFile = sys_get_temp_dir() . '/bizkaibusplus_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sKey) . '.json';
        $sFailureMarker = $sFile . '.fail';
        $iAge = is_file($sFile) ? time() - filemtime($sFile) : PHP_INT_MAX;

        if ($iAge < $iTtlSeconds) {
            $aCached = json_decode((string)file_get_contents($sFile), true);
            if ($aCached !== null) {
                return $aCached;
            }
        }

        $bRecentFailure = is_file($sFailureMarker) && (time() - filemtime($sFailureMarker)) < self::FAILURE_RETRY_SECONDS;
        if (!$bRecentFailure) {
            try {
                $aValue = $Producer();
                file_put_contents($sFile, json_encode($aValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
                @unlink($sFailureMarker);
                return $aValue;
            } catch (\Throwable $Ex) {
                touch($sFailureMarker);
            }
        }

        if ($iAge !== PHP_INT_MAX) {
            $aStale = json_decode((string)file_get_contents($sFile), true);
            if ($aStale !== null) {
                return $aStale;
            }
        }
        return [];
    }
}
