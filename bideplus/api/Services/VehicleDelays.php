<?php

namespace Services;

use Models\ServiceJourney;

// El retraso de cada vehículo por su GPS, en la parada hacia la que va: para los viajes que no traen horas y, en las
// redes con gtfs_rt.prefer_vehicles (sus horas previstas fallan a menudo) o sin horas previstas (solo posiciones), para
// todos los que llevan GPS, que mandan sobre las horas del feed. Lo usa TripDelayMatcher.
final class VehicleDelays
{
    private const MAX_DELAY_SECONDS = 45 * 60;
    // Un vehículo sin viaje se casa con uno de su línea que haya salido hace poco o vaya a salir en estos minutos.
    private const EARLY_START_SECONDS = 10 * 60;

    // id del viaje => ['delay', 'times' => [], 'nextStopId', 'canceled', 'shift'], como las de TripDelayMatcher.
    private array $aEntries = [];
    // Viajes cuyo GPS da más de MAX_DELAY_SECONDS: el vehículo lleva el número de otro viaje.
    private array $aRejected = [];
    // Viajes cuyo vehículo está ya en la última parada: han terminado, digan lo que digan las horas del feed.
    private array $aFinished = [];
    private array $aCoords = [];
    private bool $bByPosition;
    // Solo posiciones (sin horas previstas): el GPS sin parada y los vehículos sin viaje se casan por su recorrido.
    // Con prefer_vehicles (Álavabus) no: va como siempre, por la parada que da el feed.
    private bool $bOnlyPositions;

    public function __construct(
        array $aVehicles,
        array $aJourneyIds,
        private JourneyStops $Stops,
        private ServiceJourney $JourneyModel,
        private int $iNowSeconds,
        \DateTime|null $Today = null
    ) {
        $this->bOnlyPositions = \Core\Config::hasLive('vehicle-positions') && !\Core\Config::hasLive('trip-updates');
        $this->bByPosition = $this->bOnlyPositions || !empty(\Core\Config::current()['gtfs_rt']['prefer_vehicles']);
        $aUnknown = [];
        foreach ($aVehicles as $iVehicle => $aVehicle) {
            $sJourneyId = (string)($aJourneyIds[$aVehicle['tripId']] ?? '');
            if ($sJourneyId === '') {
                $aUnknown[$iVehicle] = $aVehicle;
            } elseif (!isset($this->aEntries[$sJourneyId])) {
                $this->add($sJourneyId, $aVehicle);
            }
        }
        if ($this->bOnlyPositions && $aUnknown !== []) {
            $this->addByLine($aUnknown, $Today ?? Calendar::todayMadrid());
        }
    }

    public function entries(): array
    {
        return $this->aEntries;
    }

