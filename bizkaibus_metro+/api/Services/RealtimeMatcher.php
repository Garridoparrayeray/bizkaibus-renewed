<?php

namespace Services;

use Models\ServiceJourney;

class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;

    private const POSITION_SANITY_SECONDS = 15 * 60;

    private array $aVmMap;
    private ServiceJourney|null $JourneyModel;

    public function __construct(array $aVmMap, ServiceJourney|null $JourneyModel = null)
    {
        $this->aVmMap = $aVmMap;
        $this->JourneyModel = $JourneyModel;
    }

    public function enrich(array $aRows): array
    {
        $iNow = Calendar::nowSecondsSinceMidnight();

        return array_map(function ($aRow) use ($iNow) {
            $aLive = $this->lookup((int)$aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds']);

            if ($aLive === null) {
                return $aRow + [
                    'status' => 'scheduled',
                    'delaySeconds' => 0,
                    'etaSeconds' => (int)$aRow['arrival_seconds'],
                    'vehicleRef' => null,
                    'currentStopId' => null,
                ];
            }

            $sServiceJourneyId = null;
            if (isset($aRow['service_journey_id'])) {
                $sServiceJourneyId = $aRow['service_journey_id'];
            }
            [$iEta, $iEffectiveDelay] = $this->etaForStop(
                $sServiceJourneyId,
                (int)$aRow['arrival_seconds'],
                $aLive
            );
            $bIsStale = $iEta < ($iNow - self::STALE_GRACE_SECONDS);

            if ($bIsStale) {
                $sStatus = 'scheduled';
                $iDelaySeconds = 0;
                $iEtaSeconds = (int)$aRow['arrival_seconds'];
                $sVehicleRef = null;
                $iCurrentStopId = null;
            } else {
                $sStatus = 'live';
                $iDelaySeconds = $iEffectiveDelay;
                $iEtaSeconds = $iEta;
                $sVehicleRef = $aLive['vehicleRef'];
                $iCurrentStopId = $aLive['currentStopId'];
            }

            return $aRow + [
                'status' => $sStatus,
                'delaySeconds' => $iDelaySeconds,
                'etaSeconds' => $iEtaSeconds,
                'vehicleRef' => $sVehicleRef,
                'currentStopId' => $iCurrentStopId,
            ];
        }, $aRows);
    }

    public function etaForStop(string|null $sServiceJourneyId, int $iTargetArrivalSeconds, array|null $aLive): array
    {
        if ($aLive === null) {
            return [$iTargetArrivalSeconds, 0];
        }

        $iFlatEta = $iTargetArrivalSeconds + $aLive['delaySeconds'];

        if ($this->JourneyModel !== null && $sServiceJourneyId !== null && $aLive['currentStopId'] !== null && $aLive['order'] !== null) {
            $iNow = Calendar::nowSecondsSinceMidnight();
            $iCurrentArrival = $this->JourneyModel->arrivalSecondsAtOrder($sServiceJourneyId, (int)$aLive['order']);
            if ($iCurrentArrival !== null) {
                $iRemaining = $iTargetArrivalSeconds - $iCurrentArrival;
                $iPositionEta = $iNow + $iRemaining;
                if ($iRemaining >= 0 && abs($iPositionEta - $iFlatEta) <= self::POSITION_SANITY_SECONDS) {
                    return [$iPositionEta, $iPositionEta - $iTargetArrivalSeconds];
                }
            }
        }

        return [$iFlatEta, $aLive['delaySeconds']];
    }

    public function lookup(int $iLineId, string $sTripNumber, int $iFirstDepartureSeconds): array|null
    {
        $sKey = $iLineId . '|' . $sTripNumber;
        $aCandidates = [];
        if (isset($this->aVmMap[$sKey])) {
            $aCandidates = $this->aVmMap[$sKey];
        }
        if (empty($aCandidates)) {
            return null;
        }

        $aBest = null;
        $iBestDiff = null;
        foreach ($aCandidates as $aCandidate) {
            $iDiff = abs($aCandidate['departureSeconds'] - $iFirstDepartureSeconds);
            if ($iDiff <= self::MATCH_TOLERANCE_SECONDS && ($iBestDiff === null || $iDiff < $iBestDiff)) {
                $aBest = $aCandidate;
                $iBestDiff = $iDiff;
            }
        }
        return $aBest;
    }
}
