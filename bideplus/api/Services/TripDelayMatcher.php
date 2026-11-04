<?php

namespace Services;

use Models\ServiceJourney;

// Directo por viaje (GTFS-Realtime de Renfe, Metro, Tuvisa y Dbus): cada vehículo trae su viaje del horario y su hora
// prevista en las paradas que le quedan (Metro) o un retraso (Renfe). Se empareja por el viaje; los que ya pasaron por
// la parada no salen y los cancelados se marcan.
class TripDelayMatcher
{
    use DepartedRows;

    private const ON_TIME_SECONDS = 60;
    private const DUE_SECONDS = 60;

    // id del viaje del horario => ['delay' => segundos, 'times' => [parada => segundos desde medianoche],
    // 'nextStopId' => string|null, 'canceled' => bool]
    private array $aByJourney = [];
    private array $aStopsByJourney = [];
    private int $iMidnight;
    private bool $bRail;

    // $aVehicles: posiciones GPS (GtfsRealtimeClient::fetchVehiclePositions); las de trenes sin hora prevista cuentan.
    public function __construct(array $aTrips, private ServiceJourney $JourneyModel, int|null $iMidnight = null, array $aVehicles = [])
    {
        if ($iMidnight === null) {
            $iMidnight = (new \DateTime('today', new \DateTimeZone('Europe/Madrid')))->getTimestamp();
        }
        $this->iMidnight = $iMidnight;
        $this->bRail = \in_array(\Core\Config::current()['kind'] ?? 'bus', ['train', 'metro'], true);
        $aJourneyIds = $JourneyModel->journeyIdsForTripRefs(array_column($aTrips, 'tripId'));
        foreach ($aTrips as $aTrip) {
            $sJourneyId = $aJourneyIds[$aTrip['tripId']] ?? null;
            if ($sJourneyId === null) {
                continue;
            }
            $sNextStopId = $aTrip['nextStopId'] ?? null;
            if (!empty($aTrip['stops'])) {
                $sNextStopId = (string)$aTrip['stops'][0][0];
            }
            // Con retraso (Renfe), el retraso; sin él (Metro), la hora de cada parada que trae.
            $aTimes = [];
            foreach ($aTrip['stops'] ?? [] as [$sStopId, $iTime]) {
                if (!isset($aTrip['delay'])) {
                    $aTimes[(string)$sStopId] = (int)$iTime - $this->iMidnight;
                }
            }
            $this->aByJourney[(string)$sJourneyId] = [
                'delay' => (int)($aTrip['delay'] ?? 0),
                'times' => $aTimes,
                'nextStopId' => $sNextStopId,
                'canceled' => !empty($aTrip['canceled']),
            ];
        }
        $this->addVehicles($aVehicles);
    }

    // Un tren con posición GPS y sin hora prevista también va en directo: su retraso es lo que va tarde en la estación
    // por la que va (nunca adelantado: la posición no dice si ya ha llegado), y vale para las que le quedan.
    private function addVehicles(array $aVehicles): void
    {
        $aJourneyIds = $this->JourneyModel->journeyIdsForTripRefs(array_column($aVehicles, 'tripId'));
        foreach ($aVehicles as $aVehicle) {
            $sJourneyId = (string)($aJourneyIds[$aVehicle['tripId']] ?? '');
            $sStopId = (string)($aVehicle['stopId'] ?? '');
            if ($sJourneyId === '' || $sStopId === '' || isset($this->aByJourney[$sJourneyId])) {
                continue;
            }
            $iOrder = $this->orderOf($sJourneyId, $sStopId);
            if ($iOrder === null) {
                continue;
            }
            $iArrival = (int)$this->stops($sJourneyId)[$this->indexOf($sJourneyId, $iOrder)]['arrival_seconds'];
            $this->aByJourney[$sJourneyId] = [
                'delay' => max(0, $this->nowSeconds() - $iArrival),
                'times' => [],
                'nextStopId' => $sStopId,
                'canceled' => false,
            ];
        }
    }

    private function indexOf(string $sServiceJourneyId, int $iOrder): int
    {
        foreach ($this->stops($sServiceJourneyId) as $iIndex => $aStop) {
            if ((int)$aStop['seq_order'] === $iOrder) {
                return $iIndex;
            }
        }
        return 0;
    }

