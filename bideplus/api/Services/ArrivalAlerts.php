<?php

namespace Services;

use Controllers\StopsController;
use Core\Config;
use Core\Networks;
use Core\Store;

/*
 * «Avísame cuando llegue»: desde la ficha de un viaje se pide que el móvil avise cuando falten unos minutos para que
 * ese viaje llegue a la parada. En la llamada de cada minuto del cron (/api/live/record) se mira cuánto falta, con las
 * mismas cuentas que la app, y al llegar el momento se manda la notificación push (Push y WebPush).
 *
 * Por defecto avisa una vez: se guarda unas horas y, al avisar, se borra. Con «todos los días» se queda y avisa cada
 * día que pase ese viaje (el mismo bus a la misma hora), hasta que se cancele o pasen dos meses sin avisar.
 */
class ArrivalAlerts
{
    public const MINUTES = [2, 5, 10];
    private const TTL_SECONDS = 3 * 3600;
    // Uno de todos los días dura dos meses desde la última vez que avisó: si el viaje deja de existir (cambio de
    // horario), se borra solo.
    private const REPEAT_TTL_SECONDS = 60 * 86400;
    // Tras avisar, ese día ya no se repite: la marca dura medio día, así que al día siguiente vuelve a avisar.
    private const REPEAT_SENT_SECONDS = 12 * 3600;
    // Uno de todos los días solo se mira cerca de su hora en el horario (hora y media de margen, por si va muy
    // retrasado): el resto del día no cuesta ninguna consulta.
    private const REPEAT_WINDOW_SECONDS = 90 * 60;
    private const MAX_PENDING = 5000;
    private const MAX_PER_RUN = 1000;
    private const DEPARTURES_LIMIT = 40;
    // Un aviso de llegada que no se entrega en 10 minutos ya no sirve.
    private const PUSH_TTL_SECONDS = 600;
    private const PENDING_KEY = 'bide:arrival:pending';
    private const ALERT_PREFIX = 'bide:arrival:';
    private const SENT_PREFIX = 'bide:arrival:sent:';
    private const GONE_STATUSES = ['departed', 'likely_departed', 'finished'];
    private const TEXTS = [
        'es' => ['minutes' => '{line} llega en {min} min', 'now' => '{line} está llegando', 'body' => '→ {headsign} · {stop}'],
        'eu' => ['minutes' => '{line}: {min} min barru', 'now' => '{line} iristen ari da', 'body' => '→ {headsign} · {stop}'],
        'en' => ['minutes' => '{line} arrives in {min} min', 'now' => '{line} is arriving', 'body' => '→ {headsign} · {stop}'],
    ];

