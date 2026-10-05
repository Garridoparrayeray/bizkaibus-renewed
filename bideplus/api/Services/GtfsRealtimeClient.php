<?php

namespace Services;

use Core\Cache;
use Core\Http;

class GtfsRealtimeClient
{
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LENGTH = 2;
    private const WIRE_FIXED32 = 5;

    private const SKIPPED = 1;

    public function __construct(private array $aConfig)
    {
    }

    public function fetchTripUpdates(): array
    {
        $aGtfsRt = $this->aConfig['gtfs_rt'];
        $sNetwork = $this->aConfig['network'];
        $aFeed = Cache::remember('gtfs_rt_trip_updates_' . $sNetwork, $aGtfsRt['cache_ttl_seconds'], function () use ($aGtfsRt) {
            $sBody = Http::get($aGtfsRt['trip_updates_url'], $aGtfsRt['http_timeout_seconds']);
            return self::parseTripUpdates($sBody);
        });

        if (empty($aFeed) || time() - (int)$aFeed['generatedAt'] > $aGtfsRt['max_age_seconds']) {
            return [];
        }
        return $aFeed['trips'];
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

    private static function parseTripUpdate(string $sTripUpdate): array|null
    {
        $sTripId = '';
        $aStops = [];
        foreach (self::fields($sTripUpdate) as [$iField, $Value]) {
            if ($iField === 1) {
                foreach (self::fields($Value) as [$iTripField, $TripValue]) {
                    if ($iTripField === 1) {
                        $sTripId = $TripValue;
                    }
                }
            } elseif ($iField === 2) {
                $aStop = self::parseStopTimeUpdate($Value);
                if ($aStop !== null) {
                    $aStops[] = $aStop;
                }
            }
        }
        if (empty($aStops)) {
            return null;
        }
        return ['tripId' => $sTripId, 'stops' => $aStops];
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