    public function hasData(): bool
    {
        return !empty($this->aByJourney);
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
            $aLive = $this->aByJourney[(string)$aRow['service_journey_id']] ?? null;
            if ($aLive === null && $this->missingFromFeed($aRow)) {
                $aOut[] = ['status' => 'likely_departed', 'etaSeconds' => (int)$aRow['arrival_seconds'], 'delaySeconds' => 0] + $aRow;
                continue;
            }
            if ($aLive === null || ($aRow['status'] ?? 'scheduled') !== 'scheduled') {
                $aOut[] = $aRow;
                continue;
            }
            if ($aLive['canceled']) {
                $aOut[] = ['status' => 'cancelled', 'etaSeconds' => (int)$aRow['arrival_seconds'], 'delaySeconds' => 0] + $aRow;
                continue;
            }
            if (!$this->hasRealData((string)$aRow['service_journey_id'], $aLive)) {
                $aOut[] = $aRow;
                continue;
            }
            $iEtaSeconds = $this->etaAt((string)$aRow['service_journey_id'], (int)$aRow['stop_seq'], (int)$aRow['arrival_seconds'], $aLive);
            if ($this->passed((string)$aRow['service_journey_id'], (int)$aRow['stop_seq'], $aLive['nextStopId'], $iEtaSeconds)) {
                continue;
            }
            $iDelay = $iEtaSeconds - (int)$aRow['arrival_seconds'];
            if (abs($iDelay) < self::ON_TIME_SECONDS) {
                $iDelay = 0;
            }
            $aOut[] = ['status' => 'live', 'etaSeconds' => $iEtaSeconds, 'delaySeconds' => $iDelay] + $aRow;
        }
        if ($this->hasData() && !empty($aOut)) {
            usort($aOut, fn($aA, $aB) => $aA['etaSeconds'] <=> $aB['etaSeconds']);
        }
        return $aOut;
    }

    // Hora prevista (marca de tiempo) en cada estación que le queda al tren: desde la siguiente y las que aún no ha
    // alcanzado por la hora (la de origen, si aún no ha salido). Null si no va en directo.
    public function liveTrip(string $sStopId, array $aRows, string $sServiceJourneyId): array|null
    {
        $aLive = $this->aByJourney[$sServiceJourneyId] ?? null;
        if ($aLive === null || $aLive['canceled'] || !$this->hasRealData($sServiceJourneyId, $aLive)) {
            return null;
        }
        $iFromOrder = 0;
        if ($aLive['nextStopId'] !== null) {
            $iFromOrder = $this->orderOf($sServiceJourneyId, $aLive['nextStopId']) ?? 0;
        }
        $aStops = [];
        foreach ($this->stops($sServiceJourneyId) as $aStop) {
            $iEtaSeconds = $this->etaAt($sServiceJourneyId, (int)$aStop['seq_order'], (int)$aStop['arrival_seconds'], $aLive);
            if ((int)$aStop['seq_order'] >= $iFromOrder || $iEtaSeconds > $this->nowSeconds()) {
                $aStops[] = [(string)$aStop['stop_id'], $this->iMidnight + $iEtaSeconds];
            }
        }
        return $aStops;
    }

    // El directo aporta algo si el tren ya ha salido de origen o si su hora prevista no es la del horario. Renfe y
    // Euskotren publican los trenes que aún no han salido con su hora de papel (retraso 0): eso es el horario.
    private function hasRealData(string $sServiceJourneyId, array $aLive): bool
    {
        $aStops = $this->stops($sServiceJourneyId);
        if (empty($aStops) || (int)$aStops[0]['departure_seconds'] <= $this->nowSeconds()) {
            return true;
        }
        if (empty($aLive['times'])) {
            return abs($aLive['delay']) >= self::ON_TIME_SECONDS;
        }
        foreach ($aStops as $aStop) {
            $iTime = $aLive['times'][(string)$aStop['stop_id']] ?? null;
            if ($iTime !== null && abs($iTime - (int)$aStop['arrival_seconds']) >= self::ON_TIME_SECONDS) {
                return true;
            }
        }
        return false;
    }

    // En los trenes (Metro, Renfe) el directo trae cada tren en marcha: uno que ya debería estar en la parada y no está en
    // el feed no sale el primero con su hora de papel, sino al final como «Sin datos del tren».
    private function missingFromFeed(array $aRow): bool
    {
        if (!$this->bRail || !$this->hasData() || ($aRow['status'] ?? 'scheduled') !== 'scheduled') {
            return false;
        }
        return (int)$aRow['arrival_seconds'] <= $this->nowSeconds() + self::DUE_SECONDS;
    }

    // La hora prevista en la parada de orden $iStopOrder: la del feed si la trae; si no, la de la siguiente parada que la
    // trae menos lo que se tarda hasta ella según el horario; sin horas por parada (Renfe), la del horario más el retraso.
    private function etaAt(string $sServiceJourneyId, int $iStopOrder, int $iArrivalSeconds, array $aLive): int
    {
        if (empty($aLive['times'])) {
            return $iArrivalSeconds + $aLive['delay'];
        }
        foreach ($this->stops($sServiceJourneyId) as $aStop) {
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
        $iNextOrder = $this->orderOf($sServiceJourneyId, $sNextStopId);
        return $iNextOrder !== null && $iNextOrder > $iStopOrder;
    }

    private function nowSeconds(): int
    {
        return time() - $this->iMidnight;
    }

    private function orderOf(string $sServiceJourneyId, string $sStopId): int|null
    {
        foreach ($this->stops($sServiceJourneyId) as $aStop) {
            if ((string)$aStop['stop_id'] === $sStopId) {
                return (int)$aStop['seq_order'];
            }
        }
        return null;
    }

    private function stops(string $sServiceJourneyId): array
    {
        if (!isset($this->aStopsByJourney[$sServiceJourneyId])) {
            $this->aStopsByJourney[$sServiceJourneyId] = $this->JourneyModel->stopsForJourney($sServiceJourneyId);
        }
        return $this->aStopsByJourney[$sServiceJourneyId];
    }
}
