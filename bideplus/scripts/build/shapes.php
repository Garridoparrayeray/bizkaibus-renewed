<?php
// Trazados y paradas que casan: el GTFS de Bizkaibus dibuja algunos trazados al revés y, en otros, los metros de cada
// parada no caen sobre ella. Así el GPS no sitúa al bus; se le da la vuelta al trazado y se miden de nuevo las paradas.
// Sin metros en el GTFS (Lurraldebus, Bilbobus, Tuvisa, La Unión), se miden aquí: el trazado, de punto a punto, y las
// paradas sobre él.

declare(strict_types=1);

namespace Build;

use PDO;

class Shapes
{
    private const METERS_PER_DEGREE = 111320;
    // Una parada cuyos metros del GTFS caen a más de esto de ella, sobre el trazado, está mal medida.
    private const MAX_STOP_OFFSET_METERS = 100;
    // Al buscar la parada siguiente: hasta tres veces lo que hay en línea recta entre las dos, más este margen.
    private const SEARCH_SLACK_METERS = 500;
    private const ROAD_VS_STRAIGHT_RATIO = 3;
    // Entre dos pasos del bus por la misma zona gana el primero, salvo que el otro quede claramente más cerca.
    private const EARLIER_PASS_MARGIN_METERS = 30;

    public static function align(PDO $Pdo): array
    {
        // Corre antes de que la construcción cree los índices: sin este, cada consulta recorrería la tabla entera. Es el
        // mismo índice que crea después (Schema::createIndexes), así que se queda y no hay que quitarlo.
        $Pdo->exec('CREATE INDEX IF NOT EXISTS idx_passing_times_journey ON passing_times (service_journey_id, seq_order)');
        $iOrphaned = $Pdo->exec('UPDATE service_journeys SET shape_id = NULL WHERE shape_id IS NOT NULL AND shape_id NOT IN (SELECT id FROM shapes)');
        $aJourneysByShape = [];
        $Rows = $Pdo->query('SELECT id, shape_id FROM service_journeys WHERE shape_id IS NOT NULL');
        foreach ($Rows as $aRow) {
            $aJourneysByShape[$aRow['shape_id']][] = $aRow['id'];
        }
        $LoadShape = $Pdo->prepare('SELECT points FROM shapes WHERE id = ?');
        $LoadStops = $Pdo->prepare('
            SELECT pt.seq_order, pt.dist_m, s.lat, s.lon FROM passing_times pt JOIN stops s ON s.id = pt.stop_id
            WHERE pt.service_journey_id = ? ORDER BY pt.seq_order
        ');
        $SaveShape = $Pdo->prepare('UPDATE shapes SET points = ? WHERE id = ?');
        $SaveDistance = $Pdo->prepare('UPDATE passing_times SET dist_m = ? WHERE service_journey_id = ? AND seq_order = ?');

        $aTotals = ['reversed' => 0, 'remeasured' => 0, 'measured' => 0, 'orphaned' => (int)$iOrphaned];
        foreach ($aJourneysByShape as $sShapeId => $aJourneyIds) {
            $LoadShape->execute([$sShapeId]);
            $aPoints = json_decode((string)$LoadShape->fetchColumn(), true);
            $LoadShape->closeCursor();
            if (!\is_array($aPoints) || \count($aPoints) < 2) {
                continue;
            }
            $aStopsByJourney = [];
            foreach ($aJourneyIds as $sJourneyId) {
                $LoadStops->execute([$sJourneyId]);
                $aStopsByJourney[$sJourneyId] = $LoadStops->fetchAll(PDO::FETCH_ASSOC);
            }
            $bChanged = false;
            if (!self::hasMeters($aPoints)) {
                $aPoints = self::withMeters($aPoints);
                $bChanged = true;
                $aTotals['measured']++;
            }
            $bReversed = self::runsBackwards($aPoints, $aStopsByJourney);
            if ($bReversed) {
                $aPoints = self::flipped($aPoints);
                $bChanged = true;
                $aTotals['reversed']++;
            }
            if ($bChanged) {
                $SaveShape->execute([json_encode($aPoints), $sShapeId]);
            }
            foreach ($aStopsByJourney as $sJourneyId => $aStops) {
                // Sin metros en el GTFS no casa y se miden: así el motor sitúa al bus sobre el trazado, no en la recta
                // entre paradas (medido en la memoria, «Trazados que No Casan con sus Paradas»).
                if (!$bReversed && self::stopsMatch($aPoints, $aStops)) {
                    continue;
                }
                foreach (self::stopDistances($aPoints, $aStops) as $iSeq => $dMeters) {
                    $SaveDistance->execute([round($dMeters, 1), $sJourneyId, $iSeq]);
                }
                $aTotals['remeasured']++;
            }
        }
        return $aTotals;
    }

    // Al revés si, en la mayoría de sus viajes, la primera parada queda junto al final del trazado y la última junto
    // al principio.
    public static function runsBackwards(array $aPoints, array $aStopsByJourney): bool
    {
        $aStart = $aPoints[0];
        $aEnd = $aPoints[\count($aPoints) - 1];
        $iBackwards = 0;
        $iForwards = 0;
        foreach ($aStopsByJourney as $aStops) {
            if (\count($aStops) < 2) {
                continue;
            }
            $aFirst = self::coordinates($aStops[0]);
            $aLast = self::coordinates($aStops[\count($aStops) - 1]);
            $dAsDrawn = self::meters($aFirst, $aStart) + self::meters($aLast, $aEnd);
            $dReversed = self::meters($aFirst, $aEnd) + self::meters($aLast, $aStart);
            if ($dReversed < $dAsDrawn) {
                $iBackwards++;
            } else {
                $iForwards++;
            }
        }
        return $iBackwards > $iForwards;
    }

    public static function flipped(array $aPoints): array
    {
        $dTotal = (float)$aPoints[\count($aPoints) - 1][2];
        $aFlipped = [];
        foreach (array_reverse($aPoints) as $aPoint) {
            $aFlipped[] = [$aPoint[0], $aPoint[1], round($dTotal - (float)$aPoint[2], 2)];
        }
        return $aFlipped;
    }

    // El GTFS trae los metros del trazado (shape_dist_traveled) si pasan de 0 en la mayoría de los puntos: el de Bilbobus
    // los deja a 0 en todos menos el último.
    public static function hasMeters(array $aPoints): bool
    {
        $iWithMeters = 0;
        foreach ($aPoints as $aPoint) {
            if ((float)($aPoint[2] ?? 0) > 0) {
                $iWithMeters++;
            }
        }
        return $iWithMeters * 2 >= \count($aPoints);
    }

    // Los metros de cada punto del trazado desde el primero, sumando lo que hay de un punto al siguiente.
    public static function withMeters(array $aPoints): array
    {
        $dMeters = 0.0;
        $aMeasured = [];
        foreach ($aPoints as $i => $aPoint) {
            if ($i > 0) {
                $dMeters += self::meters([(float)$aPoints[$i - 1][0], (float)$aPoints[$i - 1][1]], [(float)$aPoint[0], (float)$aPoint[1]]);
            }
            $aMeasured[] = [$aPoint[0], $aPoint[1], round($dMeters, 2)];
        }
        return $aMeasured;
    }

    // Cada parada, a sus metros del GTFS sobre el trazado, cae junto a ella.
    public static function stopsMatch(array $aPoints, array $aStops): bool
    {
        $bSorted = self::isSorted($aPoints);
        foreach ($aStops as $aStop) {
            if ($aStop['dist_m'] === null) {
                return false;
            }
            $aOnShape = self::pointAt($aPoints, (float)$aStop['dist_m'], $bSorted);
            if (self::meters(self::coordinates($aStop), $aOnShape) > self::MAX_STOP_OFFSET_METERS) {
                return false;
            }
        }
        return true;
    }

    // Los metros de cada parada sobre el trazado: cada una se busca después de la anterior y no mucho más allá, para
    // no saltar a otro paso del bus por la misma zona.
    public static function stopDistances(array $aPoints, array $aStops): array
    {
        $aDistances = [];
        $dPrevious = 0.0;
        $aPreviousStop = null;
        $bSorted = self::isSorted($aPoints);
        foreach ($aStops as $aStop) {
            $aHere = self::coordinates($aStop);
            $dLimit = INF;
            if ($aPreviousStop !== null) {
                $dLimit = $dPrevious + self::ROAD_VS_STRAIGHT_RATIO * self::meters($aPreviousStop, $aHere) + self::SEARCH_SLACK_METERS;
            }
            $dPrevious = self::nearestAlong($aPoints, $aHere, $dPrevious, $dLimit, $bSorted);
            $aDistances[(int)$aStop['seq_order']] = $dPrevious;
            $aPreviousStop = $aHere;
        }
        return $aDistances;
    }

    private static function nearestAlong(array $aPoints, array $aStop, float $dFrom, float $dTo, bool $bSorted): float
    {
        $dBest = null;
        $dBestAlong = $dFrom;
        for ($i = self::firstSegmentFrom($aPoints, $dFrom, $bSorted); $i < \count($aPoints) - 1; $i++) {
            $aA = $aPoints[$i];
            $aB = $aPoints[$i + 1];
            if ($bSorted && (float)$aA[2] > $dTo) {
                break;
            }
            if ((float)$aB[2] < $dFrom || (float)$aA[2] > $dTo) {
                continue;
            }
            [$dFraction, $dDistance] = self::projection($aStop, $aA, $aB);
            if ($dBest === null || $dDistance < $dBest - self::EARLIER_PASS_MARGIN_METERS) {
                $dBest = $dDistance;
                $dBestAlong = max($dFrom, (float)$aA[2] + $dFraction * ((float)$aB[2] - (float)$aA[2]));
            }
        }
        return $dBestAlong;
    }

    private static function pointAt(array $aPoints, float $dAlong, bool $bSorted): array
    {
        $iLast = \count($aPoints) - 1;
        for ($i = self::firstSegmentFrom($aPoints, $dAlong, $bSorted); $i < $iLast; $i++) {
            $aA = $aPoints[$i];
            $aB = $aPoints[$i + 1];
            if ((float)$aB[2] < $dAlong && $i < $iLast - 1) {
                continue;
            }
            $dFraction = 0.0;
            if ((float)$aB[2] > (float)$aA[2]) {
                $dFraction = max(0.0, min(1.0, ($dAlong - (float)$aA[2]) / ((float)$aB[2] - (float)$aA[2])));
            }
            return [(float)$aA[0] + $dFraction * ((float)$aB[0] - (float)$aA[0]), (float)$aA[1] + $dFraction * ((float)$aB[1] - (float)$aA[1])];
        }
        return [(float)$aPoints[0][0], (float)$aPoints[0][1]];
    }

    // El primer tramo del trazado que llega a $dAlong metros; con los metros desordenados, el primero de todos.
    private static function firstSegmentFrom(array $aPoints, float $dAlong, bool $bSorted): int
    {
        if (!$bSorted) {
            return 0;
        }
        $iLow = 0;
        $iHigh = \count($aPoints) - 2;
        while ($iLow < $iHigh) {
            $iMiddle = intdiv($iLow + $iHigh, 2);
            if ((float)$aPoints[$iMiddle + 1][2] < $dAlong) {
                $iLow = $iMiddle + 1;
            } else {
                $iHigh = $iMiddle;
            }
        }
        return max(0, $iLow);
    }

    // Los metros del trazado crecen de un punto al siguiente (los del GTFS suelen; si no, se recorre entero).
    private static function isSorted(array $aPoints): bool
    {
        for ($i = 1; $i < \count($aPoints); $i++) {
            if ((float)$aPoints[$i][2] < (float)$aPoints[$i - 1][2]) {
                return false;
            }
        }
        return true;
    }

    private static function projection(array $aStop, array $aA, array $aB): array
    {
        $dCos = cos(deg2rad((float)$aA[0]));
        $dAx = (float)$aA[1] * $dCos;
        $dBx = (float)$aB[1] * $dCos;
        $dPx = $aStop[1] * $dCos;
        $dDx = $dBx - $dAx;
        $dDy = (float)$aB[0] - (float)$aA[0];
        $dLength = $dDx * $dDx + $dDy * $dDy;
        $dFraction = 0.0;
        if ($dLength > 0) {
            $dFraction = max(0.0, min(1.0, (($dPx - $dAx) * $dDx + ($aStop[0] - (float)$aA[0]) * $dDy) / $dLength));
        }
        $dX = $dAx + $dFraction * $dDx - $dPx;
        $dY = (float)$aA[0] + $dFraction * $dDy - $aStop[0];
        return [$dFraction, sqrt($dX * $dX + $dY * $dY) * self::METERS_PER_DEGREE];
    }

    private static function coordinates(array $aStop): array
    {
        return [(float)$aStop['lat'], (float)$aStop['lon']];
    }

    private static function meters(array $aA, array $aB): float
    {
        $dX = ((float)$aB[1] - (float)$aA[1]) * cos(deg2rad((float)$aA[0]));
        $dY = (float)$aB[0] - (float)$aA[0];
        return sqrt($dX * $dX + $dY * $dY) * self::METERS_PER_DEGREE;
    }
}
