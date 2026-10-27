<?php

namespace Core;

// Registro de redes: cada fichero de api/Networks describe una. Añadir una red es añadir su fichero.
class Networks
{
    public const DEFAULT = 'bus';

    private static array|null $aAll = null;

    public static function all(): array
    {
        if (self::$aAll === null) {
            $aAll = [];
            foreach (glob(__DIR__ . '/../Networks/*.php') as $sPath) {
                $aNetwork = require $sPath;
                $aNetwork['network'] = $aNetwork['slug'];
                $aNetwork['db_path'] = __DIR__ . '/../../data/' . $aNetwork['db'] . '.sqlite';
                $aNetwork['schedule_source_published'] ??= date('Y-m-d');
                $aNetwork['live'] ??= [];
                $aNetwork['brand']['card_logomark'] ??= $aNetwork['brand']['logomark'];
                $aAll[$aNetwork['slug']] = $aNetwork;
            }
            uasort($aAll, fn($aA, $aB) => $aA['order'] <=> $aB['order']);
            self::$aAll = $aAll;
        }
        return self::$aAll;
    }

    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string|null $sSlug): bool
    {
        return $sSlug !== null && isset(self::all()[$sSlug]);
    }

    public static function get(string $sSlug): array
    {
        return self::all()[$sSlug] ?? self::all()[self::DEFAULT];
    }

    // ?red=... válido, o la red por defecto.
    public static function fromQuery(string|null $sSlug): string
    {
        return self::exists($sSlug) ? $sSlug : self::DEFAULT;
    }

    // Sufijo de las URL públicas: la red por defecto no lo lleva.
    public static function urlSuffix(string $sSlug): string
    {
        return $sSlug === self::DEFAULT ? '' : '?red=' . $sSlug;
    }

    public static function hasLive(array $aNetwork, string $sSource): bool
    {
        return in_array($sSource, $aNetwork['live'] ?? [], true);
    }

    public static function feature(array $aNetwork, string $sFeature): mixed
    {
        return $aNetwork['features'][$sFeature] ?? false;
    }

    public static function text(array $aNetwork, string $sKey, string $sLang = 'es'): string
    {
        return $aNetwork['texts'][$sLang][$sKey] ?? $aNetwork['texts']['es'][$sKey] ?? '';
    }
}
