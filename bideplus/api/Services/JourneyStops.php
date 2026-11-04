<?php

namespace Services;

use Models\ServiceJourney;

class JourneyStops
{
    private array $aStopsByJourney = [];

    public function __construct(private ServiceJourney $JourneyModel)
    {
    }

    public function of(string $sServiceJourneyId): array
    {
        if (!isset($this->aStopsByJourney[$sServiceJourneyId])) {
            $this->aStopsByJourney[$sServiceJourneyId] = $this->JourneyModel->stopsForJourney($sServiceJourneyId);
        }
        return $this->aStopsByJourney[$sServiceJourneyId];
    }

    public function orderOf(string $sServiceJourneyId, string $sStopId): int|null
    {
        foreach ($this->of($sServiceJourneyId) as $aStop) {
            if ((string)$aStop['stop_id'] === $sStopId) {
                return (int)$aStop['seq_order'];
            }
        }
        return null;
    }

    public function arrivalAt(string $sServiceJourneyId, int $iOrder): int
    {
        $aStops = $this->of($sServiceJourneyId);
        foreach ($aStops as $aStop) {
            if ((int)$aStop['seq_order'] === $iOrder) {
                return (int)$aStop['arrival_seconds'];
            }
        }
        return (int)($aStops[0]['arrival_seconds'] ?? 0);
    }
}
