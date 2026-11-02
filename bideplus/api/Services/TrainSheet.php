<?php

namespace Services;

use Core\Live;
use Models\ServiceJourney;

// Ficha de un viaje con horas previstas (GTFS-Realtime): se empareja el viaje con el feed como en las salidas de una
// parada (mismo reparto) y se devuelve el recorrido con esas horas. Las paradas que ya no aparecen en el feed, antes de
// la primera que sí, ya están pasadas; las que no vienen después de ella llevan el retraso de la primera.
class TrainSheet
{
    public static function build(ServiceJourney $JourneyModel, array $aJourney, string|null $sPreferredStopId): array
    {
        $aStops = $JourneyModel->stopsForJourney($aJourney['id']);
        $iNow = Calendar::nowSecondsSinceMidnight();
        $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        $TripMatcher = Live::tripMatcher($JourneyModel, $iMidnight);
        $aPredicted = self::predictedSeconds($JourneyModel, $TripMatcher, $aJourney, $aStops, $sPreferredStopId, $iNow, $iMidnight);
        $iFirstPredictedOrder = null;
        foreach ($aStops as $aStop) {
            if (isset($aPredicted[(string)$aStop['stop_id']])) {
                $iFirstPredictedOrder = (int)$aStop['seq_order'];
                break;
            }
        }
        $bLive = $iFirstPredictedOrder !== null;

        $iDelaySeconds = 0;
        $bCurrentSet = false;
        $aStopsOut = [];
        foreach ($aStops as $aStop) {
            [$iEta, $bPast] = self::eta($aStop, $aPredicted, $bLive, $iFirstPredictedOrder, $iDelaySeconds, $iNow);
            // El retraso del tren es el de la primera parada que le queda según el feed.
            if (!$bPast && !$bCurrentSet && isset($aPredicted[(string)$aStop['stop_id']])) {
                $iDelaySeconds = $iEta - (int)$aStop['arrival_seconds'];
            }
            $bCurrent = !$bPast && !$bCurrentSet;
            $bCurrentSet = $bCurrentSet || $bCurrent;
            $aStopsOut[] = TripSheet::stopEntry($aStop, $iEta, $bPast, $bCurrent, $iNow);
        }

        $iDelayMinutes = 0;
        if (abs($iDelaySeconds) >= 60) {
            $iDelayMinutes = (int)round($iDelaySeconds / 60);
        }
        $sStatus = TripSheet::status($bLive, $aStops, $iNow);
        // Renfe publica los trenes cancelados: la ficha lo dice en vez de enseñar el horario como si fuera a pasar.
        if ($TripMatcher !== null && method_exists($TripMatcher, 'isCancelled') && $TripMatcher->isCancelled((string)$aJourney['id'])) {
            $sStatus = 'cancelled';
        }
        return TripSheet::sheet($aJourney, $sStatus, null, $iDelayMinutes, $aStopsOut, ['feedTimes' => true]);
    }

    // Hora prevista por el feed en cada parada que le queda al tren (segundos desde medianoche), o [] si no está.
    private static function predictedSeconds(ServiceJourney $JourneyModel, object|null $TripMatcher, array $aJourney, array $aStops, string|null $sPreferredStopId, int $iNow, int $iMidnight): array
    {
        $sAnchorStopId = self::anchorStopId($aStops, $sPreferredStopId, $iNow);
        if ($sAnchorStopId === null || $TripMatcher === null || !$TripMatcher->hasData()) {
            return [];
        }
        $aRows = $JourneyModel->upcomingAtStop($sAnchorStopId, 40, 3 * 3600);
        foreach ($aRows as $iRow => $aRow) {
            $aRows[$iRow]['status'] = 'scheduled';
        }
        $aFeedStops = $TripMatcher->liveTrip($sAnchorStopId, $aRows, $aJourney['id']);
        $aPredicted = [];
        foreach ($aFeedStops ?? [] as [$sFeedStopId, $iTime]) {
            $aPredicted[$sFeedStopId] = $iTime - $iMidnight;
        }
        return $aPredicted;
    }

    // La parada desde la que se empareja con el feed: la de la ficha si es del recorrido, o la próxima según el horario.
    private static function anchorStopId(array $aStops, string|null $sPreferredStopId, int $iNow): string|null
    {
        $aStopIds = array_map(fn($aStop) => (string)$aStop['stop_id'], $aStops);
        if ($sPreferredStopId !== null && in_array($sPreferredStopId, $aStopIds, true)) {
            return $sPreferredStopId;
        }
        foreach ($aStops as $aStop) {
            if ((int)$aStop['arrival_seconds'] >= $iNow - TripSheet::PASSED_MARGIN_SECONDS) {
                return (string)$aStop['stop_id'];
            }
        }
        return null;
    }

    // Llegada prevista a una parada del tren y si ya la ha pasado: [segundos desde medianoche o null, pasada].
    private static function eta(array $aStop, array $aPredicted, bool $bLive, int|null $iFirstPredictedOrder, int $iDelaySeconds, int $iNow): array
    {
        $sStopId = (string)$aStop['stop_id'];
        $iScheduled = (int)$aStop['arrival_seconds'];
        if (!$bLive) {
            if ($iScheduled < $iNow - TripSheet::PASSED_MARGIN_SECONDS) {
                return [null, true];
            }
            return [$iScheduled, false];
        }
        if (isset($aPredicted[$sStopId])) {
            // El feed mantiene una parada hasta ~110 s después de su hora prevista: el tren ya la ha dejado.
            if ($aPredicted[$sStopId] < $iNow - TripUpdatesMatcher::LIVE_DEPARTED_AFTER_SECONDS) {
                return [null, true];
            }
            return [$aPredicted[$sStopId], false];
        }
        if ((int)$aStop['seq_order'] < $iFirstPredictedOrder) {
            return [null, true];
        }
        return [$iScheduled + $iDelaySeconds, false];
    }
}
