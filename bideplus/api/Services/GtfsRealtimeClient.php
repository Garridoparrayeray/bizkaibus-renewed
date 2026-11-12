<?php

namespace Services;

use Core\Cache;
use Core\Http;

// Lee los feeds GTFS-Realtime de una red (protobuf, sin librerías): la hora prevista o el retraso de cada vehículo,
// dónde va cada uno y las incidencias. Cada red pone en su ficha (gtfs_rt) las direcciones que publica su operador.
class GtfsRealtimeClient
{
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LENGTH = 2;
    private const WIRE_FIXED32 = 5;

    private const SKIPPED = 1;
    private const WAIT_SECONDS = 60;
    private const CANCELED = 3;
    private const STOP_STATUSES = [0 => 'arriving', 1 => 'at', 2 => 'towards'];
    private const LANGUAGE = 'es';

    public function __construct(private array $aConfig)
    {
    }

    public function fetchTripUpdates(): array
    {
        return $this->ofServiceDay($this->feed('trip_updates_url', [self::class, 'parseTripUpdates'])['trips'] ?? []);
    }

    public function fetchVehiclePositions(): array
    {
        return $this->ofServiceDay($this->feed('vehicle_positions_url', [self::class, 'parseVehiclePositions'])['vehicles'] ?? []);
    }

    // Con gtfs_rt.trip_date_suffix (Renfe larga distancia: el id del viaje acaba con su fecha de servicio, y el feed
    // arrastra los de ayer) sólo valen los de hoy y, de madrugada, los de ayer que aún circulan.
    private function ofServiceDay(array $aEntries): array
    {
        if (empty($this->aConfig['gtfs_rt']['trip_date_suffix'])) {
            return $aEntries;
        }
        $Now = new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
        $aValid = [$Now->format('Y-m-d')];
        if ((int)$Now->format('G') < 6) {
            $aValid[] = (clone $Now)->modify('-1 day')->format('Y-m-d');
        }
        return array_values(array_filter($aEntries, function ($aEntry) use ($aValid) {
            $sTripId = (string)($aEntry['tripId'] ?? '');
            return !preg_match('/(\d{4}-\d{2}-\d{2})$/', $sTripId, $aMatch) || in_array($aMatch[1], $aValid, true);
        }));
    }

    // Las incidencias vigentes ahora.
    public function fetchAlerts(): array
    {
        return self::activeAlerts($this->feed('alerts_url', [self::class, 'parseAlerts'])['alerts'] ?? [], time());
    }

    // Un feed de la ficha, guardado por su dirección (Euskotren y los dos tranvías leen el mismo y lo bajan una sola
    // vez). Vacío si la red no lo publica, si no responde o si su dato es viejo.
    private function feed(string $sUrlKey, callable $Parser): array
    {
        $aGtfsRt = $this->aConfig['gtfs_rt'];
        $sUrl = $aGtfsRt[$sUrlKey] ?? null;
        if ($sUrl === null) {
            return [];
        }
        $sCacheKey = 'gtfs_rt_' . substr(md5($sUrl), 0, 12);
        $aFeed = Cache::remember($sCacheKey, $aGtfsRt['cache_ttl_seconds'], function () use ($sUrl, $aGtfsRt, $Parser) {
            return $Parser(Http::get($sUrl, $aGtfsRt['http_timeout_seconds']));
        });
        if (empty($aFeed) || self::stale($aFeed, $aGtfsRt)) {
            return [];
        }
        return $aFeed;
    }

    // ¿Es viejo el dato? Con gtfs_rt.clock_offset_seconds (un feed que pone mal la hora de su cabecera, Renfe larga
    // distancia) vale la hora de la cabecera y también la corregida con ese desfase.
    private static function stale(array $aFeed, array $aGtfsRt): bool
    {
        $iAge = time() - (int)$aFeed['generatedAt'];
        if ($iAge <= $aGtfsRt['max_age_seconds']) {
            return false;
        }
        $iOffset = (int)($aGtfsRt['clock_offset_seconds'] ?? 0);
        return $iOffset === 0 || abs($iAge - $iOffset) > $aGtfsRt['max_age_seconds'];
    }

    public static function parseVehiclePositions(string $sBody): array
    {
        $iGeneratedAt = 0;
        $aVehicles = [];
        foreach (self::fields($sBody) as [$iField, $Value]) {
            if ($iField === 1) {
                $iGeneratedAt = self::headerTimestamp($Value);
            } elseif ($iField === 2) {
                $aVehicle = self::entityField($Value, 4, [self::class, 'parseVehicle']);
                if ($aVehicle !== null) {
                    $aVehicle['entityId'] = self::entityId($Value);
                    $aVehicles[] = $aVehicle;
                }
            }
        }
        return ['generatedAt' => $iGeneratedAt, 'vehicles' => $aVehicles];
    }

