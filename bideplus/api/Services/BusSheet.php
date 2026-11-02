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
    public static function build(ServiceJourney $JourneyModel, array $aTripKey, array $aVariants, string|null $sFromStopId): array
    {
        $Matcher = Live::matcher($JourneyModel);
        [$aJourney, $aLive] = self::liveVariant($Matcher, $aTripKey, $aVariants);
        $bLive = $Matcher->isLive($aJourney['id'], $aLive);
        $aPosition = $Matcher->positionFor($aJourney['id'], $aLive);
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();
        $iNextSeqOrder = self::nextSeqOrder($aStops, $aPosition, $aLive, $bLive);

        $aStopsOut = [];
        foreach ($aStops as $aStop) {
            $bPast = self::passed($aStop, $aPosition, $aLive, $bLive);
            $iEta = null;
            if (!$bPast) {
                $iEta = (int)$aStop['arrival_seconds'];
            }
            if (!$bPast && $bLive) {
                [$iEta] = $Matcher->etaForStop($aJourney['id'], (int)$aStop['arrival_seconds'], $aLive, (int)$aStop['seq_order']);
            }
            $bCurrent = $iNextSeqOrder !== null && (int)$aStop['seq_order'] === $iNextSeqOrder;
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
        return TripSheet::sheet($aJourney, $sStatus, $sVehicleRef, $iDelayMinutes, $aStopsOut);
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

    // Con GPS, las paradas de antes de su posición; sin GPS, las de antes de la parada que registró.
    private static function passed(array $aStop, array|null $aPosition, array|null $aLive, bool $bLive): bool
    {
        if ($aPosition !== null) {
            return (int)$aStop['arrival_seconds'] < $aPosition['scheduledSeconds'];
        }
        if ($bLive && isset($aLive['order'])) {
            return (int)$aStop['seq_order'] < (int)$aLive['order'];
        }
        return false;
    }

    // Orden de la siguiente parada del bus: por su posición GPS o, sin GPS, la última parada que registró.
    private static function nextSeqOrder(array $aStops, array|null $aPosition, array|null $aLive, bool $bLive): int|null
    {
        foreach ($aStops as $aStop) {
            if ($aPosition !== null && (int)$aStop['arrival_seconds'] >= $aPosition['scheduledSeconds']) {
                return (int)$aStop['seq_order'];
            }
            if ($aPosition === null && $bLive && $aStop['stop_id'] === $aLive['currentStopId']) {
                return (int)$aStop['seq_order'];
            }
        }
        return null;
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
