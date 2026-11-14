<?php

namespace Services;

use Models\Search;
use Models\ServiceJourney;

// Directo de una red con llegadas oficiales por andén (StationArrivalsClient): en cada estación manda lo que dice la
// app del operador. Cada tren se casa con la salida del horario de su andén y su destino más cercana en hora; los que
// no tienen salida salen sin horario y las salidas sin tren, hasta donde llega la lista, no salen. Donde la API no
// responde o no llega, decide el emparejador de apoyo ($Inner: el GTFS-Realtime por viaje o los trenes del SIRI).
class StationArrivalsMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    // Un tren puede llegar como mucho tanto antes de su hora para ser el de una salida (el tope de retraso, en la
    // ficha: station_arrivals.max_delay_seconds).
    private const MAX_EARLY_SECONDS = 180;
    // Las salidas del horario con las que se casan los trenes de la API: desde lo que puede llevar de retraso el más
    // tardío hasta bien pasada la última que da la lista (de noche, con un tren por hora, hasta la siguiente).
    private const POOL_LIMIT = 100;
    private const POOL_WINDOW_SECONDS = 2 * 3600;
    // Lo que pueden apartarse el apoyo y la API en la hora del mismo tren (el SIRI va un minuto por detrás).
    private const SAME_TRAIN_SECONDS = 120;
    // Cuánto puede apartarse la hora de la clave de un tren sin horario de la que da ahora la API.
    private const EXTRA_KEY_TOLERANCE_SECONDS = 120;

    private array $aViews = [];

    public function __construct(private object $Inner, private StationArrivalsClient $Client, private ServiceJourney $JourneyModel, private int $iMidnight, private int $iMaxDelaySeconds)
    {
    }

    // El emparejador de apoyo, para el diagnóstico.
    public function inner(): object
    {
        return $this->Inner;
    }

    public function hasData(): bool
    {
        return $this->Client->enabled() || $this->Inner->hasData();
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aView = $this->view($sStopId);
        if ($aView === null) {
            return $this->Inner->enrich($sStopId, $aRows);
        }
        $aOut = [];
        $aRest = [];
        foreach ($aRows as $aRow) {
            $iSeconds = $aView['live'][self::key($aRow)] ?? null;
            if ($iSeconds !== null) {
                $aOut[] = self::liveRow($aRow, $iSeconds);
            } elseif (!self::covered($aRow, $aView, (int)$aRow['arrival_seconds'])) {
                $aRest[self::key($aRow)] = $aRow;
            }
        }
        // Más allá de la lista, el apoyo; sin sus trenes sin horario ni los que pone dentro de la lista, que los de la
        // API ya están todos (serían el mismo tren dos veces): esas salidas, con su horario.
        foreach ($this->Inner->enrich($sStopId, array_values($aRest)) as $aRow) {
            if (!empty($aRow['unscheduled'])) {
                continue;
            }
            if (self::covered($aRow, $aView, (int)$aRow['etaSeconds'] - self::SAME_TRAIN_SECONDS)) {
                $aRow = $aRest[self::key($aRow)] ?? $aRow;
            }
            $aOut[] = $aRow;
        }
        array_push($aOut, ...$aView['extras']);
        usort($aOut, fn(array $aA, array $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        return $aOut;
    }

    // Los trenes que pasan por la parada sin salida en el horario (cuando el horario no tiene ninguna).
    public function unscheduledRows(string $sStopId): array
    {
        $aView = $this->view($sStopId);
        if ($aView !== null) {
            return $aView['extras'];
        }
        if (method_exists($this->Inner, 'unscheduledRows')) {
            return $this->Inner->unscheduledRows($sStopId);
        }
        return [];
    }

    // El recorrido en directo de un viaje (ficha del tren): el del apoyo, movido para que en la parada llegue a la hora
    // que da la API; sin apoyo, solo esa parada (las siguientes llevan su retraso).
    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        $aInner = $this->Inner->liveTrip($sStopId, $aRows, $sServiceJourneyId);
        $aView = $this->view($sStopId);
        $iSeconds = null;
        foreach ($aRows as $aRow) {
            if (($aRow['service_journey_id'] ?? null) === $sServiceJourneyId) {
                $iSeconds ??= $aView['live'][self::key($aRow)] ?? null;
            }
        }
        if ($iSeconds === null) {
            return $aInner;
        }
        $iTime = $this->iMidnight + $iSeconds;
        $iInnerTime = null;
        foreach ($aInner ?? [] as $aStop) {
            if ((string)$aStop[0] === $sStopId) {
                $iInnerTime = (int)$aStop[1];
            }
        }
        if ($iInnerTime === null) {
            return [[$sStopId, $iTime]];
        }
        return array_map(fn(array $aStop) => [$aStop[0], (int)$aStop[1] + $iTime - $iInnerTime], $aInner);
    }

    // Un tren sin horario por la parada y la hora de su clave, como TripUpdatesMatcher::unscheduledTrain().
    public function unscheduledTrain(string $sStopId, int $iArrivalTime): array|null
    {
        foreach ($this->view($sStopId)['extras'] ?? [] as $aExtra) {
            $iTime = $this->iMidnight + $aExtra['etaSeconds'];
            if (abs($iTime - $iArrivalTime) <= self::EXTRA_KEY_TOLERANCE_SECONDS) {
                $sPattern = $aExtra['journey_pattern_id'];
                return [
                    'stops' => [[$sStopId, $iTime]], 'sequence' => $this->JourneyModel->stopSequencesForPatterns([$sPattern])[$sPattern] ?? [],
                    'line_code' => $aExtra['line_code'], 'line_name' => $aExtra['line_name'], 'headsign' => $aExtra['headsign'],
                ];
            }
        }
        if (method_exists($this->Inner, 'unscheduledTrain')) {
            return $this->Inner->unscheduledTrain($sStopId, $iArrivalTime);
        }
        return null;
    }

    // Diagnóstico de /api/status con una estación de muestra: cuántos trenes da la API y cuántos casan con el horario.
    public function diagnostics(string $sStopId): array
    {
        $aView = $this->view($sStopId);
        $aOut = ['source' => $this->Client->host(), 'matching' => 'station-arrivals', 'probeStopId' => $sStopId, 'stationTrains' => 0, 'stationLive' => 0];
        if ($aView !== null) {
            $aOut['stationTrains'] = $aView['trains'];
            $aOut['stationLive'] = \count($aView['live']);
        }
        return $aOut;
    }

    // Lo que da la API en la parada ya casado con el horario (ver assign()), más sus trenes sin horario como salidas
    // ('extras'). Null si la API no responde o la estación no tiene código. Una vez por parada.
    private function view(string $sStopId): array|null
    {
        if (\array_key_exists($sStopId, $this->aViews)) {
            return $this->aViews[$sStopId];
        }
        $this->aViews[$sStopId] = null;
        $aPatterns = $this->JourneyModel->patternsAtStop($sStopId);
        $aNext = self::nextStops($sStopId, $this->JourneyModel->stopSequencesForPatterns(array_column($aPatterns, 'journey_pattern_id')));
        $aDirections = $this->Client->arrivals($sStopId, array_values(array_unique($aNext)), time());
        if (empty(array_filter($aDirections, fn($aTrains) => $aTrains !== null))) {
            return null;
        }
        $aView = self::assign($aDirections, $this->pool($sStopId), $aNext, $this->iMidnight, $this->iMaxDelaySeconds, time());
        $aView['extras'] = [];
        foreach ($aView['unmatched'] as $aTrain) {
            $aView['extras'][] = self::extraRow($aTrain, $aPatterns, $aNext, $sStopId);
        }
        $this->aViews[$sStopId] = $aView;
        return $aView;
    }

    // Las salidas del horario de la parada: las de hoy y, de madrugada, las de ayer que pasan de las 24:00 (en horas
    // de hoy).
    private function pool(string $sStopId): array
    {
        $aToday = $this->JourneyModel->upcomingAtStop($sStopId, self::POOL_LIMIT, self::POOL_WINDOW_SECONDS, 0, $this->iMaxDelaySeconds);
        $aYesterday = $this->JourneyModel->upcomingAtStop($sStopId, self::POOL_LIMIT, self::POOL_WINDOW_SECONDS, -1, $this->iMaxDelaySeconds);
        return array_merge($aYesterday, $aToday);
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

    // Casa los trenes de la API con las salidas del horario de su andén ($aNext) y su destino, en orden (inOrder()). Da
    // 'live' (salida => segundos), 'horizons' (andén => último tren), 'unmatched' y 'trains'. Un tren con la hora ya
    // pasada aún no ha salido: llega $iNow.
    public static function assign(array $aDirections, array $aRows, array $aNext, int $iMidnight, int $iMaxDelay, int $iNow): array
    {
        $aTrains = [];
        $aGroups = [];
        foreach ($aDirections as $sNextStopId => $aList) {
            foreach ($aList ?? [] as $aTrain) {
                $aGroups[$sNextStopId . '|' . Search::normalize($aTrain['destination'])]['trains'][] = \count($aTrains);
                $aTrains[] = $aTrain + ['next' => (string)$sNextStopId, 'seconds' => max($aTrain['time'], $iNow) - $iMidnight];
            }
        }
        foreach ($aRows as $aRow) {
            $sGroup = ($aNext[(string)$aRow['journey_pattern_id']] ?? '') . '|' . Search::normalize((string)$aRow['last_stop_name']);
            if (isset($aGroups[$sGroup])) {
                $aGroups[$sGroup]['rows'][] = $aRow;
            }
        }
        $aLive = [];
        $aUsed = [];
        foreach ($aGroups as $aGroup) {
            $aGroupRows = $aGroup['rows'] ?? [];
            usort($aGroupRows, fn(array $aA, array $aB) => (int)$aA['arrival_seconds'] <=> (int)$aB['arrival_seconds']);
            $aIndexes = $aGroup['trains'];
            usort($aIndexes, fn(int $iA, int $iB) => $aTrains[$iA]['seconds'] <=> $aTrains[$iB]['seconds']);
            $aTimes = array_map(fn(int $iTrain) => $aTrains[$iTrain]['seconds'], $aIndexes);
            $aArrivals = array_map(fn(array $aRow) => (int)$aRow['arrival_seconds'], $aGroupRows);
            foreach (self::inOrder($aTimes, $aArrivals, $iMaxDelay) as $iTrain => $iRow) {
                $aLive[self::key($aGroupRows[$iRow])] = $aTimes[$iTrain];
                $aUsed[$aIndexes[$iTrain]] = true;
            }
        }
        $aHorizons = [];
        foreach ($aTrains as $aTrain) {
            $aHorizons[$aTrain['next']] = max($aHorizons[$aTrain['next']] ?? PHP_INT_MIN, $aTrain['seconds']);
        }
        return ['live' => $aLive, 'horizons' => $aHorizons, 'next' => $aNext, 'unmatched' => array_values(array_diff_key($aTrains, $aUsed)), 'trains' => \count($aTrains)];
    }

    // Casa en orden los trenes ($aTimes) con las salidas ($aArrivals), los dos por hora: en un andén un tren no adelanta
    // a otro de su destino. Gana la combinación con menos diferencia, un tren sin salida cuenta como el mayor retraso
    // y cada pareja va de MAX_EARLY_SECONDS antes a $iMaxDelay después. Devuelve [tren => salida].
    public static function inOrder(array $aTimes, array $aArrivals, int $iMaxDelay): array
    {
        $iTrains = \count($aTimes);
        $iRows = \count($aArrivals);
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
                $iDelay = PHP_INT_MAX;
                if ($iTrain > 0 && $iRow > 0) {
                    $iDelay = $aTimes[$iTrain - 1] - $aArrivals[$iRow - 1];
                }
                if ($iDelay >= -self::MAX_EARLY_SECONDS && $iDelay <= $iMaxDelay && $aCost[$iTrain - 1][$iRow - 1] + abs($iDelay) < $aBest[0]) {
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

    // Si la lista del andén de la salida llega a esa hora (no pasa de la de su último tren). Una salida sin tren que la
    // lista ya cubre no existe.
    private static function covered(array $aRow, array $aView, int $iSeconds): bool
    {
        $sNextStopId = $aView['next'][(string)($aRow['journey_pattern_id'] ?? '')] ?? null;
        $iHorizon = $aView['horizons'][$sNextStopId ?? ''] ?? null;
        return $iHorizon !== null && $iSeconds <= $iHorizon;
    }

    private static function liveRow(array $aRow, int $iSeconds): array
    {
        $iDelay = $iSeconds - (int)$aRow['arrival_seconds'];
        if (abs($iDelay) < self::ON_TIME_SECONDS) {
            $iDelay = 0;
        }
        return ['status' => 'live', 'etaSeconds' => $iSeconds, 'delaySeconds' => $iDelay] + $aRow;
    }

    // Un tren de la API sin salida en el horario (refuerzo o recorrido que el horario no tiene): con el destino que da
    // la API y la línea del recorrido de su andén que acaba allí o, si ninguno, del que más viajes tiene.
    public static function extraRow(array $aTrain, array $aPatterns, array $aNext, string $sStopId): array
    {
        $aSame = array_values(array_filter($aPatterns, fn(array $aPattern) => ($aNext[(string)$aPattern['journey_pattern_id']] ?? null) === $aTrain['next']));
        $sDestination = Search::normalize($aTrain['destination']);
        usort($aSame, fn(array $aA, array $aB) => [Search::normalize((string)$aB['last_stop_name']) === $sDestination, (int)$aB['trips']]
            <=> [Search::normalize((string)$aA['last_stop_name']) === $sDestination, (int)$aA['trips']]);
        $aPattern = $aSame[0] ?? $aPatterns[0];
        $iSeconds = $aTrain['seconds'];
        return [
            'line_id' => $aPattern['line_id'], 'line_code' => $aPattern['line_code'], 'line_name' => $aPattern['line_name'],
            'headsign' => $aTrain['destination'], 'last_stop_name' => $aTrain['destination'], 'journey_pattern_id' => $aPattern['journey_pattern_id'],
            'trip_number' => null, 'service_journey_id' => null, 'first_departure_seconds' => null,
            'arrival_seconds' => $iSeconds, 'departure_seconds' => $iSeconds,
            'status' => 'live', 'etaSeconds' => $iSeconds, 'delaySeconds' => null, 'unscheduled' => true, 'stop_id' => $sStopId,
        ];
    }
}
