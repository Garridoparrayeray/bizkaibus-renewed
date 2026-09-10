<?php

namespace Services;

use Models\ServiceJourney;

/**
 * Enriquece las salidas programadas con datos en vivo de SIRI-VM cuando los
 * hay. Agrupa por (line_id, trip_number) y busca el departure_seconds más
 * cercano dentro de un margen; ver SiriVehicleMonitoringClient para por qué
 * ni el id exacto ni la hora exacta funcionan contra este feed.
 */
class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;
    /** Si la estimación por posición y la de retraso plano difieren más de esto,
     *  es que el Order del feed en vivo no encaja con nuestra secuencia de
     *  paradas (verificado: pasa ~1 de cada 9 veces), así que se descarta y se usa el retraso plano. */
    private const POSITION_SANITY_SECONDS = 15 * 60;

    private array $vmMap;
    private ?ServiceJourney $journeyModel;

    public function __construct(array $vmMap, ?ServiceJourney $journeyModel = null)
    {
        $this->vmMap = $vmMap;
        $this->journeyModel = $journeyModel;
    }

    /**
     * @param array<int, array<string, mixed>> $rows cada una debe traer line_id, trip_number,
     *        first_departure_seconds, arrival_seconds y (para poder calcular ETA por posición) service_journey_id
     * @return array<int, array<string, mixed>> las mismas filas más status/delaySeconds/etaSeconds/vehicleRef/currentStopId
     */
    public function enrich(array $rows): array
    {
        $now = Calendar::nowSecondsSinceMidnight();

        return array_map(function ($row) use ($now) {
            $live = $this->lookup((int)$row['line_id'], (string)$row['trip_number'], (int)$row['first_departure_seconds']);

            if ($live === null) {
                return $row + [
                    'status' => 'scheduled',
                    'delaySeconds' => 0,
                    'etaSeconds' => (int)$row['arrival_seconds'],
                    'vehicleRef' => null,
                    'currentStopId' => null,
                ];
            }

            $serviceJourneyId = null;
            if (isset($row['service_journey_id'])) {
                $serviceJourneyId = $row['service_journey_id'];
            }
            [$eta, $effectiveDelay] = $this->etaForStop(
                $serviceJourneyId,
                (int)$row['arrival_seconds'],
                $live
            );
            $isStale = $eta < ($now - self::STALE_GRACE_SECONDS);

            if ($isStale) {
                $status = 'scheduled';
                $delaySeconds = 0;
                $etaSeconds = (int)$row['arrival_seconds'];
                $vehicleRef = null;
                $currentStopId = null;
            } else {
                $status = 'live';
                $delaySeconds = $effectiveDelay;
                $etaSeconds = $eta;
                $vehicleRef = $live['vehicleRef'];
                $currentStopId = $live['currentStopId'];
            }

            return $row + [
                'status' => $status,
                'delaySeconds' => $delaySeconds,
                'etaSeconds' => $etaSeconds,
                'vehicleRef' => $vehicleRef,
                'currentStopId' => $currentStopId,
            ];
        }, $rows);
    }

    /**
     * Prefiere "ahora + tiempo programado restante desde la última parada
     * confirmada del bus" antes que "hora programada original + retraso
     * plano reportado", porque se ancla a dónde se vio al bus la última vez
     * en vez de fiarse de una única cifra de retraso para todo el trayecto
     * restante. Si la estimación por posición no está disponible o difiere
     * demasiado, cae al retraso plano. Pública para que RealtimeController
     * la reutilice por parada en "Detalle del bus".
     *
     * @return array{0:int, 1:int} [etaSeconds, delaySecondsToDisplay]
     */
    public function etaForStop(?string $serviceJourneyId, int $targetArrivalSeconds, ?array $live): array
    {
        if ($live === null) {
            return [$targetArrivalSeconds, 0];
        }

        $flatEta = $targetArrivalSeconds + $live['delaySeconds'];

        if ($this->journeyModel !== null && $serviceJourneyId !== null && $live['currentStopId'] !== null && $live['order'] !== null) {
            $now = Calendar::nowSecondsSinceMidnight();
            $currentArrival = $this->journeyModel->arrivalSecondsAtOrder($serviceJourneyId, (int)$live['order']);
            if ($currentArrival !== null) {
                $remaining = $targetArrivalSeconds - $currentArrival;
                $positionEta = $now + $remaining;
                if ($remaining >= 0 && abs($positionEta - $flatEta) <= self::POSITION_SANITY_SECONDS) {
                    return [$positionEta, $positionEta - $targetArrivalSeconds];
                }
            }
        }

        return [$flatEta, $live['delaySeconds']];
    }

    /** Candidato en vivo más cercano para (line_id, trip_number) dentro del margen de $firstDepartureSeconds, o null. */
    public function lookup(int $lineId, string $tripNumber, int $firstDepartureSeconds): ?array
    {
        $key = $lineId . '|' . $tripNumber;
        $candidates = [];
        if (isset($this->vmMap[$key])) {
            $candidates = $this->vmMap[$key];
        }
        if (empty($candidates)) {
            return null;
        }

        $best = null;
        $bestDiff = null;
        foreach ($candidates as $candidate) {
            $diff = abs($candidate['departureSeconds'] - $firstDepartureSeconds);
            if ($diff <= self::MATCH_TOLERANCE_SECONDS && ($bestDiff === null || $diff < $bestDiff)) {
                $best = $candidate;
                $bestDiff = $diff;
            }
        }
        return $best;
    }
}
