<?php

namespace Core;

// El idioma de una página para los buscadores: con ?lang=eu, ?lang=en o ?lang=fr el servidor la entrega ya traducida (los mismos
// textos de js/i18n.js y de las fichas de las redes), con su lang, su canónica y los enlaces a las otras versiones.
// Sin ?lang, en castellano, como siempre; en el navegador manda el idioma elegido (js/i18n.js).
class Lang
{
    public const LANGS = ['es', 'eu', 'en', 'fr'];
    public const LOCALES = ['es' => 'es_ES', 'eu' => 'eu_ES', 'en' => 'en_GB', 'fr' => 'fr_FR'];
    private const I18N_FILE = __DIR__ . '/../../js/i18n.js';

    private static array $aDictionaries = [];

    public static function current(): string
    {
        $sLang = $_GET['lang'] ?? 'es';
        if (!in_array($sLang, self::LANGS, true)) {
            return 'es';
        }
        return $sLang;
    }

    // La misma dirección en otro idioma: el castellano sin ?lang, los demás con ?lang= (junto a ?red= si lo lleva).
    public static function url(string $sUrl, string $sLang): string
    {
        $sBase = preg_replace('/([?&])lang=[a-z]+(&|$)/', '$1', $sUrl);
        $sBase = rtrim($sBase, '?&');
        if ($sLang === 'es') {
            return $sBase;
        }
        $sGlue = '?';
        if (str_contains($sBase, '?')) {
            $sGlue = '&';
        }
        return $sBase . $sGlue . 'lang=' . $sLang;
    }

    // Los <link rel="alternate" hreflang> de las versiones de una página, y la del castellano como x-default.
    public static function alternates(string $sCanonical): string
    {
        $sHtml = '';
        foreach (self::LANGS as $sLang) {
            $sHtml .= '<link rel="alternate" hreflang="' . $sLang . '" href="' . htmlspecialchars(self::url($sCanonical, $sLang)) . '">' . "\n    ";
        }
        return $sHtml . '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars(self::url($sCanonical, 'es')) . '">' . "\n";
    }

    // Un texto de js/i18n.js (o de la ficha de una red, net.<red>.<campo>) en el idioma pedido; sin él, en castellano.
    public static function t(string $sKey, string $sLang, array $aVars = []): string
    {
        $sText = self::dictionary($sLang)[$sKey] ?? self::dictionary('es')[$sKey] ?? $sKey;
        foreach ($aVars as $sName => $sValue) {
            $sText = str_replace('{' . $sName . '}', (string)$sValue, $sText);
        }
        return $sText;
    }

    public static function dictionary(string $sLang): array
    {
        if (!isset(self::$aDictionaries[$sLang])) {
            self::$aDictionaries[$sLang] = self::readI18n($sLang) + self::networkTexts($sLang);
        }
        return self::$aDictionaries[$sLang];
    }

    // La página ya hecha, traducida como lo haría js/i18n.js en el navegador: el texto de cada [data-i18n], los
    // atributos de cada [data-i18n-attr] y el lang de <html>.
    public static function translateHtml(string $sHtml, string $sLang): string
    {
        if ($sLang === 'es') {
            return $sHtml;
        }
        $aDictionary = self::dictionary($sLang);
        $sHtml = preg_replace('/<html lang="es"/', '<html lang="' . $sLang . '"', $sHtml, 1);
        $sHtml = preg_replace_callback('/(<[a-zA-Z][^<>]*\sdata-i18n="([^"]+)"[^<>]*>)([^<]*)/', function (array $aMatch) use ($aDictionary): string {
            if (!isset($aDictionary[$aMatch[2]])) {
                return $aMatch[0];
            }
            return $aMatch[1] . htmlspecialchars($aDictionary[$aMatch[2]], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $sHtml);
        return preg_replace_callback('/<[a-zA-Z][^<>]*\sdata-i18n-attr="([^"]+)"[^<>]*>/', function (array $aMatch) use ($aDictionary): string {
            $sTag = $aMatch[0];
            foreach (explode(',', $aMatch[1]) as $sPair) {
                [$sAttribute, $sKey] = array_pad(explode(':', $sPair, 2), 2, '');
                if (!isset($aDictionary[$sKey]) || !preg_match('/^[a-z-]+$/', $sAttribute)) {
                    continue;
                }
                $sValue = htmlspecialchars($aDictionary[$sKey], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $sTag = preg_replace('/(\s' . $sAttribute . '=")[^"]*(")/', '${1}' . addcslashes($sValue, '\\$') . '${2}', $sTag, 1);
            }
            return $sTag;
        }, $sHtml);
    }

    // Los textos de un idioma de js/i18n.js: cada línea es «'clave': 'texto',» dentro de su bloque «es: {», «eu: {»…
    private static function readI18n(string $sLang): array
    {
        $aTexts = [];
        $bInside = false;
        foreach (file(self::I18N_FILE) as $sLine) {
            if (preg_match('/^\s*(' . implode('|', self::LANGS) . '): \{\s*$/', $sLine, $aMatch)) {
                $bInside = $aMatch[1] === $sLang;
                continue;
            }
            if ($bInside && preg_match("/^\s*'([^']+)':\s*'((?:\\\\.|[^'\\\\])*)',?\s*$/", $sLine, $aMatch)) {
                $aTexts[$aMatch[1]] = stripcslashes($aMatch[2]);
            }
        }
        return $aTexts;
    }

    private static function networkTexts(string $sLang): array
    {
        $aTexts = [];
        foreach (Networks::all() as $sSlug => $aNetwork) {
            foreach ($aNetwork['texts'][$sLang] ?? [] as $sKey => $sText) {
                $aTexts['net.' . $sSlug . '.' . $sKey] = $sText;
            }
        }
        return $aTexts;
    }
}
