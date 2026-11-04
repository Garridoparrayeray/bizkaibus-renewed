<?php

namespace Core;

// Caché en archivos temporales para las fuentes externas (incidencias, directo). Si la fuente falla, devuelve lo
// último bueno y no vuelve a intentarlo hasta pasado un minuto.
class Cache
{
    private const FAILURE_RETRY_SECONDS = 60;
    // Un feed en directo que responde sin ningún vehículo (Lurraldebus y Bilbobus pasan días así) se vuelve a pedir
    // pasado este tiempo, no en cada petición ni en cada minuto del cron. Un fallo no cuenta: se reintenta al minuto.
    private const EMPTY_FEED_RETRY_SECONDS = 600;

    // Como remember(), para un feed en directo: una respuesta vacía se guarda EMPTY_FEED_RETRY_SECONDS.
    public static function rememberFeed(string $sKey, int $iTtlSeconds, callable $Producer): mixed
    {
        return self::remember($sKey, $iTtlSeconds, $Producer, self::EMPTY_FEED_RETRY_SECONDS);
    }

    public static function remember(string $sKey, int $iTtlSeconds, callable $Producer, int $iEmptyTtlSeconds = 0): mixed
    {
        $sFile = sys_get_temp_dir() . '/bizkaibusplus_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sKey) . '.json';
        $sFailureMarker = $sFile . '.fail';
        $iAge = PHP_INT_MAX;
        if (is_file($sFile)) {
            $iAge = time() - filemtime($sFile);
        }

        if ($iAge < max($iTtlSeconds, $iEmptyTtlSeconds)) {
            $aCached = json_decode((string)file_get_contents($sFile), true);
            if ($aCached !== null && ($iAge < $iTtlSeconds || $aCached === [])) {
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
