<?php

namespace Core;

// Respuestas JSON, con caché pública en Vercel para las rutas que la piden (Router::get con segundos).
class Response
{
    public static int $iCacheSeconds = 0;

    public static function json(mixed $aData, int $iStatus = 200): void
    {
        http_response_code($iStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: ' . self::cacheControl($iStatus));
        // Los nombres en mayúsculas del operador salen en formato título (features.title_case_names de la ficha).
        $aData = DisplayName::inData($aData, Config::current());
        echo json_encode($aData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Una respuesta hecha con una fuente caída y sin copia anterior (Cache) no se guarda: el CDN la daría vacía horas.
    public static function cacheControl(int $iStatus): string
    {
        if ($iStatus === 200 && self::$iCacheSeconds > 0 && Cache::$iEmptyFailures === 0) {
            return 'public, max-age=0, s-maxage=' . self::$iCacheSeconds . ', stale-while-revalidate=' . (self::$iCacheSeconds * 2);
        }
        return 'no-store';
    }

    public static function error(string $sMessage, int $iStatus = 400): void
    {
        self::json(['error' => $sMessage], $iStatus);
    }
}