    // La hora del feed (campo 3 de la cabecera), o 0 si no viene.
    private static function headerTimestamp(string $sHeader): int
    {
        foreach (self::fields($sHeader) as [$iField, $Value]) {
            if ($iField === 3) {
                return (int)$Value;
            }
        }
        return 0;
    }

    // El identificador de una entidad (campo 1). Metro Bilbao pone en el suyo la estación, el andén y el destino del tren.
    private static function entityId(string $sEntity): string
    {
        foreach (self::fields($sEntity) as [$iField, $Value]) {
            if ($iField === 1) {
                return $Value;
            }
        }
        return '';
    }

    // Lo que trae una entidad en el campo pedido (3, trip update; 4, vehículo; 5, incidencia), ya parseado, o null si
    // no lo lleva.
    private static function entityField(string $sEntity, int $iWanted, callable $Parser): array|null
    {
        foreach (self::fields($sEntity) as [$iField, $Value]) {
            if ($iField === $iWanted) {
                return $Parser($Value);
            }
        }
        return null;
    }

    // Un tren: su viaje, dónde está (GPS), la estación en la que está, a la que llega o hacia la que va (null si no lo
    // dice, como Metro), su número y la hora de la lectura.
    private static function parseVehicle(string $sVehicle): array|null
    {
        $sTripId = null;
        $dLat = null;
        $dLon = null;
        $sStopId = '';
        $sStopStatus = null;
        $sVehicleId = '';
        $iTimestamp = null;
        foreach (self::fields($sVehicle) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iTripField, $TripValue]) {
                    if ($iTripField === 1) {
                        $sTripId = $TripValue;
                    }
                }
            } elseif ($iField === 2) {
                foreach (self::fields($Value) as [$iPositionField, $PositionValue]) {
                    if ($iPositionField === 1) {
                        $dLat = self::float($PositionValue);
                    } elseif ($iPositionField === 2) {
                        $dLon = self::float($PositionValue);
                    }
                }
            } elseif ($iField === 4) {
                $sStopStatus = self::STOP_STATUSES[$Value] ?? null;
            } elseif ($iField === 5) {
                $iTimestamp = (int)$Value;
            } elseif ($iField === 7) {
                $sStopId = self::normalizeStopId($Value);
            } elseif ($iField === 8) {
                foreach (self::fields($Value) as [$iVehicleField, $VehicleValue]) {
                    if ($iVehicleField === 1) {
                        $sVehicleId = $VehicleValue;
                    }
                }
            }
        }
        if ($sTripId === null || $dLat === null || $dLon === null) {
            return null;
        }
        return ['tripId' => $sTripId, 'lat' => round($dLat, 6), 'lon' => round($dLon, 6), 'stopId' => $sStopId, 'stopStatus' => $sStopStatus, 'vehicleId' => $sVehicleId, 'timestamp' => $iTimestamp];
    }

    private static function float(mixed $Value): float|null
    {
        if (!\is_string($Value) || \strlen($Value) !== 4) {
            return null;
        }
        return unpack('g', $Value)[1];
    }

    public static function parseAlerts(string $sBody): array
    {
        $iGeneratedAt = 0;
        $aAlerts = [];
        foreach (self::fields($sBody) as [$iField, $Value]) {
            if ($iField === 1) {
                $iGeneratedAt = self::headerTimestamp($Value);
            } elseif ($iField === 2) {
                $aAlert = self::entityField($Value, 5, [self::class, 'parseAlert']);
                if ($aAlert !== null) {
                    $aAlerts[] = $aAlert;
                }
            }
        }
        return ['generatedAt' => $iGeneratedAt, 'alerts' => $aAlerts];
    }

    // Una incidencia: su titular y su texto en castellano (o en el primer idioma que traiga), cuándo vale y a qué líneas
    // y paradas afecta.
    private static function parseAlert(string $sAlert): array|null
    {
        $aPeriods = [];
        $aLineRefs = [];
        $aStopRefs = [];
        $sHeader = '';
        $sDescription = '';
        foreach (self::fields($sAlert) as [$iField, $Value]) {
            if ($iField === 1) {
                $aPeriods[] = self::period($Value);
            } elseif ($iField === 5) {
                foreach (self::fields($Value) as [$iEntityField, $EntityValue]) {
                    if ($iEntityField === 2) {
                        $aLineRefs[] = $EntityValue;
                    } elseif ($iEntityField === 5) {
                        $aStopRefs[] = self::normalizeStopId($EntityValue);
                    }
                }
            } elseif ($iField === 10) {
                $sHeader = self::translated($Value);
            } elseif ($iField === 11) {
                $sDescription = self::translated($Value);
            }
        }
        if ($sHeader === '' && $sDescription === '') {
            return null;
        }
        if ($sHeader === '') {
            $sHeader = $sDescription;
            $sDescription = '';
        }
        return [
            'summary' => $sHeader,
            'description' => $sDescription,
            'periods' => $aPeriods,
            'lineRefs' => array_values(array_unique($aLineRefs)),
            'stopRefs' => array_values(array_unique($aStopRefs)),
        ];
    }

    private static function period(string $sRange): array
    {
        $aPeriod = ['start' => null, 'end' => null];
        foreach (self::fields($sRange) as [$iField, $Value]) {
            if ($iField === 1) {
                $aPeriod['start'] = $Value;
            } elseif ($iField === 2) {
                $aPeriod['end'] = $Value;
            }
        }
        return $aPeriod;
    }

    public static function activeAlerts(array $aAlerts, int $iNow): array
    {
        return array_values(array_filter($aAlerts, fn($aAlert) => self::isActive($aAlert, $iNow)));
    }

    // Sin periodos, vale siempre; con ellos, si ahora cae en alguno.
    private static function isActive(array $aAlert, int $iNow): bool
    {
        if (empty($aAlert['periods'])) {
            return true;
        }
        foreach ($aAlert['periods'] as $aPeriod) {
            $bStarted = $aPeriod['start'] === null || $aPeriod['start'] <= $iNow;
            $bNotEnded = $aPeriod['end'] === null || $aPeriod['end'] > $iNow;
            if ($bStarted && $bNotEnded) {
                return true;
            }
        }
        return false;
    }

    private static function translated(string $sTranslated): string
    {
        $sFirst = '';
        foreach (self::fields($sTranslated) as [$iField, $Value]) {
            if ($iField !== 1) {
                continue;
            }
            $sText = '';
            $sLanguage = '';
            foreach (self::fields($Value) as [$iTextField, $TextValue]) {
                if ($iTextField === 1) {
                    $sText = trim($TextValue);
                } elseif ($iTextField === 2) {
                    $sLanguage = strtolower($TextValue);
                }
            }
            if ($sText !== '' && str_starts_with($sLanguage, self::LANGUAGE)) {
                return $sText;
            }
            if ($sFirst === '') {
                $sFirst = $sText;
            }
        }
        return $sFirst;
    }

    public static function parseTripUpdates(string $sBody): array
    {
        $iGeneratedAt = 0;
        $aTrips = [];
        foreach (self::fields($sBody) as [$iField, $Value]) {
            if ($iField === 1) {
                $iGeneratedAt = self::headerTimestamp($Value);
            } elseif ($iField === 2) {
                $aTrip = self::entityField($Value, 3, [self::class, 'parseTripUpdate']);
                if ($aTrip !== null) {
                    $aTrips[] = $aTrip;
                }
            }
        }
        return ['generatedAt' => $iGeneratedAt, 'trips' => $aTrips];
    }

    // Renfe trae además el retraso del tren («delay») y los cancelados, que pueden no llevar ninguna parada. Tuvisa y
    // Dbus dan en cada parada solo su retraso, sin hora: el de la primera vale para el viaje y ella es a la que va.
    private static function parseTripUpdate(string $sTripUpdate): array|null
    {
        $sTripId = '';
        $sStartDate = null;
        $aStops = [];
        $iDelay = null;
        $sNextStopId = null;
        $iFirstStopDelay = null;
        $bCanceled = false;
        foreach (self::fields($sTripUpdate) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iTripField, $TripValue]) {
                    if ($iTripField === 1) {
                        $sTripId = $TripValue;
                    } elseif ($iTripField === 3) {
                        $sStartDate = $TripValue;
                    } elseif ($iTripField === 4 && $TripValue === self::CANCELED) {
                        $bCanceled = true;
                    }
                }
            } elseif ($iField === 2) {
                $aStop = self::parseStopTimeUpdate($Value);
                if ($aStop !== null && $aStop['time'] !== null && $aStop['leaves'] !== null) {
                    $aStops[] = [$aStop['stopId'], $aStop['time'], $aStop['leaves']];
                } elseif ($aStop !== null && $aStop['time'] !== null) {
                    $aStops[] = [$aStop['stopId'], $aStop['time']];
                } elseif ($aStop !== null && $sNextStopId === null) {
                    $sNextStopId = $aStop['stopId'];
                    $iFirstStopDelay = $aStop['delay'];
                }
            } elseif ($iField === 5) {
                $iDelay = $Value;
            }
        }
        if ($iDelay === null) {
            $iDelay = $iFirstStopDelay;
        }
        if (empty($aStops) && !$bCanceled && $iDelay === null) {
            return null;
        }
        $aTrip = ['tripId' => $sTripId, 'stops' => $aStops];
        if ($sStartDate !== null) {
            $aTrip['startDate'] = $sStartDate;
        }
        if ($iDelay !== null) {
            $aTrip['delay'] = $iDelay;
        }
        if (empty($aStops) && $sNextStopId !== null) {
            $aTrip['nextStopId'] = $sNextStopId;
        }
        if ($bCanceled) {
            $aTrip['canceled'] = true;
        }
        return $aTrip;
    }

    // Una parada del viaje: su hora prevista o, si no la trae, su retraso. Null si no trae ninguno o se la salta. Con
    // «leaves», la hora a la que sale si el feed la da al menos un minuto después de la de llegada (espera en la parada).
    private static function parseStopTimeUpdate(string $sUpdate): array|null
    {
        $sStopId = null;
        $aArrival = null;
        $aDeparture = null;
        $iRelationship = 0;
        foreach (self::fields($sUpdate) as [$iField, $Value]) {
            if ($iField === 2) {
                $aArrival = self::event($Value);
            } elseif ($iField === 3) {
                $aDeparture = self::event($Value);
            } elseif ($iField === 4) {
                $sStopId = self::normalizeStopId($Value);
            } elseif ($iField === 5) {
                $iRelationship = $Value;
            }
        }
        $aEvent = $aArrival;
        if ($aEvent === null || ($aEvent['time'] === null && $aDeparture !== null && $aDeparture['time'] !== null)) {
            $aEvent = $aDeparture;
        }
        if ($sStopId === null || $aEvent === null || $iRelationship === self::SKIPPED) {
            return null;
        }
        if ($aEvent['time'] === null && $aEvent['delay'] === null) {
            return null;
        }
        $iLeaves = null;
        if ($aArrival !== null && $aDeparture !== null && $aArrival['time'] !== null && $aDeparture['time'] !== null
            && $aDeparture['time'] - $aArrival['time'] >= self::WAIT_SECONDS) {
            $iLeaves = $aDeparture['time'];
        }
        return ['stopId' => $sStopId, 'leaves' => $iLeaves] + $aEvent;
    }

    private static function event(string $sEvent): array
    {
        $aEvent = ['time' => null, 'delay' => null];
        foreach (self::fields($sEvent) as [$iField, $Value]) {
            if ($iField === 1) {
                $aEvent['delay'] = self::int32($Value);
            } elseif ($iField === 2) {
                $aEvent['time'] = $Value;
            }
        }
        return $aEvent;
    }

    // Un int32 negativo llega como varint de 64 bits.
    private static function int32(int $iValue): int
    {
        if ($iValue > 0x7fffffff) {
            return $iValue - 0x100000000;
        }
        return $iValue;
    }

    private static function normalizeStopId(string $sStopId): string
    {
        if (preg_match('/^(\d+)\.0+$/', $sStopId, $aMatch)) {
            return $aMatch[1];
        }
        return $sStopId;
    }

    private static function fields(string $sBuffer): \Generator
    {
        $iOffset = 0;
        $iLength = \strlen($sBuffer);
        while ($iOffset < $iLength) {
            $iKey = self::varint($sBuffer, $iOffset);
            $iField = $iKey >> 3;
            $iWire = $iKey & 7;
            if ($iWire === self::WIRE_VARINT) {
                yield [$iField, self::varint($sBuffer, $iOffset)];
            } elseif ($iWire === self::WIRE_LENGTH) {
                $iSize = self::varint($sBuffer, $iOffset);
                if ($iSize < 0 || $iOffset + $iSize > $iLength) {
                    throw new \RuntimeException('GTFS-RT: truncated field');
                }
                yield [$iField, substr($sBuffer, $iOffset, $iSize)];
                $iOffset += $iSize;
            } elseif ($iWire === self::WIRE_FIXED64) {
                $iOffset += 8;
            } elseif ($iWire === self::WIRE_FIXED32) {
                if ($iOffset + 4 > $iLength) {
                    throw new \RuntimeException('GTFS-RT: truncated field');
                }
                yield [$iField, substr($sBuffer, $iOffset, 4)];
                $iOffset += 4;
            } else {
                throw new \RuntimeException('GTFS-RT: unsupported wire type ' . $iWire);
            }
        }
    }

    private static function varint(string $sBuffer, int &$iOffset): int
    {
        $iValue = 0;
        $iShift = 0;
        $iLength = \strlen($sBuffer);
        while ($iOffset < $iLength) {
            $iByte = \ord($sBuffer[$iOffset]);
            $iOffset++;
            $iValue |= ($iByte & 0x7f) << $iShift;
            if ($iByte < 0x80) {
                return $iValue;
            }
            $iShift += 7;
            if ($iShift > 63) {
                break;
            }
        }
        throw new \RuntimeException('GTFS-RT: malformed varint');
    }
}
