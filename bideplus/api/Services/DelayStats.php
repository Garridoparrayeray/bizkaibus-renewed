<?php

namespace Services;

class DelayStats
{
    // Retrasos en segundos => mediana y percentil 95 del valor absoluto, mínimo y máximo, en minutos. Null sin datos.
    public static function summary(array $aDelays): array|null
    {
        $iCount = \count($aDelays);
        if ($iCount === 0) {
            return null;
        }
        sort($aDelays);
        $aAbs = array_map('abs', $aDelays);
        sort($aAbs);
        return [
            'median' => (int)round($aAbs[intdiv($iCount, 2)] / 60),
            'p95' => (int)round($aAbs[min($iCount - 1, (int)floor($iCount * 0.95))] / 60),
            'min' => (int)round($aDelays[0] / 60),
            'max' => (int)round($aDelays[$iCount - 1] / 60),
        ];
    }
}
