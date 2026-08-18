<?php

namespace Core;

class Database
{
    /** Una conexión PDO cacheada por red (bus/metro) — un request solo usa una, pero ambas pueden coexistir entre requests dentro del mismo proceso PHP-FPM/CLI-server. */
    private static array $connections = [];

    public static function connection(): \PDO
    {
        $config = Config::current();
        $network = $config['network'] ?? 'bus';

        if (!isset(self::$connections[$network])) {
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
            self::$connections[$network] = $pdo;
        }
        return self::$connections[$network];
    }
}
