<?php

namespace Core;

class Database
{
    private static ?\PDO $pdo = null;

    public static function connection(): \PDO
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../Config/config.php';
            $path = $config['db_path'];

            try {
                $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro&immutable=1');
            } catch (\PDOException $e) {
                // Alternativa para builds de SQLite sin soporte de URI — de
                // todas formas el fichero nunca se escribe en runtime.
                $pdo = new \PDO('sqlite:' . $path);
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }
}
