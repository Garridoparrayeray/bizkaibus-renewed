<?php

namespace Services;

/*
 * Notificaciones push web (las que llegan con la app cerrada), sin librerías: el mensaje va cifrado para el
 * navegador que se suscribió (RFC 8291, «aes128gcm») y firmado con las claves VAPID de Bide+ (RFC 8292), que
 * dicen al servicio de push (Google, Mozilla, Apple…) quién lo manda.
 *
 * Las claves van en formato de las librerías de web push: la pública, el punto P-256 sin comprimir (65 bytes) y la
 * privada, el escalar d (32 bytes), las dos en base64url.
 */
class WebPush
{
    // Solo se manda a los servicios de push de los navegadores: nunca a una dirección cualquiera que llegue en la petición.
    private const PUSH_HOSTS = ['fcm.googleapis.com', 'push.services.mozilla.com', 'notify.windows.com', 'push.apple.com'];
    private const RECORD_SIZE = 4096;
    private const JWT_SECONDS = 12 * 3600;
    // Cabeceras DER de una clave pública P-256 (SubjectPublicKeyInfo) y de una privada (ECPrivateKey, RFC 5915).
    private const P256_PUBLIC_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const P256_PRIVATE_PREFIX = '30770201010420';
    private const P256_PRIVATE_MIDDLE = 'a00a06082a8648ce3d030107a144034200';

    public static function allowedEndpoint(string $sEndpoint): bool
    {
        $aUrl = parse_url($sEndpoint);
        if (($aUrl['scheme'] ?? '') !== 'https' || !isset($aUrl['host']) || isset($aUrl['user']) || isset($aUrl['port'])) {
            return false;
        }
        $sHost = strtolower($aUrl['host']);
        foreach (self::PUSH_HOSTS as $sAllowed) {
            if ($sHost === $sAllowed || str_ends_with($sHost, '.' . $sAllowed)) {
                return true;
            }
        }
        return false;
    }

    public static function generateKeys(): array
    {
        $Key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        [$sPublic, $sPrivate] = self::rawKeyPair($Key);
        return ['publicKey' => self::base64UrlEncode($sPublic), 'privateKey' => self::base64UrlEncode($sPrivate)];
    }

    // Manda un mensaje a una suscripción. Devuelve el código HTTP del servicio de push (201 si llega; 404 o 410 si la
    // suscripción ya no existe) o 0 si no se pudo enviar.
    public static function send(array $aSubscription, string $sPayload, array $aVapid, string $sSubject, int $iTtlSeconds = 600): int
    {
        $sEndpoint = (string)($aSubscription['endpoint'] ?? '');
        if (!self::allowedEndpoint($sEndpoint)) {
            return 0;
        }
        $sBody = self::encrypt($sPayload, (string)$aSubscription['keys']['p256dh'], (string)$aSubscription['keys']['auth']);
        $sJwt = self::vapidToken($sEndpoint, $aVapid, $sSubject);

        $Ch = curl_init($sEndpoint);
        curl_setopt_array($Ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: ' . $iTtlSeconds,
                'Urgency: high',
                'Authorization: vapid t=' . $sJwt . ', k=' . $aVapid['publicKey'],
            ],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        curl_exec($Ch);
        $iStatus = (int)curl_getinfo($Ch, CURLINFO_HTTP_CODE);
        curl_close($Ch);
        return $iStatus;
    }

