<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\ArrivalAlerts;
use Services\IncidentAlerts;
use Services\Push;
use Services\WebPush;

// Notificaciones push: la clave pública para suscribirse, los avisos de llegada («Avísame cuando llegue») y los de
// incidencias nuevas en los favoritos. El envío lo hace el cron (Services\ArrivalAlerts e IncidentAlerts).
class PushController
{
    private const MAX_ID_LENGTH = 160;

    // Sin almacén no hay notificaciones push: no es un error, la app simplemente no las ofrece. Esa respuesta no se
    // guarda en caché, para que un fallo momentáneo del almacén no las quite un día entero.
    public function key(Request $Req, array $aParams): void
    {
        $aKeys = Push::keys();
        if ($aKeys === null) {
            Response::$iCacheSeconds = 0;
            Response::json(['publicKey' => null]);
            return;
        }
        Response::json(['publicKey' => $aKeys['publicKey'], 'minutes' => ArrivalAlerts::MINUTES]);
    }

    public function createArrival(Request $Req, array $aParams): void
    {
        $aBody = $Req->json();
        $aSubscription = Push::subscription($aBody['subscription'] ?? null);
        $sStopId = (string)($aBody['stopId'] ?? '');
        $sTripKey = (string)($aBody['tripKey'] ?? '');
        $iMinutes = (int)($aBody['minutes'] ?? 0);
        if ($aSubscription === null || !self::validId($sStopId) || !self::validId($sTripKey) || !in_array($iMinutes, ArrivalAlerts::MINUTES, true)) {
            Response::error('Invalid alert', 400);
            return;
        }
        // «Todos los días»: solo si llega true; cualquier otra cosa es un aviso de una vez.
        $bRepeat = ($aBody['repeat'] ?? false) === true;
        $aResult = ArrivalAlerts::create($aSubscription, $sStopId, $sTripKey, $iMinutes, Push::lang($aBody['lang'] ?? null), $bRepeat);
        $iStatus = 200;
        if ($aResult['status'] === 'unavailable') {
            $iStatus = 503;
        }
        Response::json($aResult, $iStatus);
    }

    public function cancelArrival(Request $Req, array $aParams): void
    {
        $sEndpoint = (string)($Req->json()['endpoint'] ?? '');
        if (!ArrivalAlerts::cancel($aParams['id'], $sEndpoint)) {
            Response::error('Not found', 404);
            return;
        }
        Response::json(['status' => 'cancelled']);
    }

    // Activa o actualiza los avisos de incidencias: la suscripción, los favoritos de todas las apps y el idioma.
    public function saveIncidents(Request $Req, array $aParams): void
    {
        $aBody = $Req->json();
        $aSubscription = Push::subscription($aBody['subscription'] ?? null);
        if ($aSubscription === null) {
            Response::error('Invalid subscription', 400);
            return;
        }
        $sStatus = IncidentAlerts::save($aSubscription, IncidentAlerts::favorites($aBody['favorites'] ?? null), Push::lang($aBody['lang'] ?? null));
        $iStatus = 200;
        if ($sStatus !== 'saved') {
            $iStatus = 503;
        }
        Response::json(['status' => $sStatus], $iStatus);
    }

    public function removeIncidents(Request $Req, array $aParams): void
    {
        $sEndpoint = (string)($Req->json()['endpoint'] ?? '');
        if (!WebPush::allowedEndpoint($sEndpoint)) {
            Response::error('Invalid subscription', 400);
            return;
        }
        if (!IncidentAlerts::remove($sEndpoint)) {
            Response::json(['status' => 'unavailable'], 503);
            return;
        }
        Response::json(['status' => 'removed']);
    }

    private static function validId(string $sId): bool
    {
        return $sId !== '' && strlen($sId) <= self::MAX_ID_LENGTH && preg_match('/^[A-Za-z0-9:._-]+$/', $sId) === 1;
    }
}
