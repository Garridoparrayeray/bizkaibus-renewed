<?php

namespace Services;

use Models\ServiceJourney;

// Horas previstas por paradas (GTFS-Realtime de Metro Bilbao, Euskotren y los tranvías): empareja cada tren del feed
// con su viaje del horario por sus paradas y horas, y da la hora prevista en cada estación, sin los que ya salieron.
class TripUpdatesMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    // Un recorrido cuenta como destino posible si tiene al menos 1 de cada 4 viajes de los del que más tiene.
    private const USUAL_PATTERN_SHARE = 4;
    // Una salida a punto de salir no se quita aunque su tren ya no figure en el feed: puede estar en la estación.
    private const BOARDING_GRACE_SECONDS = 120;
    // Cuánto puede apartarse la hora de la clave de un refuerzo de la que el feed da ahora para ser el mismo tren.
    private const EXTRA_KEY_TOLERANCE_SECONDS = 120;

    private int $iMidnight;

    // $sSource dice de dónde salen los trenes: «trip-updates» del GTFS-Realtime o «siri-arrivals» armados con el SIRI.
    // Con $bFeedIsComplete (el feed trae todos los trenes), los que no están en el horario (refuerzos) también salen y
    // las salidas del horario sin tren, hasta donde llega el feed, no.
    public function __construct(private array $aTrips, private ServiceJourney $JourneyModel, private int $iToleranceSeconds, int|null $iMidnight = null, private string $sSource = 'trip-updates', private bool $bFeedIsComplete = false)
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
    }

    public function source(): string
    {
        return $this->sSource;
    }

    public function trainCount(): int
    {
        return \count($this->aTrips);
    }

    public function hasData(): bool
    {
        return !empty($this->aTrips);
    }

    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        [$aPredictions, $aMatches] = $this->pair($sStopId, $aRows);
        foreach ($aMatches as $iRow => $iPrediction) {
            if (($aRows[$iRow]['service_journey_id'] ?? null) === $sServiceJourneyId) {
                return $aPredictions[$iPrediction]['stops'];
            }
        }
        return null;
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        [$aPredictions, $aMatches, $aHorizons] = $this->pair($sStopId, $aRows);
        foreach ($aMatches as $iRow => $iPrediction) {
            $iSeconds = $aPredictions[$iPrediction]['seconds'];
            $aRows[$iRow]['status'] = 'live';
            $aRows[$iRow]['etaSeconds'] = $iSeconds;
            $iDelay = $iSeconds - (int)$aRows[$iRow]['arrival_seconds'];
            if (abs($iDelay) < self::ON_TIME_SECONDS) {
                $iDelay = 0;
            }
            $aRows[$iRow]['delaySeconds'] = $iDelay;
        }
        $aRows = $this->withoutPhantoms($aRows, $aMatches, $aHorizons);
        $aRows = array_merge($aRows, $this->unscheduledUpTo($sStopId, $aMatches, $aRows));
        if (!empty($aRows)) {
            usort($aRows, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        }
        return $aRows;
    }

    // Quita las salidas del horario sin tren en el feed, hasta la hora a la que el feed llega en su recorrido: el SIRI
    // lista todas las llegadas hasta esa hora, así que un tren que no sale ahí no existe.
    private function withoutPhantoms(array $aRows, array $aMatches, array $aHorizons): array
    {
        if (!$this->bFeedIsComplete) {
            return $aRows;
        }
        $iNow = time() - $this->iMidnight;
        $aKept = [];
        foreach ($aRows as $iRow => $aRow) {
            $iHorizon = $aHorizons[(string)($aRow['journey_pattern_id'] ?? '')] ?? null;
            $bUnseen = !isset($aMatches[$iRow]) && ($aRow['status'] ?? 'scheduled') === 'scheduled';
            $bCovered = $iHorizon !== null && (int)$aRow['arrival_seconds'] <= $iHorizon;
            if ($bUnseen && $bCovered && (int)$aRow['arrival_seconds'] > $iNow + self::BOARDING_GRACE_SECONDS) {
                continue;
            }
            $aKept[] = $aRow;
        }
        return $aKept;
    }

    // Los trenes del feed que pasan por la parada y no son ninguna salida del horario (refuerzos), como salidas sin
    // viaje: con la línea y el destino de los recorridos donde caben, y sin hora teórica ni retraso. Vacío si no se
    // pidió con $bFeedIsComplete.
    public function unscheduledRows(string $sStopId, array $aMatches = []): array
    {
        if (!$this->bFeedIsComplete || empty($this->aTrips)) {
            return [];
        }
        $aPatterns = $this->JourneyModel->patternsAtStop($sStopId);
        $aSequences = $this->JourneyModel->stopSequencesForPatterns(array_column($aPatterns, 'journey_pattern_id'));
        $aPairedTrips = [];
        foreach ($aMatches as $iPrediction) {
            $aPairedTrips[$iPrediction] = true;
        }
        $aFound = [];
        foreach ($aPatterns as $aPattern) {
            foreach ($this->tripsAtStop($sStopId, $aSequences[$aPattern['journey_pattern_id']] ?? []) as $iTrip => [$iSeconds]) {
                if (!isset($aPairedTrips[$iTrip])) {
                    $aFound[$iTrip]['seconds'] = $iSeconds;
                    $aFound[$iTrip]['patterns'][] = $aPattern;
                }
            }
        }
        $aRows = [];
        foreach ($aFound as $aTrain) {
            $aRows[] = self::unscheduledRow($aTrain['seconds'], $aTrain['patterns'], $sStopId);
        }
        return $aRows;
    }

    // Las salidas sin horario hasta la última del horario que se está mirando: más allá, un tren sin pareja puede ser
    // solo uno cuya salida del horario no se ha cargado.
    private function unscheduledUpTo(string $sStopId, array $aMatches, array $aRows): array
    {
        $aExtras = $this->unscheduledRows($sStopId, $aMatches);
        if (empty($aRows)) {
            return $aExtras;
        }
        $iLast = max(array_column($aRows, 'arrival_seconds'));
        return array_values(array_filter($aExtras, fn($aRow) => $aRow['arrival_seconds'] <= $iLast));
    }

    // El refuerzo que llega a la parada a esa hora (la de la clave del tren), con el recorrido donde mejor cabe y sus
    // paradas con hora en el feed: ['stops', 'sequence', 'line_code', 'line_name', 'headsign']. Null si ya no está.
    public function unscheduledTrain(string $sStopId, int $iArrivalTime): array|null
    {
        if (!$this->bFeedIsComplete || empty($this->aTrips)) {
            return null;
        }
        $aPatterns = $this->JourneyModel->patternsAtStop($sStopId);
        $aSequences = $this->JourneyModel->stopSequencesForPatterns(array_column($aPatterns, 'journey_pattern_id'));
        $aFits = [];
        foreach ($aPatterns as $aPattern) {
            $aSequence = $aSequences[$aPattern['journey_pattern_id']] ?? [];
            foreach ($this->tripsAtStop($sStopId, $aSequence) as $iTrip => [$iSeconds, $aAligned]) {
                $iGap = abs($iSeconds + $this->iMidnight - $iArrivalTime);
                if ($iGap <= self::EXTRA_KEY_TOLERANCE_SECONDS) {
                    $aFits[$iTrip] = ($aFits[$iTrip] ?? ['gap' => $iGap, 'options' => []]);
                    $aFits[$iTrip]['options'][] = [$aPattern, $aSequence, $aAligned];
                }
            }
        }
        if (empty($aFits)) {
            return null;
        }
        uasort($aFits, fn($aA, $aB) => $aA['gap'] <=> $aB['gap']);
        $aOptions = reset($aFits)['options'];
        $aUsual = self::usualPatterns(array_column($aOptions, 0));
        usort($aUsual, fn($aA, $aB) => $aB['trips'] <=> $aA['trips']);
        $aHeadsigns = array_values(array_unique(array_column($aUsual, 'headsign')));
        foreach ($aOptions as [$aPattern, $aSequence, $aAligned]) {
            if ($aPattern['journey_pattern_id'] === $aUsual[0]['journey_pattern_id']) {
                return [
                    'stops' => $aAligned, 'sequence' => $aSequence, 'line_code' => $aPattern['line_code'],
                    'line_name' => $aPattern['line_name'], 'headsign' => implode(' · ', $aHeadsigns),
                ];
            }
        }
        return null;
    }

    // De los recorridos donde cabe el tren, los que hace de verdad el horario: un recorrido con muy pocos viajes
    // (una vuelta corta) no se cuenta como posible destino de un tren que aún no ha dejado claro adónde va.
    private static function usualPatterns(array $aPatterns): array
    {
        $iMost = max(array_column($aPatterns, 'trips'));
        return array_values(array_filter($aPatterns, fn($aPattern) => $aPattern['trips'] * self::USUAL_PATTERN_SHARE >= $iMost));
    }

    private static function unscheduledRow(int $iSeconds, array $aPatterns, string $sStopId): array
    {
        $aPatterns = self::usualPatterns($aPatterns);
        $aHeadsigns = array_values(array_unique(array_column($aPatterns, 'headsign')));
        $aLastStops = array_values(array_unique(array_column($aPatterns, 'last_stop_name')));
        $aFirst = $aPatterns[0];
        // Si el tren aún no ha dejado claro adónde va, los destinos posibles, como en las cabeceras de la parada.
        $sHeadsign = implode(' · ', $aHeadsigns);
        $sLastStop = implode(' · ', $aLastStops);
        return [
            'line_id' => $aFirst['line_id'], 'line_code' => $aFirst['line_code'], 'line_name' => $aFirst['line_name'],
            'headsign' => $sHeadsign, 'last_stop_name' => $sLastStop, 'journey_pattern_id' => $aFirst['journey_pattern_id'],
            'trip_number' => null, 'service_journey_id' => null, 'first_departure_seconds' => null,
            'arrival_seconds' => $iSeconds, 'departure_seconds' => $iSeconds,
            'status' => 'live', 'etaSeconds' => $iSeconds, 'delaySeconds' => null, 'unscheduled' => true, 'stop_id' => $sStopId,
        ];
    }

    private function pair(string $sStopId, array $aRows): array
    {
        if (empty($this->aTrips) || empty($aRows)) {
            return [[], [], []];
        }

        $aPatternIds = array_values(array_unique(array_column($aRows, 'journey_pattern_id')));
        $aSequences = $this->JourneyModel->stopSequencesForPatterns($aPatternIds);

        $aPredictions = [];
        $aPairs = [];
        $aTripsByPattern = [];
        foreach ($aRows as $iRow => $aRow) {
            if (($aRow['status'] ?? 'scheduled') !== 'scheduled') {
                continue;
            }
            $sPattern = (string)$aRow['journey_pattern_id'];
            // La alineación de cada tren solo depende del recorrido: una vez por recorrido, no por salida.
            $aTripsByPattern[$sPattern] ??= $this->tripsAtStop($sStopId, $aSequences[$aRow['journey_pattern_id']] ?? []);
            foreach ($aTripsByPattern[$sPattern] as $iTrip => [$iSeconds, $aAligned]) {
                $aPredictions[$iTrip] = ['seconds' => $iSeconds, 'stopIds' => array_column($aAligned, 0), 'stops' => $aAligned];
                $iDifference = abs($iSeconds - (int)$aRow['arrival_seconds']);
                if ($iDifference <= $this->iToleranceSeconds) {
                    $aPairs[] = [$iDifference, $iRow, $iTrip, $iSeconds, $aAligned];
                }
            }
        }
        usort($aPairs, fn($aA, $aB) => $aA[0] <=> $aB[0]);

        $aMatches = [];
        $aUsedPredictions = [];
        foreach ($aPairs as [$iDifference, $iRow, $iTrip, $iSeconds, $aAligned]) {
            if (isset($aMatches[$iRow]) || isset($aUsedPredictions[$iTrip])) {
                continue;
            }
            $aMatches[$iRow] = $iTrip;
            $aUsedPredictions[$iTrip] = true;
            $aPredictions[$iTrip] = ['seconds' => $iSeconds, 'stopIds' => array_column($aAligned, 0), 'stops' => $aAligned];
        }
        $aHorizons = [];
        foreach ($aTripsByPattern as $sPattern => $aAtStop) {
            if (!empty($aAtStop)) {
                $aHorizons[$sPattern] = max(array_column($aAtStop, 0));
            }
        }
        return [$aPredictions, $aMatches, $aHorizons];
    }

    // Los trenes del feed que, alineados con la secuencia de un recorrido, pasan por la parada: [tren => [segundos desde
    // medianoche en la parada, paradas alineadas]].
    private function tripsAtStop(string $sStopId, array $aSequence): array
    {
        $aAtStop = [];
        foreach ($this->aTrips as $iTrip => $aTrip) {
            if (\count($aTrip['stops']) < 2) {
                continue;
            }
            // Un tren con destino conocido solo cabe en los recorridos que acaban allí.
            if (isset($aTrip['destStopId']) && end($aSequence) !== $aTrip['destStopId']) {
                continue;
            }
            $aAligned = FeedStopOrder::align($aTrip['stops'], $aSequence);
            if ($aAligned === null) {
                continue;
            }
            foreach ($aAligned as [$sTripStopId, $iTime]) {
                if ($sTripStopId === $sStopId) {
                    $aAtStop[$iTrip] = [$iTime - $this->iMidnight, $aAligned];
                    break;
                }
            }
        }
        return $aAtStop;
    }
}
