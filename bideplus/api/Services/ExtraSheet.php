<?php

namespace Services;

use Core\Config;
use Core\Live;
use Models\ServiceJourney;

// Ficha de un tren que el horario no tiene (refuerzo, huelga, festivo): se vuelve a buscar en el feed por la parada y
// la hora de su clave, y el recorrido se completa con lo que tarda un tren entre paradas según el horario: hacia atrás,
// las que ya pasó (de dónde viene); hacia delante, las que le quedan tras las que el feed da.
class ExtraSheet
{
    public static function build(ServiceJourney $JourneyModel, string $sStopId, int $iArrivalTime, string|null $sPreferredStopId): array|null
    {
        $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        $Matcher = Live::tripMatcher($JourneyModel, $iMidnight);
        if (!($Matcher instanceof TripUpdatesMatcher)) {
            return null;
        }
        $aTrain = $Matcher->unscheduledTrain($sStopId, $iArrivalTime);
        if ($aTrain === null) {
            return null;
        }
        $aSegments = (new StopArrivalTrips(Config::current(), $JourneyModel))->segments();
        $aTimes = self::timesAlong($aTrain['sequence'], $aTrain['stops'], $aSegments);
        $aNames = $JourneyModel->stopNames($aTrain['sequence']);

        $iNow = Calendar::nowSecondsSinceMidnight();
        $bCurrentSet = false;
        $aStopsOut = [];
        foreach ($aTrain['sequence'] as $sSequenceStop) {
            if (!isset($aTimes[$sSequenceStop])) {
                continue;
            }
            $iEta = $aTimes[$sSequenceStop] - $iMidnight;
            $bPast = $iEta < $iNow - TripSheet::PASSED_MARGIN_SECONDS;
            $bCurrent = !$bPast && !$bCurrentSet;
            $bCurrentSet = $bCurrentSet || $bCurrent;
            $aStop = ['stop_id' => $sSequenceStop, 'name' => $aNames[$sSequenceStop] ?? $sSequenceStop, 'arrival_seconds' => $iEta];
            $aStopsOut[] = TripSheet::stopEntry($aStop, self::etaIf(!$bPast, $iEta), $bPast, $bCurrent, $iNow);
        }
        $aJourney = ['line_code' => $aTrain['line_code'], 'line_name' => $aTrain['line_name'], 'headsign' => $aTrain['headsign']];
        return TripSheet::sheet($aJourney, 'live', null, null, $aStopsOut, ['feedTimes' => true]);
    }

    private static function etaIf(bool $bKeep, int $iEta): int|null
    {
        if ($bKeep) {
            return $iEta;
        }
        return null;
    }

    // La hora de cada parada del recorrido: las del feed y, a partir de ellas, las de las paradas de antes (restando lo
    // que tarda el tren) y las de después (sumándolo). [parada => hora unix]; las que no se pueden calcular no salen.
    public static function timesAlong(array $aSequence, array $aKnown, array $aSegments): array
    {
        $aTimes = [];
        foreach ($aKnown as [$sStopId, $iTime]) {
            $aTimes[(string)$sStopId] = $iTime;
        }
        $aOrder = array_values($aSequence);
        for ($iAt = 0; $iAt < \count($aOrder); $iAt++) {
            if (isset($aTimes[$aOrder[$iAt]])) {
                break;
            }
        }
        if ($iAt >= \count($aOrder)) {
            return [];
        }
        for ($i = $iAt - 1; $i >= 0; $i--) {
            $iSeconds = $aSegments[$aOrder[$i] . '|' . $aOrder[$i + 1]] ?? null;
            if ($iSeconds === null) {
                break;
            }
            $aTimes[$aOrder[$i]] = $aTimes[$aOrder[$i + 1]] - $iSeconds;
        }
        for ($i = $iAt + 1; $i < \count($aOrder); $i++) {
            if (isset($aTimes[$aOrder[$i]])) {
                continue;
            }
            $iSeconds = $aSegments[$aOrder[$i - 1] . '|' . $aOrder[$i]] ?? null;
            if ($iSeconds === null) {
                break;
            }
            $aTimes[$aOrder[$i]] = $aTimes[$aOrder[$i - 1]] + $iSeconds;
        }
        return $aTimes;
    }
}
