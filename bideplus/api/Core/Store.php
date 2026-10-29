<?php

namespace Core;

// Almacén compartido entre servidores (Upstash Redis por su API HTTPS). Sin credenciales, o si falla, no hace nada:
// la app sigue funcionando sin él. Tras un fallo se deja de llamar un minuto para no frenar las respuestas.
class Store
{
    private const PAUSE_AFTER_FAILURE_SECONDS = 60;

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    // Ejecuta varios comandos en una sola llamada. Devuelve el resultado de cada uno (null si ese falló), o null si no hay almacén.
    public static function pipeline(array $aCommands, int $iTimeoutMs = 800): array|null
    {
        $aCredentials = self::credentials();
        if ($aCredentials === null || empty($aCommands) || self::paused()) {
            return null;
        }
        [$sUrl, $sToken] = $aCredentials;

        $Ch = curl_init($sUrl . '/pipeline');
        curl_setopt_array($Ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(array_values($aCommands), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $sToken, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS => $iTimeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($iTimeoutMs, 500),
        ]);
        $sBody = curl_exec($Ch);
        $iStatus = curl_getinfo($Ch, CURLINFO_HTTP_CODE);
        $sError = curl_error($Ch);
        curl_close($Ch);

        $aDecoded = is_string($sBody) ? json_decode($sBody, true) : null;
        if ($sError !== '' || $iStatus >= 400 || !is_array($aDecoded)) {
            error_log('Bide+ almacén: ' . ($sError !== '' ? $sError : 'HTTP ' . $iStatus));
            @touch(self::pauseMarker());
            return null;
        }
        return array_map(fn($aItem) => is_array($aItem) && !isset($aItem['error']) ? ($aItem['result'] ?? null) : null, $aDecoded);
    }

    private static function credentials(): array|null
    {
        // Nombres que pone Vercel al conectar Upstash (los de su antiguo «KV» también valen).
        $sUrl = self::env('UPSTASH_REDIS_REST_URL') ?? self::env('KV_REST_API_URL');
        $sToken = self::env('UPSTASH_REDIS_REST_TOKEN') ?? self::env('KV_REST_API_TOKEN');
        if ($sUrl === null || $sToken === null) {
            return null;
        }
        return [rtrim($sUrl, '/'), $sToken];
    }

    public static function env(string $sName): string|null
    {
        $sValue = getenv($sName);
        if ($sValue === false || $sValue === '') {
            $sValue = $_SERVER[$sName] ?? $_ENV[$sName] ?? '';
        }
        return $sValue === '' ? null : (string)$sValue;
    }

    private static function paused(): bool
    {
        $sMarker = self::pauseMarker();
        return is_file($sMarker) && time() - filemtime($sMarker) < self::PAUSE_AFTER_FAILURE_SECONDS;
    }

    private static function pauseMarker(): string
    {
        return sys_get_temp_dir() . '/bizkaibusplus_store.fail';
    }
}
