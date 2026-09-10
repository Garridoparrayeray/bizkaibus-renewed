<?php

namespace Core;

class Http
{

    public static function get(string $sUrl, int $iTimeoutSeconds = 8): string
    {
        $Ch = curl_init($sUrl);
        curl_setopt_array($Ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $iTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $sBody = curl_exec($Ch);
        $sError = curl_error($Ch);
        $iStatus = curl_getinfo($Ch, CURLINFO_HTTP_CODE);

        if ($sBody === false || $sError) {
            throw new \RuntimeException("GET $sUrl failed: $sError");
        }
        if ($iStatus >= 400) {
            throw new \RuntimeException("GET $sUrl returned HTTP $iStatus");
        }
        return $sBody;
    }
}
