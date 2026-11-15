<?php

namespace Core;

// Una sola dirección por página: sin barra final y con la parte fija en minúsculas («/MAPA», «/Stops/12/»). Los ids se
// quedan como vienen: los de Euskotren, SNCF o Txik Txak llevan mayúsculas («ES:Euskotren:StopPlace:2577:»).
class PagePath
{
    // Las páginas sin id y las que llevan uno, con cuántos tramos puede tener su dirección (/lines/MB/L1: tres).
    private const FIXED = ['mapa', 'estado'];
    private const WITH_ID = ['stops' => 2, 'lines' => 3, 'pantalla' => 2];

    // La dirección buena de una página, o null si no es ninguna (404).
    public static function canonical(string $sPath): string|null
    {
        $sTrimmed = rtrim($sPath, '/');
        if ($sTrimmed === '') {
            return '/';
        }
        $aParts = explode('/', substr($sTrimmed, 1));
        $aParts[0] = strtolower($aParts[0]);
        if (in_array($aParts[0], self::FIXED, true) && count($aParts) === 1) {
            return '/' . $aParts[0];
        }
        $iMaxParts = self::WITH_ID[$aParts[0]] ?? 0;
        if (count($aParts) < 2 || count($aParts) > $iMaxParts || in_array('', $aParts, true)) {
            return null;
        }
        return '/' . implode('/', $aParts);
    }
}
