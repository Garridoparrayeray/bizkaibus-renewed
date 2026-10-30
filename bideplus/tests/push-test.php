<?php
// Notificaciones push contra un Upstash falso (tests/fixtures/fake-upstash.php) y un servicio de push falso: cifrado
// con el vector del RFC 8291, claves VAPID y direcciones permitidas; «Avísame cuando llegue» (crear, enviar una sola
// vez, reintentar, olvidar suscripciones caducadas y cancelar) y avisos de incidencias de los favoritos (línea base,
// solo lo nuevo, resumen, reintento, cada 5 minutos y darse de baja). Uso: php tests/push-test.php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');
require __DIR__ . '/../api/autoload.php';

use Controllers\StopsController;
use Core\Config;
use Core\Store;
use Services\ArrivalAlerts;
use Services\IncidentAlerts;
use Services\Push;
use Services\WebPush;

$aFailures = [];
$expect = function (string $sName, bool $bOk, string $sDetail = '') use (&$aFailures): void {
    $sLine = 'OK  ' . $sName;
    if (!$bOk) {
        $sLine = 'MAL ' . $sName;
        if ($sDetail !== '') {
            $sLine .= '  [' . $sDetail . ']';
        }
        $aFailures[] = $sName;
    }
    echo $sLine . "\n";
};

// ===== Cifrado y firma =====

$sBody = WebPush::encrypt(
    'When I grow up, I want to be a watermelon',
    'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
    'BTBZMqHH6r4Tts7J_aSIgg',
    ['publicKey' => 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8', 'privateKey' => 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'],
    WebPush::base64UrlDecode('DGv6ra1nlYgDCS1FRnbzlw')
);
$expect('cifrado: igual que el vector del RFC 8291', WebPush::base64UrlEncode($sBody) === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');

$aKeys = WebPush::generateKeys();
$sJwt = WebPush::vapidToken('https://fcm.googleapis.com/fcm/send/abc', $aKeys, 'mailto:prueba@example.com');
[$sHeader, $sClaims, $sSignature] = explode('.', $sJwt);
$sRaw = WebPush::base64UrlDecode($sSignature);
// La firma r || s se vuelve a DER para que OpenSSL la verifique con la clave pública.
$derInteger = function (string $sValue): string {
    $sValue = ltrim($sValue, "\0");
    if (ord($sValue[0]) & 0x80) {
        $sValue = "\0" . $sValue;
    }
    return "\x02" . chr(strlen($sValue)) . $sValue;
};
$sSequence = $derInteger(substr($sRaw, 0, 32)) . $derInteger(substr($sRaw, 32));
$sDer = "\x30" . chr(strlen($sSequence)) . $sSequence;
$sPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . WebPush::base64UrlDecode($aKeys['publicKey'])), 64, "\n") . "-----END PUBLIC KEY-----\n";
$expect('firma VAPID: la verifica la clave pública', openssl_verify($sHeader . '.' . $sClaims, $sDer, $sPem, OPENSSL_ALGO_SHA256) === 1);
$aClaims = json_decode(WebPush::base64UrlDecode($sClaims), true);
$expect('firma VAPID: va al origen del servicio de push', $aClaims['aud'] === 'https://fcm.googleapis.com' && $aClaims['exp'] > time());

foreach ([
    ['https://fcm.googleapis.com/fcm/send/x', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/x', true],
    ['https://web.push.apple.com/x', true],
    ['https://wns2-db5p.notify.windows.com/w/?token=x', true],
    ['http://fcm.googleapis.com/fcm/send/x', false],
    ['https://fcm.googleapis.com.evil.example/x', false],
    ['https://127.0.0.1/x', false],
    ['https://user@fcm.googleapis.com/x', false],
    ['https://fcm.googleapis.com:8443/x', false],
] as [$sUrl, $bAllowed]) {
    $expect('direcciones de push: ' . $sUrl, WebPush::allowedEndpoint($sUrl) === $bAllowed);
}

// ===== Avisos contra el almacén falso =====

$sDataFile = tempnam(sys_get_temp_dir(), 'fake-upstash');
$iPort = 19000 + random_int(0, 999);
$sNull = '/dev/null';
if (PHP_OS_FAMILY === 'Windows') {
    $sNull = 'NUL';
}
$Server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $iPort, __DIR__ . '/fixtures/fake-upstash.php'],
    [1 => ['file', $sNull, 'w'], 2 => ['file', $sNull, 'w']],
    $aPipes,
    null,
    array_filter(array_merge($_SERVER, $_ENV), 'is_string') + ['FAKE_UPSTASH_FILE' => $sDataFile]
);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $iPort) === false; $i++) {
    usleep(100000);
}
@unlink(sys_get_temp_dir() . '/bizkaibusplus_store.fail');

