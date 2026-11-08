<?php

namespace Services;

// La hora del horario que corresponde a dónde está un vehículo por su GPS: se sitúa en el tramo antes o después de la
// parada que da el feed (Álavabus da a veces la que acaba de dejar atrás) y se reparte la hora del tramo según lo que
// lleva recorrido de él.
final class RoutePosition
{
    private const KM_PER_DEGREE = 111.32;

    // $aStops: las paradas del viaje (stop_id, arrival_seconds), $iIndex la de la parada del feed, $aCoords [id => [lat,
    // lon]]. Null si no hay coordenadas para ningún tramo.
    public static function scheduledSeconds(array $aStops, int $iIndex, array $aCoords, float $dLat, float $dLon): int|null
    {
        $aBest = null;
        foreach ([$iIndex - 1, $iIndex] as $iFrom) {
            $aSegment = self::onSegment($aStops, $iFrom, $aCoords, $dLat, $dLon);
            if ($aSegment !== null && ($aBest === null || $aSegment['distance'] < $aBest['distance'])) {
                $aBest = $aSegment;
            }
        }
        if ($aBest === null) {
            return null;
        }
        return (int)round($aBest['from'] + $aBest['fraction'] * ($aBest['to'] - $aBest['from']));
    }

    // Lo recorrido del tramo de la parada $iFrom a la siguiente (de 0 a 1) y lo lejos que queda el vehículo de él (km).
    private static function onSegment(array $aStops, int $iFrom, array $aCoords, float $dLat, float $dLon): array|null
    {
        if ($iFrom < 0 || !isset($aStops[$iFrom + 1])) {
            return null;
        }
        $aA = $aCoords[(string)$aStops[$iFrom]['stop_id']] ?? null;
        $aB = $aCoords[(string)$aStops[$iFrom + 1]['stop_id']] ?? null;
        if ($aA === null || $aB === null) {
            return null;
        }
        $dScale = cos(deg2rad($dLat)) * self::KM_PER_DEGREE;
        [$dAx, $dAy] = [$aA[1] * $dScale, $aA[0] * self::KM_PER_DEGREE];
        [$dBx, $dBy] = [$aB[1] * $dScale, $aB[0] * self::KM_PER_DEGREE];
        [$dPx, $dPy] = [$dLon * $dScale, $dLat * self::KM_PER_DEGREE];
        $dLength = ($dBx - $dAx) ** 2 + ($dBy - $dAy) ** 2;
        $dFraction = 0.0;
        if ($dLength > 0) {
            $dFraction = max(0.0, min(1.0, (($dPx - $dAx) * ($dBx - $dAx) + ($dPy - $dAy) * ($dBy - $dAy)) / $dLength));
        }
        $dDistance = hypot($dPx - ($dAx + $dFraction * ($dBx - $dAx)), $dPy - ($dAy + $dFraction * ($dBy - $dAy)));
        return [
            'fraction' => $dFraction,
            'distance' => $dDistance,
            'from' => (int)$aStops[$iFrom]['arrival_seconds'],
            'to' => (int)$aStops[$iFrom + 1]['arrival_seconds'],
        ];
    }
}
