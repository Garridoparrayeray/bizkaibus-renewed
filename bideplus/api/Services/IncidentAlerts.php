<?php

namespace Services;

use Controllers\AlertsController;
use Core\Config;
use Core\Networks;
use Core\Store;

/*
 * Avisos de incidencias nuevas en los favoritos, con la app cerrada. Al activar «Avisarme» el navegador manda su
 * suscripción push y los favoritos de todas las apps; el servidor los guarda y, cada 5 minutos (en la llamada del
 * cron de /api/live/record), mira las incidencias de cada favorito y manda una notificación por cada incidencia
 * nueva. Lo que ya había al activar los avisos, o al añadir un favorito, no avisa: la primera vista solo lo anota.
 *
 * En el almacén, por suscriptor: la suscripción, los favoritos y el idioma (los escribe el navegador) y aparte lo ya
 * visto (lo escribe el cron), para que no se pisen. Caduca a los 90 días sin abrir la app.
 */
class IncidentAlerts
{
    private const SUBSCRIBERS_KEY = 'bide:incident:subs';
    private const SUBSCRIBER_PREFIX = 'bide:incident:sub:';
    private const STATE_PREFIX = 'bide:incident:state:';
    private const RUN_KEY = 'bide:incident:run';
    // El cron llama cada minuto; la marca dura 4 minutos y medio, así que se revisa cada 5.
    private const RUN_EVERY_SECONDS = 270;
    private const TTL_SECONDS = 90 * 86400;
    private const MAX_SUBSCRIBERS = 20000;
    // Los ids más largos tienen menos de 40 caracteres; con estos topes nadie llena el almacén con favoritos inventados.
    private const MAX_FAVORITES = 60;
    private const MAX_TOTAL_FAVORITES = 150;
    private const MAX_SEEN = 300;
    private const SEEN_REFRESH_SECONDS = 86400;
    private const MAX_NOTIFICATIONS = 3;
    private const MAX_SENDS_PER_RUN = 300;
    private const CHUNK = 200;
    private const BODY_LENGTH = 180;
    // Una incidencia sigue valiendo unas horas después: si el móvil está apagado, le llega al encenderlo.
    private const PUSH_TTL_SECONDS = 6 * 3600;
    private const ID_PATTERN = '/^[A-Za-z0-9:._-]{1,64}$/';
    // Como en la app: sin título, «Incidencia» (y así la clave de la incidencia es la misma).
    private const UNTITLED = 'Incidencia';
    // Espacios que quita trim() en JavaScript.
    private const EDGE_SPACES = '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u';
    private const TEXTS = [
        'es' => ['moreTitle' => 'Más incidencias en tus favoritos', 'moreBody' => '{n} avisos más. Ábrelos en la app.'],
        'eu' => ['moreTitle' => 'Intzidentzia gehiago zure gogokoetan', 'moreBody' => 'Beste {n} abisu. Ireki aplikazioan.'],
        'en' => ['moreTitle' => 'More disruptions on your favourites', 'moreBody' => '{n} more alerts. Open them in the app.'],
    ];

