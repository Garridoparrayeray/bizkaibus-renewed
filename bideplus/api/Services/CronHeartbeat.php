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
        return self::agesFrom(Store::pipeline([['GET', self::BEAT_KEY], ['GET', self::FULL_KEY . $sSlug]]), $iNow);
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
