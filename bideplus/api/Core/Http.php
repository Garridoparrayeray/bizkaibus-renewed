<?php

namespace Core;

// Peticiones HTTP a las fuentes de datos, con tiempo máximo y la opción de probar varias direcciones.
class Http
{
    private static mixed $Share = null;

    // Una petición nueva que reutiliza las conexiones ya abiertas por este proceso, también las de peticiones
    // anteriores (PHP 8.5, el de Vercel): sin eso, cada lectura de un feed o del almacén repite el saludo TLS, unos
    // 7 ms de CPU frente a 0,4 ms. Con un PHP anterior, una petición normal.
    public static function handle(string $sUrl): \CurlHandle
    {
        $Ch = curl_init($sUrl);
        if (self::$Share === null && function_exists('curl_share_init_persistent')) {
            self::$Share = curl_share_init_persistent([CURL_LOCK_DATA_DNS, CURL_LOCK_DATA_CONNECT, CURL_LOCK_DATA_SSL_SESSION]);
        }
        if (self::$Share !== null) {
            curl_setopt($Ch, CURLOPT_SHARE, self::$Share);
        }
        return $Ch;
    }

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
        $Ch = self::handle($sUrl);
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
        if ($sBody === false || $sError) {
            throw new \RuntimeException("GET $sUrl failed: $sError");
        }
        if ($iStatus >= 400) {
            throw new \RuntimeException("GET $sUrl returned HTTP $iStatus");
        }
        return $sBody;
    }
}
