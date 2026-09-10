<?php

namespace Core;

/**
 * Config única de la app, cargada perezosamente la primera vez que se pide.
 * A diferencia de bizkaibus+, aquí no hay selección de red: un único
 * fichero de configuración para toda la app.
 */
class Config
{
    private static ?array $current = null;

    /** Config activa, cargando api/Config/config.php la primera vez que se pide. */
    public static function current(): array
    {
        if (self::$current === null) {
            self::$current = require __DIR__ . '/../Config/config.php';
        }
        return self::$current;
    }
}
