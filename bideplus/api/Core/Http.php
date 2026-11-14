<?php

namespace Core;

// Peticiones HTTP a las fuentes de datos, con tiempo máximo y la opción de probar varias direcciones.
class Http
{
    private const CONNECT_TIMEOUT_SECONDS = 4;

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
        curl_setopt_array($Ch, self::options($iTimeoutSeconds));
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

    // Varias peticiones a la vez, con el mismo tiempo máximo para todas: [clave => cuerpo, o null si falló].
    public static function getAll(array $aUrls, int $iTimeoutSeconds = 8): array
    {
        $Multi = curl_multi_init();
        $aHandles = [];
        foreach ($aUrls as $Key => $sUrl) {
            $Ch = self::handle($sUrl);
            curl_setopt_array($Ch, self::options($iTimeoutSeconds));
            curl_multi_add_handle($Multi, $Ch);
            $aHandles[$Key] = $Ch;
        }
        do {
            $iMultiStatus = curl_multi_exec($Multi, $iRunning);
            if ($iRunning > 0) {
                curl_multi_select($Multi);
            }
        } while ($iRunning > 0 && $iMultiStatus === CURLM_OK);
        $aBodies = [];
        foreach ($aHandles as $Key => $Ch) {
            $aBodies[$Key] = null;
            // Sin respuesta (el servidor no existe o no contesta a tiempo) el código es 0.
            $iStatus = curl_getinfo($Ch, CURLINFO_HTTP_CODE);
            if (curl_error($Ch) === '' && $iStatus > 0 && $iStatus < 400) {
                $aBodies[$Key] = (string)curl_multi_getcontent($Ch);
            }
            curl_multi_remove_handle($Multi, $Ch);
        }
        curl_multi_close($Multi);
        return $aBodies;
    }

    private static function options(int $iTimeoutSeconds): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $iTimeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
        ];
    }
}
