<?php

namespace Core;

class Http
{
    public static function get(string $url, int $timeoutSeconds = 8): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() no hace nada desde PHP 8.0 (deprecado en 8.5) — el handle se libera solo.

        if ($body === false || $error) {
            throw new \RuntimeException("GET $url failed: $error");
        }
        if ($status >= 400) {
            throw new \RuntimeException("GET $url returned HTTP $status");
        }
        return $body;
    }
}
