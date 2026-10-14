<?php

namespace Services;

use Core\Config;
use Models\ServiceJourney;

class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;

    private const POSITION_SANITY_SECONDS = 15 * 60;
    private const REGISTRATION_SEGMENT_SHARE = 0.5;

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
    private SegmentTimes $Segments;
    private string $sDate;
    private bool $bBusNetwork;

    public function __construct(array $aVmMap, ServiceJourney|null $JourneyModel = null, int|null $iNowSeconds = null, PaceFactors|null $Pace = null, string|null $sDate = null, SegmentTimes|null $Segments = null)
    {
        $this->aVmMap = $aVmMap;
        $this->JourneyModel = $JourneyModel;
        $this->iNow = Calendar::nowSecondsSinceMidnight();
        if ($iNowSeconds !== null) {
            $this->iNow = $iNowSeconds;
        }
        $this->Pace = $Pace ?? PaceFactors::fromFile();
        /*
         * Los tiempos por tramo (por id de parada) y el cálculo desde la parada registrada se han medido solo con
         * Bizkaibus. Las demás redes con SIRI (Euskotren, Tranvía de Bilbao) siguen con el cálculo de siempre.
         */
        $this->bBusNetwork = (Config::current()['network'] ?? 'bus') === 'bus';
        $this->Segments = $Segments ?? ($this->bBusNetwork ? SegmentTimes::fromFile() : new SegmentTimes());
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
            $iStopSeq = null;
            if (isset($aRow['stop_seq'])) {
                $iStopSeq = (int)$aRow['stop_seq'];
            }

            if ($this->isPassedPerFeed($sServiceJourneyId, $aLive, $iStopSeq)) {
                return $aRow + [
                    'status' => 'departed',
                    'delaySeconds' => 0,
                    'etaSeconds' => $iNow - 3 * 60,
                    'vehicleRef' => $aLive['vehicleRef'],
                    'currentStopId' => $aLive['currentStopId'],
                ];
            }

            $bUsable = $this->isLive($sServiceJourneyId, $aLive);
            if (!$bUsable && $iStopSeq !== null && $aLive !== null) {
                $bUsable = $this->registration($sServiceJourneyId, $aLive) !== null;
            }
            if (!$bUsable) {
                return $aRow + self::scheduledFields($aRow);
            }

            [$iEta, $iEffectiveDelay, $bPositioned] = $this->etaForStop(
                $sServiceJourneyId,
                (int)$aRow['arrival_seconds'],
                $aLive,
                $iStopSeq
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

    public function etaForStop(string|null $sServiceJourneyId, int $iTargetArrivalSeconds, array|null $aLive, int|null $iTargetSeq = null): array
    {
        if ($aLive === null) {
            return [$iTargetArrivalSeconds, 0, false];
        }

        $aPosition = $this->positionFor($sServiceJourneyId, $aLive);
        if ($aPosition !== null) {
            $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $aPosition['locationSeconds']);
            $dRemaining = $this->remainingSeconds((string)$sServiceJourneyId, $iTargetArrivalSeconds, $aPosition, $dFactor);
            $iEta = (int)round($aPosition['locationSeconds'] + $dRemaining);
            return [$iEta, $aPosition['locationSeconds'] - $aPosition['scheduledSeconds'], true];
        }

        if ($iTargetSeq !== null && $sServiceJourneyId !== null) {
            $iRegisteredEta = $this->registeredEta($sServiceJourneyId, $iTargetArrivalSeconds, $iTargetSeq, $aLive);
            if ($iRegisteredEta !== null) {
                return [$iRegisteredEta, $iRegisteredEta - $iTargetArrivalSeconds, false];
            }
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

    /*
     * Sin posición GPS utilizable (sin GPS o fuera de la ruta), la parada que el feed da como próxima es lo único
     * fiable: el bus ya ha registrado la anterior. Devuelve las horas programadas de esas dos paradas y la hora
     * del dato, o null si no hay base para fiarse (viaje sin empezar, dato viejo, parada desconocida).
     */
    private function registration(string|null $sServiceJourneyId, array $aLive): array|null
    {
        if (!$this->bBusNetwork || $this->JourneyModel === null || $sServiceJourneyId === null || !isset($aLive['order'])) {
            return null;
        }
        $iOrder = (int)$aLive['order'];
        if ($iOrder < 2) {
            return null;
        }
        $iRef = null;
        if (isset($aLive['locationSeconds'])) {
            $iRef = (int)$aLive['locationSeconds'];
        } elseif (isset($aLive['recordedSeconds'])) {
            $iRef = (int)$aLive['recordedSeconds'];
        }
        if ($iRef === null || $this->iNow - $iRef > self::MAX_LOCATION_AGE_SECONDS) {
            return null;
        }
        $aStops = $this->stopsFor($sServiceJourneyId);
        if (empty($aStops) || $iRef < (int)$aStops[0]['departure_seconds'] - self::NOT_STARTED_GRACE_SECONDS) {
            return null;
        }
        $aBySeq = array_column($aStops, null, 'seq_order');
        if (!isset($aBySeq[$iOrder], $aBySeq[$iOrder - 1])) {
            return null;
        }
        return [
            'order' => $iOrder,
            'ref' => $iRef,
            'previous' => (int)$aBySeq[$iOrder - 1]['arrival_seconds'],
            'next' => (int)$aBySeq[$iOrder]['arrival_seconds'],
        ];
    }

    /*
     * El feed ya da una parada posterior como próxima, y sin GPS que lo contradiga el bus la ha pasado
     * (la haya servido o no): la fila no puede seguir saliendo como «llega en X min».
     */
    public function isPassedPerFeed(string|null $sServiceJourneyId, array|null $aLive, int|null $iTargetSeq): bool
    {
        if ($aLive === null || $iTargetSeq === null || $this->positionFor($sServiceJourneyId, $aLive) !== null) {
            return false;
        }
        $aRegistration = $this->registration($sServiceJourneyId, $aLive);
        return $aRegistration !== null && $iTargetSeq < $aRegistration['order'];
    }

    /*
     * Hora de llegada a una parada que aún no se ha registrado: se parte de la hora del dato, a mitad del tramo hasta
     * la próxima parada, y se suman los tiempos esperados de ahí a la parada pedida. Con datos del 6 de octubre
     * (57 000 predicciones) el error medio es 2,2 min, frente a 2,0 con GPS y 8,2 con solo el horario.
     */
    private function registeredEta(string $sServiceJourneyId, int $iTargetArrivalSeconds, int $iTargetSeq, array $aLive): int|null
    {
        $aRegistration = $this->registration($sServiceJourneyId, $aLive);
        if ($aRegistration === null || $iTargetSeq < $aRegistration['order']) {
            return null;
        }
        $sBand = PaceFactors::bandFor($this->sDate, $aRegistration['ref']);
        $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $aRegistration['ref']);
        $dToNext = self::REGISTRATION_SEGMENT_SHARE * $this->expectedSeconds($sServiceJourneyId, $aRegistration['previous'], $aRegistration['next'], $sBand, $dFactor);
        $dAfterNext = 0.0;
        if ($iTargetArrivalSeconds > $aRegistration['next']) {
            $dAfterNext = $this->expectedSeconds($sServiceJourneyId, $aRegistration['next'], $iTargetArrivalSeconds, $sBand, $dFactor);
        }
        return (int)round($aRegistration['ref'] + $dToNext + $dAfterNext);
    }

    private function remainingSeconds(string $sServiceJourneyId, int $iTargetArrivalSeconds, array $aPosition, float $dFactor): float
    {
        $sBand = PaceFactors::bandFor($this->sDate, $aPosition['locationSeconds']);
        return $this->expectedSeconds($sServiceJourneyId, $aPosition['scheduledSeconds'], $iTargetArrivalSeconds, $sBand, $dFactor);
    }

    public function expectedSeconds(string $sServiceJourneyId, int $iFromSeconds, int $iTargetArrivalSeconds, string $sBand, float $dFactor): float
    {
        if ($this->Segments->isEmpty()) {
            return $dFactor * ($iTargetArrivalSeconds - $iFromSeconds);
        }

        $aStops = $this->stopsFor($sServiceJourneyId);
        $dRemaining = 0.0;
        for ($i = 0; $i < \count($aStops) - 1; $i++) {
            $iLeaveSeconds = (int)$aStops[$i]['arrival_seconds'];
            $iReachSeconds = (int)$aStops[$i + 1]['arrival_seconds'];
            if ($iReachSeconds <= $iFromSeconds) {
                continue;
            }
            if ($iLeaveSeconds >= $iTargetArrivalSeconds) {
                break;
            }
            $iScheduled = $iReachSeconds - $iLeaveSeconds;
            $dSegment = $this->Segments->seconds((string)$aStops[$i]['stop_id'], (string)$aStops[$i + 1]['stop_id'], $sBand);
            if ($dSegment === null) {
                $dSegment = $dFactor * $iScheduled;
            }
            $dShare = 1.0;
            if ($iScheduled > 0) {
                $dShare = (min($iReachSeconds, $iTargetArrivalSeconds) - max($iLeaveSeconds, $iFromSeconds)) / $iScheduled;
            }
            $dRemaining += max(0.0, $dShare) * $dSegment;
        }
        return $dRemaining;
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
        $aRegistration = $this->registration($sServiceJourneyId, $aLive);
        if ($aRegistration !== null) {
            $iEta = $this->registeredEta((string)$sServiceJourneyId, $aRegistration['next'], $aRegistration['order'], $aLive);
            if ($iEta !== null) {
                return $iEta - $aRegistration['next'];
            }
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
