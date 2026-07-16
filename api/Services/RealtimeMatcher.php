<?php

namespace Services;

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

    private array $vmMap;

    public function __construct(array $vmMap)
    {
        $this->vmMap = $vmMap;
    }

    /**
     * @param array<int, array<string, mixed>> $rows each must include line_id, trip_number, first_departure_seconds, arrival_seconds
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

            $eta = (int)$row['arrival_seconds'] + $live['delaySeconds'];
            $isStale = $eta < ($now - self::STALE_GRACE_SECONDS);

            return $row + [
                'status' => $isStale ? 'scheduled' : 'live',
                'delaySeconds' => $isStale ? 0 : $live['delaySeconds'],
                'etaSeconds' => $isStale ? (int)$row['arrival_seconds'] : $eta,
                'vehicleRef' => $isStale ? null : $live['vehicleRef'],
                'currentStopId' => $isStale ? null : $live['currentStopId'],
            ];
        }, $rows);
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
