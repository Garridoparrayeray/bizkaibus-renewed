<?php

namespace Controllers;

use Core\Config;
use Core\Live;
use Core\Request;
use Core\Response;
use Core\Store;
use Services\ArrivalAlerts;
use Services\IncidentAlerts;
use Services\UpdateAlerts;

// Lo llama un cron externo cada minuto para apuntar por dónde va cada bus, aunque nadie use la app, y de paso
// mandar las notificaciones: los avisos de llegada a los que ya les toca, cada 5 minutos las incidencias nuevas y,
// cuando sale una versión nueva de la app, su aviso.
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
        $aNotifications = [
            'arrivalAlerts' => self::safely('avisos de llegada', fn() => ArrivalAlerts::process()),
            'incidentAlerts' => self::safely('avisos de incidencias', fn() => IncidentAlerts::process()),
            'updateAlerts' => self::safely('aviso de versión nueva', fn() => UpdateAlerts::process()),
        ];
        if ($iRecorded === null && Config::hasLive('vehicle-monitoring')) {
            Response::json(['network' => Config::current()['network'], 'recorded' => null, 'error' => 'store write failed'] + $aNotifications, 502);
            return;
        }
        Response::json(['network' => Config::current()['network'], 'recorded' => $iRecorded] + $aNotifications);
    }

    // Un fallo en las notificaciones no estropea el registro de pasos: se apunta y se sigue. Recorren todas las redes,
    // así que al acabar se vuelve a la de la llamada pase lo que pase.
    private static function safely(string $sName, callable $Task): array
    {
        $sNetwork = Config::current()['network'];
        try {
            return $Task();
        } catch (\Throwable $Ex) {
            error_log('Bide+ ' . $sName . ': ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
            return ['error' => 'failed'];
        } finally {
            Config::set($sNetwork);
        }
    }

    // Pasos por parada de una línea en un día: /api/live/history?line=3514&date=2026-10-29&key=… (misma clave que el cron).
    // Cada viaje lleva «orden de la parada en el recorrido → segundos desde medianoche» del último momento en que se vio yendo hacia ella.
    public function history(Request $Req, array $aParams): void
    {
        if (!self::authorized($Req)) {
            Response::error('Not found', 404);
            return;
        }
        $sLine = (string)($Req->query('line') ?? '');
        $sDate = (string)($Req->query('date') ?? \Services\Calendar::todayMadrid()->format('Y-m-d'));
        if (!preg_match('/^[A-Za-z0-9]+$/', $sLine) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sDate)) {
            Response::error('line y date (AAAA-MM-DD) son obligatorios', 400);
            return;
        }
        $aTrips = Live::history($sDate, $sLine);
        if ($aTrips === null) {
            Response::json(['network' => Config::current()['network'], 'error' => 'store not available'], 503);
            return;
        }
        Response::json(['network' => Config::current()['network'], 'line' => $sLine, 'date' => $sDate, 'trips' => $aTrips]);
    }

    public static function authorized(Request $Req): bool
    {
        $sSecret = Store::env('BIDE_CRON_SECRET');
        if ($sSecret === null) {
            return false;
        }
        // Vale la clave en la URL (?key=) o en la cabecera «Authorization: Bearer …»: una cabecera mal puesta no anula una URL buena.
        $aGiven = [trim((string)($Req->query('key') ?? ''))];
        $sHeader = trim($Req->header('Authorization') ?? '');
        if (str_starts_with($sHeader, 'Bearer ')) {
            $aGiven[] = trim(substr($sHeader, 7));
        }
        foreach ($aGiven as $sGiven) {
            if ($sGiven !== '' && hash_equals($sSecret, $sGiven)) {
                return true;
            }
        }
        return false;
    }
}
