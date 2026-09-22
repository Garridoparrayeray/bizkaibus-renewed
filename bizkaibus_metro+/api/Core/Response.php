<?php

namespace Core;

class Response
{
    public static int $iCacheSeconds = 0;

    public static function json(mixed $aData, int $iStatus = 200): void
    {
        http_response_code($iStatus);
        header('Content-Type: application/json; charset=utf-8');
        if ($iStatus === 200 && self::$iCacheSeconds > 0) {
            header('Cache-Control: public, max-age=0, s-maxage=' . self::$iCacheSeconds . ', stale-while-revalidate=' . (self::$iCacheSeconds * 2));
        } else {
            header('Cache-Control: no-store');
        }
        echo json_encode($aData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function error(string $sMessage, int $iStatus = 400): void
    {
        self::json(['error' => $sMessage], $iStatus);
    }
}
