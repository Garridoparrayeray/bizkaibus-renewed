<?php

namespace Core;

class Config
{
    private static array|null $current = null;

    public static function set(string $network): void
    {
        if ($network === 'metro') {
            $path = __DIR__ . '/../Config/metro.php';
        } else {
            $path = __DIR__ . '/../Config/config.php';
        }
        self::$current = require $path;
    }

    public static function current(): array
    {
        if (self::$current === null) {
            self::set('bus');
        }
        return self::$current;
    }
}
