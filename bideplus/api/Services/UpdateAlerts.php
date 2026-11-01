<?php

namespace Services;

use Core\Store;

// Aviso de versión nueva: una notificación por versión a cada suscrito, que abre Ajustes.
class UpdateAlerts
{
    private const SUBSCRIBERS_KEY = 'bide:update:subs';
    private const SUBSCRIBER_PREFIX = 'bide:update:sub:';
    private const SENT_PREFIX = 'bide:update:sent:';
    private const DONE_KEY = 'bide:update:done';
    private const RUN_KEY = 'bide:update:run';
    private const RUN_LOCK_SECONDS = 60;
    private const TTL_SECONDS = 90 * 86400;
    private const SENT_TTL_SECONDS = 30 * 86400;
    private const MAX_SUBSCRIBERS = 20000;
    private const MAX_SENDS_PER_RUN = 300;
    private const CHUNK = 10;
    private const TIME_BUDGET_SECONDS = 3.0;
    private const PUSH_TTL_SECONDS = 3 * 86400;
    private const MAX_TITLES = 3;
    private const TEXTS = [
        'es' => ['title' => 'Bide+ se ha actualizado', 'body' => 'Novedades: {titles}. Toca para actualizar la app.'],
        'eu' => ['title' => 'Bide+ eguneratu da', 'body' => 'Berritasunak: {titles}. Sakatu aplikazioa eguneratzeko.'],
        'en' => ['title' => 'Bide+ has been updated', 'body' => 'What\'s new: {titles}. Tap to update the app.'],
    ];

    // Estados: saved y unavailable.
    public static function save(array $aSubscription, string $sLang): string
    {
        $sId = self::id($aSubscription['endpoint']);
        $aCheck = Store::pipeline([['SCARD', self::SUBSCRIBERS_KEY], ['SISMEMBER', self::SUBSCRIBERS_KEY, $sId]]);
        if ($aCheck === null || ((int)$aCheck[0] >= self::MAX_SUBSCRIBERS && (int)$aCheck[1] !== 1)) {
            return 'unavailable';
        }
        $aRecord = ['subscription' => $aSubscription, 'lang' => $sLang, 'updated' => time()];
        $aStored = Store::pipeline([
            ['SET', self::SUBSCRIBER_PREFIX . $sId, Push::json($aRecord), 'EX', self::TTL_SECONDS],
            ['SADD', self::SUBSCRIBERS_KEY, $sId],
        ]);
        if ($aStored === null) {
            return 'unavailable';
        }
        return 'saved';
    }

    public static function remove(string $sEndpoint): bool
    {
        return self::forget(self::id($sEndpoint));
    }

    // Cron de cada minuto. $Sender devuelve el código HTTP del servicio de push.
    public static function process(callable|null $Sender = null): array
    {
        $iVersion = Changelog::version();
        $aDone = Store::pipeline([['GET', self::DONE_KEY]]);
        if ($aDone === null || (int)$aDone[0] >= $iVersion) {
            return ['skipped' => true];
        }
        $aLock = Store::pipeline([['SET', self::RUN_KEY, (string)time(), 'NX', 'EX', self::RUN_LOCK_SECONDS]]);
        if ($aLock === null || $aLock[0] === null) {
            return ['busy' => true];
        }
        $aRun = [
            'version' => $iVersion,
            'previous' => (int)$aDone[0],
            'sender' => $Sender ?? Push::sender(self::PUSH_TTL_SECONDS),
            'sentKey' => self::SENT_PREFIX . $iVersion,
            'deadline' => microtime(true) + self::TIME_BUDGET_SECONDS,
            'messages' => [],
            'sent' => 0,
            'handled' => 0,
        ];
        $aPending = self::pending($aRun['sentKey']);
        if ($aPending !== null && $aRun['sender'] !== null) {
            foreach (array_chunk(array_slice($aPending, 0, self::MAX_SENDS_PER_RUN), self::CHUNK) as $aChunk) {
                if (microtime(true) > $aRun['deadline']) {
                    break;
                }
                self::sendChunk($aChunk, $aRun);
            }
        }
        $aEnd = [['DEL', self::RUN_KEY]];
        $iLeft = null;
        if ($aPending !== null) {
            $iLeft = count($aPending) - $aRun['handled'];
        }
        if ($iLeft === 0) {
            $aEnd[] = ['SET', self::DONE_KEY, (string)$iVersion];
        }
        Store::pipeline($aEnd);
        return ['version' => $iVersion, 'sent' => $aRun['sent'], 'pending' => $iLeft];
    }

    private static function pending(string $sSentKey): array|null
    {
        $aSets = Store::pipeline([['SMEMBERS', self::SUBSCRIBERS_KEY], ['SMEMBERS', IncidentAlerts::SUBSCRIBERS_KEY], ['SMEMBERS', $sSentKey]]);
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
                $aRun['messages'][$sLang] ??= self::message($aRun['version'], $aRun['previous'], $sLang);
                $iStatus = (int)($aRun['sender'])($aRecord['subscription'], Push::json($aRun['messages'][$sLang]));
            }
            if ($iStatus === 404 || $iStatus === 410) {
                self::forget($sId);
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

    public static function message(int $iVersion, int $iPrevious, string $sLang): array
    {
        $aNotes = Changelog::newerThan($iPrevious, $sLang);
        if ($iPrevious <= 0) {
            $aNotes = array_slice($aNotes, 0, self::MAX_TITLES);
        }
        $aTitles = array_slice(array_column($aNotes, 'title'), 0, self::MAX_TITLES);
        $aTexts = self::TEXTS[$sLang];
        return [
            'title' => $aTexts['title'],
            'body' => strtr($aTexts['body'], ['{titles}' => implode(' · ', $aTitles)]),
            'url' => '/?aviso=' . base_convert((string)time(), 10, 36) . '-v' . $iVersion . '#ajustes',
            'tag' => 'bide-update',
        ];
    }

    private static function records(array $aIds): array
    {
        if (empty($aIds)) {
            return [];
        }
        $aCommands = [];
        foreach ($aIds as $sId) {
            $aCommands[] = ['GET', self::SUBSCRIBER_PREFIX . $sId];
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

    private static function forget(string $sId): bool
    {
        return Store::pipeline([
            ['DEL', self::SUBSCRIBER_PREFIX . $sId],
            ['SREM', self::SUBSCRIBERS_KEY, $sId],
        ]) !== null;
    }

    // El mismo id que en IncidentAlerts: un móvil apuntado en los dos cuenta una vez.
    private static function id(string $sEndpoint): string
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
