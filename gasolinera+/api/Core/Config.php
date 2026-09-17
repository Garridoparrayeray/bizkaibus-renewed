<?php

namespace Core;

class Config
{
    private static ?array $current = null;

    
    public static function current(): array
    {
        if (self::$current === null) {
            self::$current = require __DIR__ . '/../Config/config.php';
        }
        return self::$current;
    }
}
