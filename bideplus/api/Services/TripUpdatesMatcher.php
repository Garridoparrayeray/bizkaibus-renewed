<?php

namespace Services;

use Models\ServiceJourney;

// Horas previstas por paradas (GTFS-Realtime de Metro Bilbao, Euskotren y los tranvías): empareja cada tren del feed
// con su viaje del horario por sus paradas y horas, y da la hora prevista en cada estación, sin los que ya salieron.
class TripUpdatesMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;

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
        if (empty($this->aTrips) || empty($aRows)) {
            return [[], []];
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
        return [$aPredictions, $aMatches];
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
