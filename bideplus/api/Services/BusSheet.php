<?php

namespace Services;

use Core\Config;
use Core\Ids;
use Core\Live;
use Models\ServiceJourney;

// Ficha de un viaje de autobús: el algoritmo (engine/) sitúa el bus con su GPS o con la última parada que registró, y
// de ahí salen las paradas pasadas, la siguiente, la llegada a cada una y el retraso.
class BusSheet
{
    // Hasta 2 min después de su hora, un viaje sin bus puede estar a punto de salir.
    private const AWAITING_GRACE_SECONDS = 120;

    // $aLateVariants: las de ayer del viaje que pasan de las 24:00 y aún no han terminado (de madrugada).
    public static function build(ServiceJourney $JourneyModel, array $aTripKey, array $aVariants, string|null $sFromStopId, array $aLateVariants = []): array
    {
        $aVehicles = Live::vehicles();
        $Matcher = Live::matcher($JourneyModel, $aVehicles);
        [$aJourney, $aLive] = self::liveVariant($Matcher, $aTripKey, $aVariants);
        $iDaysAgo = 0;
        // Si ningún bus lo hace hoy, es el de ayer: con su emparejador y en las horas de su horario (00:30 son 24:30).
        if ($aLive === null && !empty($aLateVariants)) {
            $iDaysAgo = 1;
            $Matcher = Live::matcher($JourneyModel, $aVehicles, 1);
            [$aJourney, $aLive] = self::liveVariant($Matcher, $aTripKey, $aLateVariants);
        }
        $bLive = $Matcher->isLive($aJourney['id'], $aLive);
        $aPosition = $Matcher->positionFor($aJourney['id'], $aLive);
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight() + $iDaysAgo * Calendar::DAY_SECONDS;
        $aStopsOut = [];
        $bCurrentFound = false;
        foreach ($aStops as $aStop) {
            $bPast = $bLive && $Matcher->hasPassed($aJourney['id'], $aLive, (int)$aStop['arrival_seconds'], (int)$aStop['seq_order']);
            $iEta = null;
            if (!$bPast) {
                $iEta = (int)$aStop['arrival_seconds'];
            }
            if (!$bPast && $bLive) {
                [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order']);
                if ($aPosition !== null) {
                    $iEta = max($iEta, $iNow);
                }
            }
            $bCurrent = $bLive && !$bPast && !$bCurrentFound;
            $bCurrentFound = $bCurrentFound || $bCurrent;
            $aStopsOut[] = TripSheet::stopEntry($aStop, $iEta, $bPast, $bCurrent, $iNow);
        }

        $iDelaySeconds = 0;
        $sVehicleRef = null;
        if ($bLive) {
            $iDelaySeconds = self::delaySeconds($Matcher, $aJourney['id'], $aLive, $aStops, $aStopsOut, $sFromStopId);
            $sVehicleRef = $aLive['vehicleRef'] ?? null;
        }
        $sStatus = TripSheet::status($bLive, $aStops, $iNow);
        $iDelayMinutes = $Matcher->displayDelayMinutes($iDelaySeconds);

        // Sin GPS (solo la última parada registrada) es una estimación, como en la tarjeta de la parada: ni «en
        // directo» ni adelanto o retraso.
        if ($sStatus === 'live' && $aPosition === null && Config::hasLive('vehicle-monitoring')) {
            $sStatus = 'estimated';
            $iDelayMinutes = 0;
            $sVehicleRef = null;
        }
        $aExtra = ['awaitingVehicle' => self::awaitingVehicle($sStatus, $aVehicles, (int)$aTripKey[2], $iNow)];
        return TripSheet::sheet($aJourney, $sStatus, $sVehicleRef, $iDelayMinutes, $aStopsOut, $aExtra);
    }

    // Un viaje sin bus que aún no ha llegado a su hora de salida, en una red cuyo directo trae autobuses (con hora de
    // hoy: de madrugada el de Bizkaibus repite los de antes de medianoche): el operador todavía no le ha asignado uno
    // (Bizkaibus lo hace unos 6 min antes de la salida, la mitad de las veces).
    private static function awaitingVehicle(string $sStatus, array $aVehicles, int $iFirstDepartureSeconds, int $iNow): bool
    {
        return $sStatus === 'scheduled' && Live::isFresh($aVehicles) && Config::hasLive('vehicle-monitoring')
            && $iNow < $iFirstDepartureSeconds + self::AWAITING_GRACE_SECONDS;
    }

    // Entre las variantes del viaje (calendarios distintos), la que el directo sigue; si ninguna, la que circula hoy.
    private static function liveVariant(object $Matcher, array $aTripKey, array $aVariants): array
    {
        [$sLineId, $sTripNumber, $iFirstDepartureSeconds] = $aTripKey;
        foreach ($aVariants as $aVariant) {
            $aFound = $Matcher->lookup($sLineId, $sTripNumber, $iFirstDepartureSeconds, $aVariant['id']);
            if ($aFound !== null && $Matcher->isLive($aVariant['id'], $aFound)) {
                return [$aVariant, $aFound];
            }
        }
        return [$aVariants[0], null];
    }

    // Adelanto o retraso en la siguiente parada del bus. Abierta desde una parada que el bus aún no ha pasado, el de
    // esa parada: el mismo que la tarjeta.
    private static function delaySeconds(object $Matcher, string $sJourneyId, array $aLive, array $aStops, array $aStopsOut, string|null $sFromStopId): int
    {
        foreach ($aStops as $iIndex => $aStop) {
            if ($sFromStopId !== null && (string)Ids::forOutput($aStop['stop_id']) === $sFromStopId && !$aStopsOut[$iIndex]['isPast']) {
                return $Matcher->etaForStop($sJourneyId, (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order'])[1];
            }
        }
        return $Matcher->displayDelaySeconds($sJourneyId, $aLive);
    }
}
