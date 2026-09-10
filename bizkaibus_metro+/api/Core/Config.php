<?php

namespace Core;

class Config
{
    private static array|null $aCurrent = null;

    public static function set(string $sNetwork): void
    {
        if ($sNetwork === 'metro') {
            $sPath = __DIR__ . '/../Config/metro.php';
        } else {
            $sPath = __DIR__ . '/../Config/config.php';
        }
        self::$aCurrent = require $sPath;
    }

    public static function current(): array
    {
        if (self::$aCurrent === null) {
            self::set('bus');
        }
        return self::$aCurrent;
    }
}
