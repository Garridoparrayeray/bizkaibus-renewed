<?php

namespace Core;

class Database
{
    private static ?\PDO $connection = null;

    /** Conexión PDO de solo lectura al SQLite de precios, creándola la primera vez. */
    public static function connection(): \PDO
    {
        if (self::$connection === null) {
            $path = Config::current()['db_path'];

            try {
                $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro&immutable=1');
            } catch (\PDOException $e) {
                // Alternativa para builds de SQLite sin soporte de URI. De
                // todas formas el fichero nunca se escribe en runtime.
                $pdo = new \PDO('sqlite:' . $path);
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$connection = $pdo;
        }
        return self::$connection;
    }
}