    // Cifrado aes128gcm (RFC 8291). Las claves efímeras y la sal se pueden dar para comprobarlo con el vector del RFC.
    public static function encrypt(string $sPayload, string $sUaPublicB64, string $sAuthB64, array|null $aEphemeral = null, string|null $sSalt = null): string
    {
        $sUaPublic = self::base64UrlDecode($sUaPublicB64);
        $sAuth = self::base64UrlDecode($sAuthB64);
        if ($aEphemeral === null) {
            $aEphemeral = self::generateKeys();
        }
        if ($sSalt === null) {
            $sSalt = random_bytes(16);
        }
        $sAsPublic = self::base64UrlDecode($aEphemeral['publicKey']);
        $AsPrivate = self::privateKey($aEphemeral);
        $UaPublic = openssl_pkey_get_public(self::pem('PUBLIC KEY', hex2bin(self::P256_PUBLIC_PREFIX) . $sUaPublic));
        $sSecret = openssl_pkey_derive($UaPublic, $AsPrivate, 32);

        $sPrkKey = hash_hmac('sha256', $sSecret, $sAuth, true);
        $sIkm = hash_hmac('sha256', "WebPush: info\0" . $sUaPublic . $sAsPublic . "\x01", $sPrkKey, true);
        $sPrk = hash_hmac('sha256', $sIkm, $sSalt, true);
        $sCek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $sPrk, true), 0, 16);
        $sNonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $sPrk, true), 0, 12);

        // Un solo registro: el mensaje, el delimitador de último registro (0x02) y la etiqueta de GCM.
        $sTag = '';
        $sCipher = openssl_encrypt($sPayload . "\x02", 'aes-128-gcm', $sCek, OPENSSL_RAW_DATA, $sNonce, $sTag);
        return $sSalt . pack('N', self::RECORD_SIZE) . chr(strlen($sAsPublic)) . $sAsPublic . $sCipher . $sTag;
    }

    // JWT ES256 para el servicio de push: a quién va (su origen), hasta cuándo vale y quién lo manda.
    public static function vapidToken(string $sEndpoint, array $aVapid, string $sSubject): string
    {
        $aUrl = parse_url($sEndpoint);
        $sHeader = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $sClaims = self::base64UrlEncode(json_encode([
            'aud' => $aUrl['scheme'] . '://' . $aUrl['host'],
            'exp' => time() + self::JWT_SECONDS,
            'sub' => $sSubject,
        ], JSON_UNESCAPED_SLASHES));
        $sInput = $sHeader . '.' . $sClaims;
        $sDer = '';
        openssl_sign($sInput, $sDer, self::privateKey($aVapid), OPENSSL_ALGO_SHA256);
        return $sInput . '.' . self::base64UrlEncode(self::derToRaw($sDer));
    }

    public static function base64UrlEncode(string $sData): string
    {
        return rtrim(strtr(base64_encode($sData), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $sData): string
    {
        return (string)base64_decode(strtr($sData, '-_', '+/') . str_repeat('=', (4 - strlen($sData) % 4) % 4));
    }

    private static function rawKeyPair(\OpenSSLAsymmetricKey $Key): array
    {
        $aEc = openssl_pkey_get_details($Key)['ec'];
        $sPublic = "\x04" . str_pad($aEc['x'], 32, "\0", STR_PAD_LEFT) . str_pad($aEc['y'], 32, "\0", STR_PAD_LEFT);
        return [$sPublic, str_pad($aEc['d'], 32, "\0", STR_PAD_LEFT)];
    }

    private static function privateKey(array $aKeys): \OpenSSLAsymmetricKey
    {
        $sDer = hex2bin(self::P256_PRIVATE_PREFIX) . self::base64UrlDecode($aKeys['privateKey'])
            . hex2bin(self::P256_PRIVATE_MIDDLE) . self::base64UrlDecode($aKeys['publicKey']);
        return openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $sDer));
    }

    private static function pem(string $sLabel, string $sDer): string
    {
        return "-----BEGIN $sLabel-----\n" . chunk_split(base64_encode($sDer), 64, "\n") . "-----END $sLabel-----\n";
    }

    // La firma ECDSA de OpenSSL viene en DER (SEQUENCE de r y s); JWT la quiere como r || s, 32 bytes cada uno.
    private static function derToRaw(string $sDer): string
    {
        $iOffset = 2;
        if (ord($sDer[1]) & 0x80) {
            $iOffset += ord($sDer[1]) & 0x7f;
        }
        $iRLength = ord($sDer[$iOffset + 1]);
        $sR = substr($sDer, $iOffset + 2, $iRLength);
        $iOffset += 2 + $iRLength;
        $iSLength = ord($sDer[$iOffset + 1]);
        $sS = substr($sDer, $iOffset + 2, $iSLength);
        return str_pad(ltrim($sR, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($sS, "\0"), 32, "\0", STR_PAD_LEFT);
    }
}
