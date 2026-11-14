<?php

namespace Controllers;

use Core\Config;
use Core\Networks;
use Core\Request;
use Core\Response;
use Services\Calendar;

// El directo del planificador del inicio (/api/planner/live?legs=red|parada|línea|hora|viaje,...): el retraso de
// los vehículos de las opciones que se ven, con el emparejado de las próximas salidas de cada red, en el formato de la
// operación «live» del núcleo. Lo que no va en directo no sale: el planificador lo deja con su horario.
class PlannerController
{
    private const MAX_LEGS = 40;
    private const LEG_SEPARATOR = ',';
    private const PART_SEPARATOR = '|';
    private const LEG_PARTS = 5;
    // Lo más que se cree que va adelantado un vehículo: un bus que espera en cabecera parece ir muy adelantado sin
    // estarlo, y dar por hecho que pasa antes haría perderlo. Los retrasos van enteros.
    private const MAX_ADVANCE_SECONDS = 120;

    public function live(Request $Req, array $aParams): void
    {
        $aByNetwork = self::legsByNetwork((string)$Req->query('legs', ''));
        if ($aByNetwork === null) {
            Response::error('Invalid legs', 422);
            return;
        }
        $aUpdates = [];
        $sCurrent = Config::network();
        try {
            foreach ($aByNetwork as $sNetwork => $aLegs) {
                array_push($aUpdates, ...self::networkUpdates($sNetwork, $aLegs));
            }
        } finally {
            Config::set($sCurrent);
        }
        Response::json(['day' => self::serviceDay(), 'generated' => time(), 'updates' => $aUpdates]);
    }

    // red => parada => [[línea, hora en la parada, viaje]], de «red|parada|línea|hora|viaje» separados por comas (cada
    // parte con su escape de URL). Las redes que la app no tiene se pasan por alto; null si sobran o hay uno mal escrito.
    public static function legsByNetwork(string $sLegs): array|null
    {
        $aByNetwork = [];
        if ($sLegs === '') {
            return $aByNetwork;
        }
        $aItems = explode(self::LEG_SEPARATOR, $sLegs);
        if (count($aItems) > self::MAX_LEGS) {
            return null;
        }
        foreach ($aItems as $sItem) {
            $aParts = array_map('rawurldecode', explode(self::PART_SEPARATOR, $sItem));
            if (count($aParts) !== self::LEG_PARTS || in_array('', $aParts, true) || !ctype_digit($aParts[3])) {
                return null;
            }
            [$sNetwork, $sStop, $sLine, $sSeconds, $sTrip] = $aParts;
            if (Networks::exists($sNetwork)) {
                $aByNetwork[$sNetwork][$sStop][] = [$sLine, (int)$sSeconds, $sTrip];
            }
        }
        return $aByNetwork;
    }

    // Los viajes de una red que van en directo o cancelados. Si su directo falla, la red se queda con su horario.
    private static function networkUpdates(string $sNetwork, array $aLegs): array
    {
        try {
            Config::set($sNetwork);
            return self::updatesOf($sNetwork, StopsController::liveLegs($aLegs));
        } catch (\Throwable $Ex) {
            error_log('Bide+ directo del planificador (' . $sNetwork . '): ' . $Ex->getMessage());
            return [];
        }
    }

    public static function updatesOf(string $sNetwork, array $aLive): array
    {
        $aUpdates = [];
        foreach ($aLive as $sTrip => $aRow) {
            if ($aRow['status'] === 'live') {
                $iDelay = max(-self::MAX_ADVANCE_SECONDS, (int)$aRow['delaySeconds']);
                $aUpdates[] = ['network' => $sNetwork, 'trip' => (string)$sTrip, 'delay' => $iDelay, 'cancelled' => false];
            } elseif ($aRow['status'] === 'cancelled') {
                $aUpdates[] = ['network' => $sNetwork, 'trip' => (string)$sTrip, 'delay' => 0, 'cancelled' => true];
            }
        }
        return $aUpdates;
    }

    // El día de servicio de hoy como lo cuenta el planificador: días desde el 1 de enero de 1970.
    private static function serviceDay(): int
    {
        $Midnight = new \DateTimeImmutable(Calendar::todayMadrid()->format('Y-m-d'), new \DateTimeZone('UTC'));
        return intdiv($Midnight->getTimestamp(), Calendar::DAY_SECONDS);
    }
}
