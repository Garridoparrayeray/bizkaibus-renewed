<?php

namespace Services;

use Models\ServiceJourney;

// Directo de Renfe (GTFS-Realtime): cada tren trae su viaje del horario, el retraso y la estación a la que va. Se
// empareja por el viaje, el retraso vale para las estaciones que le quedan, los que ya pasaron por la parada no salen y
// los cancelados se marcan.
class TripDelayMatcher
{
    private const ON_TIME_SECONDS = 60;
    private const LIVE_DEPARTED_AFTER_SECONDS = 45;
    private const SCHEDULED_DEPARTED_AFTER_SECONDS = 60;

    // id del viaje del horario => ['delay' => segundos, 'nextStopId' => string|null, 'canceled' => bool]
    private array $aByJourney = [];
    private array $aStopsByJourney = [];
    private int $iMidnight;

    public function __construct(array $aTrips, private ServiceJourney $JourneyModel, int|null $iMidnight = null)
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
        $aJourneyIds = $JourneyModel->journeyIdsForTripRefs(array_column($aTrips, 'tripId'));
        foreach ($aTrips as $aTrip) {
            $sJourneyId = $aJourneyIds[$aTrip['tripId']] ?? null;
            if ($sJourneyId === null) {
                continue;
            }
            $sNextStopId = null;
            if (!empty($aTrip['stops'])) {
                $sNextStopId = (string)$aTrip['stops'][0][0];
            }
            $this->aByJourney[(string)$sJourneyId] = [
                'delay' => (int)($aTrip['delay'] ?? 0),
                'nextStopId' => $sNextStopId,
                'canceled' => !empty($aTrip['canceled']),
            ];
        }
    }

    public function hasData(): bool
    {
        return !empty($this->aByJourney);
    }

    public function isCancelled(string $sServiceJourneyId): bool
    {
        return !empty($this->aByJourney[$sServiceJourneyId]['canceled']);
    }

    public function cancelledJourneyIds(): array
    {
        $aIds = [];
        foreach ($this->aByJourney as $sJourneyId => $aLive) {
            if ($aLive['canceled']) {
                $aIds[] = (string)$sJourneyId;
            }
        }
        return $aIds;
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aOut = [];
        foreach ($aRows as $aRow) {
            $aLive = $this->aByJourney[(string)$aRow['service_journey_id']] ?? null;
            if ($aLive === null || ($aRow['status'] ?? 'scheduled') !== 'scheduled') {
                $aOut[] = $aRow;
                continue;
            }
            if ($aLive['canceled']) {
                $aOut[] = ['status' => 'cancelled', 'etaSeconds' => (int)$aRow['arrival_seconds'], 'delaySeconds' => 0] + $aRow;
                continue;
            }
            if ($this->passed((string)$aRow['service_journey_id'], (int)$aRow['stop_seq'], $aLive['nextStopId'])) {
                continue;
            }
            $iDelay = $aLive['delay'];
            if (abs($iDelay) < self::ON_TIME_SECONDS) {
                $iDelay = 0;
            }
            $aOut[] = ['status' => 'live', 'etaSeconds' => (int)$aRow['arrival_seconds'] + $aLive['delay'], 'delaySeconds' => $iDelay] + $aRow;
        }
        if ($this->hasData() && !empty($aOut)) {
            usort($aOut, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        }
        return $aOut;
    }

    public function withoutDeparted(array $aRows, int $iNowSeconds): array
    {
        return array_values(array_filter($aRows, function ($aRow) use ($iNowSeconds) {
            $iGrace = self::SCHEDULED_DEPARTED_AFTER_SECONDS;
            if (($aRow['status'] ?? 'scheduled') === 'live') {
                $iGrace = self::LIVE_DEPARTED_AFTER_SECONDS;
            }
            return (int)$aRow['etaSeconds'] - $iNowSeconds >= -$iGrace;
        }));
    }

    // Hora prevista (marca de tiempo) en cada estación que le queda al tren, desde la siguiente; null si no va en directo.
    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        $aLive = $this->aByJourney[$sServiceJourneyId] ?? null;
        if ($aLive === null || $aLive['canceled']) {
            return null;
        }
        $iFromOrder = 0;
        if ($aLive['nextStopId'] !== null) {
            $iFromOrder = $this->orderOf($sServiceJourneyId, $aLive['nextStopId']) ?? 0;
        }
        $aStops = [];
        foreach ($this->stops($sServiceJourneyId) as $aStop) {
            if ((int)$aStop['seq_order'] >= $iFromOrder) {
                $aStops[] = [(string)$aStop['stop_id'], $this->iMidnight + (int)$aStop['arrival_seconds'] + $aLive['delay']];
            }
        }
        return $aStops;
    }

    // Ya pasó por la parada si la estación a la que va está después en su recorrido.
    private function passed(string $sServiceJourneyId, int $iStopOrder, string|null $sNextStopId): bool
    {
        if ($sNextStopId === null) {
            return false;
        }
        $iNextOrder = $this->orderOf($sServiceJourneyId, $sNextStopId);
        return $iNextOrder !== null && $iNextOrder > $iStopOrder;
    }

    private function orderOf(string $sServiceJourneyId, string $sStopId): int|null
    {
        foreach ($this->stops($sServiceJourneyId) as $aStop) {
            if ((string)$aStop['stop_id'] === $sStopId) {
                return (int)$aStop['seq_order'];
            }
        }
        return null;
    }

    private function stops(string $sServiceJourneyId): array
    {
        if (!isset($this->aStopsByJourney[$sServiceJourneyId])) {
            $this->aStopsByJourney[$sServiceJourneyId] = $this->JourneyModel->stopsForJourney($sServiceJourneyId);
        }
        return $this->aStopsByJourney[$sServiceJourneyId];
    }
}
