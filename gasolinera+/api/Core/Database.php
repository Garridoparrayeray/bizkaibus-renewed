<?php

namespace Core;

class Database
{
    private static ?\PDO $connection = null;

    
    public static function connection(): \PDO
    {
        if (self::$connection === null) {
            $path = Config::current()['db_path'];

            try {
                $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro&immutable=1');
            } catch (\PDOException $e) {
                
                
                $pdo = new \PDO('sqlite:' . $path);
            }
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$connection = $pdo;
        }
        return self::$connection;
    }
}
