<?php

namespace Core;

class TripKey
{
    public static function build(string|int $LineId, string $sTripNumber, int $iFirstDepartureSeconds): string
    {
        return $LineId . '-' . $sTripNumber . '-' . $iFirstDepartureSeconds;
    }

    public static function parse(string $sKey): array|null
    {
        if (!preg_match('/^(.+)-([^-]+)-(\d+)$/', $sKey, $aMatches)) {
            return null;
        }
        return [$aMatches[1], $aMatches[2], (int)$aMatches[3]];
    }
}