    // Crea el aviso en la red actual. Estados: created (con id), now (ya llega: no hace falta), gone (ya pasó),
    // unknown (el viaje no sale de esa parada) y unavailable (sin almacén o demasiados avisos pendientes). Uno de todos
    // los días se crea aunque hoy ya llegue o haya pasado (today: coming, now o gone): avisará desde mañana.
    public static function create(array $aSubscription, string $sStopId, string $sTripKey, int $iMinutes, string $sLang, bool $bRepeat = false): array
    {
        [$aStop, $aDeparture] = self::departure($sStopId, $sTripKey);
        if ($aDeparture === null) {
            return ['status' => 'unknown'];
        }
        $iLeft = self::minutesLeft($aDeparture);
        $sToday = 'coming';
        if (in_array($aDeparture['status'], self::GONE_STATUSES, true)) {
            $sToday = 'gone';
        } elseif ($iLeft <= $iMinutes) {
            $sToday = 'now';
        }
        if (!$bRepeat && $sToday === 'gone') {
            return ['status' => 'gone'];
        }
        if (!$bRepeat && $sToday === 'now') {
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
            'repeat' => $bRepeat,
            'scheduledSeconds' => Calendar::hmToSeconds((string)$aDeparture['scheduledTime']),
        ];
        $iTtl = self::TTL_SECONDS;
        if ($bRepeat) {
            $iTtl = self::REPEAT_TTL_SECONDS;
        }
        $aCommands = [
            ['SET', self::ALERT_PREFIX . $sId, json_encode($aAlert, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'EX', $iTtl],
            ['SADD', self::PENDING_KEY, $sId],
        ];
        // Si hoy ya está llegando, el aviso de hoy se da por hecho: la ficha ya lo está enseñando.
        if ($bRepeat && $sToday === 'now') {
            $aCommands[] = ['SET', self::SENT_PREFIX . $sId, '1', 'EX', self::REPEAT_SENT_SECONDS];
        }
        if (Store::pipeline($aCommands) === null) {
            return ['status' => 'unavailable'];
        }
        return [
            'status' => 'created',
            'id' => $sId,
            'minutesLeft' => $iLeft,
            'repeat' => $bRepeat,
            'today' => $sToday,
            'lineCode' => $aAlert['lineCode'],
            'headsign' => $aAlert['headsign'],
            'stopName' => $aAlert['stopName'],
        ];
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
        Store::pipeline([['DEL', self::ALERT_PREFIX . $sId], ['DEL', self::SENT_PREFIX . $sId], ['SREM', self::PENDING_KEY, $sId]]);
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
        $Sender ??= Push::sender(self::PUSH_TTL_SECONDS);
        if ($aValues === null || $Sender === null) {
            return $aCounts;
        }

        [$aByStop, $aForget] = self::groupByStop($aIds, $aValues[0]);
        // Cada parada se mira en su red; al acabar se vuelve a la de la llamada.
        $sNetwork = Config::current()['network'];
        foreach ($aByStop as $aAlerts) {
            [$iSent, $aGone] = self::processStop($aAlerts, $Sender);
            $aCounts['sent'] += $iSent;
            array_push($aForget, ...$aGone);
        }
        Config::set($sNetwork);
        $aCounts['dropped'] = self::forget($aForget);
        return $aCounts;
    }

    // Los avisos agrupados por parada, para calcular sus salidas una sola vez, y los que ya no están en el almacén
    // (caducados), para quitarlos de la lista. Los de todos los días lejos de su hora ni se miran.
    private static function groupByStop(array $aIds, array $aValues): array
    {
        $aByStop = [];
        $aForget = [];
        foreach ($aIds as $iIndex => $sId) {
            $aAlert = null;
            if (is_string($aValues[$iIndex] ?? null)) {
                $aAlert = json_decode($aValues[$iIndex], true);
            }
            if (!is_array($aAlert)) {
                $aForget[] = $sId;
                continue;
            }
            if (!empty($aAlert['repeat']) && !self::nearItsTime($aAlert)) {
                continue;
            }
            $aByStop[$aAlert['network'] . '|' . $aAlert['stopId']][] = $aAlert;
        }
        return [$aByStop, $aForget];
    }

    // Los avisos de una parada: manda los que ya tocan y devuelve [enviados, ids de avisos que ya no sirven].
    private static function processStop(array $aAlerts, callable $Sender): array
    {
        Config::set($aAlerts[0]['network']);
        $aData = (new StopsController())->departuresData($aAlerts[0]['stopId'], self::DEPARTURES_LIMIT);
        $iSent = 0;
        $aGone = [];
        foreach ($aAlerts as $aAlert) {
            $aDeparture = self::findTrip($aData, $aAlert['tripKey']);
            if ($aDeparture === null) {
                continue;
            }
            $bRepeat = !empty($aAlert['repeat']);
            if (in_array($aDeparture['status'], self::GONE_STATUSES, true)) {
                // Uno de una vez ya no sirve; uno de todos los días espera a mañana.
                if (!$bRepeat) {
                    $aGone[] = $aAlert['id'];
                }
                continue;
            }
            $iLeft = self::minutesLeft($aDeparture);
            if ($iLeft > $aAlert['minutes']) {
                continue;
            }
            $bSent = false;
            if ($bRepeat) {
                [$bSent, $bDead] = self::sendToday($aAlert, max(0, $iLeft), $Sender);
                if ($bDead) {
                    $aGone[] = $aAlert['id'];
                }
            } else {
                $bSent = self::sendOnce($aAlert, max(0, $iLeft), $Sender);
            }
            if ($bSent) {
                $iSent++;
            }
        }
        return [$iSent, $aGone];
    }

    // Quita los avisos de la lista y del almacén. Devuelve cuántos.
    private static function forget(array $aIds): int
    {
        if (empty($aIds)) {
            return 0;
        }
        $aCommands = [array_merge(['SREM', self::PENDING_KEY], $aIds)];
        foreach ($aIds as $sId) {
            $aCommands[] = ['DEL', self::ALERT_PREFIX . $sId, self::SENT_PREFIX . $sId];
        }
        Store::pipeline($aCommands);
        return count($aIds);
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

    // Uno de todos los días se queda en la lista. La marca de enviado hoy (solo la pone quien llega primero) evita
    // repetirlo; al avisar se alarga su vida y, si el servicio de push falla, se quita la marca para reintentar al
    // minuto siguiente. Devuelve [enviado, la suscripción ya no existe].
    private static function sendToday(array $aAlert, int $iLeft, callable $Sender): array
    {
        $aTaken = Store::pipeline([['SET', self::SENT_PREFIX . $aAlert['id'], '1', 'NX', 'EX', self::REPEAT_SENT_SECONDS]]);
        if ($aTaken === null || $aTaken[0] !== 'OK') {
            return [false, false];
        }
        $iStatus = (int)$Sender($aAlert['subscription'], Push::json(self::message($aAlert, $iLeft)));
        if ($iStatus >= 200 && $iStatus < 300) {
            Store::pipeline([['EXPIRE', self::ALERT_PREFIX . $aAlert['id'], self::REPEAT_TTL_SECONDS]]);
            return [true, false];
        }
        if ($iStatus === 404 || $iStatus === 410) {
            return [false, true];
        }
        Store::pipeline([['DEL', self::SENT_PREFIX . $aAlert['id']]]);
        return [false, false];
    }

    // Cerca de su hora en el horario: desde un rato antes de que toque avisar hasta un rato después (por si va
    // retrasado). Cuenta la vuelta del día: un viaje de las 00:10 está cerca a las 23:50.
    private static function nearItsTime(array $aAlert): bool
    {
        $iDiff = ((((int)($aAlert['scheduledSeconds'] ?? 0) - Calendar::nowSecondsSinceMidnight()) % 86400) + 86400) % 86400;
        if ($iDiff > 43200) {
            $iDiff -= 86400;
        }
        return $iDiff >= -self::REPEAT_WINDOW_SECONDS && $iDiff <= (int)$aAlert['minutes'] * 60 + self::REPEAT_WINDOW_SECONDS;
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
        // «aviso», distinto en cada envío: la app abre la ficha una sola vez aunque el sistema recargue esa dirección.
        $sUrl .= $sSeparator . 'viaje=' . rawurlencode($aAlert['tripKey']) . '&aviso=' . $aAlert['id'] . '-' . base_convert((string)time(), 10, 36);
        // Con el id y si es de una vez, la app lo quita de su lista en cuanto avisa (sw.js).
        return [
            'title' => $sTitle,
            'body' => strtr($aTexts['body'], $aVars),
            'url' => $sUrl,
            'tag' => 'bide-arrival-' . $aAlert['id'],
            'alertId' => $aAlert['id'],
            'repeat' => !empty($aAlert['repeat']),
        ];
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
