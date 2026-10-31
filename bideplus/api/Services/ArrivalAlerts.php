<?php

namespace Services;

use Controllers\StopsController;
use Core\Config;
use Core\Networks;
use Core\Store;

/*
 * «Avísame cuando llegue»: desde la ficha de un viaje se pide que el móvil avise cuando falten unos minutos para que
 * ese viaje llegue a la parada. El aviso se guarda en el almacén unas horas; en la llamada de cada minuto del cron
 * (/api/live/record) se mira cuánto falta, con las mismas cuentas que la app, y al llegar el momento se manda la
 * notificación push (Push y WebPush) y se borra.
 */
class ArrivalAlerts
{
    public const MINUTES = [2, 5, 10];
    private const TTL_SECONDS = 3 * 3600;
    private const MAX_PENDING = 5000;
    private const MAX_PER_RUN = 300;
    private const DEPARTURES_LIMIT = 40;
    // Un aviso de llegada que no se entrega en 10 minutos ya no sirve.
    private const PUSH_TTL_SECONDS = 600;
    private const PENDING_KEY = 'bide:arrival:pending';
    private const ALERT_PREFIX = 'bide:arrival:';
    private const GONE_STATUSES = ['departed', 'likely_departed', 'finished'];
    private const TEXTS = [
        'es' => ['minutes' => '{line} llega en {min} min', 'now' => '{line} está llegando', 'body' => '→ {headsign} · {stop}'],
        'eu' => ['minutes' => '{line}: {min} min barru', 'now' => '{line} iristen ari da', 'body' => '→ {headsign} · {stop}'],
        'en' => ['minutes' => '{line} arrives in {min} min', 'now' => '{line} is arriving', 'body' => '→ {headsign} · {stop}'],
    ];

