<?php

namespace Core;

class Response
{
    /** Salida JSON estándar de toda la API: todos los controllers terminan aquí. */
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Atajo para respuestas de error, mismo formato que json() con {"error": ...}. */
    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }
}
