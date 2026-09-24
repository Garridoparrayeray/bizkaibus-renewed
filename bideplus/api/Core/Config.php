<?php

namespace Core;

class Config
{
    private static array|null $aCurrent = null;

    public static function set(string $sNetwork): void
    {
        if ($sNetwork === 'metro') {
            $sPath = __DIR__ . '/../Config/metro.php';
        } elseif ($sNetwork === 'euskotren') {
            $sPath = __DIR__ . '/../Config/euskotren.php';
        } elseif ($sNetwork === 'tranvia-bilbao') {
            $sPath = __DIR__ . '/../Config/tranvia-bilbao.php';
        } elseif ($sNetwork === 'tranvia-vitoria') {
            $sPath = __DIR__ . '/../Config/tranvia-vitoria.php';
        } else {
            $sPath = __DIR__ . '/../Config/config.php';
        }
        self::$aCurrent = require $sPath;
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
            self::set('bus');
        }
        return self::$aCurrent;
    }
}
