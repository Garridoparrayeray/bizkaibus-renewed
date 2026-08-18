<?php

namespace Core;

/**
 * Resuelve qué config (y por tanto qué red: bus o metro) está activa para
 * el request actual. Se fija una única vez al arrancar (ver api/index.php)
 * para que Database::connection() y los controllers compartidos
 * (StopsController, TimetableController) usen siempre la misma red durante
 * todo el request — nunca se decide dos veces ni se mezcla.
 */
class Config
{
    private static ?array $current = null;

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
