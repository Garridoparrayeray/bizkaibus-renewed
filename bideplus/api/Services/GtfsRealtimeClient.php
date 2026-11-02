<?php

namespace Services;

use Core\Cache;
use Core\Http;

// Lee un feed GTFS-Realtime (protobuf, sin librerías): la hora prevista de cada tren en cada parada (Metro Bilbao,
// Euskotren, los tranvías y Renfe) y, donde lo publican, la posición GPS de cada tren (Metro Bilbao y Renfe).
class GtfsRealtimeClient
{
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LENGTH = 2;
    private const WIRE_FIXED32 = 5;

    private const SKIPPED = 1;
    private const CANCELED = 3;
    private const STOP_STATUSES = [0 => 'arriving', 1 => 'at', 2 => 'towards'];

    public function __construct(private array $aConfig)
    {
    }

    public function fetchTripUpdates(): array
    {
        $aGtfsRt = $this->aConfig['gtfs_rt'];
        // Por dirección: Euskotren y los dos tranvías leen el mismo fichero y lo bajan una sola vez.
        $sCacheKey = 'gtfs_rt_trip_updates_' . substr(md5($aGtfsRt['trip_updates_url']), 0, 12);
        $aFeed = Cache::remember($sCacheKey, $aGtfsRt['cache_ttl_seconds'], function () use ($aGtfsRt) {
            $sBody = Http::get($aGtfsRt['trip_updates_url'], $aGtfsRt['http_timeout_seconds']);
            return self::parseTripUpdates($sBody);
        });

        if (empty($aFeed) || time() - (int)$aFeed['generatedAt'] > $aGtfsRt['max_age_seconds']) {
            return [];
        }
        return $aFeed['trips'];
    }

    // Sin dirección de posiciones en la ficha de la red, no hay trenes en el mapa.
    public function fetchVehiclePositions(): array
    {
        $aGtfsRt = $this->aConfig['gtfs_rt'];
        $sUrl = $aGtfsRt['vehicle_positions_url'] ?? null;
        if ($sUrl === null) {
            return [];
        }
        $sCacheKey = 'gtfs_rt_vehicle_positions_' . substr(md5($sUrl), 0, 12);
        $aFeed = Cache::remember($sCacheKey, $aGtfsRt['cache_ttl_seconds'], function () use ($sUrl, $aGtfsRt) {
            return self::parseVehiclePositions(Http::get($sUrl, $aGtfsRt['http_timeout_seconds']));
        });

        if (empty($aFeed) || time() - (int)$aFeed['generatedAt'] > $aGtfsRt['max_age_seconds']) {
            return [];
        }
        return $aFeed['vehicles'];
    }

    public static function parseVehiclePositions(string $sBody): array
    {
        $iGeneratedAt = 0;
        $aVehicles = [];
        foreach (self::fields($sBody) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iHeaderField, $HeaderValue]) {
                    if ($iHeaderField === 3) {
                        $iGeneratedAt = $HeaderValue;
                    }
                }
            } elseif ($iField === 2) {
                foreach (self::fields($Value) as [$iEntityField, $EntityValue]) {
                    if ($iEntityField === 4) {
                        $aVehicle = self::parseVehicle($EntityValue);
                        if ($aVehicle !== null) {
                            $aVehicles[] = $aVehicle;
                        }
                    }
                }
            }
        }
        return ['generatedAt' => $iGeneratedAt, 'vehicles' => $aVehicles];
    }

    // Un tren: su viaje, dónde está (GPS), la estación en la que está, a la que llega o hacia la que va (null si no lo
    // dice, como Metro), y su número.
    private static function parseVehicle(string $sVehicle): array|null
    {
        $sTripId = null;
        $dLat = null;
        $dLon = null;
        $sStopId = '';
        $sStopStatus = null;
        $sVehicleId = '';
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
        return ['tripId' => $sTripId, 'lat' => round($dLat, 6), 'lon' => round($dLon, 6), 'stopId' => $sStopId, 'stopStatus' => $sStopStatus, 'vehicleId' => $sVehicleId];
    }

    private static function float(mixed $Value): float|null
    {
        if (!\is_string($Value) || \strlen($Value) !== 4) {
            return null;
        }
        return unpack('g', $Value)[1];
    }

    public static function parseTripUpdates(string $sBody): array
    {
        $iGeneratedAt = 0;
        $aTrips = [];
        foreach (self::fields($sBody) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iHeaderField, $HeaderValue]) {
                    if ($iHeaderField === 3) {
                        $iGeneratedAt = $HeaderValue;
                    }
                }
            } elseif ($iField === 2) {
                $aTrip = self::parseEntity($Value);
                if ($aTrip !== null) {
                    $aTrips[] = $aTrip;
                }
            }
        }
        return ['generatedAt' => $iGeneratedAt, 'trips' => $aTrips];
    }

    private static function parseEntity(string $sEntity): array|null
    {
        foreach (self::fields($sEntity) as [$iField, $Value]) {
            if ($iField === 3) {
                return self::parseTripUpdate($Value);
            }
        }
        return null;
    }

    // Renfe trae además el retraso del tren («delay») y los cancelados, que pueden no llevar ninguna parada.
    private static function parseTripUpdate(string $sTripUpdate): array|null
    {
        $sTripId = '';
        $aStops = [];
        $iDelay = null;
        $bCanceled = false;
        foreach (self::fields($sTripUpdate) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iTripField, $TripValue]) {
                    if ($iTripField === 1) {
                        $sTripId = $TripValue;
                    } elseif ($iTripField === 4 && $TripValue === self::CANCELED) {
                        $bCanceled = true;
                    }
                }
            } elseif ($iField === 2) {
                $aStop = self::parseStopTimeUpdate($Value);
                if ($aStop !== null) {
                    $aStops[] = $aStop;
                }
            } elseif ($iField === 5) {
                $iDelay = $Value;
            }
        }
        if (empty($aStops) && !$bCanceled && $iDelay === null) {
            return null;
        }
        $aTrip = ['tripId' => $sTripId, 'stops' => $aStops];
        if ($iDelay !== null) {
            $aTrip['delay'] = $iDelay;
        }
        if ($bCanceled) {
            $aTrip['canceled'] = true;
        }
        return $aTrip;
    }

    private static function parseStopTimeUpdate(string $sUpdate): array|null
    {
        $sStopId = null;
        $iArrival = null;
        $iDeparture = null;
        $iRelationship = 0;
        foreach (self::fields($sUpdate) as [$iField, $Value]) {
            if ($iField === 2) {
                $iArrival = self::eventTime($Value);
            } elseif ($iField === 3) {
                $iDeparture = self::eventTime($Value);
            } elseif ($iField === 4) {
                $sStopId = self::normalizeStopId($Value);
            } elseif ($iField === 5) {
                $iRelationship = $Value;
            }
        }
        $iTime = $iArrival;
        if ($iTime === null) {
            $iTime = $iDeparture;
        }
        if ($sStopId === null || $iTime === null || $iRelationship === self::SKIPPED) {
            return null;
        }
        return [$sStopId, $iTime];
    }

    private static function eventTime(string $sEvent): int|null
    {
        foreach (self::fields($sEvent) as [$iField, $Value]) {
            if ($iField === 2) {
                return $Value;
            }
        }
        return null;
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
