<?php

namespace Core;

// Los títulos y las descripciones para los buscadores: la palabra clave delante y medidas que Google enseña enteras.
class Seo
{
    public const TITLE_MAX = 60;
    public const DESCRIPTION_MIN = 120;
    public const DESCRIPTION_MAX = 155;

    // El primer título que cabe, de más completo a más corto; si ninguno cabe, el último.
    public static function title(array $aCandidates): string
    {
        foreach ($aCandidates as $sCandidate) {
            if (mb_strlen($sCandidate) <= self::TITLE_MAX) {
                return $sCandidate;
            }
        }
        return end($aCandidates);
    }

    // La primera frase, las siguientes que quepan y, si se queda corta, la primera de relleno que quepa.
    public static function description(array $aSentences, array $aFillers): string
    {
        $sText = array_shift($aSentences);
        foreach ($aSentences as $sSentence) {
            if (mb_strlen($sText . ' ' . $sSentence) <= self::DESCRIPTION_MAX) {
                $sText .= ' ' . $sSentence;
            }
        }
        foreach ($aFillers as $sFiller) {
            if (mb_strlen($sText) < self::DESCRIPTION_MIN && mb_strlen($sText . ' ' . $sFiller) <= self::DESCRIPTION_MAX) {
                $sText .= ' ' . $sFiller;
            }
        }
        return $sText;
    }

    // El municipio de una parada: el último trozo de su zona («Iparralde, Bilbao» → «Bilbao»).
    public static function municipality(string $sArea): string
    {
        $aParts = array_map('trim', explode(',', $sArea));
        return end($aParts);
    }
}
