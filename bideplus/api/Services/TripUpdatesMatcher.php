<?php

namespace Services;

use Models\ServiceJourney;

class TripUpdatesMatcher
{
    private const ON_TIME_SECONDS = 60;
    public const LIVE_DEPARTED_AFTER_SECONDS = 45;
    private const SCHEDULED_DEPARTED_AFTER_SECONDS = 60;

    private int $iMidnight;

    public function __construct(private array $aTrips, private ServiceJourney $JourneyModel, private int $iToleranceSeconds, int|null $iMidnight = null)
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
    }

    public function hasData(): bool
    {
        return !empty($this->aTrips);
    }

    /*
     * El feed publica un tren en una parada hasta ~35 s (máximo ~90 s) después de su hora y luego lo quita.
     * Con el feed activo, un tren que ya no aparece y pasó de su hora hace más de un minuto ya ha salido:
     * mantenerlo como «0 min» lo hacía parecer un tren repetido junto al que llega.
     */
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

    /*
     * Predicciones del feed para el viaje de la salida `$sServiceJourneyId`, emparejado como en enrich()
     * (mismo reparto entre las salidas de la parada `$sStopId`). Devuelve las paradas que le quedan al tren
     * como [[stopId, hora Unix], ...] o null si el feed no lo trae.
     */
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
        [$aPredictions, $aMatches] = $this->pair($sStopId, $aRows);
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
        if (!empty($aPredictions) && !empty($aRows)) {
            usort($aRows, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        }
        return $aRows;
    }

    /*
     * Empareja las salidas programadas de la parada con los trenes del feed. Para cada salida se alinea la lista de
     * paradas de cada tren con el patrón de esa salida: el feed del CTB a veces publica mal el id de una parada
     * (Urduliz, 29, sale como Gobela, 20), así que un tren con una sola parada fuera de orden no se descarta: esa
     * parada se corrige con la que le toca en el patrón. Con más paradas fuera de orden (tren de otro sentido) sí se
     * descarta. Devuelve [previsiones candidatas por tren, emparejamientos fila => tren].
     */
    private function pair(string $sStopId, array $aRows): array
    {
        if (empty($this->aTrips) || empty($aRows)) {
            return [[], []];
        }

        $aPatternIds = array_values(array_unique(array_column($aRows, 'journey_pattern_id')));
        $aSequences = $this->JourneyModel->stopSequencesForPatterns($aPatternIds);

        $aPredictions = [];
        $aPairs = [];
        foreach ($aRows as $iRow => $aRow) {
            if (($aRow['status'] ?? 'scheduled') !== 'scheduled') {
                continue;
            }
            $aSequence = $aSequences[$aRow['journey_pattern_id']] ?? [];
            foreach ($this->aTrips as $iTrip => $aTrip) {
                if (\count($aTrip['stops']) < 2) {
                    continue;
                }
                $aAligned = self::alignToSequence($aTrip['stops'], $aSequence);
                if ($aAligned === null) {
                    continue;
                }
                $iSeconds = null;
                foreach ($aAligned as [$sTripStopId, $iTime]) {
                    if ($sTripStopId === $sStopId) {
                        $iSeconds = $iTime - $this->iMidnight;
                        break;
                    }
                }
                if ($iSeconds === null) {
                    continue;
                }
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
        return [$aPredictions, $aMatches];
    }

    /*
     * Lista de paradas del tren ajustada al patrón: la misma lista si todas siguen el orden del patrón; si basta quitar
     * una parada para que lo sigan, esa parada se corrige por la que le toca (la siguiente a la anterior en el patrón,
     * o la anterior a la siguiente si es la primera); null si el tren no sigue ese patrón o la corrección no es única.
     */
    private static function alignToSequence(array $aStops, array $aSequence): array|null
    {
        $aPositions = [];
        foreach ($aStops as [$sStopId]) {
            $aPositions[] = array_search((string)$sStopId, $aSequence, true);
        }
        if (self::increasing($aPositions)) {
            return $aStops;
        }
        if (\count($aStops) < 3) {
            return null;
        }

        $aCandidate = null;
        $iBestScore = -1;
        $bTie = false;
        foreach (array_keys($aStops) as $iOutlier) {
            $aRest = $aPositions;
            unset($aRest[$iOutlier]);
            if (!self::increasing(array_values($aRest))) {
                continue;
            }
            $iPrevious = $aPositions[$iOutlier - 1] ?? null;
            $iNext = $aPositions[$iOutlier + 1] ?? null;
            $iExpected = null;
            if ($iOutlier > 0 && $iPrevious !== false && $iPrevious !== null) {
                $iExpected = $iPrevious + 1;
            } elseif ($iOutlier === 0 && $iNext !== false && $iNext !== null) {
                $iExpected = $iNext - 1;
            }
            if ($iExpected === null || !isset($aSequence[$iExpected]) || \in_array($iExpected, $aPositions, true)) {
                continue;
            }
            $aFixed = $aStops;
            $aFixed[$iOutlier][0] = (string)$aSequence[$iExpected];
            $aFixedPositions = $aPositions;
            $aFixedPositions[$iOutlier] = $iExpected;
            if (!self::increasing($aFixedPositions)) {
                continue;
            }
            // Si hay varias correcciones posibles, gana la que deja la parada corregida pegada a sus dos vecinas en el patrón.
            $iScore = 0;
            if ($iPrevious !== null && $iPrevious !== false && $iExpected === $iPrevious + 1) {
                $iScore++;
            }
            if ($iNext !== null && $iNext !== false && $iExpected === $iNext - 1) {
                $iScore++;
            }
            if ($aCandidate === null || $iScore > $iBestScore) {
                $aCandidate = $aFixed;
                $iBestScore = $iScore;
                $bTie = false;
            } elseif ($iScore === $iBestScore) {
                $bTie = true;
            }
        }
        return $bTie ? null : $aCandidate;
    }

    private static function increasing(array $aPositions): bool
    {
        $iLast = -1;
        foreach ($aPositions as $Position) {
            if ($Position === false || $Position <= $iLast) {
                return false;
            }
            $iLast = $Position;
        }
        return true;
    }

    private static function followsSequence(array $aStopIds, array $aSequence): bool
    {
        $iLastPosition = -1;
        foreach ($aStopIds as $sStopId) {
            $Position = array_search((string)$sStopId, $aSequence, true);
            if ($Position === false || $Position <= $iLastPosition) {
                return false;
            }
            $iLastPosition = $Position;
        }
        return true;
    }
}
