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
        return self::cut(end($aCandidates), self::TITLE_MAX);
    }

    // El texto recortado a $iMax caracteres por el último espacio, con «…».
    private static function cut(string $sText, int $iMax): string
    {
        $sCut = mb_substr($sText, 0, $iMax - 1);
        $iSpace = mb_strrpos($sCut, ' ');
        if ($iSpace !== false) {
            $sCut = mb_substr($sCut, 0, $iSpace);
        }
        return rtrim($sCut, ' ,.;:·|-') . '…';
    }

    // La primera frase, las siguientes que quepan y, si se queda corta, la primera de relleno que quepa.
    public static function description(array $aSentences, array $aFillers): string
    {
        $sText = array_shift($aSentences);
        if (mb_strlen($sText) > self::DESCRIPTION_MAX) {
            return self::cut($sText, self::DESCRIPTION_MAX);
        }
        foreach ($aSentences as $sSentence) {
            if (mb_strlen($sText . ' ' . $sSentence) <= self::DESCRIPTION_MAX) {
                $sText .= ' ' . $sSentence;
            }
        }
        // Mientras se quede corta: la frase de relleno más corta que la deja en su medida o, si ninguna llega, la más
        // larga que cabe (y se sigue con las demás).
        while (mb_strlen($sText) < self::DESCRIPTION_MIN && !empty($aFillers)) {
            $aFits = array_filter($aFillers, fn($sFiller) => mb_strlen($sText . ' ' . $sFiller) <= self::DESCRIPTION_MAX);
            if (empty($aFits)) {
                break;
            }
            $aEnough = array_filter($aFits, fn($sFiller) => mb_strlen($sText . ' ' . $sFiller) >= self::DESCRIPTION_MIN);
            usort($aEnough, fn($sA, $sB) => mb_strlen($sA) <=> mb_strlen($sB));
            usort($aFits, fn($sA, $sB) => mb_strlen($sB) <=> mb_strlen($sA));
            $sChosen = $aEnough[0] ?? $aFits[0];
            $sText .= ' ' . $sChosen;
            $aFillers = array_values(array_diff($aFillers, [$sChosen]));
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
