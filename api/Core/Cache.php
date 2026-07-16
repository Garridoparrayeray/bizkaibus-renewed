<?php

namespace Core;

/**
 * Minimal file cache in the system temp dir (Vercel's /tmp is the only
 * writable path at runtime; this also works unchanged for local dev).
 * Never the source of truth — every caller must be fine recomputing on miss.
 */
class Cache
{
    public static function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $file = sys_get_temp_dir() . '/bizkaibusplus_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $key) . '.json';

        if (is_file($file) && (time() - filemtime($file)) < $ttlSeconds) {
            $cached = json_decode((string)file_get_contents($file), true);
            if ($cached !== null) {
                return $cached;
            }
        }

        $value = $producer();
        file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $value;
    }
}
