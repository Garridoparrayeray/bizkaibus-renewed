<?php

namespace Services;

use Core\Store;

// Aviso de versión nueva: una notificación por versión a cada suscrito, que abre Ajustes.
class UpdateAlerts
{
    public const SUBSCRIBERS_KEY = 'bide:update:subs';
    public const SUBSCRIBER_PREFIX = 'bide:update:sub:';
    private const SENT_PREFIX = 'bide:update:sent:';
    private const DONE_KEY = 'bide:update:done';
    private const RUN_KEY = 'bide:update:run';
    private const TTL_SECONDS = 90 * 86400;
    private const MAX_SUBSCRIBERS = 20000;
    private const MAX_TITLES = 3;
    private const TEXTS = [
        'es' => ['title' => 'Bide+ se ha actualizado', 'body' => 'Novedades: {titles}. Toca para actualizar la app.'],
        'eu' => ['title' => 'Bide+ eguneratu da', 'body' => 'Berritasunak: {titles}. Sakatu aplikazioa eguneratzeko.'],
        'en' => ['title' => 'Bide+ has been updated', 'body' => 'What\'s new: {titles}. Tap to update the app.'],
        'fr' => ['title' => 'Bide+ a été mis à jour', 'body' => 'Nouveautés : {titles}. Touchez pour mettre à jour l’application.'],
    ];

    // Estados: saved y unavailable.
    public static function save(array $aSubscription, string $sLang): string
    {
        $sId = Broadcast::id($aSubscription['endpoint']);
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
        return self::forget(Broadcast::id($sEndpoint));
    }

    // Borra un móvil de la lista de novedades (también lo hace Broadcast con las suscripciones que ya no existen).
    public static function forget(string $sId): bool
    {
        return Store::pipeline([
            ['DEL', self::SUBSCRIBER_PREFIX . $sId],
            ['SREM', self::SUBSCRIBERS_KEY, $sId],
        ]) !== null;
    }

    // Cron de cada minuto. $Sender devuelve el código HTTP del servicio de push.
    public static function process(callable|null $Sender = null): array
    {
        $iVersion = Changelog::version();
        // Una versión ya avisada no se vuelve a avisar: este servidor lo apunta y deja de preguntar al almacén.
        if (self::doneHere() >= $iVersion) {
            return ['skipped' => true];
        }
        $aDone = Store::pipeline([['GET', self::DONE_KEY]]);
        if ($aDone === null) {
            return ['skipped' => true];
        }
        if ((int)$aDone[0] >= $iVersion) {
            self::rememberDone((int)$aDone[0]);
            return ['skipped' => true];
        }
        $aRun = Broadcast::run(
            self::RUN_KEY,
            self::SENT_PREFIX . $iVersion,
            fn(string $sLang) => self::message($iVersion, (int)$aDone[0], $sLang),
            $Sender,
            [['SET', self::DONE_KEY, (string)$iVersion]]
        );
        if (isset($aRun['busy'])) {
            return ['busy' => true];
        }
        if ($aRun['done']) {
            self::rememberDone($iVersion);
        }
        return ['version' => $iVersion, 'sent' => $aRun['sent'], 'pending' => $aRun['left']];
    }

    private static function doneHere(): int
    {
        $sDone = @file_get_contents(self::doneMarker());
        if ($sDone === false) {
            return 0;
        }
        return (int)$sDone;
    }

    private static function rememberDone(int $iVersion): void
    {
        @file_put_contents(self::doneMarker(), (string)$iVersion, LOCK_EX);
    }

    public static function doneMarker(): string
    {
        return sys_get_temp_dir() . '/bizkaibusplus_update_done';
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
}
