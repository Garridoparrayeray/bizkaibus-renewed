<?php

namespace Services;

use Core\Store;

// La notificación del aviso general, si quien lo publicó marcó «notificar»: una vez por aviso, a todos los móviles
// apuntados a las novedades o a las incidencias. El reparto es el de Broadcast. Lo llama el cron de cada minuto.
class NoticeAlerts
{
    private const SENT_PREFIX = 'bide:notice:sent:';
    private const DONE_KEY = 'bide:notice:done';
    private const RUN_KEY = 'bide:notice:run';
    private const MAX_BODY_LENGTH = 160;
    private const TITLES = [
        'es' => 'Aviso de Bide+',
        'eu' => 'Bide+-en oharra',
        'en' => 'Bide+ notice',
        'fr' => 'Avis de Bide+',
    ];

    // $aNotice es el de Notice::current(): null si no hay o ya caducó.
    public static function process(array|null $aNotice, callable|null $Sender = null): array
    {
        if ($aNotice === null || !$aNotice['notify']) {
            return ['skipped' => true];
        }
        $sId = $aNotice['id'];
        // Un aviso ya repartido no se vuelve a repartir: este servidor lo apunta y deja de preguntar al almacén.
        if (self::doneHere() === $sId) {
            return ['skipped' => true];
        }
        $aDone = Store::pipeline([['GET', self::DONE_KEY]]);
        if ($aDone === null) {
            return ['skipped' => true];
        }
        if ($aDone[0] === $sId) {
            self::rememberDone($sId);
            return ['skipped' => true];
        }
        $aRun = Broadcast::run(
            self::RUN_KEY,
            self::SENT_PREFIX . $sId,
            fn(string $sLang) => self::message($aNotice, $sLang),
            $Sender,
            [['SET', self::DONE_KEY, $sId]]
        );
        if (isset($aRun['busy'])) {
            return ['busy' => true];
        }
        if ($aRun['done']) {
            self::rememberDone($sId);
        }
        return ['id' => $sId, 'sent' => $aRun['sent'], 'pending' => $aRun['left']];
    }

    public static function message(array $aNotice, string $sLang): array
    {
        $sText = $aNotice['text'][$sLang] ?? $aNotice['text']['es'];
        return [
            'title' => self::TITLES[$sLang],
            'body' => mb_strimwidth($sText, 0, self::MAX_BODY_LENGTH, '…'),
            'url' => '/',
            'tag' => 'bide-notice',
        ];
    }

    private static function doneHere(): string|null
    {
        $sDone = @file_get_contents(self::doneMarker());
        if ($sDone === false) {
            return null;
        }
        return $sDone;
    }

    private static function rememberDone(string $sId): void
    {
        @file_put_contents(self::doneMarker(), $sId, LOCK_EX);
    }

    public static function doneMarker(): string
    {
        return sys_get_temp_dir() . '/bizkaibusplus_notice_done';
    }
}