    // Los viajes cuyo GPS daría más del tope: sin el día del viaje, puede ser otro con ese número (salen sin dato).
    public function rejected(): array
    {
        return array_keys($this->aRejected);
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
            if ($this->bOnlyPositions) {
                $this->addByPosition($sJourneyId, $aVehicle);
            }
            return;
        }
        if ($iOrder === (int)end($aStops)['seq_order']) {
            $this->aFinished[$sJourneyId] = true;
            return;
        }
        $iArrival = $this->Stops->arrivalAt($sJourneyId, $iOrder);
        $iShift = ServiceDay::shiftFromNow($iArrival, $this->iNowSeconds);
        $iScheduled = $this->scheduledAt($sJourneyId, $iOrder, $iArrival, $aVehicle);
        // El GPS lo pone pasada la parada del feed (la que acaba de dejar atrás): va a la siguiente. Si no, se
        // sumaría lo que lleva desde la hora de la que ya pasó, como si aún no hubiera llegado a ella.
        if ($iScheduled > $iArrival) {
            $sStopId = $this->Stops->after($sJourneyId, $iOrder) ?? $sStopId;
        }
        $this->keep($sJourneyId, $sStopId, $iScheduled, $iShift);
    }

    private function keep(string $sJourneyId, string $sNextStopId, int $iScheduled, int $iShift): void
    {
        $iDelay = max(0, $this->iNowSeconds + $iShift - $iScheduled);
        if ($iDelay > self::MAX_DELAY_SECONDS) {
            $this->aRejected[$sJourneyId] = true;
            return;
        }
        $this->aEntries[$sJourneyId] = ['delay' => $iDelay, 'times' => [], 'nextStopId' => $sNextStopId,
            'canceled' => false, 'shift' => $iShift];
    }

    // Sin la parada del feed (o con una que no es de su viaje): por dónde va su GPS en el recorrido entero.
    private function addByPosition(string $sJourneyId, array $aVehicle): void
    {
        $aLocated = $this->locate($sJourneyId, $aVehicle);
        if ($aLocated === null) {
            return;
        }
        if ($aLocated['end']) {
            $this->aFinished[$sJourneyId] = true;
            return;
        }
        $aStop = array_values($this->Stops->of($sJourneyId))[$aLocated['index']];
        $iShift = ServiceDay::shiftFromNow((int)$aStop['arrival_seconds'], $this->iNowSeconds);
        $this->keep($sJourneyId, (string)$aStop['stop_id'], $aLocated['seconds'], $iShift);
    }

    // Dónde va el vehículo en su viaje (RoutePosition::locate), o null sin GPS, sin el modo por posición o lejos de él.
    private function locate(string $sJourneyId, array $aVehicle): array|null
    {
        if (!$this->bByPosition || !isset($aVehicle['lat'], $aVehicle['lon'])) {
            return null;
        }
        $aStops = $this->Stops->of($sJourneyId);
        $iShift = ServiceDay::shiftFromNow((int)($aStops[0]['arrival_seconds'] ?? 0), $this->iNowSeconds);
        $aCoords = $this->coordinates(array_column($aStops, 'stop_id'));
        $dBearing = null;
        if (isset($aVehicle['bearing'])) {
            $dBearing = (float)$aVehicle['bearing'];
        }
        $iNow = $this->iNowSeconds + $iShift;
        [$dLat, $dLon] = [(float)$aVehicle['lat'], (float)$aVehicle['lon']];
        return RoutePosition::locate($aStops, $aCoords, $dLat, $dLon, $iNow, $dBearing);
    }

    // Los vehículos que no traen un viaje del horario, con su línea (route_id): cada uno con el viaje de esa línea en
    // marcha cuya hora en el punto donde está quede más cerca de la de ahora. El sentido lo dan el rumbo del GPS y la
    // parada del feed (el viaje tiene que pasar por ella); cada viaje, para un solo vehículo.
    private function addByLine(array $aVehicles, \DateTime $Day): void
    {
        $aLines = array_column($aVehicles, 'routeId');
        $aRunning = $this->JourneyModel->runningOfLines($aLines, $Day, $this->iNowSeconds, self::EARLY_START_SECONDS,
            self::MAX_DELAY_SECONDS);
        $aPairs = [];
        foreach ($aVehicles as $iVehicle => $aVehicle) {
            foreach ($aRunning[(string)($aVehicle['routeId'] ?? '')] ?? [] as $sJourneyId) {
                $aLocated = $this->onJourney($sJourneyId, $aVehicle);
                if ($aLocated !== null) {
                    $aPairs[] = [abs($this->iNowSeconds - $aLocated['seconds']), $iVehicle, $sJourneyId];
                }
            }
        }
        usort($aPairs, fn(array $aA, array $aB) => $aA[0] <=> $aB[0]);
        $aUsed = [];
        foreach ($aPairs as [$iGap, $iVehicle, $sJourneyId]) {
            if ($iGap <= self::MAX_DELAY_SECONDS && !isset($aUsed[$iVehicle]) && !isset($this->aEntries[$sJourneyId])) {
                $aUsed[$iVehicle] = true;
                $this->addByPosition($sJourneyId, $aVehicles[$iVehicle]);
            }
        }
    }

    // Dónde va el vehículo en un viaje de su línea, si el viaje pasa por la parada que da el feed (o no da ninguna).
    private function onJourney(string $sJourneyId, array $aVehicle): array|null
    {
        $sStopId = (string)($aVehicle['stopId'] ?? '');
        if ($sStopId !== '' && $this->Stops->orderOf($sJourneyId, $sStopId) === null) {
            return null;
        }
        $aLocated = $this->locate($sJourneyId, $aVehicle);
        if ($aLocated === null || $aLocated['end']) {
            return null;
        }
        return $aLocated;
    }

    private function coordinates(array $aStopIds): array
    {
        $aMissing = array_diff(array_map('strval', $aStopIds), array_keys($this->aCoords));
        if ($aMissing !== []) {
            $this->aCoords += $this->JourneyModel->stopCoordinates($aMissing);
        }
        return $this->aCoords;
    }

    // La hora del horario donde está el vehículo: la de la parada que da el feed o, en el modo por posición (hay
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
        $aCoords = $this->coordinates(array_column($aNear, 'stop_id'));
        return RoutePosition::scheduledSeconds($aStops, $iIndex, $aCoords, (float)$aVehicle['lat'], (float)$aVehicle['lon']) ?? $iArrival;
    }
}
