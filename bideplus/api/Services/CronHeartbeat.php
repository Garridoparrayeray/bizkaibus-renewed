<?php

namespace Services;

use Core\Store;

// Latido del cron externo de cada minuto (/api/live/record) y la última vez que vino lleno el feed de cada red con
// motor. Se escribe como mucho cada EVERY_SECONDS (un SET con caducidad en una sola llamada al almacén, no en cada
// minuto) y /api/status da su antigüedad: .github/scripts/live-health.py avisa si el cron se para o un feed se vacía.
class CronHeartbeat
{
    public const EVERY_SECONDS = 600;
    // Lo que dura cada marca en el almacén: más que el plazo con el que avisa live-health.py.
    private const KEEP_SECONDS = 7 * 86400;
    private const BEAT_KEY = 'cron:heartbeat';
    private const FULL_KEY = 'live:full:';
    // /api/status va en el CDN 30 s por dirección y red (y live-health.py añade ?intento=): la lectura del almacén se
    // guarda este rato en cada servidor, para no gastar dos comandos de Upstash en cada petición que no está en caché.
    public const READ_CACHE_SECONDS = 60;

    // Desde el cron, con lo que ha apuntado cada red: si toca, escribe el latido y las redes que vinieron llenas.
    public static function beat(array $aRecorded, int $iNow): bool
    {
        $sMarker = sys_get_temp_dir() . '/bizkaibusplus_heartbeat';
        if (!self::due($sMarker, $iNow) || Store::pipeline(self::commands($aRecorded, $iNow)) === null) {
            return false;
        }
        @touch($sMarker, $iNow);
        return true;
    }

    // Toca escribir si este servidor no lo ha hecho en los últimos EVERY_SECONDS.
    public static function due(string $sMarker, int $iNow): bool
    {
        return !is_file($sMarker) || $iNow - filemtime($sMarker) >= self::EVERY_SECONDS;
    }

    public static function commands(array $aRecorded, int $iNow): array
    {
        $aCommands = [['SET', self::BEAT_KEY, (string)$iNow, 'EX', (string)self::KEEP_SECONDS]];
        foreach ($aRecorded as $sSlug => $iRecorded) {
            if (!empty($iRecorded)) {
                $aCommands[] = ['SET', self::FULL_KEY . $sSlug, (string)$iNow, 'EX', (string)self::KEEP_SECONDS];
            }
        }
        return $aCommands;
    }

    // Para /api/status: segundos desde el último latido y desde la última vez que la red vino llena (null si no hay
    // marca). Sin almacén, o si no responde, nada: no se sabe.
    public static function ages(string $sSlug, int $iNow): array
    {
        if (!Store::enabled()) {
            return [];
        }
        $sCache = self::readCachePath($sSlug);
        $aCached = json_decode((string)@file_get_contents($sCache), true);
        if (is_array($aCached) && $iNow - (int)($aCached['at'] ?? 0) < self::READ_CACHE_SECONDS) {
            return self::agesFrom($aCached['results'], $iNow);
        }
        $aResults = Store::pipeline([['GET', self::BEAT_KEY], ['GET', self::FULL_KEY . $sSlug]]);
        if ($aResults !== null) {
            @file_put_contents($sCache, json_encode(['at' => $iNow, 'results' => $aResults]));
        }
        return self::agesFrom($aResults, $iNow);
    }

    public static function readCachePath(string $sSlug): string
    {
        return sys_get_temp_dir() . '/bizkaibusplus_heartbeat_read_' . $sSlug . '.json';
    }

    public static function agesFrom(array|null $aResults, int $iNow): array
    {
        if ($aResults === null) {
            return [];
        }
        $aAges = ['cronSeconds' => null, 'cronEverySeconds' => self::EVERY_SECONDS, 'lastFullSeconds' => null];
        if (is_numeric($aResults[0] ?? null)) {
            $aAges['cronSeconds'] = max(0, $iNow - (int)$aResults[0]);
        }
        if (is_numeric($aResults[1] ?? null)) {
            $aAges['lastFullSeconds'] = max(0, $iNow - (int)$aResults[1]);
        }
        return $aAges;
    }
}
