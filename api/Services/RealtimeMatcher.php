<?php

namespace Services;

use Models\ServiceJourney;

/**
 * Enriches scheduled-departure rows with live SIRI-VM data where available.
 * Groups by (line_id, trip_number) and matches the closest departure_seconds
 * within a small tolerance — see SiriVehicleMonitoringClient for why neither
 * exact-id nor exact-time matching works against this feed.
 */
class RealtimeMatcher
{
    private const STALE_GRACE_SECONDS = 120;
    private const MATCH_TOLERANCE_SECONDS = 180;
    /** If position-based and flat-delay estimates disagree by more than this, the
     *  live feed's Order likely doesn't line up with our stop sequence (verified
     *  against real traffic: happens ~1 in 9 times) — distrust it, use flat-delay. */
    private const POSITION_SANITY_SECONDS = 15 * 60;

    private array $vmMap;
    private ?ServiceJourney $journeyModel;

    public function __construct(array $vmMap, ?ServiceJourney $journeyModel = null)
    {
        $this->vmMap = $vmMap;
        $this->journeyModel = $journeyModel;
    }

    /**
     * @param array<int, array<string, mixed>> $rows each must include line_id, trip_number,
     *        first_departure_seconds, arrival_seconds, and (to enable position-based ETAs) service_journey_id
     * @return array<int, array<string, mixed>> same rows plus status/delaySeconds/etaSeconds/vehicleRef/currentStopId
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

            [$eta, $effectiveDelay] = $this->etaForStop(
                $row['service_journey_id'] ?? null,
                (int)$row['arrival_seconds'],
                $live
            );
            $isStale = $eta < ($now - self::STALE_GRACE_SECONDS);

            return $row + [
                'status' => $isStale ? 'scheduled' : 'live',
                'delaySeconds' => $isStale ? 0 : $effectiveDelay,
                'etaSeconds' => $isStale ? (int)$row['arrival_seconds'] : $eta,
                'vehicleRef' => $isStale ? null : $live['vehicleRef'],
                'currentStopId' => $isStale ? null : $live['currentStopId'],
            ];
        }, $rows);
    }

    /**
     * Prefers "now + scheduled time remaining from the bus's last confirmed
     * stop" over "original scheduled time + flat reported delay", since it's
     * anchored to where the bus actually was last seen rather than trusting
     * a single delay figure for the whole remaining trip. Falls back to the
     * flat-delay figure whenever the position-based estimate isn't available
     * or disagrees enough with it to suggest a bad Order match. Public so
     * RealtimeController can reuse it per-stop for "Detalle del bus".
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

    /** Closest live candidate for (line_id, trip_number) within tolerance of $firstDepartureSeconds, or null. */
    public function lookup(int $lineId, string $tripNumber, int $firstDepartureSeconds): ?array
    {
        $candidates = $this->vmMap[$lineId . '|' . $tripNumber] ?? [];
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
