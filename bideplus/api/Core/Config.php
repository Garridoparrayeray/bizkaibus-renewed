<?php

namespace Core;

class Config
{
    private static array|null $aCurrent = null;

    public static function set(string $sNetwork): void
    {
        self::$aCurrent = Networks::get($sNetwork);
    }

    public static function withoutHiddenLines(array $aLines): array
    {
        $aHidden = self::current()['hidden_line_codes'] ?? [];
        if (empty($aHidden)) {
            return $aLines;
        }
        return array_values(array_filter($aLines, fn($aLine) => !in_array($aLine['code'], $aHidden, true)));
    }

    public static function current(): array
    {
        if (self::$aCurrent === null) {
            self::set(Networks::DEFAULT);
        }
        return self::$aCurrent;
    }

    public static function network(): string
    {
        return self::current()['slug'];
    }

    public static function feature(string $sFeature): mixed
    {
        return Networks::feature(self::current(), $sFeature);
    }

    public static function hasLive(string $sSource): bool
    {
        return Networks::hasLive(self::current(), $sSource);
    }
}
