<?php

namespace Services;

use Core\Cache;
use Core\Http;


class PlaceGeocoder
{
    private const CONTACT = 'garridoparrayeraytx@gmail.com';

    
    public static function resolve(string $query): ?array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return null;
        }

        $cacheKey = 'geocode_' . preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($trimmed));
        return Cache::remember($cacheKey, 30 * 24 * 3600, function () use ($trimmed) {
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $trimmed,
                'countrycodes' => 'es',
                'format' => 'jsonv2',
                'limit' => 1,
            ]);
            try {
                $body = Http::get($url, 6, ['User-Agent: GasolineraPlus-geocoder/1.0 (' . self::CONTACT . ')']);
            } catch (\Throwable $e) {
                return null;
            }

            $results = json_decode($body, true);
            if (!is_array($results) || empty($results)) {
                return null;
            }
            $first = $results[0];
            if (!isset($first['lat'], $first['lon'])) {
                return null;
            }
            return ['lat' => (float)$first['lat'], 'lon' => (float)$first['lon']];
        });
    }
}
