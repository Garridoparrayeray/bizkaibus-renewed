<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\ArrivalAlerts;
use Services\WebPush;

// «Avísame cuando llegue»: la clave pública para suscribirse a las notificaciones, crear un aviso y cancelarlo.
// El envío lo hace el cron cada minuto (Services\ArrivalAlerts::process).
class ArrivalAlertsController
{
    private const MAX_ID_LENGTH = 160;
    private const MAX_ENDPOINT_LENGTH = 1000;

    public function key(Request $Req, array $aParams): void
    {
        $aKeys = ArrivalAlerts::vapidKeys();
        if ($aKeys === null) {
            Response::json(['error' => 'not available'], 503);
            return;
        }
        Response::json(['publicKey' => $aKeys['publicKey'], 'minutes' => ArrivalAlerts::MINUTES]);
    }

    public function create(Request $Req, array $aParams): void
    {
        $aBody = $Req->json();
        $aSubscription = self::subscription($aBody['subscription'] ?? null);
        $sStopId = (string)($aBody['stopId'] ?? '');
        $sTripKey = (string)($aBody['tripKey'] ?? '');
        $iMinutes = (int)($aBody['minutes'] ?? 0);
        $sLang = (string)($aBody['lang'] ?? 'es');
        if (!in_array($sLang, ArrivalAlerts::LANGS, true)) {
            $sLang = 'es';
        }
        if ($aSubscription === null || !self::validId($sStopId) || !self::validId($sTripKey) || !in_array($iMinutes, ArrivalAlerts::MINUTES, true)) {
            Response::error('Invalid alert', 400);
            return;
        }
        $aResult = ArrivalAlerts::create($aSubscription, $sStopId, $sTripKey, $iMinutes, $sLang);
        $iStatus = 200;
        if ($aResult['status'] === 'unavailable') {
            $iStatus = 503;
        }
        Response::json($aResult, $iStatus);
    }

    public function cancel(Request $Req, array $aParams): void
    {
        $sEndpoint = (string)($Req->json()['endpoint'] ?? '');
        if (!ArrivalAlerts::cancel($aParams['id'], $sEndpoint)) {
            Response::error('Not found', 404);
            return;
        }
        Response::json(['status' => 'cancelled']);
    }

    // Solo suscripciones de los servicios de push de los navegadores, con sus claves de cifrado bien formadas.
    private static function subscription(mixed $aInput): array|null
    {
        if (!is_array($aInput) || !is_array($aInput['keys'] ?? null)) {
            return null;
        }
        $sEndpoint = (string)($aInput['endpoint'] ?? '');
        $sP256dh = (string)($aInput['keys']['p256dh'] ?? '');
        $sAuth = (string)($aInput['keys']['auth'] ?? '');
        if (strlen($sEndpoint) > self::MAX_ENDPOINT_LENGTH || !WebPush::allowedEndpoint($sEndpoint)) {
            return null;
        }
        $sPublic = WebPush::base64UrlDecode($sP256dh);
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $sP256dh . $sAuth) || strlen($sPublic) !== 65 || $sPublic[0] !== "\x04" || strlen(WebPush::base64UrlDecode($sAuth)) !== 16) {
            return null;
        }
        return ['endpoint' => $sEndpoint, 'keys' => ['p256dh' => $sP256dh, 'auth' => $sAuth]];
    }

    private static function validId(string $sId): bool
    {
        return $sId !== '' && strlen($sId) <= self::MAX_ID_LENGTH && preg_match('/^[A-Za-z0-9:._-]+$/', $sId) === 1;
    }
}
