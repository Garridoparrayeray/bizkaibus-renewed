<?php

namespace Core;

// Registro de redes: cada fichero de api/Networks describe una. Añadir una red es añadir su fichero.
class Networks
{
    private static array|null $aAll = null;
    private static string|null $sDefault = null;

    // La red por defecto (default_network en api/Config/site.php): se abre en «/» y sus direcciones no llevan ?red=.
    // Si esa red no existe, la primera.
    public static function defaultSlug(): string
    {
        if (self::$sDefault === null) {
            $sSlug = (require __DIR__ . '/../Config/site.php')['default_network'] ?? null;
            if (!isset(self::all()[$sSlug])) {
                $sSlug = array_key_first(self::all());
            }
            self::$sDefault = $sSlug;
        }
        return self::$sDefault;
    }

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
        return self::all()[$sSlug] ?? self::all()[self::defaultSlug()];
    }

    // ?red=... válido, o la red por defecto.
    public static function fromQuery(string|null $sSlug): string
    {
        if (self::exists($sSlug)) {
            return $sSlug;
        }
        return self::defaultSlug();
    }

    // Sufijo de las URL públicas: la red por defecto no lo lleva.
    public static function urlSuffix(string $sSlug): string
    {
        if ($sSlug === self::defaultSlug()) {
            return '';
        }
        return '?red=' . $sSlug;
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
