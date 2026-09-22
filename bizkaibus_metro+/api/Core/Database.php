<?php

namespace Core;

class Database
{
    private static array $aConnections = [];

    public static function connection(): \PDO
    {
        $aConfig = Config::current();
        $sNetwork = $aConfig['network'] ?? 'bus';

        if (!isset(self::$aConnections[$sNetwork])) {
            $sPath = $aConfig['db_path'];

            try {
                $Pdo = new \PDO('sqlite:file:' . $sPath . '?mode=ro&immutable=1');
            } catch (\PDOException $Ex) {
                $Pdo = new \PDO('sqlite:' . $sPath);
            }
            $Pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $Pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $Pdo->exec('PRAGMA query_only = 1; PRAGMA temp_store = MEMORY; PRAGMA cache_size = -16000; PRAGMA mmap_size = 268435456');
            self::$aConnections[$sNetwork] = $Pdo;
        }
        return self::$aConnections[$sNetwork];
    }
}
