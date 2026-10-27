<?php

namespace Core;

use Models\ServiceJourney;

// Elige el motor de llegadas de la red activa (clave «engine» de su ficha). Sin motor o sin directo,
// devuelve uno sin datos en vivo: el horario oficial pasa tal cual.
class Live
{
    private static function engineClass(string $sName): string|null
    {
        $sEngine = Config::current()['engine'] ?? null;
        if ($sEngine === null || !Config::hasLive('vehicle-monitoring')) {
            return null;
        }
        return 'Engine\\' . ucfirst($sEngine) . '\\' . $sName;
    }

    public static function vehicles(): array
    {
        $sClass = self::engineClass('Live');
        return $sClass === null ? [] : $sClass::vehicles(Config::current());
    }

    public static function matcher(ServiceJourney|null $JourneyModel, array|null $aVehicles = null): object
    {
        $sClass = self::engineClass('Live');
        if ($sClass === null) {
            return new \Engine\Bizkaibus\RealtimeMatcher([], $JourneyModel);
        }
        return $sClass::matcher($aVehicles ?? self::vehicles(), $JourneyModel);
    }
}