try {
    putenv('VAPID_PUBLIC_KEY');
    putenv('VAPID_PRIVATE_KEY');
    putenv('UPSTASH_REDIS_REST_URL=http://127.0.0.1:' . $iPort . '/');
    putenv('UPSTASH_REDIS_REST_TOKEN=token-de-prueba');

    $aVapid = Push::keys();
    $expect('claves VAPID: se crean la primera vez', is_array($aVapid) && strlen(WebPush::base64UrlDecode($aVapid['publicKey'])) === 65);
    $expect('claves VAPID: después se usan las mismas', Push::keys() === $aVapid);

    // Un viaje de verdad de La Casilla al que le falten más de 10 minutos.
    Config::set('bus');
    $aData = (new StopsController())->departuresData('356', 40);
    $aTrip = null;
    foreach ($aData['departures'] ?? [] as $aDeparture) {
        if ($aDeparture['etaAt'] - time() > 15 * 60 && !in_array($aDeparture['status'], ['departed', 'likely_departed'], true)) {
            $aTrip = $aDeparture;
            break;
        }
    }
    $expect('hay un viaje de prueba en La Casilla', $aTrip !== null);

    $aSubscription = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/prueba', 'keys' => ['p256dh' => WebPush::generateKeys()['publicKey'], 'auth' => WebPush::base64UrlEncode(random_bytes(16))]];
    $aSent = [];
    $iAnswer = 201;
    $Sender = function (array $aTo, string $sPayload) use (&$aSent, &$iAnswer): int {
        $aSent[] = json_decode($sPayload, true);
        return $iAnswer;
    };
    $pending = fn() => Store::pipeline([['SMEMBERS', 'bide:arrival:pending']])[0];
    $alertNow = function (string $sId): void {
        // Que le toque ya: se guarda como si pidiera el aviso con un día de antelación.
        $aAlert = json_decode(Store::pipeline([['GET', 'bide:arrival:' . $sId]])[0], true);
        $aAlert['minutes'] = 1440;
        Store::pipeline([['SET', 'bide:arrival:' . $sId, json_encode($aAlert)]]);
    };

    if ($aTrip !== null) {
        $aCreated = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'en');
        $expect('crear: queda guardado y pendiente', $aCreated['status'] === 'created' && in_array($aCreated['id'], $pending(), true), json_encode($aCreated));
        $expect('crear: si ya falta menos, no hace falta aviso', ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 1440, 'es')['status'] === 'now');
        $expect('crear: un viaje que no pasa por la parada no vale', ArrivalAlerts::create($aSubscription, '356', 'no-existe-1-1', 10, 'es')['status'] === 'unknown');

        $aCounts = ArrivalAlerts::process($Sender);
        $expect('enviar: si aún falta, no se manda', $aCounts['sent'] === 0 && empty($aSent) && in_array($aCreated['id'], $pending(), true));

        $alertNow($aCreated['id']);
        $aCounts = ArrivalAlerts::process($Sender);
        $expect('enviar: cuando toca, se manda una vez', $aCounts['sent'] === 1 && count($aSent) === 1);
        $expect('enviar: en el idioma pedido y con la línea', str_contains($aSent[0]['title'] ?? '', $aTrip['lineCode']) && (str_contains($aSent[0]['title'], 'arrives in') || str_contains($aSent[0]['title'], 'is arriving')), json_encode($aSent[0] ?? null));
        $expect('enviar: al tocarla se abre la parada con el viaje', ($aSent[0]['url'] ?? '') === '/stops/356?viaje=' . rawurlencode($aTrip['tripKey']));
        $expect('enviar: enviado, ya no queda pendiente', !in_array($aCreated['id'], $pending(), true));
        ArrivalAlerts::process($Sender);
        $expect('enviar: no se repite', count($aSent) === 1);

        $aRetry = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es');
        $alertNow($aRetry['id']);
        $iAnswer = 500;
        ArrivalAlerts::process($Sender);
        $expect('reintento: si el servicio de push falla, vuelve a la lista', in_array($aRetry['id'], $pending(), true));
        $iAnswer = 410;
        ArrivalAlerts::process($Sender);
        $expect('caducada: si la suscripción ya no existe, se olvida', !in_array($aRetry['id'], $pending(), true) && Store::pipeline([['GET', 'bide:arrival:' . $aRetry['id']]])[0] === null);

        $aCancel = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es');
        $expect('cancelar: con otra suscripción no se puede', ArrivalAlerts::cancel($aCancel['id'], 'https://fcm.googleapis.com/fcm/send/otra') === false);
        $expect('cancelar: con la suya sí', ArrivalAlerts::cancel($aCancel['id'], $aSubscription['endpoint']) === true && !in_array($aCancel['id'], $pending(), true));
    }

    Store::pipeline([['SADD', 'bide:arrival:pending', 'aaaaaaaaaaaaaaaaaaaaaaaa']]);
    ArrivalAlerts::process($Sender);
    $expect('caducado: un aviso que ya no está en el almacén sale de la lista', !in_array('aaaaaaaaaaaaaaaaaaaaaaaa', $pending(), true));

    $aMessage = ArrivalAlerts::message(['id' => 'x', 'network' => 'metro', 'stopId' => '7', 'stopName' => 'Abando', 'tripKey' => 'MB-1-2', 'lineCode' => 'MB', 'headsign' => 'Basauri', 'lang' => 'eu'], 3);
    $expect('mensaje: en euskera y con la red en el enlace', $aMessage['title'] === 'MB: 3 min barru' && $aMessage['url'] === '/stops/7?red=metro&viaje=MB-1-2' && $aMessage['body'] === '→ Basauri · Abando', json_encode($aMessage, JSON_UNESCAPED_UNICODE));

    // ===== Avisos de incidencias de los favoritos =====

    $expect('incidencias: la clave es la misma que en la app (js/alerts-store.js)', IncidentAlerts::key('bus', 'Obras en Abando', 'Desvío por la calle Bidebarrieta 🚧 hasta el día 3.') === 'bus:zvsnys');
    $aClean = IncidentAlerts::favorites(['bus' => ['lines' => ['3513', 3514, '3513', '<script>'], 'stops' => []], 'metro' => ['lines' => [], 'stops' => []], 'inventada' => ['lines' => ['A']]]);
    $expect('incidencias: favoritos limpios (redes que existen, ids válidos, sin repetir)', $aClean === ['bus' => ['lines' => ['3513', '3514'], 'stops' => []]], json_encode($aClean));

    $aFeeds = [
        'bus|3513' => [['summary' => 'Obras', 'description' => 'Desvío en Gernika A']],
        'bus|3514' => [],
        'metro|*' => [['title' => 'Ascensor', 'description' => 'Fuera de servicio en Abando']],
        'renfe|stop:13400' => [],
    ];
    $aAsked = [];
    $Fetcher = function (string $sNetwork, string|null $sLine, string|null $sStop) use (&$aFeeds, &$aAsked): array|null {
        $sKey = $sNetwork . '|' . ($sLine ?? ($sStop !== null ? 'stop:' . $sStop : '*'));
        $aAsked[] = $sKey;
        if (($aFeeds[$sKey] ?? []) === 'falla') {
            throw new RuntimeException('fuente caída');
        }
        return $aFeeds[$sKey] ?? [];
    };
    $aSent = [];
    $iAnswer = 201;
    $run = function () use (&$Sender, &$Fetcher): array {
        // La marca de «ya se revisó hace menos de 5 minutos» se quita para poder probar varias vueltas seguidas.
        Store::pipeline([['DEL', 'bide:incident:run']]);
        return IncidentAlerts::process($Sender, $Fetcher);
    };
    $aSubscriber = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/incidencias', 'keys' => $aSubscription['keys']];
    $expect('incidencias: se guarda el suscriptor', IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514'], 'stops' => []], 'metro' => ['lines' => ['MB'], 'stops' => []], 'renfe' => ['lines' => [], 'stops' => ['13400']]], 'eu') === 'saved');

    $aCounts = $run();
    $expect('incidencias: la primera vuelta solo anota lo que ya hay', $aCounts['subscribers'] === 1 && $aCounts['sent'] === 0 && empty($aSent), json_encode($aCounts));
    sort($aAsked);
    $expect('incidencias: mira cada línea en Bizkaibus, toda la red en Metro y la estación en Renfe', $aAsked === ['bus|3513', 'bus|3514', 'metro|*', 'renfe|stop:13400'], json_encode($aAsked));
    $expect('incidencias: dentro de 5 minutos no se vuelve a mirar', IncidentAlerts::process($Sender, $Fetcher) === ['skipped' => true]);

    $aFeeds['bus|3514'] = [['summary' => 'Huelga', 'description' => '  Servicios mínimos el día 5  ']];
    $run();
    $expect('incidencias: una nueva se manda una vez', count($aSent) === 1);
    $expect('incidencias: con la red en el título, en su idioma y con la app al tocarla', ($aSent[0]['title'] ?? '') === 'Bizkaibus+ · Huelga' && $aSent[0]['body'] === 'Servicios mínimos el día 5' && $aSent[0]['url'] === '/?red=bus' && $aSent[0]['tag'] === IncidentAlerts::key('bus', 'Huelga', 'Servicios mínimos el día 5') && $aSent[0]['icon'] === '/icons-pro/icon-192.png', json_encode($aSent[0] ?? null, JSON_UNESCAPED_UNICODE));
    $run();
    $expect('incidencias: la misma no se repite', count($aSent) === 1);

    $aFeeds['metro|*'][] = ['title' => 'Obras L2', 'description' => 'Sin servicio entre Urbinaga y Kabiezes'];
    $iAnswer = 500;
    $run();
    $expect('incidencias: si el servicio de push falla, no se da por avisada', count($aSent) === 2);
    $iAnswer = 201;
    $run();
    $expect('incidencias: y se manda en la vuelta siguiente', count($aSent) === 3 && $aSent[2]['title'] === 'Metro+ · Obras L2', json_encode(end($aSent), JSON_UNESCAPED_UNICODE));

    $aFeeds['bus|3513'] = 'falla';
    $run();
    $aFeeds['bus|3513'] = [['summary' => 'Obras', 'description' => 'Desvío en Gernika A']];
    $run();
    $expect('incidencias: una fuente caída no hace que lo de antes parezca nuevo', count($aSent) === 3);

    for ($i = 1; $i <= 5; $i++) {
        $aFeeds['bus|3513'][] = ['summary' => 'Aviso ' . $i, 'description' => 'Texto ' . $i];
    }
    $iBefore = count($aSent);
    $run();
    $aBatch = array_slice($aSent, $iBefore);
    $expect('incidencias: muchas a la vez, tres sueltas y un resumen en su idioma', count($aBatch) === 4 && $aBatch[3]['title'] === 'Intzidentzia gehiago zure gogokoetan' && $aBatch[3]['body'] === 'Beste 2 abisu. Ireki aplikazioan.', json_encode($aBatch, JSON_UNESCAPED_UNICODE));
    $run();
    $expect('incidencias: el resumen también cuenta como avisado', count($aSent) === $iBefore + 4);

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514', '3517'], 'stops' => []]], 'es');
    $aFeeds['bus|3517'] = [['summary' => 'Parada trasladada', 'description' => 'En Plentzia']];
    $run();
    $expect('incidencias: un favorito nuevo empieza también sin avisar de lo que ya había', count($aSent) === $iBefore + 4);

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514'], 'stops' => []]], 'es');
    $run();
    $aFeeds['bus|3517'][] = ['summary' => 'Cambio de horario', 'description' => 'Desde el lunes'];
    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514', '3517'], 'stops' => []]], 'es');
    $run();
    $expect('incidencias: un favorito que se quita y se vuelve a poner tampoco avisa de lo que ya había', count($aSent) === $iBefore + 4);

    $aFeeds['bus|3517'][] = ['summary' => 'Desvío', 'description' => 'Por fiestas'];
    $iAnswer = 410;
    $run();
    $aLeft = Store::pipeline([['SMEMBERS', 'bide:incident:subs']])[0];
    $expect('incidencias: si la suscripción ya no existe, se borra', $aLeft === [], json_encode($aLeft));
    $iAnswer = 201;

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513'], 'stops' => []]], 'es');
    $expect('incidencias: darse de baja', IncidentAlerts::remove($aSubscriber['endpoint']) === true && Store::pipeline([['SMEMBERS', 'bide:incident:subs']])[0] === []);
} finally {
    proc_terminate($Server);
    @unlink($sDataFile);
}

echo "\nRESULTADO notificaciones push: " . (empty($aFailures) ? 'OK' : 'FALLA -> ' . implode('; ', $aFailures)) . "\n";
exit(empty($aFailures) ? 0 : 1);
