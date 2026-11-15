<?php

namespace Services;

// La hora del horario que corresponde a dónde está un vehículo por su GPS: se sitúa en el tramo antes o después de la
// parada que da el feed (Álavabus da a veces la que acaba de dejar atrás), o en el recorrido entero si no da
// ninguna, y se reparte la hora del tramo según lo que lleva recorrido de él.
final class RoutePosition
{
    private const KM_PER_DEGREE = 111.32;
    // Más lejos del recorrido, el vehículo no va por él (otra línea, en cocheras).
    private const MAX_OFF_ROUTE_KM = 0.3;
    private const MAX_TURN_DEGREES = 90.0;
    // Tramos a menos de esto de más que el más cercano cuentan como igual de cerca (el GPS y la calle de enfrente).
    private const SAME_DISTANCE_KM = 0.05;
    // Ir adelantado es raro: cada segundo antes de hora cuenta como tres de retraso al elegir el tramo.
    private const EARLY_WEIGHT = 3;

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
        return self::seconds($aBest);
    }

    // Sin parada del feed: el tramo junto al que va el vehículo (a menos de MAX_OFF_ROUTE_KM, en su sentido si el
    // GPS da su rumbo); si hay varios igual de cerca (una circular), el de hora más cercana a $iNowSeconds, y antes uno
    // que va tarde que uno que va adelantado. ['index' de la parada a la que va, 'seconds', 'end'] o null.
    public static function locate(array $aStops, array $aCoords, float $dLat, float $dLon, int $iNowSeconds,
        float|null $dBearing = null): array|null
    {
        $aStops = array_values($aStops);
        $aNear = [];
        foreach (array_keys($aStops) as $iFrom) {
            $aSegment = self::onSegment($aStops, $iFrom, $aCoords, $dLat, $dLon);
            if ($aSegment === null || $aSegment['distance'] > self::MAX_OFF_ROUTE_KM) {
                continue;
            }
            if (self::sameWay($aSegment, $dBearing)) {
                $aSegment['index'] = $iFrom + 1;
                $aSegment['seconds'] = self::seconds($aSegment);
                $aNear[] = $aSegment;
            }
        }
        if ($aNear === []) {
            return null;
        }
        $dClosest = min(array_column($aNear, 'distance'));
        $aBest = null;
        foreach ($aNear as $aSegment) {
            $iGap = $iNowSeconds - $aSegment['seconds'];
            if ($iGap < 0) {
                $iGap = -self::EARLY_WEIGHT * $iGap;
            }
            $aSegment['gap'] = $iGap;
            $bClose = $aSegment['distance'] <= $dClosest + self::SAME_DISTANCE_KM;
            if ($bClose && ($aBest === null || $iGap < $aBest['gap'])) {
                $aBest = $aSegment;
            }
        }
        $bEnd = $aBest['index'] === \count($aStops) - 1 && $aBest['fraction'] >= 1.0;
        return ['index' => $aBest['index'], 'seconds' => $aBest['seconds'], 'end' => $bEnd];
    }

    // El rumbo del GPS (grados desde el norte) va como el tramo, con menos de MAX_TURN_DEGREES de diferencia.
    private static function sameWay(array $aSegment, float|null $dBearing): bool
    {
        if ($dBearing === null || $aSegment['heading'] === null) {
            return true;
        }
        return abs(fmod($dBearing - $aSegment['heading'] + 540.0, 360.0) - 180.0) <= self::MAX_TURN_DEGREES;
    }

    private static function seconds(array $aSegment): int
    {
        return (int)round($aSegment['from'] + $aSegment['fraction'] * ($aSegment['to'] - $aSegment['from']));
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
        $dHeading = null;
        if ($dLength > 0) {
            $dHeading = fmod(rad2deg(atan2($dBx - $dAx, $dBy - $dAy)) + 360.0, 360.0);
        }
        return [
            'fraction' => $dFraction,
            'distance' => $dDistance,
            'heading' => $dHeading,
            'from' => (int)$aStops[$iFrom]['arrival_seconds'],
            'to' => (int)$aStops[$iFrom + 1]['arrival_seconds'],
        ];
    }
}
