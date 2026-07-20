<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Feed SIRI-VM "vehicle monitoring" (mal nombrado bizkaibus-trip-updates.xml
 * en origen). Da retraso en vivo + parada/orden actual por cada viaje activo.
 *
 * Los VehicleJourneyRef (p.ej. "trp_A3513_907_OP44LV_61500_O44LV3513_351302_6")
 * NO coinciden exactamente con el id de service_journeys del estático —
 * verificado: solo los tokens de línea y trip_number son fiables, el token
 * de calendario y todo lo que va después usan un vocabulario distinto en
 * vivo que en estático.
 *
 * trip_number tampoco es único por sí solo — es un id de bloque/vehículo
 * reutilizado en decenas de salidas distintas a lo largo del día (verificado:
 * el trip_number "1137" de la línea 2322 aparece a las 06:23, 07:36, 08:36,
 * 09:33... todo el día). El token de segundos de salida que lleva incrustado
 * lo acota, pero tampoco coincide byte a byte con el horario estático —
 * verificado con pares reales, p.ej. un "72900" en vivo contra un "72928"
 * estático para la misma (línea, trip) son la misma salida física, con
 * decenas de segundos de diferencia. Por eso se agrupa por (line_id,
 * trip_number) y se elige el first_departure_seconds más cercano dentro de
 * un margen, en vez de exigir igualdad exacta en ningún sitio.
 */
class SiriVehicleMonitoringClient
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /** @return array<string, array<int, array{departureSeconds:int, delaySeconds:int, vehicleRef:string, currentStopId:?int, order:?int}>> indexado por "{lineId}|{tripNumber}" */
    public function fetchActiveTrips(): array
    {
        $cfg = $this->config['siri'];
        return Cache::remember('siri_vm', $cfg['cache_ttl_seconds'], function () use ($cfg) {
            try {
                $xmlString = Http::get($cfg['vehicle_monitoring_url'], $cfg['http_timeout_seconds']);
            } catch (\Throwable $e) {
                return [];
            }
            return self::parse($xmlString);
        });
    }

    private static function parse(string $xmlString): array
    {
        $xml = @simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }
        $activities = $xml->ServiceDelivery->VehicleMonitoringDelivery->VehicleActivity ?? [];

        $map = [];
        foreach ($activities as $activity) {
            $mvj = $activity->MonitoredVehicleJourney ?? null;
            if ($mvj === null || !isset($mvj->VehicleJourneyRef)) {
                continue;
            }
            $ref = (string)$mvj->VehicleJourneyRef;
            if (!preg_match('/^trp_[A-Za-z]+(\d+)_(\d+)_[A-Za-z0-9]+_(\d+)/', $ref, $m)) {
                continue;
            }
            [, $lineId, $tripNumber, $departureSeconds] = $m;

            $key = $lineId . '|' . $tripNumber;
            $map[$key][] = [
                'departureSeconds' => (int)$departureSeconds,
                'delaySeconds' => self::parseIsoDuration((string)($mvj->Delay ?? 'PT0S')),
                'vehicleRef' => (string)($mvj->VehicleRef ?? ''),
                'currentStopId' => isset($mvj->MonitoredCall->StopPointRef) ? (int)$mvj->MonitoredCall->StopPointRef : null,
                'order' => isset($mvj->MonitoredCall->Order) ? (int)$mvj->MonitoredCall->Order : null,
            ];
        }
        return $map;
    }

    /** Parsea el subconjunto de duraciones ISO 8601 que usa este feed (PT0S, PT5M, PT1H2M, con signo). */
    private static function parseIsoDuration(string $iso): int
    {
        if (!preg_match('/^(-?)PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) {
            return 0;
        }
        $sign = $m[1] === '-' ? -1 : 1;
        $hours = (int)($m[2] ?? 0);
        $minutes = (int)($m[3] ?? 0);
        $seconds = (int)($m[4] ?? 0);
        return $sign * ($hours * 3600 + $minutes * 60 + $seconds);
    }
}
