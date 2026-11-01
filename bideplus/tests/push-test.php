<?php
// Notificaciones push contra un Upstash y un servicio de push falsos (tests/fixtures/fake-upstash.php).
// Uso: php tests/push-test.php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');
require __DIR__ . '/../api/autoload.php';

use Controllers\StopsController;
use Core\Config;
use Core\Store;
use Services\ArrivalAlerts;
use Services\Changelog;
use Services\IncidentAlerts;
use Services\UpdateAlerts;
use Services\Push;
use Services\WebPush;

$aFailures = [];
$Expect = function (string $sName, bool $bOk, string $sDetail = '') use (&$aFailures): void {
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
$Expect('cifrado: igual que el vector del RFC 8291', WebPush::base64UrlEncode($sBody) === 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');

$aKeys = WebPush::generateKeys();
$sJwt = WebPush::vapidToken('https://fcm.googleapis.com/fcm/send/abc', $aKeys, 'mailto:prueba@example.com');
[$sHeader, $sClaims, $sSignature] = explode('.', $sJwt);
$sRaw = WebPush::base64UrlDecode($sSignature);
// La firma r || s se vuelve a DER para que OpenSSL la verifique con la clave pública.
$DerInteger = function (string $sValue): string {
    $sValue = ltrim($sValue, "\0");
    if (ord($sValue[0]) & 0x80) {
        $sValue = "\0" . $sValue;
    }
    return "\x02" . chr(strlen($sValue)) . $sValue;
};
$sSequence = $DerInteger(substr($sRaw, 0, 32)) . $DerInteger(substr($sRaw, 32));
$sDer = "\x30" . chr(strlen($sSequence)) . $sSequence;
$sPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . WebPush::base64UrlDecode($aKeys['publicKey'])), 64, "\n") . "-----END PUBLIC KEY-----\n";
$Expect('firma VAPID: la verifica la clave pública', openssl_verify($sHeader . '.' . $sClaims, $sDer, $sPem, OPENSSL_ALGO_SHA256) === 1);
$aClaims = json_decode(WebPush::base64UrlDecode($sClaims), true);
$Expect('firma VAPID: va al origen del servicio de push', $aClaims['aud'] === 'https://fcm.googleapis.com' && $aClaims['exp'] > time());

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
    $Expect('direcciones de push: ' . $sUrl, WebPush::allowedEndpoint($sUrl) === $bAllowed);
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
    $Expect('claves VAPID: se crean la primera vez', is_array($aVapid) && strlen(WebPush::base64UrlDecode($aVapid['publicKey'])) === 65);
    $Expect('claves VAPID: después se usan las mismas', Push::keys() === $aVapid);

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
    $Expect('hay un viaje de prueba en La Casilla', $aTrip !== null);

    $aSubscription = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/prueba', 'keys' => ['p256dh' => WebPush::generateKeys()['publicKey'], 'auth' => WebPush::base64UrlEncode(random_bytes(16))]];
    $aSent = [];
    $iAnswer = 201;
    $Sender = function (array $aTo, string $sPayload) use (&$aSent, &$iAnswer): int {
        $aSent[] = json_decode($sPayload, true);
        return $iAnswer;
    };
    $Pending = fn() => Store::pipeline([['SMEMBERS', 'bide:arrival:pending']])[0];
    $AlertNow = function (string $sId): void {
        // Que le toque ya: se guarda como si pidiera el aviso con un día de antelación.
        $aAlert = json_decode(Store::pipeline([['GET', 'bide:arrival:' . $sId]])[0], true);
        $aAlert['minutes'] = 1440;
        Store::pipeline([['SET', 'bide:arrival:' . $sId, json_encode($aAlert)]]);
    };

    if ($aTrip !== null) {
        $aCreated = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'en');
        $Expect('crear: queda guardado y pendiente', $aCreated['status'] === 'created' && in_array($aCreated['id'], $Pending(), true), json_encode($aCreated));
        $Expect('crear: si ya falta menos, no hace falta aviso', ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 1440, 'es')['status'] === 'now');
        $Expect('crear: un viaje que no pasa por la parada no vale', ArrivalAlerts::create($aSubscription, '356', 'no-existe-1-1', 10, 'es')['status'] === 'unknown');

        $aCounts = ArrivalAlerts::process($Sender);
        $Expect('enviar: si aún falta, no se manda', $aCounts['sent'] === 0 && empty($aSent) && in_array($aCreated['id'], $Pending(), true));

        $AlertNow($aCreated['id']);
        $aCounts = ArrivalAlerts::process($Sender);
        $Expect('enviar: cuando toca, se manda una vez', $aCounts['sent'] === 1 && count($aSent) === 1);
        $Expect('enviar: en el idioma pedido y con la línea', str_contains($aSent[0]['title'] ?? '', $aTrip['lineCode']) && (str_contains($aSent[0]['title'], 'arrives in') || str_contains($aSent[0]['title'], 'is arriving')), json_encode($aSent[0] ?? null));
        $Expect('enviar: al tocarla se abre la parada con el viaje', str_starts_with($aSent[0]['url'] ?? '', '/stops/356?viaje=' . rawurlencode($aTrip['tripKey']) . '&aviso=' . $aCreated['id'] . '-'), $aSent[0]['url'] ?? '');
        $Expect('enviar: enviado, ya no queda pendiente', !in_array($aCreated['id'], $Pending(), true));
        ArrivalAlerts::process($Sender);
        $Expect('enviar: no se repite', count($aSent) === 1);

        $aRetry = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es');
        $AlertNow($aRetry['id']);
        $iAnswer = 500;
        ArrivalAlerts::process($Sender);
        $Expect('reintento: si el servicio de push falla, vuelve a la lista', in_array($aRetry['id'], $Pending(), true));
        $iAnswer = 410;
        ArrivalAlerts::process($Sender);
        $Expect('caducada: si la suscripción ya no existe, se olvida', !in_array($aRetry['id'], $Pending(), true) && Store::pipeline([['GET', 'bide:arrival:' . $aRetry['id']]])[0] === null);

        $aCancel = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es');
        $Expect('cancelar: con otra suscripción no se puede', ArrivalAlerts::cancel($aCancel['id'], 'https://fcm.googleapis.com/fcm/send/otra') === false);
        $Expect('cancelar: con la suya sí', ArrivalAlerts::cancel($aCancel['id'], $aSubscription['endpoint']) === true && !in_array($aCancel['id'], $Pending(), true));

        // «Avisarme todos los días»: avisa, se queda y ese día no se repite; al día siguiente vuelve a avisar.
        $Patch = function (string $sId, array $aChanges): void {
            $aAlert = json_decode(Store::pipeline([['GET', 'bide:arrival:' . $sId]])[0], true);
            Store::pipeline([['SET', 'bide:arrival:' . $sId, json_encode($aChanges + $aAlert)]]);
        };
        $Stored = fn(string $sKey) => Store::pipeline([['GET', $sKey]])[0];
        $iAnswer = 201;
        $aDaily = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es', true);
        $Expect('todos los días: se crea', $aDaily['status'] === 'created' && $aDaily['repeat'] === true && $aDaily['today'] === 'coming', json_encode($aDaily));
        $AlertNow($aDaily['id']);
        $iBefore = count($aSent);
        ArrivalAlerts::process($Sender);
        $aLast = end($aSent);
        $Expect('todos los días: cuando toca, avisa', count($aSent) === $iBefore + 1 && ($aLast['repeat'] ?? null) === true && ($aLast['alertId'] ?? '') === $aDaily['id'], json_encode($aLast));
        $Expect('todos los días: tras avisar sigue guardado', in_array($aDaily['id'], $Pending(), true) && $Stored('bide:arrival:' . $aDaily['id']) !== null);
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: ese día no se repite', count($aSent) === $iBefore + 1);
        // Pasa el día: la marca de enviado caduca.
        Store::pipeline([['DEL', 'bide:arrival:sent:' . $aDaily['id']]]);
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: al día siguiente vuelve a avisar', count($aSent) === $iBefore + 2);
        Store::pipeline([['DEL', 'bide:arrival:sent:' . $aDaily['id']]]);
        $iAnswer = 500;
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: si el servicio de push falla, se reintenta al minuto siguiente', $Stored('bide:arrival:sent:' . $aDaily['id']) === null && in_array($aDaily['id'], $Pending(), true));
        $iAnswer = 410;
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: si la suscripción ya no existe, se olvida', !in_array($aDaily['id'], $Pending(), true) && $Stored('bide:arrival:' . $aDaily['id']) === null);
        $iAnswer = 201;

        // Lejos de su hora en el horario ni se mira (aunque, si se mirara, ya tocaría avisar).
        $aFar = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 10, 'es', true);
        $AlertNow($aFar['id']);
        $Patch($aFar['id'], ['scheduledSeconds' => (\Services\Calendar::nowSecondsSinceMidnight() + 86400 - 3 * 3600) % 86400]);
        $iBefore = count($aSent);
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: lejos de su hora en el horario no se mira', count($aSent) === $iBefore && in_array($aFar['id'], $Pending(), true));

        // Pedido cuando hoy ya está llegando: se crea igual y hoy no avisa (la ficha ya lo enseña).
        $aLate = ArrivalAlerts::create($aSubscription, '356', $aTrip['tripKey'], 1440, 'es', true);
        $AlertNow($aLate['id']);
        ArrivalAlerts::process($Sender);
        $Expect('todos los días: si hoy ya está llegando, se crea y hoy no avisa', $aLate['status'] === 'created' && $aLate['today'] === 'now' && count($aSent) === $iBefore, json_encode($aLate));
        $Expect('todos los días: al cancelar se borra todo', ArrivalAlerts::cancel($aLate['id'], $aSubscription['endpoint']) === true && $Stored('bide:arrival:' . $aLate['id']) === null && $Stored('bide:arrival:sent:' . $aLate['id']) === null && !in_array($aLate['id'], $Pending(), true));
        ArrivalAlerts::cancel($aFar['id'], $aSubscription['endpoint']);
    }

    // En todas las apps, no solo en Bizkaibus: un viaje de cada red se avisa y su enlace lleva la red. Una red sin
    // viajes a esta hora (de noche) se salta.
    $aSampleStops = [
        'metro' => ['7'],
        'euskotren' => ['ES:Euskotren:StopPlace:2581:', 'ES:Euskotren:StopPlace:2590:'],
        'tranvia-bilbao' => ['ES:Euskotren:StopPlace:1471:', 'ES:Euskotren:StopPlace:1475:'],
        'tranvia-vitoria' => ['ES:Euskotren:StopPlace:1560:', 'ES:Euskotren:StopPlace:1568:'],
        'renfe' => ['13200'],
        'lurraldebus' => ['1700', '1704', '1701'],
    ];
    foreach ($aSampleStops as $sNetwork => $aStopIds) {
        Config::set($sNetwork);
        $aSample = null;
        foreach ($aStopIds as $sStopId) {
            foreach ((new StopsController())->departuresData($sStopId, 40)['departures'] ?? [] as $aDeparture) {
                if ($aDeparture['etaAt'] - time() > 15 * 60 && !in_array($aDeparture['status'], ['departed', 'likely_departed'], true)) {
                    $aSample = [$sStopId, $aDeparture];
                    break 2;
                }
            }
        }
        if ($aSample === null) {
            echo 'SKIP todas las apps: ' . $sNetwork . " no tiene viajes ahora\n";
            continue;
        }
        [$sStopId, $aDeparture] = $aSample;
        $aCreated = ArrivalAlerts::create($aSubscription, $sStopId, $aDeparture['tripKey'], 10, 'es', true);
        $AlertNow($aCreated['id'] ?? 'x');
        $iBefore = count($aSent);
        ArrivalAlerts::process($Sender);
        $aLast = end($aSent);
        $Expect('todas las apps: ' . $sNetwork . ' avisa y abre su parada en su app', $aCreated['status'] === 'created' && count($aSent) === $iBefore + 1 && str_contains($aLast['url'] ?? '', 'red=' . $sNetwork), json_encode($aCreated) . ' ' . ($aLast['url'] ?? ''));
        ArrivalAlerts::cancel($aCreated['id'] ?? 'x', $aSubscription['endpoint']);
    }
    Config::set('bus');

    Store::pipeline([['SADD', 'bide:arrival:pending', 'aaaaaaaaaaaaaaaaaaaaaaaa']]);
    ArrivalAlerts::process($Sender);
    $Expect('caducado: un aviso que ya no está en el almacén sale de la lista', !in_array('aaaaaaaaaaaaaaaaaaaaaaaa', $Pending(), true));

    $aMessage = ArrivalAlerts::message(['id' => 'x', 'network' => 'metro', 'stopId' => '7', 'stopName' => 'Abando', 'tripKey' => 'MB-1-2', 'lineCode' => 'MB', 'headsign' => 'Basauri', 'lang' => 'eu'], 3);
    $Expect('mensaje: en euskera y con la red en el enlace', $aMessage['title'] === 'MB: 3 min barru' && str_starts_with($aMessage['url'], '/stops/7?red=metro&viaje=MB-1-2&aviso=x-') && $aMessage['body'] === '→ Basauri · Abando', json_encode($aMessage, JSON_UNESCAPED_UNICODE));
    $Expect('mensaje: lleva el id y si es de una vez, para que la app lo quite de su lista', $aMessage['alertId'] === 'x' && $aMessage['repeat'] === false);

    // ===== Avisos de incidencias de los favoritos =====

    $Expect('incidencias: la clave es la misma que en la app (js/alerts-store.js)', IncidentAlerts::key('bus', 'Obras en Abando', 'Desvío por la calle Bidebarrieta 🚧 hasta el día 3.') === 'bus:zvsnys');
    $aClean = IncidentAlerts::favorites(['bus' => ['lines' => ['3513', 3514, '3513', '<script>'], 'stops' => []], 'metro' => ['lines' => [], 'stops' => []], 'inventada' => ['lines' => ['A']]]);
    $Expect('incidencias: favoritos limpios (redes que existen, ids válidos, sin repetir)', $aClean === ['bus' => ['lines' => ['3513', '3514'], 'stops' => []]], json_encode($aClean));
    $aMany = IncidentAlerts::favorites(['bus' => ['lines' => range(1, 100), 'stops' => range(1, 100)], 'metro' => ['lines' => [], 'stops' => range(1, 100)], 'renfe' => ['lines' => [str_repeat('1', 65)], 'stops' => []]]);
    $iTotal = array_sum(array_map(fn($aEntry) => count($aEntry['lines']) + count($aEntry['stops']), $aMany));
    $Expect('incidencias: como mucho 150 favoritos y sin ids larguísimos', $iTotal === 150 && !isset($aMany['renfe']), (string)$iTotal);

    $aFeeds = [
        'bus|3513' => [['summary' => 'Obras', 'description' => 'Desvío en Gernika A']],
        'bus|3514' => [],
        'metro|*' => [['title' => 'Ascensor', 'description' => 'Fuera de servicio en Abando']],
        'renfe|stop:13400' => [],
    ];
    $aAsked = [];
    $Fetcher = function (string $sNetwork, string|null $sLine, string|null $sStop) use (&$aFeeds, &$aAsked): array|null {
        $sSource = '*';
        if ($sStop !== null) {
            $sSource = 'stop:' . $sStop;
        }
        if ($sLine !== null) {
            $sSource = $sLine;
        }
        $sKey = $sNetwork . '|' . $sSource;
        $aAsked[] = $sKey;
        if (($aFeeds[$sKey] ?? []) === 'falla') {
            throw new RuntimeException('fuente caída');
        }
        return $aFeeds[$sKey] ?? [];
    };
    $aSent = [];
    $iAnswer = 201;
    $Run = function () use (&$Sender, &$Fetcher): array {
        // La marca de «ya se revisó hace menos de 5 minutos» se quita para poder probar varias vueltas seguidas.
        Store::pipeline([['DEL', 'bide:incident:run']]);
        return IncidentAlerts::process($Sender, $Fetcher);
    };
    $aSubscriber = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/incidencias', 'keys' => $aSubscription['keys']];
    $Expect('incidencias: se guarda el suscriptor', IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514'], 'stops' => []], 'metro' => ['lines' => ['MB'], 'stops' => []], 'renfe' => ['lines' => [], 'stops' => ['13400']]], 'eu') === 'saved');

    $aCounts = $Run();
    $Expect('incidencias: la primera vuelta solo anota lo que ya hay', $aCounts['subscribers'] === 1 && $aCounts['sent'] === 0 && empty($aSent), json_encode($aCounts));
    sort($aAsked);
    $Expect('incidencias: mira cada línea en Bizkaibus, toda la red en Metro y la estación en Renfe', $aAsked === ['bus|3513', 'bus|3514', 'metro|*', 'renfe|stop:13400'], json_encode($aAsked));
    $Expect('incidencias: dentro de 5 minutos no se vuelve a mirar', IncidentAlerts::process($Sender, $Fetcher) === ['skipped' => true]);

    $aFeeds['bus|3514'] = [['summary' => 'Huelga', 'description' => '  Servicios mínimos el día 5  ']];
    $Run();
    $Expect('incidencias: una nueva se manda una vez', count($aSent) === 1);
    $Expect('incidencias: con la red en el título, en su idioma y con la app al tocarla', ($aSent[0]['title'] ?? '') === 'Bizkaibus+ · Huelga' && $aSent[0]['body'] === 'Servicios mínimos el día 5' && preg_match('/^\/\?red=bus&aviso=[a-z0-9]+-[a-f0-9]{8}#avisos$/', $aSent[0]['url']) === 1 && $aSent[0]['tag'] === IncidentAlerts::key('bus', 'Huelga', 'Servicios mínimos el día 5') && $aSent[0]['icon'] === '/icons-pro/icon-192.png', json_encode($aSent[0] ?? null, JSON_UNESCAPED_UNICODE));
    $Run();
    $Expect('incidencias: la misma no se repite', count($aSent) === 1);

    $aFeeds['metro|*'][] = ['title' => 'Obras L2', 'description' => 'Sin servicio entre Urbinaga y Kabiezes'];
    $iAnswer = 500;
    $Run();
    $Expect('incidencias: si el servicio de push falla, no se da por avisada', count($aSent) === 2);
    $iAnswer = 201;
    $Run();
    $Expect('incidencias: y se manda en la vuelta siguiente', count($aSent) === 3 && $aSent[2]['title'] === 'Metro+ · Obras L2', json_encode(end($aSent), JSON_UNESCAPED_UNICODE));

    $aFeeds['bus|3513'] = 'falla';
    $Run();
    $aFeeds['bus|3513'] = [['summary' => 'Obras', 'description' => 'Desvío en Gernika A']];
    $Run();
    $Expect('incidencias: una fuente caída no hace que lo de antes parezca nuevo', count($aSent) === 3);

    for ($i = 1; $i <= 5; $i++) {
        $aFeeds['bus|3513'][] = ['summary' => 'Aviso ' . $i, 'description' => 'Texto ' . $i];
    }
    $iBefore = count($aSent);
    $Run();
    $aBatch = array_slice($aSent, $iBefore);
    $Expect('incidencias: muchas a la vez, tres sueltas y un resumen en su idioma', count($aBatch) === 4 && $aBatch[3]['title'] === 'Intzidentzia gehiago zure gogokoetan' && $aBatch[3]['body'] === 'Beste 2 abisu. Ireki aplikazioan.', json_encode($aBatch, JSON_UNESCAPED_UNICODE));
    $Run();
    $Expect('incidencias: el resumen también cuenta como avisado', count($aSent) === $iBefore + 4);

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514', '3517'], 'stops' => []]], 'es');
    $aFeeds['bus|3517'] = [['summary' => 'Parada trasladada', 'description' => 'En Plentzia']];
    $Run();
    $Expect('incidencias: un favorito nuevo empieza también sin avisar de lo que ya había', count($aSent) === $iBefore + 4);

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514'], 'stops' => []]], 'es');
    $Run();
    $aFeeds['bus|3517'][] = ['summary' => 'Cambio de horario', 'description' => 'Desde el lunes'];
    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513', '3514', '3517'], 'stops' => []]], 'es');
    $Run();
    $Expect('incidencias: un favorito que se quita y se vuelve a poner tampoco avisa de lo que ya había', count($aSent) === $iBefore + 4);

    $aFeeds['bus|3517'][] = ['summary' => 'Desvío', 'description' => 'Por fiestas'];
    $iAnswer = 410;
    $Run();
    $aLeft = Store::pipeline([['SMEMBERS', 'bide:incident:subs']])[0];
    $Expect('incidencias: si la suscripción ya no existe, se borra', $aLeft === [], json_encode($aLeft));
    $iAnswer = 201;

    IncidentAlerts::save($aSubscriber, ['bus' => ['lines' => ['3513'], 'stops' => []]], 'es');
    $Expect('incidencias: darse de baja', IncidentAlerts::remove($aSubscriber['endpoint']) === true && Store::pipeline([['SMEMBERS', 'bide:incident:subs']])[0] === []);

    // En todas las apps: Euskotren, los dos tranvías y Lurraldebus miran toda la red, como Metro.
    $aOthers = ['euskotren', 'tranvia-bilbao', 'tranvia-vitoria', 'lurraldebus'];
    $aEveryNetwork = [];
    foreach ($aOthers as $sNetwork) {
        $aEveryNetwork[$sNetwork] = ['lines' => [], 'stops' => ['P1']];
        $aFeeds[$sNetwork . '|*'] = [];
    }
    $aOtherSubscriber = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/otras-redes', 'keys' => $aSubscription['keys']];
    IncidentAlerts::save($aOtherSubscriber, $aEveryNetwork, 'es');
    $Run();
    foreach ($aOthers as $sNetwork) {
        $aFeeds[$sNetwork . '|*'][] = ['title' => 'Obras', 'description' => 'Corte en ' . $sNetwork];
    }
    $iBefore = count($aSent);
    $Run();
    $aNew = array_slice($aSent, $iBefore);
    $aNetworksSent = array_map(fn($aMessage) => explode('&', substr($aMessage['url'], strlen('/?red=')))[0], $aNew);
    sort($aNetworksSent);
    $aExpected = $aOthers;
    sort($aExpected);
    $Expect('incidencias en todas las apps: Euskotren, tranvías y Lurraldebus avisan y abren su pestaña Avisos', $aNetworksSent === $aExpected && count(array_filter($aNew, fn($aMessage) => str_ends_with($aMessage['url'], '#avisos'))) === 4, json_encode($aNew, JSON_UNESCAPED_UNICODE));
    IncidentAlerts::remove($aOtherSubscriber['endpoint']);

    // ===== Aviso de versión nueva =====

    $iVersion = Changelog::version();
    $Expect('versión: es la última novedad', $iVersion === Changelog::ENTRIES[0][2] && $iVersion >= 34, (string)$iVersion);
    $aNewer = Changelog::newerThan($iVersion - 2, 'es');
    $Expect('versión: lo que trae cada versión posterior a la del móvil, de la más nueva a la más vieja', array_column($aNewer, 'number') === [$iVersion, $iVersion - 1] && $aNewer[0]['title'] === Changelog::ENTRIES[0][3]);
    $aBasque = Changelog::newerThan($iVersion - 1, 'eu');
    $Expect('versión: en euskera y en inglés, con los textos de js/i18n.js', count($aBasque) === 1 && $aBasque[0]['title'] !== Changelog::ENTRIES[0][3] && Changelog::newerThan($iVersion - 1, 'en')[0]['title'] !== $aBasque[0]['title']);
    $Expect('versión: el móvil al día no tiene nada pendiente', Changelog::newerThan($iVersion, 'es') === []);

    $Device = fn(string $sName) => ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $sName, 'keys' => $aSubscription['keys']];
    $Expect('versión: se apunta un móvil', UpdateAlerts::save($Device('version-en'), 'en') === 'saved');
    UpdateAlerts::save($Device('las-dos'), 'es');
    IncidentAlerts::save($Device('las-dos'), ['bus' => ['lines' => ['3513'], 'stops' => []]], 'es');
    IncidentAlerts::save($Device('solo-incidencias'), ['bus' => ['lines' => ['3513'], 'stops' => []]], 'eu');
    $Again = function (): void {
        Store::pipeline([['DEL', 'bide:update:done'], ['DEL', 'bide:update:sent:' . Changelog::version()]]);
    };
    $Again();
    $aSent = [];
    $aRun = UpdateAlerts::process($Sender);
    $Expect('versión: avisa una vez a cada móvil, también al que solo tiene los avisos de incidencias', $aRun['sent'] === 3 && $aRun['pending'] === 0 && count($aSent) === 3, json_encode($aRun));
    $aTitles = array_column($aSent, 'title');
    sort($aTitles);
    $Expect('versión: cada uno en su idioma', $aTitles === ['Bide+ eguneratu da', 'Bide+ has been updated', 'Bide+ se ha actualizado'], json_encode($aTitles, JSON_UNESCAPED_UNICODE));
    $Expect('versión: abre Ajustes y no se vuelve a abrir si el sistema recarga la dirección', count(array_filter($aSent, fn($aMessage) => str_starts_with($aMessage['url'], '/?aviso=') && str_ends_with($aMessage['url'], '#ajustes'))) === 3);
    $aLangOf = ['Bide+ se ha actualizado' => 'es', 'Bide+ eguneratu da' => 'eu', 'Bide+ has been updated' => 'en'];
    $Expect('versión: cuenta lo que trae, en su idioma', count(array_filter($aSent, fn($aMessage) => str_contains($aMessage['body'], Changelog::newerThan(0, $aLangOf[$aMessage['title']])[0]['title']))) === 3);
    $Expect('versión: la vuelta siguiente no manda nada', UpdateAlerts::process($Sender) === ['skipped' => true] && count($aSent) === 3);

    $Again();
    Store::pipeline([['SET', 'bide:update:run', '1']]);
    $Expect('versión: si otra vuelta está mandando, esta no se mete', UpdateAlerts::process($Sender) === ['busy' => true] && count($aSent) === 3);
    Store::pipeline([['DEL', 'bide:update:run']]);
    $aSent = [];
    $aRun = UpdateAlerts::process($Sender);
    $Expect('versión: al acabar quita su marca y apunta la versión como avisada', $aRun['sent'] === 3 && Store::pipeline([['GET', 'bide:update:run'], ['GET', 'bide:update:done']]) === [null, (string)Changelog::version()], json_encode($aRun));

    $Again();
    $iAnswer = 503;
    $aRun = UpdateAlerts::process($Sender);
    $Expect('versión: si el servicio de push falla, se queda pendiente', $aRun['sent'] === 0 && $aRun['pending'] === 3, json_encode($aRun));
    $iAnswer = 201;
    $aRun = UpdateAlerts::process($Sender);
    $Expect('versión: y se manda en la vuelta siguiente', $aRun['sent'] === 3 && $aRun['pending'] === 0, json_encode($aRun));

    $Again();
    $iAnswer = 410;
    UpdateAlerts::process($Sender);
    $Expect('versión: una suscripción que ya no existe se borra', Store::pipeline([['SMEMBERS', 'bide:update:subs']])[0] === []);
    $iAnswer = 201;
    UpdateAlerts::save($Device('baja'), 'es');
    $Expect('versión: darse de baja', UpdateAlerts::remove($Device('baja')['endpoint']) === true && Store::pipeline([['SMEMBERS', 'bide:update:subs']])[0] === []);
    IncidentAlerts::remove($Device('las-dos')['endpoint']);
    IncidentAlerts::remove($Device('solo-incidencias')['endpoint']);
} finally {
    proc_terminate($Server);
    @unlink($sDataFile);
}

if (!empty($aFailures)) {
    echo "\nRESULTADO notificaciones push: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO notificaciones push: OK\n";
exit(0);
