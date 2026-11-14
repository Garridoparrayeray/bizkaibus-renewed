<?php

namespace Services;

use Models\ServiceJourney;

// Directo por andén con los trenes del feed abierto (GTFS-Realtime o SIRI) cuando sus ids no son viajes del horario.
// De cada tren se saca, con la secuencia de paradas del horario, por qué andén pasa (la parada siguiente) y adónde va
// (las terminales que admiten sus paradas). En cada andén los trenes se casan en orden con las salidas del horario de
// su destino; los que sobran salen sin horario y las salidas sin tren, dentro de lo que cubre el feed, no salen.
// Ajustes en la ficha de la red: gtfs_rt.platform_matching.
class PlatformMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    // Un tren con menos paradas no dice por qué andén va.
    private const MIN_STOPS = 2;
    // Las salidas del horario con las que se casan los trenes: desde lo que puede llevar de retraso el más tardío
    // hasta bien pasada la ventana del feed (de noche, con un tren por hora, hasta el siguiente).
    private const POOL_LIMIT = 100;
    private const POOL_WINDOW_SECONDS = 2 * 3600;
    // Cuánto puede apartarse la hora de la clave de un tren sin horario de la que da ahora el feed.
    private const EXTRA_KEY_TOLERANCE_SECONDS = 120;

    private array $aViews = [];

    // $aTrains: los trenes del feed, [['tripId', 'stops' => [[parada, hora unix], ...]]]. $sSource: «trip-updates» o
    // «siri-arrivals». $aSegments: lo que tarda un tren entre paradas según el horario (['A|B' => segundos]).
    public function __construct(private array $aTrains, private string $sSource, private ServiceJourney $JourneyModel, private int $iMidnight, private array $aSettings, private array $aSegments)
    {
    }

    public function source(): string
    {
        return $this->sSource;
    }

    public function trainCount(): int
    {
        return \count(array_filter($this->aTrains, fn(array $aTrain) => \count($aTrain['stops']) >= self::MIN_STOPS));
    }

    public function hasData(): bool
    {
        return $this->trainCount() > 0;
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aView = $this->view($sStopId);
        $aOut = [];
        foreach ($aRows as $aRow) {
            $iSeconds = $aView['live'][self::key($aRow)] ?? null;
            if ($iSeconds !== null) {
                $aOut[] = self::liveRow($aRow, $iSeconds);
            } elseif (!self::covered($aRow, $aView)) {
                $aOut[] = $aRow;
            }
        }
        array_push($aOut, ...$aView['extras']);
        usort($aOut, fn(array $aA, array $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        return $aOut;
    }

    // Los trenes que pasan por la parada sin salida en el horario (cuando el horario no tiene ninguna).
    public function unscheduledRows(string $sStopId): array
    {
        return $this->view($sStopId)['extras'];
    }

    // Las paradas que le quedan al tren casado con el viaje en la parada (ficha del tren), o null si no tiene tren.
    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        $aView = $this->view($sStopId);
        foreach ($aRows as $aRow) {
            $iAt = $aView['trainOf'][self::key($aRow)] ?? null;
            if ($iAt !== null && ($aRow['service_journey_id'] ?? null) === $sServiceJourneyId) {
                $aFits = $aView['arrivals'][$iAt]['fits'];
                $sTerminal = $aView['terminals'][(string)$aRow['journey_pattern_id']] ?? null;
                return ($aFits[$sTerminal] ?? reset($aFits))['stops'];
            }
        }
        return null;
    }

    // Un tren sin horario por la parada y la hora de su clave: sus paradas, el recorrido donde mejor cabe, su línea y
    // su destino. Null si ya no está.
    public function unscheduledTrain(string $sStopId, int $iArrivalTime): array|null
    {
        $aView = $this->view($sStopId);
        foreach ($aView['extras'] as $iExtra => $aExtra) {
            if (abs($this->iMidnight + $aExtra['etaSeconds'] - $iArrivalTime) <= self::EXTRA_KEY_TOLERANCE_SECONDS) {
                $sPattern = $aExtra['journey_pattern_id'];
                $aFits = $aView['unmatched'][$iExtra]['fits'];
                return [
                    'stops' => ($aFits[$aView['terminals'][$sPattern]] ?? reset($aFits))['stops'],
                    'sequence' => $aView['sequences'][$sPattern], 'line_code' => $aExtra['line_code'],
                    'line_name' => $aExtra['line_name'], 'headsign' => $aExtra['headsign'],
                ];
            }
        }
        return null;
    }

    // Diagnóstico de /api/status: de dónde salen los trenes, cuántos hay y de cuántos se sabe ya el destino.
    public function diagnostics(): array
    {
        return ['matching' => 'platform', 'trainSource' => $this->sSource, 'trains' => $this->trainCount()];
    }

    // Lo que dice el feed en la parada ya casado con el horario (ver assign()), más sus trenes sin horario como salidas
    // ('extras', en el orden de 'unmatched'). Una vez por parada.
    private function view(string $sStopId): array
    {
        if (isset($this->aViews[$sStopId])) {
            return $this->aViews[$sStopId];
        }
        $aPatterns = $this->JourneyModel->patternsAtStop($sStopId);
        $aSequences = $this->JourneyModel->stopSequencesForPatterns(array_column($aPatterns, 'journey_pattern_id'));
        $iNow = time();
        $aTrips = array_column($aPatterns, 'trips', 'journey_pattern_id');
        $aArrivals = self::arrivalsAt($sStopId, $this->aTrains, $aSequences, $aTrips, $this->aSegments, $iNow, $this->aSettings);
        $aView = self::assign($aArrivals, $this->pool($sStopId), self::nextStops($sStopId, $aSequences), self::terminalsOf($aSequences), $this->iMidnight, $iNow, $this->aSettings);
        $aView['arrivals'] = $aArrivals;
        $aView['sequences'] = $aSequences;
        $aView['extras'] = [];
        foreach ($aView['unmatched'] as $aArrival) {
            $aView['extras'][] = self::extraRow($aArrival, $aPatterns, $aView, $sStopId);
        }
        $this->aViews[$sStopId] = $aView;
        return $aView;
    }

    // Las salidas del horario de la parada: las de hoy y, de madrugada, las de ayer que pasan de las 24:00 (en horas
    // de hoy).
    private function pool(string $sStopId): array
    {
        $iLookback = $this->aSettings['max_delay_seconds'];
        $aToday = $this->JourneyModel->upcomingAtStop($sStopId, self::POOL_LIMIT, self::POOL_WINDOW_SECONDS, 0, $iLookback);
        $aYesterday = $this->JourneyModel->upcomingAtStop($sStopId, self::POOL_LIMIT, self::POOL_WINDOW_SECONDS, -1, $iLookback);
        return array_merge($aYesterday, $aToday);
    }

    // Los trenes del feed que pasan por la parada: [['train', 'time', 'next' (el andén), 'fits' => [terminal =>
    // ['stops']]]]. Sus destinos: las terminales de los recorridos donde caben sus paradas a un ritmo posible, solo la
    // que sigue a la última si el feed no da la terminal (exacta) y, si esa es de un recorrido raro, las de los usuales.
    public static function arrivalsAt(string $sStopId, array $aTrains, array $aSequences, array $aTrips, array $aSegments, int $iNow, array $aSettings): array
    {
        $iOmitted = (int)!empty($aSettings['omits_terminal']);
        $aArrivals = [];
        foreach ($aTrains as $iTrain => $aTrain) {
            if (\count($aTrain['stops']) < self::MIN_STOPS) {
                continue;
            }
            $aFits = [];
            foreach ($aSequences as $sPattern => $aSequence) {
                $aFit = self::fit($sStopId, $aTrain['stops'], $aSequence, $iOmitted, $aSegments, $aSettings['min_travel_share']);
                if ($aFit !== null) {
                    $aFits[] = $aFit + ['trips' => (int)($aTrips[$sPattern] ?? 0)];
                }
            }
            if (empty($aFits)) {
                continue;
            }
            $sNext = $aFits[0]['next'];
            $aFits = array_values(array_filter($aFits, fn(array $aFit) => $aFit['next'] === $sNext));
            $aByTerminal = [];
            foreach (self::likely($aFits, end($aTrain['stops'])[1] > $iNow + $aSettings['truncated_after_seconds'], $aSettings['usual_pattern_share']) as $aFit) {
                $aByTerminal[$aFit['terminal']] ??= ['stops' => $aFit['stops']];
            }
            $aArrivals[] = ['train' => $iTrain, 'time' => $aFits[0]['time'], 'next' => $sNext, 'fits' => $aByTerminal];
        }
        return $aArrivals;
    }

    // De los recorridos donde cabe un tren, a los que puede ir: los usuales (con al menos 1 de cada $iShare viajes del
    // que más tiene) y, de ellos, los que acaban justo tras su última parada si los hay y su lista no está cortada.
    private static function likely(array $aFits, bool $bTruncated, int $iShare): array
    {
        $iMost = max(array_column($aFits, 'trips'));
        $aUsual = array_values(array_filter($aFits, fn(array $aFit) => $aFit['trips'] * $iShare >= $iMost));
        $aExact = array_values(array_filter($aUsual, fn(array $aFit) => $aFit['exact']));
        if (!$bTruncated && !empty($aExact)) {
            return $aExact;
        }
        return $aUsual;
    }

    // Cómo cabe un tren en un recorrido que pasa por la parada: su hora en ella, la parada siguiente, la terminal y si
    // su lista acaba justo antes de ella ('exact'). Null si no cabe, si su lista pasa de la terminal o si va más rápido
    // de lo posible (con un id cambiado, «Urduliz» como «Gobela», cabría en el otro sentido).
    private static function fit(string $sStopId, array $aStops, array $aSequence, int $iOmitted, array $aSegments, float $dMinShare): array|null
    {
        $aAligned = FeedStopOrder::align($aStops, $aSequence);
        if ($aAligned === null || !self::possiblePace($aAligned, $aSequence, $aSegments, $dMinShare)) {
            return null;
        }
        $aIds = array_map('strval', array_column($aAligned, 0));
        $iAt = array_search($sStopId, $aIds, true);
        $iGap = \count($aSequence) - 1 - (int)array_search(end($aIds), $aSequence, true);
        if ($iAt === false || $iGap < $iOmitted) {
            return null;
        }
        $sNext = $aSequence[array_search($sStopId, $aSequence, true) + 1] ?? null;
        if ($sNext === null) {
            return null;
        }
        return ['time' => (int)$aAligned[$iAt][1], 'next' => (string)$sNext, 'terminal' => (string)end($aSequence), 'exact' => $iGap === $iOmitted, 'stops' => $aAligned];
    }

    // Si entre cada dos paradas seguidas del tren pasa al menos $dMinShare de lo que tarda el horario en ese tramo.
    private static function possiblePace(array $aAligned, array $aSequence, array $aSegments, float $dMinShare): bool
    {
        $aPositions = array_flip(array_map('strval', $aSequence));
        for ($iStop = 1; $iStop < \count($aAligned); $iStop++) {
            $iScheduled = 0;
            for ($iAt = $aPositions[(string)$aAligned[$iStop - 1][0]]; $iAt < $aPositions[(string)$aAligned[$iStop][0]]; $iAt++) {
                $iScheduled += $aSegments[$aSequence[$iAt] . '|' . $aSequence[$iAt + 1]] ?? 0;
            }
            if ($aAligned[$iStop][1] - $aAligned[$iStop - 1][1] < $iScheduled * $dMinShare) {
                return false;
            }
        }
        return true;
    }

    // La parada siguiente de cada recorrido que pasa por esta (la que da el andén): [recorrido => parada].
    public static function nextStops(string $sStopId, array $aSequences): array
    {
        $aNext = [];
        foreach ($aSequences as $sPattern => $aSequence) {
            $iAt = array_search($sStopId, $aSequence, true);
            if ($iAt !== false && isset($aSequence[$iAt + 1])) {
                $aNext[(string)$sPattern] = (string)$aSequence[$iAt + 1];
            }
        }
        return $aNext;
    }

    private static function terminalsOf(array $aSequences): array
    {
        return array_map(fn(array $aSequence) => (string)end($aSequence), $aSequences);
    }

    // Casa los trenes de la parada ($aArrivals, de arrivalsAt()) con las salidas del horario ($aRows), andén por andén:
    // primero los de destino seguro, cada destino en orden; luego los demás, en orden, con las salidas que les quedan
    // de alguno de sus destinos. Da 'live' y 'trainOf' (salida => segundos y tren), 'horizons' y 'unmatched'.
    public static function assign(array $aArrivals, array $aRows, array $aNext, array $aTerminals, int $iMidnight, int $iNow, array $aSettings): array
    {
        $aPlatforms = [];
        foreach ($aArrivals as $iAt => $aArrival) {
            // Un tren que el feed aún da con la hora pasada no ha salido: llega ahora, como el «0 min» de la app.
            $aArrivals[$iAt]['seconds'] = max($aArrival['time'], $iNow) - $iMidnight;
            $aArrivals[$iAt]['terminals'] = array_map('strval', array_keys($aArrival['fits']));
            $aPlatforms[$aArrival['next']]['trains'][] = $iAt;
        }
        foreach ($aRows as $aRow) {
            $sNext = $aNext[(string)$aRow['journey_pattern_id']] ?? null;
            if ($sNext !== null && isset($aPlatforms[$sNext])) {
                $aPlatforms[$sNext]['rows'][] = $aRow + ['terminal' => $aTerminals[(string)$aRow['journey_pattern_id']]];
            }
        }
        $aView = ['live' => [], 'trainOf' => [], 'horizons' => [], 'next' => $aNext, 'terminals' => $aTerminals];
        $iCovered = $iNow + $aSettings['covered_seconds'] - $iMidnight;
        foreach ($aPlatforms as $sNext => $aPlatform) {
            $aTrains = array_map(fn(int $iAt) => $aArrivals[$iAt] + ['at' => $iAt], $aPlatform['trains']);
            usort($aTrains, fn(array $aA, array $aB) => $aA['seconds'] <=> $aB['seconds']);
            $aPlatformRows = $aPlatform['rows'] ?? [];
            usort($aPlatformRows, fn(array $aA, array $aB) => (int)$aA['arrival_seconds'] <=> (int)$aB['arrival_seconds']);
            $aSure = array_filter($aTrains, fn(array $aTrain) => \count($aTrain['terminals']) === 1);
            foreach (array_unique(array_map(fn(array $aTrain) => $aTrain['terminals'][0], $aSure)) as $sTerminal) {
                $aGroup = array_filter($aSure, fn(array $aTrain) => $aTrain['terminals'][0] === $sTerminal);
                self::pairInOrder($aView, $aGroup, $aPlatformRows, $aSettings);
            }
            self::pairInOrder($aView, $aTrains, $aPlatformRows, $aSettings);
            $aView['horizons'][$sNext] = min(max(array_column($aTrains, 'seconds')), $iCovered);
        }
        $aUsed = array_flip($aView['trainOf']);
        $aView['unmatched'] = array_values(array_filter($aArrivals, fn(array $aArrival, int $iAt) => !isset($aUsed[$iAt]), ARRAY_FILTER_USE_BOTH));
        return $aView;
    }

    // Casa en orden los trenes que aún no tienen salida con las salidas libres y apunta las parejas en $aView.
    private static function pairInOrder(array &$aView, array $aTrains, array $aRows, array $aSettings): void
    {
        $aUsed = array_flip($aView['trainOf']);
        $aTrains = array_values(array_filter($aTrains, fn(array $aTrain) => !isset($aUsed[$aTrain['at']])));
        $aRows = array_values(array_filter($aRows, fn(array $aRow) => !isset($aView['trainOf'][self::key($aRow)])));
        foreach (self::inOrder($aTrains, $aRows, $aSettings['max_early_seconds'], $aSettings['max_delay_seconds']) as $iTrain => $iRow) {
            $sKey = self::key($aRows[$iRow]);
            $aView['live'][$sKey] = $aTrains[$iTrain]['seconds'];
            $aView['trainOf'][$sKey] = $aTrains[$iTrain]['at'];
        }
    }

    // Casa en orden los trenes (['seconds', 'terminals']) con las salidas (['arrival_seconds', 'terminal']), los dos
    // por hora: por un andén un tren no adelanta a otro. Una pareja va a uno de los destinos del tren, de $iMaxEarly
    // antes a $iMaxDelay después; gana la combinación con menos diferencia y un tren sin salida cuenta como $iMaxDelay.
    public static function inOrder(array $aTrains, array $aRows, int $iMaxEarly, int $iMaxDelay): array
    {
        $iTrains = \count($aTrains);
        $iRows = \count($aRows);
        $aCost = array_fill(0, $iTrains + 1, array_fill(0, $iRows + 1, 0));
        $aStep = $aCost;
        for ($iTrain = 0; $iTrain <= $iTrains; $iTrain++) {
            for ($iRow = 0; $iRow <= $iRows; $iRow++) {
                $aBest = [PHP_INT_MAX, 'none'];
                if ($iTrain > 0) {
                    $aBest = [$aCost[$iTrain - 1][$iRow] + $iMaxDelay + 1, 'train'];
                }
                if ($iRow > 0 && $aCost[$iTrain][$iRow - 1] < $aBest[0]) {
                    $aBest = [$aCost[$iTrain][$iRow - 1], 'row'];
                }
                $iDelay = null;
                if ($iTrain > 0 && $iRow > 0 && \in_array($aRows[$iRow - 1]['terminal'], $aTrains[$iTrain - 1]['terminals'], true)) {
                    $iDelay = $aTrains[$iTrain - 1]['seconds'] - (int)$aRows[$iRow - 1]['arrival_seconds'];
                }
                if ($iDelay !== null && $iDelay >= -$iMaxEarly && $iDelay <= $iMaxDelay && $aCost[$iTrain - 1][$iRow - 1] + abs($iDelay) < $aBest[0]) {
                    $aBest = [$aCost[$iTrain - 1][$iRow - 1] + abs($iDelay), 'pair'];
                }
                if ($iTrain > 0 || $iRow > 0) {
                    [$aCost[$iTrain][$iRow], $aStep[$iTrain][$iRow]] = $aBest;
                }
            }
        }
        $aPairs = [];
        while ($iTrains > 0 || $iRows > 0) {
            $sStep = $aStep[$iTrains][$iRows];
            if ($sStep === 'pair') {
                $aPairs[$iTrains - 1] = $iRows - 1;
            }
            if ($sStep !== 'row') {
                $iTrains--;
            }
            if ($sStep !== 'train') {
                $iRows--;
            }
        }
        return $aPairs;
    }

    private static function key(array $aRow): string
    {
        return ($aRow['service_journey_id'] ?? $aRow['trip_number'] ?? '') . '|' . (int)$aRow['arrival_seconds'];
    }

    // Una salida sin tren que la ventana del feed en su andén ya cubre: no existe.
    private static function covered(array $aRow, array $aView): bool
    {
        $sNext = $aView['next'][(string)($aRow['journey_pattern_id'] ?? '')] ?? '';
        $iHorizon = $aView['horizons'][$sNext] ?? null;
        return $iHorizon !== null && (int)$aRow['arrival_seconds'] <= $iHorizon;
    }

    private static function liveRow(array $aRow, int $iSeconds): array
    {
        $iDelay = $iSeconds - (int)$aRow['arrival_seconds'];
        if (abs($iDelay) < self::ON_TIME_SECONDS) {
            $iDelay = 0;
        }
        return ['status' => 'live', 'etaSeconds' => $iSeconds, 'delaySeconds' => $iDelay] + $aRow;
    }

    // Un tren del feed sin salida en el horario (refuerzo, o con más retraso del tope): con la línea y el destino del
    // recorrido de su andén con más viajes que acaba en una de sus terminales.
    private static function extraRow(array $aArrival, array $aPatterns, array $aView, string $sStopId): array
    {
        $aTerminals = array_map('strval', array_keys($aArrival['fits']));
        $aSame = array_values(array_filter($aPatterns, fn(array $aPattern) => ($aView['next'][(string)$aPattern['journey_pattern_id']] ?? null) === $aArrival['next']
            && \in_array($aView['terminals'][(string)$aPattern['journey_pattern_id']], $aTerminals, true)));
        usort($aSame, fn(array $aA, array $aB) => (int)$aB['trips'] <=> (int)$aA['trips']);
        $aPattern = $aSame[0];
        $iSeconds = $aArrival['seconds'];
        return [
            'line_id' => $aPattern['line_id'], 'line_code' => $aPattern['line_code'], 'line_name' => $aPattern['line_name'],
            'headsign' => $aPattern['last_stop_name'], 'last_stop_name' => $aPattern['last_stop_name'], 'journey_pattern_id' => $aPattern['journey_pattern_id'],
            'trip_number' => null, 'service_journey_id' => null, 'first_departure_seconds' => null,
            'arrival_seconds' => $iSeconds, 'departure_seconds' => $iSeconds,
            'status' => 'live', 'etaSeconds' => $iSeconds, 'delaySeconds' => null, 'unscheduled' => true, 'stop_id' => $sStopId,
        ];
    }
}
