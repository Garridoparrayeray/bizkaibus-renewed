<?php

namespace Services;

use Models\ServiceJourney;

// El retraso de cada vehículo por su GPS, en la parada hacia la que va: para los viajes que no traen horas y, en las
// redes con gtfs_rt.prefer_vehicles (sus horas previstas fallan a menudo), para todos los que llevan GPS, que mandan
// sobre las horas del feed. Lo usa TripDelayMatcher.
final class VehicleDelays
{
    private const MAX_DELAY_SECONDS = 45 * 60;

    // id del viaje => ['delay', 'times' => [], 'nextStopId', 'canceled', 'shift'], como las de TripDelayMatcher.
    private array $aEntries = [];
    // Viajes cuyo GPS da más de MAX_DELAY_SECONDS: el vehículo lleva el número de otro viaje.
    private array $aRejected = [];
    // Viajes cuyo vehículo está ya en la última parada: han terminado, digan lo que digan las horas del feed.
    private array $aFinished = [];
    private bool $bByPosition;

    public function __construct(array $aVehicles, array $aJourneyIds, private JourneyStops $Stops, private ServiceJourney $JourneyModel, private int $iNowSeconds)
    {
        $this->bByPosition = !empty(\Core\Config::current()['gtfs_rt']['prefer_vehicles']);
        foreach ($aVehicles as $aVehicle) {
            $sJourneyId = (string)($aJourneyIds[$aVehicle['tripId']] ?? '');
            if ($sJourneyId !== '' && !isset($this->aEntries[$sJourneyId])) {
                $this->add($sJourneyId, $aVehicle);
            }
        }
    }

    public function entries(): array
    {
        return $this->aEntries;
    }

    // Con gtfs_rt.prefer_vehicles, un viaje con GPS va con el GPS; si su GPS no vale (otro viaje), tampoco sus horas.
    // Y en cualquier red, un viaje con el vehículo en su última parada no va en directo.
    public function overrides(string $sJourneyId): bool
    {
        if (isset($this->aFinished[$sJourneyId])) {
            return true;
        }
        return $this->bByPosition && (isset($this->aEntries[$sJourneyId]) || isset($this->aRejected[$sJourneyId]));
    }

    // No cuentan los que están en la última parada (ya han terminado) ni los que darían más del tope (el de Bizkaibus).
    private function add(string $sJourneyId, array $aVehicle): void
    {
        $sStopId = (string)($aVehicle['stopId'] ?? '');
        $iOrder = $this->Stops->orderOf($sJourneyId, $sStopId);
        $aStops = $this->Stops->of($sJourneyId);
        if ($sStopId === '' || $iOrder === null) {
            return;
        }
        if ($iOrder === (int)end($aStops)['seq_order']) {
            $this->aFinished[$sJourneyId] = true;
            return;
        }
        $iArrival = $this->Stops->arrivalAt($sJourneyId, $iOrder);
        $iShift = ServiceDay::shiftFromNow($iArrival, $this->iNowSeconds);
        $iScheduled = $this->scheduledAt($sJourneyId, $iOrder, $iArrival, $aVehicle);
        $iDelay = max(0, $this->iNowSeconds + $iShift - $iScheduled);
        if ($iDelay > self::MAX_DELAY_SECONDS) {
            $this->aRejected[$sJourneyId] = true;
            return;
        }
        // El GPS lo pone pasada la parada del feed (la que acaba de dejar atrás): va a la siguiente. Si no, se
        // sumaría lo que lleva desde la hora de la que ya pasó, como si aún no hubiera llegado a ella.
        if ($iScheduled > $iArrival) {
            $sStopId = $this->Stops->after($sJourneyId, $iOrder) ?? $sStopId;
        }
        $this->aEntries[$sJourneyId] = ['delay' => $iDelay, 'times' => [], 'nextStopId' => $sStopId, 'canceled' => false, 'shift' => $iShift];
    }

    // La hora del horario donde está el vehículo: la de la parada que da el feed o, con gtfs_rt.prefer_vehicles (hay
    // feeds que dan la parada que acaba de dejar atrás), la de su posición GPS en el tramo antes o después de ella.
    private function scheduledAt(string $sJourneyId, int $iOrder, int $iArrival, array $aVehicle): int
    {
        if (!$this->bByPosition || !isset($aVehicle['lat'], $aVehicle['lon'])) {
            return $iArrival;
        }
        $aStops = array_values($this->Stops->of($sJourneyId));
        $iIndex = array_search($iOrder, array_map(fn($aStop) => (int)$aStop['seq_order'], $aStops), true);
        if ($iIndex === false) {
            return $iArrival;
        }
        $aNear = array_slice($aStops, max(0, $iIndex - 1), 3);
        $aCoords = $this->JourneyModel->stopCoordinates(array_column($aNear, 'stop_id'));
        return RoutePosition::scheduledSeconds($aStops, $iIndex, $aCoords, (float)$aVehicle['lat'], (float)$aVehicle['lon']) ?? $iArrival;
    }
}
