<?php

namespace Services;

use Models\ServiceJourney;

// Directo por viaje (GTFS-Realtime de Renfe, Metro, Euskotren, tranvías, Tuvisa, Dbus y Álavabus): cada vehículo trae
// su viaje del horario y su hora en las paradas que le quedan o un retraso; sin horas, el de su GPS (VehicleDelays).
// Se empareja por el viaje, los que ya pasaron por la parada no salen y los cancelados se marcan.
class TripDelayMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    private const DUE_SECONDS = 60;
    private const STALE_PREDICTION_SECONDS = 60;
    private const OFF_OPERATOR_SECONDS = 120;
    private const MAX_STALE_PREDICTION_SECONDS = 3600;
    private const EARLY_MORNING_SECONDS = 4 * 3600;

    // id del viaje => ['delay', 'times' => [parada => segundos], 'nextStopId', 'canceled', 'shift']. 'times' va en las
    // horas del horario del viaje; 'shift' se suma a las de hoy para llegar a ellas (24 h de madrugada en un viaje de
    // ayer que pasa de las 24:00, 0 el resto del día).
    private array $aByJourney = [];
    private int $iMidnight;
    private int $iZone = 0;
    private int $iShifted = 0;
    private bool $bRail;
    private int $iMatched = 0;
    private bool $bCopiesSchedule = false;
    private bool $bContradictsVehicles = false;
    private JourneyStops $Stops;
    private FeedTrust $Trust;

    public function __construct(array $aTrips, ServiceJourney $JourneyModel, int|null $iMidnight = null, array $aVehicles = [])
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
        $this->bRail = \in_array(\Core\Config::current()['kind'] ?? 'bus', ['train', 'metro'], true);
        $this->Stops = new JourneyStops($JourneyModel);
        $this->Trust = new FeedTrust($this->Stops, $this->nowSeconds());
        $aJourneyIds = $JourneyModel->journeyIdsForTripRefs(array_column($aTrips, 'tripId'));
        $aVehicleJourneys = $JourneyModel->journeyIdsForTripRefs(array_column($aVehicles, 'tripId'));
        $Vehicles = new VehicleDelays($aVehicles, $aVehicleJourneys, $this->Stops, $JourneyModel, $this->nowSeconds());
        $this->iZone = (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))->getOffset();
        foreach ($aTrips as $aTrip) {
            $sJourneyId = $aJourneyIds[$aTrip['tripId']] ?? null;
            if ($sJourneyId === null || !$this->ofToday($aTrip) || $Vehicles->overrides((string)$sJourneyId)) {
                continue;
            }
            $aLive = $this->liveFromTrip($aTrip, (string)$sJourneyId);
            if (!$this->finished($aLive) && !$this->implausible((string)$sJourneyId, $aLive)) {
                $this->aByJourney[(string)$sJourneyId] = $aLive;
            }
        }
        $this->iMatched = \count($this->aByJourney);
        $this->bCopiesSchedule = $this->Trust->copiesSchedule($this->aByJourney);
        $this->bContradictsVehicles = $this->Trust->contradictsVehicles($this->aByJourney, $aVehicles, $aVehicleJourneys);
        if ($this->bCopiesSchedule || $this->bContradictsVehicles) {
            $this->aByJourney = array_filter($this->aByJourney, fn(array $aLive) => $aLive['canceled']);
        }
        $this->aByJourney += $Vehicles->entries();
    }

    private function liveFromTrip(array $aTrip, string $sJourneyId): array
    {
        $aStops = $this->Stops->of($sJourneyId);
        // Las paradas del feed en el orden del viaje: la que llega con el id de otra (Metro manda Urduliz como Gobela)
        // se arregla o se quita, para que no ponga su hora a una estación por la que el tren ya ha pasado.
        $aFeedStops = FeedStopOrder::fit($aTrip['stops'] ?? [], array_map(fn(array $aStop) => (string)$aStop['stop_id'], $aStops));
        $sNextStopId = $aTrip['nextStopId'] ?? null;
        // En la cabecera cuenta cuándo sale el vehículo, no cuándo llega a esperar su hora.
        $sOrigin = (string)($aStops[0]['stop_id'] ?? '');
        foreach ($aFeedStops as $iIndex => $aFeedStop) {
            if ((string)$aFeedStop[0] === $sOrigin && isset($aFeedStop[2])) {
                $aFeedStops[$iIndex][1] = $aFeedStop[2];
            }
        }
        if (!empty($aFeedStops)) {
            $sNextStopId = self::nextFeedStop($aFeedStops);
        }
        $iShift = ServiceDay::shiftFromNow((int)($aStops[0]['arrival_seconds'] ?? 0), $this->nowSeconds());
        $aTimes = [];
        if (!isset($aTrip['delay'])) {
            $iClock = FeedClock::shift($aFeedStops, $aStops, $this->iMidnight, $this->iZone);
            if ($iClock > 0) {
                $this->iShifted++;
            }
            $aArrivals = array_column($aStops, 'arrival_seconds', 'stop_id');
            foreach ($aFeedStops as $iIndex => [$sStopId, $iTime]) {
                $iToday = (int)$iTime - $this->iMidnight - $iClock;
                if ($iIndex === 0 && isset($aArrivals[$sStopId])) {
                    $iShift = ServiceDay::shift((int)$aArrivals[$sStopId], $iToday);
                }
                $aTimes[(string)$sStopId] = $iToday + $iShift;
            }
        }
        return ['delay' => (int)($aTrip['delay'] ?? 0), 'times' => $aTimes, 'nextStopId' => $sNextStopId, 'canceled' => !empty($aTrip['canceled']), 'shift' => $iShift];
    }

    // Con gtfs_rt.keeps_passed_stops, un viaje con todas las horas de sus paradas ya pasadas ha terminado (o ha salido
    // de las estaciones de la red) y sigue en el feed: no cuenta.
    private function finished(array $aLive): bool
    {
        if (empty(\Core\Config::current()['gtfs_rt']['keeps_passed_stops']) || empty($aLive['times'])) {
            return false;
        }
        return max($aLive['times']) - $aLive['shift'] < $this->nowSeconds() - self::STALE_PREDICTION_SECONDS;
    }

    // La parada a la que va: la primera del feed. Con gtfs_rt.keeps_passed_stops (Txik Txak sigue dando las paradas por
    // las que el bus ya ha pasado, con la hora a la que pasó), la primera cuya hora aún no ha llegado.
    private static function nextFeedStop(array $aFeedStops): string
    {
        if (empty(\Core\Config::current()['gtfs_rt']['keeps_passed_stops'])) {
            return (string)$aFeedStops[0][0];
        }
        foreach ($aFeedStops as [$sStopId, $iTime]) {
            if ((int)$iTime >= time()) {
                return (string)$sStopId;
            }
        }
        return (string)end($aFeedStops)[0];
    }

    // Un viaje de otro día (Álavabus deja en su feed viajes de días pasados, alguno de hace más de un año) no vale; de
    // madrugada, sí los de ayer que pasan de las 24:00.
    private function ofToday(array $aTrip): bool
    {
        if (!isset($aTrip['startDate'])) {
            return true;
        }
        $Today = (new \DateTime('@' . ($this->iMidnight + 12 * 3600)))->setTimezone(new \DateTimeZone('Europe/Madrid'));
        if ($aTrip['startDate'] === $Today->format('Ymd')) {
            return true;
        }
        return $this->nowSeconds() < self::EARLY_MORNING_SECONDS && $aTrip['startDate'] === $Today->modify('-1 day')->format('Ymd');
    }

    // Con gtfs_rt.max_feed_gap_seconds (Álavabus, 45 min), un retraso de más es de otro viaje con ese número (da a
    // veces el de hace horas): no vale y el viaje sale con su horario. Cuenta también el de la parada a la que va, con
    // lo que lleva de más si no ha llegado. Las demás redes no tienen tope: Renfe con +2 h sale con su retraso.
    private function implausible(string $sJourneyId, array $aLive): bool
    {
        $iMaxGap = \Core\Config::current()['gtfs_rt']['max_feed_gap_seconds'] ?? null;
        if ($iMaxGap === null) {
            return false;
        }
        $iNextOrder = null;
        if ($aLive['nextStopId'] !== null) {
            $iNextOrder = $this->Stops->orderOf($sJourneyId, (string)$aLive['nextStopId']);
        }
        if ($iNextOrder !== null) {
            $iNextArrival = $this->Stops->arrivalAt($sJourneyId, $iNextOrder);
            if (abs($this->etaAt($sJourneyId, $iNextOrder, $iNextArrival, $aLive) - $iNextArrival) > $iMaxGap) {
                return true;
            }
        }
        foreach ($this->Stops->of($sJourneyId) as $aStop) {
            $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
            if ($iTime !== null) {
                return abs($iTime - (int)$aStop['arrival_seconds']) > $iMaxGap;
            }
        }
        return abs($aLive['delay']) > $iMaxGap;
    }

    public function hasData(): bool
    {
        return !empty($this->aByJourney);
    }

    public function matchedJourneys(): int
    {
        return $this->iMatched;
    }

    public function diagnostics(): array
    {
        $aDelays = [];
        foreach ($this->aByJourney as $sJourneyId => $aLive) {
            $iDelay = $this->nextStopDelay((string)$sJourneyId, $aLive);
            if ($iDelay !== null) {
                $aDelays[] = $iDelay;
            }
        }
        [$iCompared, $iOff] = $this->offOperator();
        $aOut = [
            'matched' => $this->iMatched,
            'clockShifted' => $this->iShifted,
            'copiesSchedule' => $this->bCopiesSchedule,
            'contradictsVehicles' => $this->bContradictsVehicles,
            'live' => \count($aDelays),
            'cancelled' => \count($this->cancelledJourneyIds()),
            'compared' => $iCompared,
            'offOperator' => $iOff,
        ];
        $aSummary = DelayStats::summary($aDelays);
        if ($aSummary !== null) {
            $aOut['delayMinutes'] = $aSummary;
        }
        return $aOut;
    }

    private function nextStopDelay(string $sJourneyId, array $aLive): int|null
    {
        if ($aLive['canceled'] || $aLive['nextStopId'] === null || !$this->Trust->hasRealData($sJourneyId, $aLive)) {
            return null;
        }
        $iOrder = $this->Stops->orderOf($sJourneyId, $aLive['nextStopId']);
        if ($iOrder === null) {
            return null;
        }
        $iArrival = $this->Stops->arrivalAt($sJourneyId, $iOrder);
        return $this->etaAt($sJourneyId, $iOrder, $iArrival, $aLive) - $iArrival;
    }

    // Comprobación de que lo que se enseña es lo que publica el operador: de los viajes con horas del feed, en cuántos
    // la llegada a su primera parada con hora por venir se aparta dos minutos o más de esa hora.
    private function offOperator(): array
    {
        $iCompared = 0;
        $iOff = 0;
        foreach ($this->aByJourney as $sJourneyId => $aLive) {
            if ($aLive['canceled'] || empty($aLive['times'])) {
                continue;
            }
            foreach ($this->Stops->of((string)$sJourneyId) as $aStop) {
                $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
                if ($iTime === null || $iTime < $this->nowSeconds() + $aLive['shift']) {
                    continue;
                }
                $iCompared++;
                $iEta = $this->etaAt((string)$sJourneyId, (int)$aStop['seq_order'], (int)$aStop['arrival_seconds'], $aLive);
                $iOff += (int)(abs($iEta - $iTime) >= self::OFF_OPERATOR_SECONDS);
                break;
            }
        }
        return [$iCompared, $iOff];
    }

    public function isCancelled(string $sServiceJourneyId): bool
    {
        return !empty($this->aByJourney[$sServiceJourneyId]['canceled']);
    }

    public function cancelledJourneyIds(): array
    {
        $aIds = [];
        foreach ($this->aByJourney as $sJourneyId => $aLive) {
            if ($aLive['canceled']) {
                $aIds[] = (string)$sJourneyId;
            }
        }
        return $aIds;
    }

    public function enrich(string $sStopId, array $aRows): array
    {
        $aOut = [];
        foreach ($aRows as $aRow) {
            $aEnriched = $this->enrichRow($aRow);
            if ($aEnriched !== null) {
                $aOut[] = $aEnriched;
            }
        }
        if ($this->hasData() && !empty($aOut)) {
            usort($aOut, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        }
        return $aOut;
    }

    private function enrichRow(array $aRow): array|null
    {
        $sJourneyId = (string)$aRow['service_journey_id'];
        $aLive = $this->aByJourney[$sJourneyId] ?? null;
        if ($aLive === null && $this->missingFromFeed($aRow)) {
            return ['status' => 'likely_departed', 'etaSeconds' => (int)$aRow['arrival_seconds'], 'delaySeconds' => 0] + $aRow;
        }
        if ($aLive === null || ($aRow['status'] ?? 'scheduled') !== 'scheduled') {
            return $aRow;
        }
        if ($aLive['canceled']) {
            return ['status' => 'cancelled', 'etaSeconds' => (int)$aRow['arrival_seconds'], 'delaySeconds' => 0] + $aRow;
        }
        if (!$this->Trust->hasRealData($sJourneyId, $aLive)) {
            return $aRow;
        }
        $iEtaSeconds = $this->etaAt($sJourneyId, (int)$aRow['stop_seq'], (int)$aRow['arrival_seconds'], $aLive);
        if ($this->passed($sJourneyId, (int)$aRow['stop_seq'], $aLive['nextStopId'], $iEtaSeconds)) {
            return null;
        }
        $iDelay = $iEtaSeconds - (int)$aRow['arrival_seconds'];
        if (abs($iDelay) < self::ON_TIME_SECONDS) {
            $iDelay = 0;
        }
        return ['status' => 'live', 'etaSeconds' => $iEtaSeconds, 'delaySeconds' => $iDelay] + $aRow;
    }

    // Hora prevista (marca de tiempo) en cada estación que le queda al tren: desde la siguiente y las que aún no ha
    // alcanzado por la hora (la de origen, si aún no ha salido). Null si no va en directo.
    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        $aLive = $this->aByJourney[$sServiceJourneyId] ?? null;
        if ($aLive === null || $aLive['canceled'] || !$this->Trust->hasRealData($sServiceJourneyId, $aLive)) {
            return null;
        }
        $iFromOrder = 0;
        if ($aLive['nextStopId'] !== null) {
            $iFromOrder = $this->Stops->orderOf($sServiceJourneyId, $aLive['nextStopId']) ?? 0;
        }
        $aStops = [];
        foreach ($this->Stops->of($sServiceJourneyId) as $aStop) {
            $iEtaSeconds = $this->etaAt($sServiceJourneyId, (int)$aStop['seq_order'], (int)$aStop['arrival_seconds'], $aLive);
            if ((int)$aStop['seq_order'] >= $iFromOrder || $iEtaSeconds > $this->nowSeconds() + $aLive['shift']) {
                $aStops[] = [(string)$aStop['stop_id'], $this->iMidnight + $iEtaSeconds - $aLive['shift']];
            }
        }
        return $aStops;
    }

    private function missingFromFeed(array $aRow): bool
    {
        if (!$this->bRail || !$this->hasData() || ($aRow['status'] ?? 'scheduled') !== 'scheduled') {
            return false;
        }
        return (int)$aRow['arrival_seconds'] <= $this->nowSeconds() + self::DUE_SECONDS;
    }

    private function etaAt(string $sServiceJourneyId, int $iStopOrder, int $iArrivalSeconds, array $aLive): int
    {
        $iEta = $this->feedEtaAt($sServiceJourneyId, $iStopOrder, $iArrivalSeconds, $aLive);
        $iNextOrder = null;
        if ($aLive['nextStopId'] !== null) {
            $iNextOrder = $this->Stops->orderOf($sServiceJourneyId, $aLive['nextStopId']);
        }
        if ($iNextOrder === null || $iStopOrder < $iNextOrder) {
            return $iEta;
        }
        $iNextArrival = $this->Stops->arrivalAt($sServiceJourneyId, $iNextOrder);
        $iLate = $this->nowSeconds() + $aLive['shift'] - $this->feedEtaAt($sServiceJourneyId, $iNextOrder, $iNextArrival, $aLive);
        if ($iLate < self::STALE_PREDICTION_SECONDS || $iLate > self::MAX_STALE_PREDICTION_SECONDS) {
            return $iEta;
        }
        return $iEta + $iLate;
    }

    private function feedEtaAt(string $sServiceJourneyId, int $iStopOrder, int $iArrivalSeconds, array $aLive): int
    {
        if (empty($aLive['times'])) {
            return $iArrivalSeconds + $aLive['delay'];
        }
        foreach ($this->Stops->of($sServiceJourneyId) as $aStop) {
            $sStopId = (string)$aStop['stop_id'];
            if ((int)$aStop['seq_order'] >= $iStopOrder && isset($aLive['times'][$sStopId])) {
                return $aLive['times'][$sStopId] - ((int)$aStop['arrival_seconds'] - $iArrivalSeconds);
            }
        }
        return $iArrivalSeconds + $aLive['delay'];
    }

    // Ya pasó por la parada si la estación a la que va está después en su recorrido y su hora prevista en la parada ya ha
    // llegado: Renfe da a los trenes que esperan en la estación de origen la segunda como siguiente.
    private function passed(string $sServiceJourneyId, int $iStopOrder, string|null $sNextStopId, int $iEtaSeconds): bool
    {
        if ($sNextStopId === null || $iEtaSeconds > $this->nowSeconds()) {
            return false;
        }
        $iNextOrder = $this->Stops->orderOf($sServiceJourneyId, $sNextStopId);
        return $iNextOrder !== null && $iNextOrder > $iStopOrder;
    }

    private function nowSeconds(): int
    {
        return time() - $this->iMidnight;
    }
}
