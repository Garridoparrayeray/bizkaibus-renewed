<?php

namespace Core;

class Http
{

    public static function getFirst(array $aUrls, int $iTimeoutSeconds = 8): string
    {
        $Last = null;
        foreach ($aUrls as $sUrl) {
            try {
                return self::get($sUrl, $iTimeoutSeconds);
            } catch (\RuntimeException $Ex) {
                $Last = $Ex;
            }
        }
        throw $Last ?? new \RuntimeException('No URL to fetch');
    }

    public static function get(string $sUrl, int $iTimeoutSeconds = 8): string
    {
        $Ch = curl_init($sUrl);
        curl_setopt_array($Ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $iTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $sBody = curl_exec($Ch);
        $sError = curl_error($Ch);
        $iStatus = curl_getinfo($Ch, CURLINFO_HTTP_CODE);

        curl_close($Ch);
        if ($sBody === false || $sError) {
            throw new \RuntimeException("GET $sUrl failed: $sError");
        }
        if ($iStatus >= 400) {
            throw new \RuntimeException("GET $sUrl returned HTTP $iStatus");
        }
        return $sBody;
    }
}
