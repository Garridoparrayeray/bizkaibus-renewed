<?php

namespace Services;

use Models\ServiceJourney;

class TripUpdatesMatcher
{
    private const ON_TIME_SECONDS = 60;
    private const LIVE_DEPARTED_AFTER_SECONDS = 45;
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

    private function pair(string $sStopId, array $aRows): array
    {
        $aPredictions = $this->predictionsAt($sStopId);
        if (empty($aPredictions) || empty($aRows)) {
            return [$aPredictions, []];
        }

        $aPatternIds = array_values(array_unique(array_column($aRows, 'journey_pattern_id')));
        $aSequences = $this->JourneyModel->stopSequencesForPatterns($aPatternIds);

        $aPairs = [];
        foreach ($aRows as $iRow => $aRow) {
            if (($aRow['status'] ?? 'scheduled') !== 'scheduled') {
                continue;
            }
            $aSequence = $aSequences[$aRow['journey_pattern_id']] ?? [];
            foreach ($aPredictions as $iPrediction => $aPrediction) {
                $iDifference = abs($aPrediction['seconds'] - (int)$aRow['arrival_seconds']);
                if ($iDifference <= $this->iToleranceSeconds && self::followsSequence($aPrediction['stopIds'], $aSequence)) {
                    $aPairs[] = [$iDifference, $iRow, $iPrediction];
                }
            }
        }
        usort($aPairs, fn($aA, $aB) => $aA[0] <=> $aB[0]);

        $aMatches = [];
        $aUsedPredictions = [];
        foreach ($aPairs as [$iDifference, $iRow, $iPrediction]) {
            if (isset($aMatches[$iRow]) || isset($aUsedPredictions[$iPrediction])) {
                continue;
            }
            $aMatches[$iRow] = $iPrediction;
            $aUsedPredictions[$iPrediction] = true;
        }
        return [$aPredictions, $aMatches];
    }

    private function predictionsAt(string $sStopId): array
    {
        $aPredictions = [];
        foreach ($this->aTrips as $aTrip) {
            if (\count($aTrip['stops']) < 2) {
                continue;
            }
            foreach ($aTrip['stops'] as [$sTripStopId, $iTime]) {
                if ($sTripStopId === $sStopId) {
                    $aPredictions[] = [
                        'seconds' => $iTime - $this->iMidnight,
                        'stopIds' => array_column($aTrip['stops'], 0),
                        'stops' => $aTrip['stops'],
                    ];
                    break;
                }
            }
        }
        return $aPredictions;
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
