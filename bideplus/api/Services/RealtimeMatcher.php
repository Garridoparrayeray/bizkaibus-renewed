<?php

namespace Services;

use Models\ServiceJourney;

class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;

    private const POSITION_SANITY_SECONDS = 15 * 60;

    private const MAX_DISTANCE_FROM_ROUTE_METERS = 150;
    private const MAX_DISTANCE_FROM_ROUTE_SEGMENT_RATIO = 0.15;
    private const NOT_STARTED_GRACE_SECONDS = 60;
    private const MAX_LOCATION_AGE_SECONDS = 10 * 60;
    private const SEGMENTS_BEHIND_NEXT_STOP = 3;
    private const SEGMENTS_AHEAD_OF_NEXT_STOP = 2;
    private const METERS_PER_DEGREE = 111320;

    private array $aVmMap;
    private ServiceJourney|null $JourneyModel;
    private int $iNow;
    private array $aJourneyIdByTripRef = [];
    private array $aEntryByJourneyId = [];
    private array $aStopsByJourneyId = [];

    public function __construct(array $aVmMap, ServiceJourney|null $JourneyModel = null, int|null $iNowSeconds = null)
    {
        $this->aVmMap = $aVmMap;
        $this->JourneyModel = $JourneyModel;
        $this->iNow = Calendar::nowSecondsSinceMidnight();
        if ($iNowSeconds !== null) {
            $this->iNow = $iNowSeconds;
        }
        $this->indexExactTrips();
    }

    public function enrich(array $aRows): array
    {
        $iNow = $this->iNow;

        return array_map(function ($aRow) use ($iNow) {
            $sServiceJourneyId = null;
            if (isset($aRow['service_journey_id'])) {
                $sServiceJourneyId = $aRow['service_journey_id'];
            }
            $aLive = $this->lookup($aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds'], $sServiceJourneyId);

            if (!$this->isLive($sServiceJourneyId, $aLive)) {
                return $aRow + self::scheduledFields($aRow);
            }

            [$iEta, $iEffectiveDelay, $bPositioned] = $this->etaForStop(
                $sServiceJourneyId,
                (int)$aRow['arrival_seconds'],
                $aLive
            );
            $bAlreadyPassed = $iEta < ($iNow - self::STALE_GRACE_SECONDS);

            if ($bAlreadyPassed && !$bPositioned) {
                return $aRow + self::scheduledFields($aRow);
            }

            $sStatus = 'live';
            if ($bAlreadyPassed) {
                $sStatus = 'departed';
            }

            return $aRow + [
                'status' => $sStatus,
                'delaySeconds' => $iEffectiveDelay,
                'etaSeconds' => $iEta,
                'vehicleRef' => $aLive['vehicleRef'],
                'currentStopId' => $aLive['currentStopId'],
            ];
        }, $aRows);
    }

    public function isLive(string|null $sServiceJourneyId, array|null $aLive): bool
    {
        if ($aLive === null) {
            return false;
        }
        if (!self::hasLocation($aLive)) {
            return true;
        }
        return $this->positionFor($sServiceJourneyId, $aLive) !== null;
    }

    public function etaForStop(string|null $sServiceJourneyId, int $iTargetArrivalSeconds, array|null $aLive): array
    {
        if ($aLive === null) {
            return [$iTargetArrivalSeconds, 0, false];
        }

        $aPosition = $this->positionFor($sServiceJourneyId, $aLive);
        if ($aPosition !== null) {
            $iEta = $aPosition['locationSeconds'] + $iTargetArrivalSeconds - $aPosition['scheduledSeconds'];
            return [$iEta, $iEta - $iTargetArrivalSeconds, true];
        }

        $iFlatEta = $iTargetArrivalSeconds + $aLive['delaySeconds'];

        if (!self::hasLocation($aLive) && $this->JourneyModel !== null && $sServiceJourneyId !== null && $aLive['currentStopId'] !== null && $aLive['order'] !== null) {
            $iCurrentArrival = $this->JourneyModel->arrivalSecondsAtOrder($sServiceJourneyId, (int)$aLive['order']);
            if ($iCurrentArrival !== null) {
                $iRemaining = $iTargetArrivalSeconds - $iCurrentArrival;
                $iPositionEta = $this->iNow + $iRemaining;
                if ($iRemaining >= 0 && abs($iPositionEta - $iFlatEta) <= self::POSITION_SANITY_SECONDS) {
                    return [$iPositionEta, $iPositionEta - $iTargetArrivalSeconds, false];
                }
            }
        }

        return [$iFlatEta, $aLive['delaySeconds'], false];
    }

    public function currentDelaySeconds(string|null $sServiceJourneyId, array|null $aLive): int
    {
        if ($aLive === null) {
            return 0;
        }
        $aPosition = $this->positionFor($sServiceJourneyId, $aLive);
        if ($aPosition !== null) {
            return $aPosition['locationSeconds'] - $aPosition['scheduledSeconds'];
        }
        if (self::hasLocation($aLive)) {
            return 0;
        }
        return (int)$aLive['delaySeconds'];
    }

    public function positionFor(string|null $sServiceJourneyId, array|null $aLive): array|null
    {
        if ($aLive === null || $sServiceJourneyId === null || $this->JourneyModel === null) {
            return null;
        }
        if (!self::hasLocation($aLive) || !isset($aLive['order'])) {
            return null;
        }
        $iLocationSeconds = (int)$aLive['locationSeconds'];
        if ($this->iNow - $iLocationSeconds > self::MAX_LOCATION_AGE_SECONDS) {
            return null;
        }

        $aStops = $this->stopsFor($sServiceJourneyId);
        if (\count($aStops) < 2) {
            return null;
        }
        if ($iLocationSeconds < (int)$aStops[0]['departure_seconds'] - self::NOT_STARTED_GRACE_SECONDS) {
            return null;
        }

        $iNextStopIndex = null;
        foreach ($aStops as $iIndex => $aStop) {
            if ((int)$aStop['seq_order'] === (int)$aLive['order']) {
                $iNextStopIndex = $iIndex;
                break;
            }
        }
        if ($iNextStopIndex === null) {
            return null;
        }

        $dBestDistance = null;
        $dBestAllowedDistance = null;
        $dBestScheduled = null;
        $iFirstSegment = max(0, $iNextStopIndex - self::SEGMENTS_BEHIND_NEXT_STOP);
        $iLastSegment = min(\count($aStops) - 2, $iNextStopIndex + self::SEGMENTS_AHEAD_OF_NEXT_STOP);
        for ($iSegment = $iFirstSegment; $iSegment <= $iLastSegment; $iSegment++) {
            [$dFraction, $dDistance, $dSegmentLength] = self::projectOntoSegment((float)$aLive['lat'], (float)$aLive['lon'], $aStops[$iSegment], $aStops[$iSegment + 1]);
            if ($dBestDistance === null || $dDistance < $dBestDistance) {
                $iLeaveSeconds = (int)$aStops[$iSegment]['departure_seconds'];
                $iReachSeconds = (int)$aStops[$iSegment + 1]['arrival_seconds'];
                $dBestDistance = $dDistance;
                $dBestAllowedDistance = max(self::MAX_DISTANCE_FROM_ROUTE_METERS, $dSegmentLength * self::MAX_DISTANCE_FROM_ROUTE_SEGMENT_RATIO);
                $dBestScheduled = $iLeaveSeconds + $dFraction * ($iReachSeconds - $iLeaveSeconds);
            }
        }
        if ($dBestDistance === null || $dBestDistance > $dBestAllowedDistance) {
            return null;
        }

        return [
            'scheduledSeconds' => (int)round($dBestScheduled),
            'locationSeconds' => $iLocationSeconds,
        ];
    }

    public function journeyIdFor(array $aEntry): string|null
    {
        if (isset($aEntry['tripRef'], $this->aJourneyIdByTripRef[$aEntry['tripRef']])) {
            return $this->aJourneyIdByTripRef[$aEntry['tripRef']];
        }
        return null;
    }

    public function lookup(int|string $iLineId, string $sTripNumber, int $iFirstDepartureSeconds, string|null $sServiceJourneyId = null): array|null
    {
        if ($sServiceJourneyId !== null && isset($this->aEntryByJourneyId[$sServiceJourneyId])) {
            return $this->aEntryByJourneyId[$sServiceJourneyId];
        }

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
            if ($sServiceJourneyId !== null && $this->journeyIdFor($aCandidate) !== null) {
                continue;
            }
            $iDiff = abs($aCandidate['departureSeconds'] - $iFirstDepartureSeconds);
            if ($iDiff <= self::MATCH_TOLERANCE_SECONDS && ($iBestDiff === null || $iDiff < $iBestDiff)) {
                $aBest = $aCandidate;
                $iBestDiff = $iDiff;
            }
        }
        return $aBest;
    }

    private function indexExactTrips(): void
    {
        if ($this->JourneyModel === null) {
            return;
        }
        $aTripRefs = [];
        foreach ($this->aVmMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                if (isset($aEntry['tripRef'])) {
                    $aTripRefs[] = $aEntry['tripRef'];
                }
            }
        }
        $this->aJourneyIdByTripRef = $this->JourneyModel->journeyIdsForTripRefs($aTripRefs);

        foreach ($this->aVmMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                $sJourneyId = $this->journeyIdFor($aEntry);
                if ($sJourneyId !== null && !isset($this->aEntryByJourneyId[$sJourneyId])) {
                    $this->aEntryByJourneyId[$sJourneyId] = $aEntry;
                }
            }
        }
    }

    private function stopsFor(string $sServiceJourneyId): array
    {
        if (!isset($this->aStopsByJourneyId[$sServiceJourneyId])) {
            $this->aStopsByJourneyId[$sServiceJourneyId] = $this->JourneyModel->stopsWithCoordinates($sServiceJourneyId);
        }
        return $this->aStopsByJourneyId[$sServiceJourneyId];
    }

    private static function hasLocation(array $aLive): bool
    {
        return isset($aLive['lat'], $aLive['lon'], $aLive['locationSeconds']);
    }

    private static function scheduledFields(array $aRow): array
    {
        return [
            'status' => 'scheduled',
            'delaySeconds' => 0,
            'etaSeconds' => (int)$aRow['arrival_seconds'],
            'vehicleRef' => null,
            'currentStopId' => null,
        ];
    }

    private static function projectOntoSegment(float $dLat, float $dLon, array $aFrom, array $aTo): array
    {
        $dMetersPerDegreeLon = cos(deg2rad($dLat)) * self::METERS_PER_DEGREE;
        $dFromX = ((float)$aFrom['lon'] - $dLon) * $dMetersPerDegreeLon;
        $dFromY = ((float)$aFrom['lat'] - $dLat) * self::METERS_PER_DEGREE;
        $dToX = ((float)$aTo['lon'] - $dLon) * $dMetersPerDegreeLon;
        $dToY = ((float)$aTo['lat'] - $dLat) * self::METERS_PER_DEGREE;
        $dDeltaX = $dToX - $dFromX;
        $dDeltaY = $dToY - $dFromY;
        $dLengthSquared = $dDeltaX * $dDeltaX + $dDeltaY * $dDeltaY;

        $dFraction = 0.0;
        if ($dLengthSquared > 0) {
            $dFraction = max(0.0, min(1.0, -($dFromX * $dDeltaX + $dFromY * $dDeltaY) / $dLengthSquared));
        }
        $dClosestX = $dFromX + $dFraction * $dDeltaX;
        $dClosestY = $dFromY + $dFraction * $dDeltaY;

        return [$dFraction, sqrt($dClosestX * $dClosestX + $dClosestY * $dClosestY), sqrt($dLengthSquared)];
    }
}
