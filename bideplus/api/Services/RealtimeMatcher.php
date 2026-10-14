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
    private const REGISTRATION_MAX_SEGMENT_SECONDS = 20 * 60;
    private const REGISTRATION_CROSSCHECK_MAX_SEGMENT_SECONDS = 6 * 60;
    public const MAX_PLAUSIBLE_DELAY_SECONDS = 45 * 60;
    private const SCHEDULE_ORIGIN_OFFSET_SECONDS = 60;
    private const MAX_GPS_VS_STOP_DISAGREEMENT_SECONDS = 6 * 60;

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
    private array $aDistrusted = [];
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
            try {
                return $this->enrichRow($aRow, $iNow);
            } catch (\Throwable $Ex) {
                // Una fila que no se puede calcular sale con el horario oficial; nunca rompe la lista.
                error_log('Bide+ enrich: ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
                return $aRow + $this->scheduledFieldsFor($aRow);
            }
        }, $aRows);
    }

    private function enrichRow(array $aRow, int $iNow): array
    {
        $sServiceJourneyId = null;
        if (isset($aRow['service_journey_id'])) {
            $sServiceJourneyId = $aRow['service_journey_id'];
        }
        $aLive = $this->lookup($aRow['line_id'], (string)$aRow['trip_number'], (int)$aRow['first_departure_seconds'], $sServiceJourneyId);
        $iStopSeq = null;
        if (isset($aRow['stop_seq'])) {
            $iStopSeq = (int)$aRow['stop_seq'];
        }

        // Capa 3: sin dato en vivo del bus, o sin nada utilizable en él, el horario oficial.
        if (!$this->isLive($sServiceJourneyId, $aLive)) {
            return $aRow + $this->scheduledFieldsFor($aRow);
        }

        // Parada ya pasada: el feed ya marca una parada posterior como la próxima, o el GPS ya está más allá. Sale como «saliendo» unos segundos y se retira.
        if ($this->bBusNetwork) {
            $iPassedAt = $this->passedAtSeconds($sServiceJourneyId, $aLive, (int)$aRow['arrival_seconds'], $iStopSeq);
            if ($iPassedAt !== null) {
                return $aRow + [
                    'status' => 'departed',
                    'delaySeconds' => 0,
                    'etaSeconds' => min($iPassedAt, $iNow),
                    'vehicleRef' => $aLive['vehicleRef'],
                    'currentStopId' => $aLive['currentStopId'],
                ];
            }
        }

        if ($this->bBusNetwork && $this->positionFor($sServiceJourneyId, $aLive) === null
            && ($iStopSeq === null || $this->registeredEta((string)$sServiceJourneyId, (int)$aRow['arrival_seconds'], $iStopSeq, $aLive) === null)) {
            return $aRow + $this->scheduledFieldsFor($aRow);
        }

        [$iEta, $iEffectiveDelay, $bPositioned] = $this->etaForStop(
            $sServiceJourneyId,
            (int)$aRow['arrival_seconds'],
            $aLive,
            $iStopSeq
        );
        /*
         * Más de 45 min entre la llegada estimada y la hora publicada de esta parada: el viaje del feed no es el que hace
         * el bus (o el horario no sirve de referencia). La llegada estimada sigue valiendo (sale de su posición y de los
         * tiempos por tramo, no de la etiqueta del viaje; medido contra los pasos reales por parada), pero el retraso no
         * se afirma.
         */
        if ($this->bBusNetwork && abs($iEffectiveDelay) > self::MAX_PLAUSIBLE_DELAY_SECONDS) {
            $iEffectiveDelay = null;
        }
        $bAlreadyPassed = $iEta < ($iNow - self::STALE_GRACE_SECONDS);

        if ($bAlreadyPassed && !$bPositioned) {
            return $aRow + $this->scheduledFieldsFor($aRow);
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
    }

    /*
     * Hora estimada a la que el bus pasó por una parada que ya ha dejado atrás, o null si no la ha pasado. Con GPS, el
     * momento en que el bus estaba en esa parada según su posición actual; sin GPS, desde la hora del dato.
     */
    private function passedAtSeconds(string|null $sServiceJourneyId, array $aLive, int $iTargetArrivalSeconds, int|null $iStopSeq): int|null
    {
        $aPosition = $this->positionFor($sServiceJourneyId, $aLive);
        if ($aPosition !== null) {
            if ($iTargetArrivalSeconds < $aPosition['scheduledSeconds']) {
                return $aPosition['locationSeconds'] - ($aPosition['scheduledSeconds'] - $iTargetArrivalSeconds);
            }
            if ($iStopSeq !== null && isset($aLive['order']) && $iStopSeq < (int)$aLive['order']) {
                return $aPosition['locationSeconds'];
            }
            return null;
        }
        if ($iStopSeq !== null && isset($aLive['order']) && $iStopSeq < (int)$aLive['order']) {
            $aRegistration = $this->registration($sServiceJourneyId, $aLive);
            if ($aRegistration !== null) {
                return $aRegistration['ref'] - max(0, $aRegistration['previous'] - $iTargetArrivalSeconds);
            }
        }
        return null;
    }

    public function isLive(string|null $sServiceJourneyId, array|null $aLive): bool
    {
        if ($aLive === null) {
            return false;
        }
        if ($this->bBusNetwork) {
            // Bizkaibus: en vivo con posición GPS; sin ella, con la parada registrada y la hora del dato; si no, horario oficial.
            return $this->positionFor($sServiceJourneyId, $aLive) !== null || $this->registration($sServiceJourneyId, $aLive) !== null;
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
            try {
                if ($iTargetArrivalSeconds < $aPosition['scheduledSeconds']) {
                    // La parada queda por detras del bus: ya ha pasado, su hora cae en el pasado.
                    $iPassedEta = $aPosition['locationSeconds'] - ($aPosition['scheduledSeconds'] - $iTargetArrivalSeconds);
                    return [$iPassedEta, $aPosition['locationSeconds'] - $aPosition['scheduledSeconds'], true];
                }
                $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $aPosition['locationSeconds']);
                $dRemaining = $this->remainingSeconds((string)$sServiceJourneyId, $iTargetArrivalSeconds, $aPosition, $dFactor);
                $iEta = (int)round($aPosition['locationSeconds'] + $dRemaining);
                // El retraso de la salida es el de esa parada: llegada estimada menos la hora publicada, lo que el usuario ve en pantalla.
                return [$iEta, $iEta - $iTargetArrivalSeconds, true];
            } catch (\Throwable $Ex) {
                error_log('Bide+ ETA: ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
                return [$iTargetArrivalSeconds, 0, false];
            }
        }

        if ($this->bBusNetwork) {
            if ($iTargetSeq !== null && $sServiceJourneyId !== null) {
                $iRegisteredEta = $this->registeredEta($sServiceJourneyId, $iTargetArrivalSeconds, $iTargetSeq, $aLive);
                if ($iRegisteredEta !== null) {
                    return [$iRegisteredEta, $iRegisteredEta - $iTargetArrivalSeconds, false];
                }
            }
            return [$iTargetArrivalSeconds, 0, false];
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
     * Sin posición GPS utilizable (el bus no la manda o está fuera de la ruta), la parada que el feed da como próxima es
     * lo único fiable: el bus ya ha registrado la anterior. Devuelve las horas programadas de esas dos paradas y la hora
     * del dato, o null si no hay base para fiarse (sin hora del dato, dato viejo, viaje sin empezar, parada desconocida).
     */
    private function registration(string|null $sServiceJourneyId, array $aLive): array|null
    {
        if (!$this->bBusNetwork || $this->JourneyModel === null || $sServiceJourneyId === null || !isset($aLive['order'])) {
            return null;
        }
        if (isset($this->aDistrusted[$sServiceJourneyId . '|' . ($aLive['vehicleRef'] ?? '')])) {
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
        try {
            $aStops = $this->stopsFor($sServiceJourneyId);
        } catch (\Throwable $Ex) {
            error_log('Bide+ parada registrada: ' . $Ex->getMessage());
            return null;
        }
        if (empty($aStops) || $iRef < (int)$aStops[0]['departure_seconds'] - self::NOT_STARTED_GRACE_SECONDS) {
            return null;
        }
        $aBySeq = array_column($aStops, null, 'seq_order');
        if (!isset($aBySeq[$iOrder], $aBySeq[$iOrder - 1])) {
            return null;
        }
        /*
         * Contrastado con el feed real (201 buses con GPS): en tramos de hasta 5 min la parada registrada coincide con el
         * GPS (mediana 0,7 min, p90 1,7); en tramos de más de 10 min (cabeceras, esperas) se desvía 10 min de mediana.
         * Ahí no se puede afirmar nada y se queda el horario oficial.
         */
        if ((int)$aBySeq[$iOrder]['arrival_seconds'] - (int)$aBySeq[$iOrder - 1]['arrival_seconds'] > self::REGISTRATION_MAX_SEGMENT_SECONDS) {
            return null;
        }
        $iPrevious = (int)$aBySeq[$iOrder - 1]['arrival_seconds'];
        $iNext = (int)$aBySeq[$iOrder]['arrival_seconds'];
        $sBand = PaceFactors::bandFor($this->sDate, $iRef);
        $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $iRef);
        $iToNext = (int)round(self::REGISTRATION_SEGMENT_SHARE * $this->expectedSeconds($sServiceJourneyId, $iPrevious, $iNext, $sBand, $dFactor));
        // Retraso del bus en su próxima parada (el mismo concepto que con GPS: lo que lleva el bus, no la diferencia en una parada lejana).
        $iDelay = $iRef + $iToNext - $iNext;
        return [
            'order' => $iOrder,
            'ref' => $iRef,
            'previous' => $iPrevious,
            'next' => $iNext,
            'toNext' => $iToNext,
            'delay' => $iDelay,
            'gap' => $iNext - $iPrevious,
        ];
    }

    /*
     * Hora de llegada a una parada que aún no se ha registrado: se parte de la hora del dato, a mitad del tramo hasta
     * la próxima parada, y se suman los tiempos esperados de ahí a la parada pedida.
     */
    private function registeredEta(string $sServiceJourneyId, int $iTargetArrivalSeconds, int $iTargetSeq, array $aLive): int|null
    {
        $aRegistration = $this->registration($sServiceJourneyId, $aLive);
        if ($aRegistration === null || $iTargetSeq < $aRegistration['order']) {
            return null;
        }
        $dAfterNext = 0.0;
        if ($iTargetArrivalSeconds > $aRegistration['next']) {
            $sBand = PaceFactors::bandFor($this->sDate, $aRegistration['ref']);
            $dFactor = $this->Pace->factor($aLive['lineId'] ?? null, $this->sDate, $aRegistration['ref']);
            $dAfterNext = $this->expectedSeconds($sServiceJourneyId, $aRegistration['next'], $iTargetArrivalSeconds, $sBand, $dFactor);
        }
        return (int)round($aRegistration['ref'] + $aRegistration['toNext'] + $dAfterNext);
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

    /*
     * Minutos de retraso para mostrar, o null si el retraso no es creíble (Bizkaibus: más de 45 min).
     */
    public function displayDelayMinutes(int|null $iDelaySeconds): int|null
    {
        if ($iDelaySeconds === null) {
            return null;
        }
        if ($this->bBusNetwork && abs($iDelaySeconds) > self::MAX_PLAUSIBLE_DELAY_SECONDS) {
            return null;
        }
        return (int)round($iDelaySeconds / 60);
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
        if ($this->bBusNetwork) {
            $aRegistration = $this->registration($sServiceJourneyId, $aLive);
            if ($aRegistration !== null) {
                return $aRegistration['delay'];
            }
            return 0;
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
            try {
                $this->aPositionMemo[$sMemoKey] = $this->computePosition($sServiceJourneyId, $aLive);
                /*
                 * Dos medidas del mismo bus que no cuadran: el GPS proyectado sobre el recorrido y la parada que marca el
                 * feed. Con una captura de 14 min del feed real, 11 buses con más de 25 min de retraso se mantuvieron
                 * estables y coincidían con los pasos por parada, y otros 10 cambiaron hasta 34 min en 14 min, con GPS y
                 * parada discrepando. Si discrepan más de 6 min no se fía de ninguna: horario oficial.
                 */
                if ($this->bBusNetwork && $this->aPositionMemo[$sMemoKey] !== null) {
                    $aRegistration = $this->registration($sServiceJourneyId, $aLive);
                    $iGpsDelay = $this->aPositionMemo[$sMemoKey]['locationSeconds'] - $this->aPositionMemo[$sMemoKey]['scheduledSeconds'];
                    if ($aRegistration !== null && $aRegistration['gap'] <= self::REGISTRATION_CROSSCHECK_MAX_SEGMENT_SECONDS && abs($iGpsDelay - $aRegistration['delay']) > self::MAX_GPS_VS_STOP_DISAGREEMENT_SECONDS) {
                        $this->aDistrusted[$sServiceJourneyId . '|' . ($aLive['vehicleRef'] ?? '')] = true;
                        $this->aPositionMemo[$sMemoKey] = null;
                    }
                }
            } catch (\Throwable $Ex) {
                // Si el cálculo con GPS falla, ese bus se queda sin posición y sale con el horario oficial.
                error_log('Bide+ GPS: ' . $Ex->getMessage() . ' en ' . $Ex->getFile() . ':' . $Ex->getLine());
                $this->aPositionMemo[$sMemoKey] = null;
            }
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

    /*
     * Capa 3, sin datos del bus. El horario publicado está interpolado a velocidad constante y no se parece a lo que
     * tardan los buses (autopistas, esperas). Con los tiempos aprendidos se estima la llegada como la salida de cabecera
     * más lo que tardan los buses en llegar a esta parada. Medido contra los pasos reales (7 627 llegadas), con el bus
     * sin usar: 37 % dentro de ±2 min frente al 18 % del horario publicado, y error mediano de unos 3-4 min frente a 7. Se suma 1 min porque los buses salen de cabecera algo después de la hora publicada (medido en una sola ventana de 14 min; el resumen de cada captura muestra si el sesgo sigue en 0).
     * Si lo estimado ya queda en el pasado (no se sabe si el bus ha pasado o va tarde), se deja el horario publicado:
     * nunca se esconde una salida ni se deja una en «0 min».
     */
    private function scheduledFieldsFor(array $aRow): array
    {
        $aFields = self::scheduledFields($aRow);
        if (!$this->bBusNetwork || $this->Segments->isEmpty() || $this->JourneyModel === null || empty($aRow['service_journey_id'])) {
            return $aFields;
        }
        try {
            $sJourneyId = (string)$aRow['service_journey_id'];
            $aStops = $this->stopsFor($sJourneyId);
            if (empty($aStops)) {
                return $aFields;
            }
            $iFirst = (int)$aStops[0]['departure_seconds'];
            $iTarget = (int)$aRow['arrival_seconds'];
            if ($iTarget <= $iFirst) {
                return $aFields;
            }
            $iMid = intdiv($iFirst + $iTarget, 2);
            $sBand = PaceFactors::bandFor($this->sDate, $iMid);
            $dFactor = $this->Pace->factor($aRow['line_id'] ?? null, $this->sDate, $iMid);
            $iCorrected = $iFirst + self::SCHEDULE_ORIGIN_OFFSET_SECONDS + (int)round($this->expectedSeconds($sJourneyId, $iFirst - 1, $iTarget, $sBand, $dFactor));
            if ($iCorrected >= $this->iNow) {
                $aFields['etaSeconds'] = $iCorrected;
            }
        } catch (\Throwable $Ex) {
            error_log('Bide+ horario corregido: ' . $Ex->getMessage());
        }
        return $aFields;
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
