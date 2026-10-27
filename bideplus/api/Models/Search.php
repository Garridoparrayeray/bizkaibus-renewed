<?php

namespace Models;

class Search
{
    public static function normalize(string $sText): string
    {
        $aMap = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
            'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
            'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
            'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
            'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
            'Ñ' => 'n', 'Ç' => 'c',
        ];
        $sLower = mb_strtolower(strtr($sText, $aMap), 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $sLower));
    }

    // Palabras de la búsqueda («amorebieta san juan» -> amorebieta, san, juan), sin signos y sin repetir.
    public static function words(string $sQuery): array
    {
        $aWords = preg_split('/[^\p{L}\p{N}]+/u', self::normalize($sQuery), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique($aWords));
    }

    // Condición SQL: cada palabra aparece en alguna de las columnas (en cualquier orden).
    public static function wordsCondition(array $aWords, string $sHaystack): array
    {
        $aParts = [];
        $aArgs = [];
        foreach ($aWords as $sWord) {
            $aParts[] = $sHaystack . ' LIKE ?';
            $aArgs[] = '%' . $sWord . '%';
        }
        return [implode(' AND ', $aParts) ?: '1', $aArgs];
    }
}