    // Crea el aviso en la red actual. Estados: created (con id), now (ya llega: no hace falta), gone (ya pasó),
    // unknown (el viaje no sale de esa parada) y unavailable (sin almacén o demasiados avisos pendientes).
    public static function create(array $aSubscription, string $sStopId, string $sTripKey, int $iMinutes, string $sLang): array
    {
        [$aStop, $aDeparture] = self::departure($sStopId, $sTripKey);
        if ($aDeparture === null) {
            return ['status' => 'unknown'];
        }
        if (in_array($aDeparture['status'], self::GONE_STATUSES, true)) {
            return ['status' => 'gone'];
        }
        $iLeft = self::minutesLeft($aDeparture);
        if ($iLeft <= $iMinutes) {
            return ['status' => 'now', 'minutesLeft' => max(0, $iLeft)];
        }
        $aCount = Store::pipeline([['SCARD', self::PENDING_KEY]]);
        if ($aCount === null || (int)$aCount[0] >= self::MAX_PENDING) {
            return ['status' => 'unavailable'];
        }
        $sId = bin2hex(random_bytes(12));
        $aAlert = [
            'id' => $sId,
            'network' => Config::current()['network'],
            'stopId' => (string)$aStop['id'],
            'stopName' => $aStop['name'],
            'tripKey' => $sTripKey,
            'lineCode' => $aDeparture['lineCode'],
            'headsign' => $aDeparture['headsign'],
            'minutes' => $iMinutes,
            'lang' => $sLang,
            'subscription' => $aSubscription,
            'created' => time(),
        ];
        $aStored = Store::pipeline([
            ['SET', self::ALERT_PREFIX . $sId, json_encode($aAlert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'EX', self::TTL_SECONDS],
            ['SADD', self::PENDING_KEY, $sId],
        ]);
        if ($aStored === null) {
            return ['status' => 'unavailable'];
        }
        return ['status' => 'created', 'id' => $sId, 'minutesLeft' => $iLeft, 'lineCode' => $aAlert['lineCode'], 'headsign' => $aAlert['headsign'], 'stopName' => $aAlert['stopName']];
    }

    // Solo cancela quien creó el aviso: hay que dar la misma suscripción.
    public static function cancel(string $sId, string $sEndpoint): bool
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $sId)) {
            return false;
        }
        $aResult = Store::pipeline([['GET', self::ALERT_PREFIX . $sId]]);
        if ($aResult === null) {
            return false;
        }
        if (!is_string($aResult[0])) {
            return true;
        }
        $aAlert = json_decode($aResult[0], true);
        if (!hash_equals((string)($aAlert['subscription']['endpoint'] ?? ''), $sEndpoint)) {
            return false;
        }
        Store::pipeline([['DEL', self::ALERT_PREFIX . $sId], ['SREM', self::PENDING_KEY, $sId]]);
        return true;
    }

    // Lo llama el cron cada minuto: manda los avisos a los que ya les toca y olvida los caducados o ya pasados.
    // $Sender recibe la suscripción y el mensaje y devuelve el código HTTP del servicio de push (en las pruebas, uno falso).
    public static function process(callable|null $Sender = null): array
    {
        $aCounts = ['pending' => 0, 'sent' => 0, 'dropped' => 0];
        $aIds = Store::pipeline([['SMEMBERS', self::PENDING_KEY]]);
        if ($aIds === null || !is_array($aIds[0]) || empty($aIds[0])) {
            return $aCounts;
        }
        $aIds = array_slice($aIds[0], 0, self::MAX_PER_RUN);
        $aCounts['pending'] = count($aIds);
        $aValues = Store::pipeline([array_merge(['MGET'], array_map(fn($sId) => self::ALERT_PREFIX . $sId, $aIds))]);
        if ($aValues === null) {
            return $aCounts;
        }
        $Sender ??= Push::sender(self::PUSH_TTL_SECONDS);
        if ($Sender === null) {
            return $aCounts;
        }

        // Caducados: se quitan de la lista. El resto, agrupados por parada para calcular sus salidas una sola vez.
        $aForget = [];
        $aByStop = [];
        foreach ($aIds as $iIndex => $sId) {
            $aAlert = null;
            if (is_string($aValues[0][$iIndex] ?? null)) {
                $aAlert = json_decode($aValues[0][$iIndex], true);
            }
            if (!is_array($aAlert)) {
                $aForget[] = $sId;
                continue;
            }
            $aByStop[$aAlert['network'] . '|' . $aAlert['stopId']][] = $aAlert;
        }

        $sNetwork = Config::current()['network'];
        foreach ($aByStop as $aAlerts) {
            Config::set($aAlerts[0]['network']);
            $aData = (new StopsController())->departuresData($aAlerts[0]['stopId'], self::DEPARTURES_LIMIT);
            foreach ($aAlerts as $aAlert) {
                $aDeparture = self::findTrip($aData, $aAlert['tripKey']);
                if ($aDeparture === null) {
                    continue;
                }
                if (in_array($aDeparture['status'], self::GONE_STATUSES, true)) {
                    $aForget[] = $aAlert['id'];
                    continue;
                }
                $iLeft = self::minutesLeft($aDeparture);
                if ($iLeft > $aAlert['minutes']) {
                    continue;
                }
                if (self::sendOnce($aAlert, max(0, $iLeft), $Sender)) {
                    $aCounts['sent']++;
                }
            }
        }
        Config::set($sNetwork);

        if (!empty($aForget)) {
            $aCommands = [array_merge(['SREM', self::PENDING_KEY], $aForget)];
            foreach ($aForget as $sId) {
                $aCommands[] = ['DEL', self::ALERT_PREFIX . $sId];
            }
            Store::pipeline($aCommands);
            $aCounts['dropped'] = count($aForget);
        }
        return $aCounts;
    }

    // Se quita de la lista antes de enviar (SREM devuelve 1 solo a quien lo quita): dos llamadas del cron a la vez no
    // mandan el mismo aviso dos veces. Si el servicio de push falla, vuelve a la lista para el minuto siguiente.
    private static function sendOnce(array $aAlert, int $iLeft, callable $Sender): bool
    {
        $aTaken = Store::pipeline([['SREM', self::PENDING_KEY, $aAlert['id']]]);
        if ($aTaken === null || (int)$aTaken[0] !== 1) {
            return false;
        }
        $iStatus = (int)$Sender($aAlert['subscription'], Push::json(self::message($aAlert, $iLeft)));
        $bDelivered = $iStatus >= 200 && $iStatus < 300;
        // 404 y 410: la suscripción ya no existe (se desinstaló la app o se quitó el permiso). No se reintenta.
        if ($bDelivered || $iStatus === 404 || $iStatus === 410) {
            Store::pipeline([['DEL', self::ALERT_PREFIX . $aAlert['id']]]);
            return $bDelivered;
        }
        Store::pipeline([['SADD', self::PENDING_KEY, $aAlert['id']]]);
        return false;
    }

    public static function message(array $aAlert, int $iLeft): array
    {
        $aTexts = self::TEXTS[$aAlert['lang']] ?? self::TEXTS['es'];
        $aVars = ['{line}' => $aAlert['lineCode'], '{min}' => (string)$iLeft, '{headsign}' => $aAlert['headsign'], '{stop}' => $aAlert['stopName']];
        $sTitle = strtr($aTexts['minutes'], $aVars);
        if ($iLeft <= 0) {
            $sTitle = strtr($aTexts['now'], $aVars);
        }
        // Al tocarla se abre la parada con la ficha del viaje en directo.
        $sUrl = '/stops/' . rawurlencode($aAlert['stopId']) . Networks::urlSuffix($aAlert['network']);
        $sSeparator = '?';
        if (str_contains($sUrl, '?')) {
            $sSeparator = '&';
        }
        $sUrl .= $sSeparator . 'viaje=' . rawurlencode($aAlert['tripKey']);
        return ['title' => $sTitle, 'body' => strtr($aTexts['body'], $aVars), 'url' => $sUrl, 'tag' => 'bide-arrival-' . $aAlert['id']];
    }

    private static function departure(string $sStopId, string $sTripKey): array
    {
        $aData = (new StopsController())->departuresData($sStopId, self::DEPARTURES_LIMIT);
        if ($aData === null) {
            return [null, null];
        }
        return [$aData['stop'], self::findTrip($aData, $sTripKey)];
    }

    private static function findTrip(array|null $aData, string $sTripKey): array|null
    {
        foreach ($aData['departures'] ?? [] as $aDeparture) {
            if (($aDeparture['tripKey'] ?? null) === $sTripKey) {
                return $aDeparture;
            }
        }
        return null;
    }

    // Igual que la app: con la hora absoluta prevista (etaAt), contra el reloj de ahora.
    private static function minutesLeft(array $aDeparture): int
    {
        if (isset($aDeparture['etaAt']) && is_numeric($aDeparture['etaAt'])) {
            return (int)round(($aDeparture['etaAt'] - time()) / 60);
        }
        return (int)$aDeparture['etaMinutes'];
    }
}
