<?php

namespace Services;

// Cuándo el directo de un operador no es directo: un feed que copia el horario, unas horas que contradicen al GPS o un
// viaje que aún no ha salido y para el que solo se repite el horario.
class FeedTrust
{
    private const ON_TIME_SECONDS = 60;
    private const VEHICLE_DISAGREEMENT_SECONDS = 1200;
    private const MIN_VEHICLES_TO_DISTRUST = 3;

    public function __construct(private JourneyStops $Stops, private int $iNowSeconds)
    {
    }

    public function copiesSchedule(array $aByJourney): bool
    {
        foreach ($aByJourney as $sJourneyId => $aLive) {
            if (empty($aLive['times'])) {
                return false;
            }
            foreach ($this->Stops->of((string)$sJourneyId) as $aStop) {
                $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
                if ($iTime !== null && $iTime !== (int)$aStop['arrival_seconds']) {
                    return false;
                }
            }
        }
        return !empty($aByJourney);
    }

    public function contradictsVehicles(array $aByJourney, array $aVehicles, array $aJourneyIds): bool
    {
        $iChecked = 0;
        $iOff = 0;
        foreach ($aVehicles as $aVehicle) {
            $sJourneyId = (string)($aJourneyIds[$aVehicle['tripId']] ?? '');
            $iTime = $aByJourney[$sJourneyId]['times'][(string)($aVehicle['stopId'] ?? '')] ?? null;
            if ($iTime === null) {
                continue;
            }
            $iChecked++;
            if (abs($iTime - ($aByJourney[$sJourneyId]['shift'] ?? 0) - $this->iNowSeconds) > self::VEHICLE_DISAGREEMENT_SECONDS) {
                $iOff++;
            }
        }
        return $iChecked >= self::MIN_VEHICLES_TO_DISTRUST && $iOff * 2 > $iChecked;
    }

    public function hasRealData(string $sServiceJourneyId, array $aLive): bool
    {
        $aStops = $this->Stops->of($sServiceJourneyId);
        if (empty($aStops) || (int)$aStops[0]['departure_seconds'] <= $this->iNowSeconds) {
            return true;
        }
        if (empty($aLive['times'])) {
            return abs($aLive['delay']) >= self::ON_TIME_SECONDS;
        }
        foreach ($aStops as $aStop) {
            $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
            if ($iTime !== null && abs($iTime - (int)$aStop['arrival_seconds']) >= self::ON_TIME_SECONDS) {
                return true;
            }
        }
        return false;
    }
}
