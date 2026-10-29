<?php

namespace Controllers;

use Core\Config;
use Core\Live;
use Core\Request;
use Core\Response;
use Core\Store;

// Lo llama un cron externo cada minuto para apuntar por dónde va cada bus, aunque nadie use la app.
// Sin la clave secreta (BIDE_CRON_SECRET) responde como si la ruta no existiera.
class LiveRecordController
{
    public function record(Request $Req, array $aParams): void
    {
        if (!self::authorized($Req)) {
            Response::error('Not found', 404);
            return;
        }
        if (!Store::enabled()) {
            Response::json(['network' => Config::current()['network'], 'recorded' => null, 'error' => 'store not configured'], 503);
            return;
        }
        $iRecorded = Live::record();
        if ($iRecorded === null && Config::hasLive('vehicle-monitoring')) {
            Response::json(['network' => Config::current()['network'], 'recorded' => null, 'error' => 'store write failed'], 502);
            return;
        }
        Response::json(['network' => Config::current()['network'], 'recorded' => $iRecorded]);
    }

    public static function authorized(Request $Req): bool
    {
        $sSecret = Store::env('BIDE_CRON_SECRET');
        if ($sSecret === null) {
            return false;
        }
        $sGiven = $Req->query('key') ?? '';
        $sHeader = $Req->header('Authorization') ?? '';
        if (str_starts_with($sHeader, 'Bearer ')) {
            $sGiven = substr($sHeader, 7);
        }
        return $sGiven !== '' && hash_equals($sSecret, $sGiven);
    }
}
