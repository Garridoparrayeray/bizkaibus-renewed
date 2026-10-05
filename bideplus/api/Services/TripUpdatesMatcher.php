<?php

namespace Services;

use Models\ServiceJourney;

class TripUpdatesMatcher
{
    private const ON_TIME_SECONDS = 60;

    private int $iMidnight;

    public function __construct(private array $aTrips, private ServiceJourney $JourneyModel, private int $iToleranceSeconds, int|null $iMidnight = null)
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aPredictions = $this->predictionsAt($sStopId);
        if (empty($aPredictions) || empty($aRows)) {
            return $aRows;
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

        $aUsedRows = [];
        $aUsedPredictions = [];
        foreach ($aPairs as [$iDifference, $iRow, $iPrediction]) {
            if (isset($aUsedRows[$iRow]) || isset($aUsedPredictions[$iPrediction])) {
                continue;
            }
            $aUsedRows[$iRow] = true;
            $aUsedPredictions[$iPrediction] = true;
            $iSeconds = $aPredictions[$iPrediction]['seconds'];
            $aRows[$iRow]['status'] = 'live';
            $aRows[$iRow]['etaSeconds'] = $iSeconds;
            $iDelay = $iSeconds - (int)$aRows[$iRow]['arrival_seconds'];
            if (abs($iDelay) < self::ON_TIME_SECONDS) {
                $iDelay = 0;
            }
            $aRows[$iRow]['delaySeconds'] = $iDelay;
        }

        usort($aRows, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        return $aRows;
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
