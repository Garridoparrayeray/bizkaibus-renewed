<?php

namespace Core;

class Response
{

    public static function json(mixed $aData, int $iStatus = 200): void
    {
        http_response_code($iStatus);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($aData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $sMessage, int $iStatus = 400): void
    {
        self::json(['error' => $sMessage], $iStatus);
    }
}