    // Guarda o actualiza un suscriptor. Estados: saved y unavailable (sin almacén o demasiados suscriptores).
    public static function save(array $aSubscription, array $aFavorites, string $sLang): string
    {
        $sId = self::id($aSubscription['endpoint']);
        $aCheck = Store::pipeline([['SCARD', self::SUBSCRIBERS_KEY], ['SISMEMBER', self::SUBSCRIBERS_KEY, $sId]]);
        if ($aCheck === null || ((int)$aCheck[0] >= self::MAX_SUBSCRIBERS && (int)$aCheck[1] !== 1)) {
            return 'unavailable';
        }
        $aRecord = ['subscription' => $aSubscription, 'favorites' => $aFavorites, 'lang' => $sLang, 'updated' => time()];
        $aStored = Store::pipeline([
            ['SET', self::SUBSCRIBER_PREFIX . $sId, Push::json($aRecord), 'EX', self::TTL_SECONDS],
            ['EXPIRE', self::STATE_PREFIX . $sId, self::TTL_SECONDS],
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

    // Los favoritos que manda el navegador, por red: solo redes que existen e identificadores con forma de id.
    public static function favorites(mixed $aInput): array
    {
        $aFavorites = [];
        if (!is_array($aInput)) {
            return $aFavorites;
        }
        $iLeft = self::MAX_TOTAL_FAVORITES;
        foreach (Networks::slugs() as $sNetwork) {
            $aEntry = $aInput[$sNetwork] ?? null;
            if (!is_array($aEntry)) {
                continue;
            }
            $aLines = array_slice(self::ids($aEntry['lines'] ?? []), 0, $iLeft);
            $aStops = array_slice(self::ids($aEntry['stops'] ?? []), 0, $iLeft - count($aLines));
            $iLeft -= count($aLines) + count($aStops);
            if (!empty($aLines) || !empty($aStops)) {
                $aFavorites[$sNetwork] = ['lines' => $aLines, 'stops' => $aStops];
            }
        }
        return $aFavorites;
    }

    // Lo llama el cron cada minuto; trabaja una vez cada 5. $Sender manda el mensaje y devuelve el código HTTP del
    // servicio de push; $Fetcher da los avisos de una red, línea o parada (null si la fuente falla). En las pruebas,
    // los dos son falsos.
    public static function process(callable|null $Sender = null, callable|null $Fetcher = null): array
    {
        $aCounts = ['subscribers' => 0, 'sent' => 0, 'removed' => 0];
        // Cada comando del almacén cuenta: la lista de suscriptores solo se pide cuando toca revisar.
        $aRun = Store::pipeline([['SET', self::RUN_KEY, (string)time(), 'NX', 'EX', self::RUN_EVERY_SECONDS]]);
        if ($aRun === null || $aRun[0] === null) {
            return ['skipped' => true];
        }
        $aIds = Store::pipeline([['SMEMBERS', self::SUBSCRIBERS_KEY]]);
        if ($aIds === null || !is_array($aIds[0]) || empty($aIds[0])) {
            return $aCounts;
        }
        $aIds = $aIds[0];
        $Sender ??= Push::sender(self::PUSH_TTL_SECONDS);
        if ($Sender === null) {
            return $aCounts;
        }
        $sNetwork = Config::current()['network'];
        $Fetcher ??= function (string $sNetwork, string|null $sLine, string|null $sStop): array {
            Config::set($sNetwork);
            return (new AlertsController())->alerts($sLine, $sStop);
        };
        $aCache = [];
        $iBudget = self::MAX_SENDS_PER_RUN;

        foreach (array_chunk($aIds, self::CHUNK) as $aChunk) {
            $aKeys = [];
            foreach ($aChunk as $sId) {
                $aKeys[] = self::SUBSCRIBER_PREFIX . $sId;
                $aKeys[] = self::STATE_PREFIX . $sId;
            }
            $aValues = Store::pipeline([array_merge(['MGET'], $aKeys)]);
            if ($aValues === null || !is_array($aValues[0])) {
                break;
            }
            $aSaves = [];
            foreach ($aChunk as $iIndex => $sId) {
                $aRecord = self::decode($aValues[0][$iIndex * 2] ?? null);
                if ($aRecord === null) {
                    // Caducado: ya no abre la app desde hace 90 días.
                    self::forget($sId);
                    continue;
                }
                $aCounts['subscribers']++;
                $aState = self::decode($aValues[0][$iIndex * 2 + 1] ?? null) ?? [];
                $aState += ['seen' => [], 'base' => []];
                $sBefore = Push::json($aState);
                $aFresh = self::freshAlerts($aRecord['favorites'] ?? [], $aState, $Fetcher, $aCache);
                $sOutcome = 'kept';
                if (!empty($aFresh) && $iBudget > 0) {
                    [$sOutcome, $iSent] = self::deliver($aRecord, $aFresh, $aState, $Sender);
                    $iBudget -= max(1, $iSent);
                    $aCounts['sent'] += $iSent;
                }
                if ($sOutcome === 'gone') {
                    self::forget($sId);
                    $aCounts['removed']++;
                    continue;
                }
                // Solo se guarda si cambia algo (una incidencia nueva, un favorito nuevo…).
                $sAfter = Push::json(self::pruned($aState));
                if ($sAfter !== $sBefore) {
                    $aSaves[] = ['SET', self::STATE_PREFIX . $sId, $sAfter, 'EX', self::TTL_SECONDS];
                }
            }
            if (!empty($aSaves)) {
                Store::pipeline($aSaves);
            }
        }
        Config::set($sNetwork);
        return $aCounts;
    }

    // La misma clave que usa la app para cada incidencia (js/alerts-store.js): red y un resumen (djb2) del título y
    // el texto, contado en unidades UTF-16 como en JavaScript. Así, si llegan las dos, la notificación se sustituye.
    public static function key(string $sNetwork, string $sTitle, string $sBody): string
    {
        $iValue = 5381;
        $aUnits = unpack('n*', mb_convert_encoding($sTitle . '|' . $sBody, 'UTF-16BE', 'UTF-8'));
        if ($aUnits === false) {
            $aUnits = [];
        }
        foreach ($aUnits as $iUnit) {
            $iValue = (($iValue << 5) + $iValue + $iUnit) & 0xFFFFFFFF;
        }
        return $sNetwork . ':' . base_convert((string)$iValue, 10, 36);
    }

    public static function message(string $sNetwork, array $aAlert, string $sLang): array
    {
        $aNetwork = Networks::get($sNetwork);
        return [
            'title' => Networks::text($aNetwork, 'name', $sLang) . ' · ' . $aAlert['title'],
            'body' => mb_substr($aAlert['body'], 0, self::BODY_LENGTH),
            'url' => '/?red=' . $sNetwork,
            'tag' => $aAlert['key'],
            'icon' => '/' . $aNetwork['brand']['icons'] . '/icon-192.png',
        ];
    }

    // Incidencias que este suscriptor no ha visto. Una fuente (línea, parada o red) que se mira por primera vez solo
    // se anota; una que falla no cambia nada y se vuelve a mirar la próxima vez.
    private static function freshAlerts(array $aFavorites, array &$aState, callable $Fetcher, array &$aCache): array
    {
        $aFresh = [];
        $aCurrent = [];
        foreach ($aFavorites as $sNetwork => $aFavorite) {
            if (!Networks::exists($sNetwork)) {
                continue;
            }
            foreach (self::sources($sNetwork, $aFavorite) as [$sSource, $sLine, $sStop]) {
                $sCacheKey = $sNetwork . '|' . $sSource;
                $aCurrent[$sCacheKey] = true;
                if (!array_key_exists($sCacheKey, $aCache)) {
                    $aCache[$sCacheKey] = self::fetch($Fetcher, $sNetwork, $sLine, $sStop);
                }
                if ($aCache[$sCacheKey] === null) {
                    continue;
                }
                $bBaselined = isset($aState['base'][$sCacheKey]);
                foreach ($aCache[$sCacheKey] as $aAlert) {
                    if (isset($aState['seen'][$aAlert['key']])) {
                        // Una incidencia que sigue activa se renueva cada día, para no olvidarla al recortar lo visto.
                        if (time() - $aState['seen'][$aAlert['key']] > self::SEEN_REFRESH_SECONDS) {
                            $aState['seen'][$aAlert['key']] = time();
                        }
                        continue;
                    }
                    if (isset($aFresh[$aAlert['key']])) {
                        continue;
                    }
                    if ($bBaselined) {
                        $aFresh[$aAlert['key']] = $aAlert + ['network' => $sNetwork];
                    } else {
                        $aState['seen'][$aAlert['key']] = time();
                    }
                }
                if (!$bBaselined) {
                    $aState['base'][$sCacheKey] = true;
                }
            }
        }
        // Un favorito que se quita y se vuelve a poner empieza de nuevo sin avisar de lo que ya había.
        $aState['base'] = array_intersect_key($aState['base'], $aCurrent);
        return array_values($aFresh);
    }

    // Igual que la app: en Bizkaibus, por línea; en Renfe, por estación y por línea; en el resto, toda la red.
    private static function sources(string $sNetwork, array $aFavorite): array
    {
        $aLines = $aFavorite['lines'] ?? [];
        $aStops = $aFavorite['stops'] ?? [];
        $sMode = Networks::feature(Networks::get($sNetwork), 'alerts');
        if ($sMode === 'lines') {
            return array_map(fn($sLine) => ['line:' . $sLine, $sLine, null], $aLines);
        }
        if ($sMode === 'stops') {
            return array_merge(
                array_map(fn($sStop) => ['stop:' . $sStop, null, $sStop], $aStops),
                array_map(fn($sLine) => ['line:' . $sLine, $sLine, null], $aLines)
            );
        }
        if (empty($aLines) && empty($aStops)) {
            return [];
        }
        return [['*', null, null]];
    }

    private static function fetch(callable $Fetcher, string $sNetwork, string|null $sLine, string|null $sStop): array|null
    {
        try {
            $aRaw = $Fetcher($sNetwork, $sLine, $sStop);
        } catch (\Throwable $Ex) {
            return null;
        }
        if (!is_array($aRaw)) {
            return null;
        }
        $aAlerts = [];
        foreach ($aRaw as $aAlert) {
            $sTitle = (string)($aAlert['summary'] ?? '');
            if ($sTitle === '') {
                $sTitle = (string)($aAlert['title'] ?? '');
            }
            if ($sTitle === '') {
                $sTitle = self::UNTITLED;
            }
            $sBody = preg_replace(self::EDGE_SPACES, '', (string)($aAlert['description'] ?? '')) ?? '';
            $aAlerts[] = ['title' => $sTitle, 'body' => $sBody, 'key' => self::key($sNetwork, $sTitle, $sBody)];
        }
        return $aAlerts;
    }

    // Manda hasta tres incidencias sueltas y, si hay más, un resumen. Lo enviado queda visto; si el servicio de push
    // falla, lo que falte se reintenta en la próxima vuelta. Devuelve gone si la suscripción ya no existe.
    private static function deliver(array $aRecord, array $aFresh, array &$aState, callable $Sender): array
    {
        $sLang = Push::lang($aRecord['lang'] ?? 'es');
        $aMessages = [];
        foreach (array_slice($aFresh, 0, self::MAX_NOTIFICATIONS) as $aAlert) {
            $aMessages[] = [self::message($aAlert['network'], $aAlert, $sLang), [$aAlert]];
        }
        $aRest = array_slice($aFresh, self::MAX_NOTIFICATIONS);
        if (!empty($aRest)) {
            $aTexts = self::TEXTS[$sLang];
            $aMessages[] = [[
                'title' => $aTexts['moreTitle'],
                'body' => strtr($aTexts['moreBody'], ['{n}' => (string)count($aRest)]),
                'url' => '/?red=' . $aRest[0]['network'],
                'tag' => 'bide-more-alerts',
            ], $aRest];
        }
        $iSent = 0;
        foreach ($aMessages as [$aMessage, $aCovered]) {
            $iStatus = (int)$Sender($aRecord['subscription'], Push::json($aMessage));
            if ($iStatus === 404 || $iStatus === 410) {
                return ['gone', $iSent];
            }
            if ($iStatus < 200 || $iStatus >= 300) {
                break;
            }
            $iSent++;
            foreach ($aCovered as $aAlert) {
                $aState['seen'][$aAlert['key']] = time();
            }
        }
        return ['kept', $iSent];
    }

    private static function pruned(array $aState): array
    {
        if (count($aState['seen']) > self::MAX_SEEN) {
            arsort($aState['seen']);
            $aState['seen'] = array_slice($aState['seen'], 0, self::MAX_SEEN, true);
        }
        return $aState;
    }

    private static function forget(string $sId): bool
    {
        return Store::pipeline([
            ['DEL', self::SUBSCRIBER_PREFIX . $sId],
            ['DEL', self::STATE_PREFIX . $sId],
            ['SREM', self::SUBSCRIBERS_KEY, $sId],
        ]) !== null;
    }

    private static function id(string $sEndpoint): string
    {
        return substr(hash('sha256', $sEndpoint), 0, 32);
    }

    private static function ids(mixed $aInput): array
    {
        if (!is_array($aInput)) {
            return [];
        }
        $aIds = [];
        foreach ($aInput as $Id) {
            if ((is_string($Id) || is_int($Id)) && preg_match(self::ID_PATTERN, (string)$Id)) {
                $aIds[(string)$Id] = true;
            }
        }
        // array_keys da números para ids numéricos: se vuelven a texto.
        return array_slice(array_map('strval', array_keys($aIds)), 0, self::MAX_FAVORITES);
    }

    private static function decode(mixed $sValue): array|null
    {
        if (!is_string($sValue)) {
            return null;
        }
        $aValue = json_decode($sValue, true);
        if (!is_array($aValue)) {
            return null;
        }
        return $aValue;
    }
}
