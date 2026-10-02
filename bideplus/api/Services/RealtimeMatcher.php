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
    private const MAX_DISTANCE_FROM_SHAPE_METERS = 50;
    private const BEYOND_NEXT_STOP_SLACK_METERS = 100;
    private const AMBIGUITY_MARGIN_METERS = 20;
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
    private array $aShapeByJourneyId = [];
    private array $aPositionMemo = [];
    private PaceFactors $Pace;
    private string $sDate;

    public function __construct(array $aVmMap, ServiceJourney|null $JourneyModel = null, int|null $iNowSeconds = null, PaceFactors|null $Pace = null, string|null $sDate = null)
    {
        $this->aVmMap = $aVmMap;
        $this->JourneyModel = $JourneyModel;
        $this->iNow = Calendar::nowSecondsSinceMidnight();
        if ($iNowSeconds !== null) {
            $this->iNow = $iNowSeconds;
        }
        $this->Pace = $Pace ?? PaceFactors::fromFile();
        $this->sDate = $sDate ?? Calendar::todayMadrid()->format('Y-m-d');
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
            $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $aPosition['locationSeconds']);
            $iRemaining = $iTargetArrivalSeconds - $aPosition['scheduledSeconds'];
            $iEta = (int)round($aPosition['locationSeconds'] + $dFactor * $iRemaining);
            return [$iEta, $aPosition['locationSeconds'] - $aPosition['scheduledSeconds'], true];
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
        $sMemoKey = $sServiceJourneyId . '|' . $aLive['vehicleRef'] . '|' . $aLive['locationSeconds'] . '|' . $aLive['lat'] . '|' . $aLive['lon'] . '|' . $aLive['order'];
        if (!array_key_exists($sMemoKey, $this->aPositionMemo)) {
            $this->aPositionMemo[$sMemoKey] = $this->computePosition($sServiceJourneyId, $aLive);
        }
        return $this->aPositionMemo[$sMemoKey];
    }

    private function computePosition(string $sServiceJourneyId, array $aLive): array|null
    {
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

        $iFirstSegment = max(0, $iNextStopIndex - self::SEGMENTS_BEHIND_NEXT_STOP);
        $iLastSegment = min(\count($aStops) - 2, $iNextStopIndex + self::SEGMENTS_AHEAD_OF_NEXT_STOP);

        $aShapePoints = $this->shapeFor($sServiceJourneyId);
        if (!empty($aShapePoints) && self::stopsHaveDistances($aStops, $iFirstSegment, $iLastSegment + 1)) {
            return $this->positionOnShape($aLive, $aStops, $aShapePoints, $iFirstSegment, $iLastSegment, $iNextStopIndex, $iLocationSeconds);
        }

        $dBestDistance = null;
        $dBestAllowedDistance = null;
        $dBestScheduled = null;
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
            'offRouteMeters' => $dBestDistance,
            'method' => 'segment',
        ];
    }

    private function positionOnShape(array $aLive, array $aStops, array $aShapePoints, int $iFirstSegment, int $iLastSegment, int $iNextStopIndex, int $iLocationSeconds): array|null
    {
        $dFromMeters = (float)$aStops[$iFirstSegment]['dist_m'];
        $dToMeters = (float)$aStops[$iLastSegment + 1]['dist_m'];
        $dExpectedLimit = (float)$aStops[$iNextStopIndex]['dist_m'] + self::BEYOND_NEXT_STOP_SLACK_METERS;

        $aBestExpected = null;
        $aBestBeyond = null;
        $iPoints = \count($aShapePoints);
        for ($i = 0; $i < $iPoints - 1; $i++) {
            $aA = $aShapePoints[$i];
            $aB = $aShapePoints[$i + 1];
            if ($aB[2] < $dFromMeters || $aA[2] > $dToMeters) {
                continue;
            }
            [$dFraction, $dDistance] = self::projectOntoSegment(
                (float)$aLive['lat'],
                (float)$aLive['lon'],
                ['lat' => $aA[0], 'lon' => $aA[1]],
                ['lat' => $aB[0], 'lon' => $aB[1]]
            );
            $dAlong = $aA[2] + $dFraction * ($aB[2] - $aA[2]);
            if ($dAlong <= $dExpectedLimit) {
                if ($aBestExpected === null || $dDistance < $aBestExpected[0]) {
                    $aBestExpected = [$dDistance, $dAlong];
                }
            } elseif ($aBestBeyond === null || $dDistance < $aBestBeyond[0]) {
                $aBestBeyond = [$dDistance, $dAlong];
            }
        }

        $aBest = $aBestExpected;
        if ($aBestBeyond !== null && ($aBest === null || $aBestBeyond[0] + self::AMBIGUITY_MARGIN_METERS < $aBest[0])) {
            $aBest = $aBestBeyond;
        }
        if ($aBest === null || $aBest[0] > self::MAX_DISTANCE_FROM_SHAPE_METERS) {
            return null;
        }
        [$dBestDistance, $dBestAlong] = $aBest;

        $dBestAlong = max($dFromMeters, min($dToMeters, $dBestAlong));
        for ($iSegment = $iFirstSegment; $iSegment <= $iLastSegment; $iSegment++) {
            $dStart = (float)$aStops[$iSegment]['dist_m'];
            $dEnd = (float)$aStops[$iSegment + 1]['dist_m'];
            if ($dBestAlong > $dEnd && $iSegment < $iLastSegment) {
                continue;
            }
            $dFraction = 0.0;
            if ($dEnd > $dStart) {
                $dFraction = max(0.0, min(1.0, ($dBestAlong - $dStart) / ($dEnd - $dStart)));
            }
            $iLeaveSeconds = (int)$aStops[$iSegment]['departure_seconds'];
            $iReachSeconds = (int)$aStops[$iSegment + 1]['arrival_seconds'];
            return [
                'scheduledSeconds' => (int)round($iLeaveSeconds + $dFraction * ($iReachSeconds - $iLeaveSeconds)),
                'locationSeconds' => $iLocationSeconds,
                'offRouteMeters' => $dBestDistance,
                'method' => 'shape',
            ];
        }
        return null;
    }

    private function shapeFor(string $sServiceJourneyId): array
    {
        if (!isset($this->aShapeByJourneyId[$sServiceJourneyId])) {
            $this->aShapeByJourneyId[$sServiceJourneyId] = $this->JourneyModel->shapePointsForJourney($sServiceJourneyId);
        }
        return $this->aShapeByJourneyId[$sServiceJourneyId];
    }

    private static function stopsHaveDistances(array $aStops, int $iFrom, int $iTo): bool
    {
        for ($i = $iFrom; $i <= $iTo; $i++) {
            if (!isset($aStops[$i]['dist_m'])) {
                return false;
            }
        }
        return true;
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
