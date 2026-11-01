<?php

namespace Services;

use Core\Store;

/*
 * Aviso de versión nueva de la app. Quien tiene permitidas las notificaciones se apunta al abrir la app (su suscripción
 * push y su idioma, js/app-update.js); quien tiene activados los avisos de incidencias ya está apuntado allí. Cuando la
 * versión de la app (la última novedad, Changelog) sube, el cron de /api/live/record manda a cada uno una notificación,
 * una sola vez por versión, que abre Ajustes para vaciar la caché y actualizar.
 *
 * En el almacén: los apuntados (caducan a los 90 días sin abrir la app), los ya avisados de cada versión y la última
 * versión de la que se avisó a todos. Mientras esa marca coincide con la versión, cada minuto cuesta un solo comando.
 */
class UpdateAlerts
{
    private const SUBSCRIBERS_KEY = 'bide:update:subs';
    private const SUBSCRIBER_PREFIX = 'bide:update:sub:';
    private const SENT_PREFIX = 'bide:update:sent:';
    private const DONE_KEY = 'bide:update:done';
    private const TTL_SECONDS = 90 * 86400;
    private const SENT_TTL_SECONDS = 30 * 86400;
    private const MAX_SUBSCRIBERS = 20000;
    private const MAX_SENDS_PER_RUN = 300;
    // Si el móvil está apagado, el aviso le llega al encenderlo durante tres días.
    private const PUSH_TTL_SECONDS = 3 * 86400;
    // La primera vez no se sabe desde qué versión vienen: se cuentan las tres últimas novedades.
    private const MAX_TITLES = 3;
    private const TEXTS = [
        'es' => ['title' => 'Bide+ se ha actualizado', 'body' => 'Novedades: {titles}. Toca para actualizar la app.'],
        'eu' => ['title' => 'Bide+ eguneratu da', 'body' => 'Berritasunak: {titles}. Sakatu aplikazioa eguneratzeko.'],
        'en' => ['title' => 'Bide+ has been updated', 'body' => 'What\'s new: {titles}. Tap to update the app.'],
    ];

    // Apunta o actualiza a quien quiere el aviso. Estados: saved y unavailable (sin almacén o demasiados apuntados).
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

    // Lo llama el cron cada minuto. $Sender manda el mensaje y devuelve el código HTTP del servicio de push (en las
    // pruebas, uno falso). Manda como mucho 300 por vuelta; lo que falte, en la siguiente.
    public static function process(callable|null $Sender = null): array
    {
        $iVersion = Changelog::version();
        $aDone = Store::pipeline([['GET', self::DONE_KEY]]);
        if ($aDone === null || (int)$aDone[0] >= $iVersion) {
            return ['skipped' => true];
        }
        $Sender = $Sender ?? Push::sender(self::PUSH_TTL_SECONDS);
        $sSentKey = self::SENT_PREFIX . $iVersion;
        $aSets = Store::pipeline([['SMEMBERS', self::SUBSCRIBERS_KEY], ['SMEMBERS', IncidentAlerts::SUBSCRIBERS_KEY], ['SMEMBERS', $sSentKey]]);
        if ($aSets === null || $Sender === null) {
            return ['version' => $iVersion, 'sent' => 0, 'pending' => null];
        }
        $aPending = array_values(array_diff(array_unique(array_merge(self::members($aSets[0]), self::members($aSets[1]))), self::members($aSets[2])));
        $aBatch = array_slice($aPending, 0, self::MAX_SENDS_PER_RUN);
        $aMessages = [];
        $aHandled = [];
        $iSent = 0;
        foreach (self::records($aBatch) as $sId => $aRecord) {
            $iStatus = 0;
            if ($aRecord !== null) {
                $sLang = Push::lang($aRecord['lang'] ?? 'es');
                $aMessages[$sLang] ??= self::message($iVersion, (int)$aDone[0], $sLang);
                $iStatus = (int)$Sender($aRecord['subscription'], Push::json($aMessages[$sLang]));
            }
            if ($iStatus === 404 || $iStatus === 410) {
                self::forget($sId);
            }
            // Un fallo pasajero del servicio de push (sin respuesta, 429, 5xx) se reintenta en la siguiente vuelta.
            if ($aRecord !== null && ($iStatus === 0 || $iStatus === 429 || $iStatus >= 500)) {
                continue;
            }
            if ($iStatus >= 200 && $iStatus < 300) {
                $iSent++;
            }
            $aHandled[] = $sId;
        }
        $aCommands = [];
        if (!empty($aHandled)) {
            $aCommands[] = array_merge(['SADD', $sSentKey], $aHandled);
            $aCommands[] = ['EXPIRE', $sSentKey, self::SENT_TTL_SECONDS];
        }
        $iLeft = count($aPending) - count($aHandled);
        if ($iLeft === 0) {
            $aCommands[] = ['SET', self::DONE_KEY, (string)$iVersion];
        }
        if (!empty($aCommands)) {
            Store::pipeline($aCommands);
        }
        return ['version' => $iVersion, 'sent' => $iSent, 'pending' => $iLeft];
    }

    // La notificación en un idioma: los títulos de lo nuevo desde la última versión avisada (o las tres últimas
    // novedades) y la dirección de Ajustes. «aviso» hace que, si el sistema recarga esa dirección, no se vuelva a abrir.
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

    // La suscripción y el idioma de cada id: los de este aviso o, si no se apuntó aquí, los de los avisos de incidencias.
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

    // El mismo id que en los avisos de incidencias, para que un móvil apuntado en los dos sitios cuente una vez.
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
