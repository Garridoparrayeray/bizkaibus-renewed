<?php

namespace Core;

class Database
{

    private static array $connections = [];

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

                $pdo = new \PDO('sqlite:' . $path);
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$connections[$network] = $pdo;
        }
        return self::$connections[$network];
    }
}
