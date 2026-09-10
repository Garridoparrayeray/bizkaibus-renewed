<?php

namespace Core;

class Cache
{

    public static function remember(string $sKey, int $iTtlSeconds, callable $Producer): mixed
    {
        $sFile = sys_get_temp_dir() . '/bizkaibusplus_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $sKey) . '.json';

        if (is_file($sFile) && (time() - filemtime($sFile)) < $iTtlSeconds) {
            $aCached = json_decode((string)file_get_contents($sFile), true);
            if ($aCached !== null) {
                return $aCached;
            }
        }

        $aValue = $Producer();
        file_put_contents($sFile, json_encode($aValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $aValue;
    }
}
