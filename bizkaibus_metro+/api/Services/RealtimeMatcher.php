<?php

namespace Services;

use Models\ServiceJourney;

class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;

    private const POSITION_SANITY_SECONDS = 15 * 60;

    private array $vmMap;
    private ServiceJourney|null $journeyModel;

    public function __construct(array $vmMap, ServiceJourney|null $journeyModel = null)
    {
        $this->vmMap = $vmMap;
        $this->journeyModel = $journeyModel;
    }

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

    public function etaForStop(string|null $serviceJourneyId, int $targetArrivalSeconds, array|null $live): array
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

    public function lookup(int $lineId, string $tripNumber, int $firstDepartureSeconds): array|null
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
