<?php

namespace Core;

class Database
{
    /**
     * Una conexión PDO cacheada por red (bus/metro). Un request solo usa una,
     * pero ambas pueden coexistir entre requests dentro del mismo proceso
     * PHP-FPM/CLI-server.
     */
    private static array $connections = [];

    /** Conexión PDO de solo lectura al SQLite de la red actual, creándola la primera vez. */
    public static function connection(): \PDO
    {
        $config = Config::current();
        if (isset($config['network'])) {
            $network = $config['network'];
        } else {
            $network = 'bus';
        }

        if (!isset(self::$connections[$network])) {
            $path = $config['db_path'];

            try {
                $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro&immutable=1');
            } catch (\PDOException $e) {
                // Alternativa para builds de SQLite sin soporte de URI. De
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
