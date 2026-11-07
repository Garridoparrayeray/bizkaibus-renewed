<?php

namespace Services;

// El emparejador de las redes sin vehículos en directo (o sin engine/ a mano): las mismas llamadas que
// Engine\Bizkaibus\RealtimeMatcher, con el horario oficial tal cual y ningún vehículo.
class ScheduleOnlyMatcher
{
    // Cada salida con los campos del horario oficial, como da el motor a las que no tienen vehículo.
    public function enrich(array $aRows): array
    {
        return array_map(fn(array $aRow) => $aRow + [
            'status' => 'scheduled',
            'delaySeconds' => 0,
            'etaSeconds' => (int)$aRow['arrival_seconds'],
            'vehicleRef' => null,
            'currentStopId' => null,
        ], $aRows);
    }

    // Los vehículos que sigue: ninguno.
    public function vehicles(): array
    {
        return [];
    }

    public function isLive(string|null $sServiceJourneyId, array|null $aLive): bool
    {
        return false;
    }

    // [llegada prevista, retraso, en directo], como el motor sin datos del vehículo.
    public function etaForStop(string|null $sServiceJourneyId, int $iTargetArrivalSeconds, array|null $aLive, int|null $iTargetSeq = null): array
    {
        return [$iTargetArrivalSeconds, 0, false];
    }

    public function displayDelayMinutes(int|null $iDelaySeconds): int|null
    {
        if ($iDelaySeconds === null) {
            return null;
        }
        return (int)round($iDelaySeconds / 60);
    }

    public function displayDelaySeconds(string|null $sServiceJourneyId, array|null $aLive): int
    {
        return 0;
    }

    public function positionFor(string|null $sServiceJourneyId, array|null $aLive): array|null
    {
        return null;
    }

    public function journeyIdFor(array $aEntry): string|null
    {
        return null;
    }

    public function lookup(int|string $iLineId, string $sTripNumber, int $iFirstDepartureSeconds, string|null $sServiceJourneyId = null): array|null
    {
        return null;
    }
}
