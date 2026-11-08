<?php

namespace Services;

// Las paradas de un tren en el feed, puestas en el orden de su recorrido. Algunos feeds mandan una parada con el id de
// otra: el de Metro Bilbao manda Urduliz como Gobela («20.0» en vez de «29.0») en los viajes del ramal de Plentzia. Si
// quitando una sola parada el resto va en orden y su sitio es el hueco que queda entre sus vecinas, se le pone el id de
// ese hueco. Lo usan los dos emparejadores de horas previstas: TripUpdatesMatcher (por paradas) y TripDelayMatcher
// (por viaje).
final class FeedStopOrder
{
    // Las paradas en orden, con la que llega con otro id arreglada, o null si no está claro cuál es.
    public static function align(array $aStops, array $aSequence): array|null
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
        if ($bTie) {
            return null;
        }
        return $aCandidate;
    }

    // Como align, pero sin quedarse sin nada: si no se puede arreglar, quedan solo las paradas que avanzan por el
    // recorrido, y las que van hacia atrás o no son de él se quitan.
    public static function fit(array $aStops, array $aSequence): array
    {
        if (empty($aStops) || empty($aSequence)) {
            return $aStops;
        }
        $aAligned = self::align($aStops, $aSequence);
        if ($aAligned !== null) {
            return $aAligned;
        }
        $aKept = [];
        $iLast = -1;
        foreach ($aStops as $aStop) {
            $Position = array_search((string)$aStop[0], $aSequence, true);
            if ($Position !== false && $Position > $iLast) {
                $aKept[] = $aStop;
                $iLast = $Position;
            }
        }
        return $aKept;
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
}
