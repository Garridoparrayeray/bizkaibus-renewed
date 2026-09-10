<?php

namespace Core;

class Database
{

    private static array $aConnections = [];

    public static function connection(): \PDO
    {
        $aConfig = Config::current();
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        } else {
            $sNetwork = 'bus';
        }

        if (!isset(self::$aConnections[$sNetwork])) {
            $sPath = $aConfig['db_path'];

            try {
                $Pdo = new \PDO('sqlite:file:' . $sPath . '?mode=ro&immutable=1');
            } catch (\PDOException $Ex) {

                $Pdo = new \PDO('sqlite:' . $sPath);
            }
            $Pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $Pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            self::$aConnections[$sNetwork] = $Pdo;
        }
        return self::$aConnections[$sNetwork];
    }
}
