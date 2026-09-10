<?php

namespace Services;

use Core\Cache;
use Core\Http;

/**
 * Resuelve un nombre de lugar (municipio, localidad, código postal...) a
 * lat/lon vía Nominatim, para el caso en que la búsqueda de texto sobre
 * `stations` no encuentra nada porque ese municipio no tiene ninguna
 * gasolinera propia: en vez de un resultado vacío, se usa el centroide
 * para buscar las gasolineras más cercanas por radio, igual que si el
 * usuario hubiese pulsado "cerca de mí" ahí.
 */
class PlaceGeocoder
{
    private const CONTACT = 'garridoparrayeraytx@gmail.com';

    /** @return array{lat: float, lon: float}|null */
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
