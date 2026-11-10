<?php

namespace Services;

use Core\Store;

// El reparto de una notificación a todos los móviles apuntados (a los avisos de novedades y a los de incidencias): de a
// diez, con un tiempo máximo por vuelta del cron, lo ya enviado apuntado aparte y el reintento de lo que el servicio de
// push no ha podido entregar. Lo usan el aviso de versión nueva y el aviso general.
class Broadcast
{
    private const RUN_LOCK_SECONDS = 60;
    private const SENT_TTL_SECONDS = 30 * 86400;
    private const MAX_SENDS_PER_RUN = 300;
    private const CHUNK = 10;
    private const TIME_BUDGET_SECONDS = 3.0;
    private const PUSH_TTL_SECONDS = 3 * 86400;

    // Una vuelta. $Message da el mensaje de un idioma; $aDoneCommands se apunta con el cierre si no queda nadie pendiente.
    // Devuelve ['busy' => true] si otra vuelta está mandando o ['sent', 'left' (null sin almacén), 'done'].
    public static function run(string $sRunKey, string $sSentKey, callable $Message, callable|null $Sender = null, array $aDoneCommands = []): array
    {
        $aLock = Store::pipeline([['SET', $sRunKey, (string)time(), 'NX', 'EX', self::RUN_LOCK_SECONDS]]);
        if ($aLock === null || $aLock[0] === null) {
            return ['busy' => true];
        }
        $aRun = [
            'message' => $Message,
            'sender' => $Sender ?? Push::sender(self::PUSH_TTL_SECONDS),
            'sentKey' => $sSentKey,
            'deadline' => microtime(true) + self::TIME_BUDGET_SECONDS,
            'messages' => [],
            'sent' => 0,
            'handled' => 0,
        ];
        $aPending = self::pending($sSentKey);
        if ($aPending !== null && $aRun['sender'] !== null) {
            foreach (array_chunk(array_slice($aPending, 0, self::MAX_SENDS_PER_RUN), self::CHUNK) as $aChunk) {
                if (microtime(true) > $aRun['deadline']) {
                    break;
                }
                self::sendChunk($aChunk, $aRun);
            }
        }
        $aEnd = [['DEL', $sRunKey]];
        $iLeft = null;
        if ($aPending !== null) {
            $iLeft = count($aPending) - $aRun['handled'];
        }
        if ($iLeft === 0) {
            array_push($aEnd, ...$aDoneCommands);
        }
        $aEnded = Store::pipeline($aEnd);
        $bDone = $iLeft === 0 && $aEnded !== null && !empty($aDoneCommands) && end($aEnded) === 'OK';
        return ['sent' => $aRun['sent'], 'left' => $iLeft, 'done' => $bDone];
    }

    private static function pending(string $sSentKey): array|null
    {
        $aSets = Store::pipeline([['SMEMBERS', UpdateAlerts::SUBSCRIBERS_KEY], ['SMEMBERS', IncidentAlerts::SUBSCRIBERS_KEY], ['SMEMBERS', $sSentKey]]);
        if ($aSets === null) {
            return null;
        }
        return array_values(array_diff(array_unique(array_merge(self::members($aSets[0]), self::members($aSets[1]))), self::members($aSets[2])));
    }

    // Sin respuesta, 429 o 5xx queda pendiente; una suscripción que ya no existe se borra.
    private static function sendChunk(array $aChunk, array &$aRun): void
    {
        $aHandled = [];
        foreach (self::records($aChunk) as $sId => $aRecord) {
            if (microtime(true) > $aRun['deadline']) {
                break;
            }
            $iStatus = 0;
            if ($aRecord !== null) {
                $sLang = Push::lang($aRecord['lang'] ?? 'es');
                $aRun['messages'][$sLang] ??= ($aRun['message'])($sLang);
                $iStatus = (int)($aRun['sender'])($aRecord['subscription'], Push::json($aRun['messages'][$sLang]));
            }
            if ($iStatus === 404 || $iStatus === 410) {
                UpdateAlerts::forget($sId);
            }
            if ($aRecord !== null && ($iStatus === 0 || $iStatus === 429 || $iStatus >= 500)) {
                continue;
            }
            if ($iStatus >= 200 && $iStatus < 300) {
                $aRun['sent']++;
            }
            $aHandled[] = $sId;
        }
        if (!empty($aHandled)) {
            Store::pipeline([array_merge(['SADD', $aRun['sentKey']], $aHandled), ['EXPIRE', $aRun['sentKey'], self::SENT_TTL_SECONDS]]);
            $aRun['handled'] += count($aHandled);
        }
    }

    private static function records(array $aIds): array
    {
        if (empty($aIds)) {
            return [];
        }
        $aCommands = [];
        foreach ($aIds as $sId) {
            $aCommands[] = ['GET', UpdateAlerts::SUBSCRIBER_PREFIX . $sId];
            $aCommands[] = ['GET', IncidentAlerts::SUBSCRIBER_PREFIX . $sId];
        }
        $aValues = Store::pipeline($aCommands);
        $aRecords = [];
        foreach ($aIds as $iIndex => $sId) {
            $aRecords[$sId] = null;
            if ($aValues !== null) {
                $aRecords[$sId] = self::decode($aValues[2 * $iIndex]) ?? self::decode($aValues[2 * $iIndex + 1]);
            }
        }
        return $aRecords;
    }

    private static function members(mixed $aValue): array
    {
        if (!is_array($aValue)) {
            return [];
        }
        return array_map('strval', $aValue);
    }

    // El mismo id que en IncidentAlerts: un móvil apuntado en los dos cuenta una vez.
    public static function id(string $sEndpoint): string
    {
        return substr(hash('sha256', $sEndpoint), 0, 32);
    }

    private static function decode(mixed $sValue): array|null
    {
        if (!is_string($sValue)) {
            return null;
        }
        $aValue = json_decode($sValue, true);
        if (!is_array($aValue) || !is_array($aValue['subscription'] ?? null)) {
            return null;
        }
        return $aValue;
    }
}
