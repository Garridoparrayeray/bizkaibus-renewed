<?php

namespace Core;

// Los nombres que el operador publica en mayúsculas («BILBAO INTERMODAL»), en formato título en la app y en los
// buscadores («Bilbao Intermodal»). Solo en las redes con features.title_case_names; la base y los ids no cambian.
class DisplayName
{
    // Las palabras que van en minúscula salvo al principio del nombre.
    private const SMALL_WORDS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'al', 'en', 'por', 'con'];
    // Los números romanos se quedan como están («Juan XXIII», «Pío XII»).
    private const ROMAN = '/^M{0,3}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/';
    // Una palabra sin vocales son siglas («JM», «NH», «TC»): se queda en mayúsculas.
    private const VOWELS = '/[AEIOUÁÉÍÓÚÜY]/u';
    // Los campos de las respuestas de la API que son nombres de paradas, líneas o destinos.
    private const OUTPUT_KEYS = ['name', 'lineName', 'headsign', 'hint', 'area', 'stopName', 'destination', 'label'];

    // Las palabras que se escriben tal cual (features.title_case_names.keep de la ficha: siglas y nombres como
    // «McDonalds»), o null si la red no lo usa.
    public static function keep(array $aNetwork): array|null
    {
        $Setting = $aNetwork['features']['title_case_names'] ?? false;
        if ($Setting === false) {
            return null;
        }
        return $Setting['keep'] ?? [];
    }

    public static function of(string $sName, array $aNetwork): string
    {
        $aKeep = self::keep($aNetwork);
        if ($aKeep === null) {
            return $sName;
        }
        $aExceptions = [];
        foreach ($aKeep as $sWord) {
            $aExceptions[mb_strtoupper($sWord)] = $sWord;
        }
        // Las abreviaturas de la ficha («AV» → «Av.»), sin repetir el punto si el nombre ya lo lleva.
        $aShort = $aNetwork['features']['title_case_names']['abbreviations'] ?? [];
        $Word = function (array $aMatch) use ($aExceptions, $aShort, $sName): string {
            [$sWord, $iOffset] = $aMatch[0];
            if (isset($aExceptions[$sWord])) {
                return $aExceptions[$sWord];
            }
            if (isset($aShort[$sWord])) {
                return self::abbreviation($aShort[$sWord], substr($sName, $iOffset + strlen($sWord), 1));
            }
            if (preg_match(self::ROMAN, $sWord) || !preg_match(self::VOWELS, $sWord)) {
                return $sWord;
            }
            $sLower = mb_strtolower($sWord);
            // «de», «la»… en minúscula entre palabras; tras «/», «(» o «-» empiezan nombre: «Areeta/Las Arenas».
            if ($iOffset > 0 && $sName[$iOffset - 1] === ' ' && in_array($sLower, self::SMALL_WORDS, true)) {
                return $sLower;
            }
            return mb_strtoupper(mb_substr($sLower, 0, 1)) . mb_substr($sLower, 1);
        };
        return preg_replace_callback('/\p{Lu}[\p{Lu}\']+/u', $Word, $sName, -1, $iCount, PREG_OFFSET_CAPTURE);
    }

    private static function abbreviation(string $sShort, string $sNext): string
    {
        if ($sNext === '.') {
            return rtrim($sShort, '.');
        }
        return $sShort;
    }

    // Una respuesta de la API con sus nombres ya en formato título.
    public static function inData(mixed $Data, array $aNetwork): mixed
    {
        if (self::keep($aNetwork) === null || !is_array($Data)) {
            return $Data;
        }
        foreach ($Data as $Key => $Value) {
            if (is_array($Value)) {
                $Data[$Key] = self::inData($Value, $aNetwork);
            } elseif (is_string($Value) && in_array($Key, self::OUTPUT_KEYS, true)) {
                $Data[$Key] = self::of($Value, $aNetwork);
            }
        }
        return $Data;
    }
}
