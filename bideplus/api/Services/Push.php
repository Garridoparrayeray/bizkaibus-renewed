<?php

namespace Services;

use Core\Store;

/*
 * Lo común a todas las notificaciones push de Bide+ (avisos de llegada e incidencias de favoritos): las claves VAPID,
 * la suscripción que manda el navegador y la función que envía. Las claves se crean solas la primera vez y se
 * guardan en el almacén, salvo que vengan en VAPID_PUBLIC_KEY y VAPID_PRIVATE_KEY.
 */
class Push
{
    public const LANGS = ['es', 'eu', 'en'];
    private const VAPID_KEY = 'bide:vapid';
    private const MAX_ENDPOINT_LENGTH = 1000;

    public static function keys(): array|null
    {
        $sPublic = Store::env('VAPID_PUBLIC_KEY');
        $sPrivate = Store::env('VAPID_PRIVATE_KEY');
        if ($sPublic !== null && $sPrivate !== null) {
            return ['publicKey' => $sPublic, 'privateKey' => $sPrivate];
        }
        $aResult = Store::pipeline([['GET', self::VAPID_KEY]]);
        if ($aResult === null) {
            return null;
        }
        if (!is_string($aResult[0])) {
            // Primera vez: se guardan solo si nadie lo ha hecho a la vez (NX), y se usan las que hayan quedado.
            $aResult = Store::pipeline([['SET', self::VAPID_KEY, json_encode(WebPush::generateKeys()), 'NX'], ['GET', self::VAPID_KEY]]);
            if ($aResult === null || !is_string($aResult[1])) {
                return null;
            }
            $aResult = [$aResult[1]];
        }
        $aKeys = json_decode($aResult[0], true);
        if (!is_array($aKeys) || !isset($aKeys['publicKey'], $aKeys['privateKey'])) {
            return null;
        }
        return $aKeys;
    }

    // Solo suscripciones de los servicios de push de los navegadores, con sus claves de cifrado bien formadas.
    public static function subscription(mixed $aInput): array|null
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

    public static function lang(mixed $sLang): string
    {
        if (in_array($sLang, self::LANGS, true)) {
            return $sLang;
        }
        return 'es';
    }

    // La función que envía de verdad: recibe la suscripción y el mensaje y devuelve el código HTTP del servicio de
    // push. Null si no hay claves. En las pruebas se cambia por una falsa.
    public static function sender(int $iTtlSeconds): callable|null
    {
        $aVapid = self::keys();
        if ($aVapid === null) {
            return null;
        }
        $sSubject = 'mailto:' . (require __DIR__ . '/../Config/site.php')['contact_email'];
        return fn(array $aSubscription, string $sPayload) => WebPush::send($aSubscription, $sPayload, $aVapid, $sSubject, $iTtlSeconds);
    }

    public static function json(array $aMessage): string
    {
        return json_encode($aMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
