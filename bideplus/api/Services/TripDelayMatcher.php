<?php

namespace Services;

use Models\ServiceJourney;

// Directo por viaje (GTFS-Realtime de Renfe, Metro, Euskotren, tranvías, Tuvisa, Dbus y Álavabus): cada vehículo trae
// su viaje del horario y su hora en las paradas que le quedan o un retraso. Se empareja por el viaje, los que ya
// pasaron por la parada no salen y los cancelados se marcan.
class TripDelayMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    private const DUE_SECONDS = 60;
    private const STALE_PREDICTION_SECONDS = 60;
    private const MAX_STALE_PREDICTION_SECONDS = 3600;
    private const MAX_GPS_DELAY_SECONDS = 45 * 60;
    private const EARLY_MORNING_SECONDS = 4 * 3600;
    private const DAY_SECONDS = 86400;

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

    public function __construct(array $aTrips, private ServiceJourney $JourneyModel, int|null $iMidnight = null, array $aVehicles = [])
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
        $aFromVehicles = $this->vehicleEntries($aVehicles, $aVehicleJourneys);
        // Álavabus: donde hay GPS manda el GPS; sus horas previstas fallan a menudo (+35 min con el bus a +14).
        $bPreferVehicles = !empty(\Core\Config::current()['gtfs_rt']['prefer_vehicles']);
        $this->iZone = (new \DateTime('now', new \DateTimeZone('Europe/Madrid')))->getOffset();
        foreach ($aTrips as $aTrip) {
            $sJourneyId = $aJourneyIds[$aTrip['tripId']] ?? null;
            if ($sJourneyId === null || !$this->ofToday($aTrip) || ($bPreferVehicles && isset($aFromVehicles[$sJourneyId]))) {
                continue;
            }
            $aLive = $this->liveFromTrip($aTrip, (string)$sJourneyId);
            if (!$this->implausible((string)$sJourneyId, $aLive)) {
                $this->aByJourney[(string)$sJourneyId] = $aLive;
            }
        }
        $this->iMatched = \count($this->aByJourney);
        $this->bCopiesSchedule = $this->Trust->copiesSchedule($this->aByJourney);
        $this->bContradictsVehicles = $this->Trust->contradictsVehicles($this->aByJourney, $aVehicles, $aVehicleJourneys);
        if ($this->bCopiesSchedule || $this->bContradictsVehicles) {
            $this->aByJourney = array_filter($this->aByJourney, fn(array $aLive) => $aLive['canceled']);
        }
        $this->aByJourney += $aFromVehicles;
    }

    private function liveFromTrip(array $aTrip, string $sJourneyId): array
    {
        $sNextStopId = $aTrip['nextStopId'] ?? null;
        if (!empty($aTrip['stops'])) {
            $sNextStopId = (string)$aTrip['stops'][0][0];
        }
        $aStops = $this->Stops->of($sJourneyId);
        $iShift = $this->shiftFromNow((int)($aStops[0]['arrival_seconds'] ?? 0));
        $aTimes = [];
        if (!isset($aTrip['delay'])) {
            $iClock = FeedClock::shift($aTrip['stops'] ?? [], $aStops, $this->iMidnight, $this->iZone);
            if ($iClock > 0) {
                $this->iShifted++;
            }
            $aArrivals = array_column($aStops, 'arrival_seconds', 'stop_id');
            foreach ($aTrip['stops'] ?? [] as $iIndex => [$sStopId, $iTime]) {
                $iToday = (int)$iTime - $this->iMidnight - $iClock;
                if ($iIndex === 0 && isset($aArrivals[$sStopId])) {
                    $iShift = $this->dayShift((int)$aArrivals[$sStopId], $iToday);
                }
                $aTimes[(string)$sStopId] = $iToday + $iShift;
            }
        }
        return ['delay' => (int)($aTrip['delay'] ?? 0), 'times' => $aTimes, 'nextStopId' => $sNextStopId, 'canceled' => !empty($aTrip['canceled']), 'shift' => $iShift];
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

    // En las redes con gtfs_rt.max_feed_gap_seconds (Álavabus, 2 h), unas horas que se apartan más de su horario son
    // de otro viaje con ese número (da a veces el de hace horas): no valen, el viaje sale con su horario. Las demás no
    // tienen tope: un tren de Renfe con más de 2 h de retraso sigue saliendo con su retraso.
    private function implausible(string $sJourneyId, array $aLive): bool
    {
        $iMaxGap = \Core\Config::current()['gtfs_rt']['max_feed_gap_seconds'] ?? null;
        if ($iMaxGap === null) {
            return false;
        }
        foreach ($this->Stops->of($sJourneyId) as $aStop) {
            $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
            if ($iTime !== null) {
                return abs($iTime - (int)$aStop['arrival_seconds']) > $iMaxGap;
            }
        }
        return abs($aLive['delay']) > $iMaxGap;
    }

    // Días enteros (en segundos) que separan una hora de hoy de la del horario más cercana: 0 casi siempre, 24 h para
    // las 02:00 de un viaje anotado a las 26:00.
    private function dayShift(int $iScheduledSeconds, int $iTodaySeconds): int
    {
        return self::DAY_SECONDS * (int)round(($iScheduledSeconds - $iTodaySeconds) / self::DAY_SECONDS);
    }

    // Sin horas del directo, la referencia es la hora de ahora (solo con una medianoche de verdad: ahora entre 0 y 24 h).
    private function shiftFromNow(int $iScheduledSeconds): int
    {
        $iNow = $this->nowSeconds();
        if ($iNow < 0 || $iNow >= self::DAY_SECONDS) {
            return 0;
        }
        return $this->dayShift($iScheduledSeconds, $iNow);
    }

    // El retraso de cada vehículo con GPS por la parada hacia la que va, para los viajes que no traen horas.
    private function vehicleEntries(array $aVehicles, array $aJourneyIds): array
    {
        $aEntries = [];
        foreach ($aVehicles as $aVehicle) {
            $sJourneyId = (string)($aJourneyIds[$aVehicle['tripId']] ?? '');
            $sStopId = (string)($aVehicle['stopId'] ?? '');
            if ($sJourneyId === '' || $sStopId === '' || isset($aEntries[$sJourneyId])) {
                continue;
            }
            $iOrder = $this->Stops->orderOf($sJourneyId, $sStopId);
            $aStops = $this->Stops->of($sJourneyId);
            if ($iOrder === null || $iOrder === (int)end($aStops)['seq_order']) {
                continue;
            }
            $iArrival = $this->Stops->arrivalAt($sJourneyId, $iOrder);
            $iShift = $this->shiftFromNow($iArrival);
            $iDelay = max(0, $this->nowSeconds() + $iShift - $iArrival);
            if ($iDelay > self::MAX_GPS_DELAY_SECONDS) {
                continue;
            }
            $aEntries[$sJourneyId] = ['delay' => $iDelay, 'times' => [], 'nextStopId' => $sStopId, 'canceled' => false, 'shift' => $iShift];
        }
        return $aEntries;
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
        $aOut = [
            'matched' => $this->iMatched,
            'clockShifted' => $this->iShifted,
            'copiesSchedule' => $this->bCopiesSchedule,
            'contradictsVehicles' => $this->bContradictsVehicles,
            'live' => \count($aDelays),
            'cancelled' => \count($this->cancelledJourneyIds()),
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
