<?php

namespace Services;

use Core\Cache;
use Core\CacheSeconds;
use Core\Http;

// El aviso general que publica el dueño de la app desde GitHub (Actions, «Aviso a los usuarios»): un archivo
// notice.json en una release pública. Aquí se lee (un minuto de caché), se valida y se da a la app; pasada su hora de
// fin ya no sale.
class Notice
{
    private const LANGS = ['es', 'eu', 'en'];
    private const MAX_TEXT_LENGTH = 400;
    private const MAX_ID_LENGTH = 40;

    // El aviso publicado ahora, con la dirección de api/Config/site.php.
    public static function published(int $iNow): array|null
    {
        $sUrl = (require __DIR__ . '/../Config/site.php')['notice_url'];
        $aRaw = Cache::remember('notice', CacheSeconds::MINUTE, fn() => self::fetch($sUrl));
        return self::fromArray($aRaw, $iNow);
    }

    private static function fetch(string $sUrl): array
    {
        $aDecoded = json_decode(Http::get($sUrl, 5), true);
        if (!is_array($aDecoded)) {
            return [];
        }
        return $aDecoded;
    }

    // El aviso listo para la app, o null si no hay, está mal escrito o ya ha caducado.
    public static function fromArray(array $aRaw, int $iNow): array|null
    {
        $sId = $aRaw['id'] ?? null;
        if (!is_string($sId) || !preg_match('/^[A-Za-z0-9._-]{1,' . self::MAX_ID_LENGTH . '}$/', $sId)) {
            return null;
        }
        $iExpiresAt = $aRaw['expiresAt'] ?? null;
        if (!is_int($iExpiresAt) || $iExpiresAt <= $iNow) {
            return null;
        }
        $aText = self::texts($aRaw['text'] ?? null);
        if (!isset($aText['es'])) {
            return null;
        }
        return [
            'id' => $sId,
            'text' => $aText,
            'url' => self::webAddress($aRaw['url'] ?? null),
            'expiresAt' => $iExpiresAt,
            'notify' => ($aRaw['notify'] ?? false) === true,
        ];
    }

    // Los idiomas que traen texto; el castellano es obligatorio y los demás caen en él.
    private static function texts(mixed $aText): array
    {
        if (!is_array($aText)) {
            return [];
        }
        $aClean = [];
        foreach (self::LANGS as $sLang) {
            $sText = $aText[$sLang] ?? null;
            if (is_string($sText) && trim($sText) !== '') {
                $aClean[$sLang] = mb_substr(trim($sText), 0, self::MAX_TEXT_LENGTH);
            }
        }
        return $aClean;
    }

    private static function webAddress(mixed $Url): string|null
    {
        if (!is_string($Url) || !filter_var($Url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $sScheme = parse_url($Url, PHP_URL_SCHEME);
        if ($sScheme !== 'https' && $sScheme !== 'http') {
            return null;
        }
        return $Url;
    }
}
