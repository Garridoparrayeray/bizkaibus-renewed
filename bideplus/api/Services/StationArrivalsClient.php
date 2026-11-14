<?php

namespace Services;

use Core\Cache;
use Core\Http;

// Llegadas oficiales por estación y andén: la API de la app del operador (station_arrivals en la ficha de la red). Cada
// andén se pide como el viaje de la estación a la siguiente de ese sentido, así salen todos los trenes que paran en
// él, con su destino y su hora exacta. Solo lee: lo que se hace con ellas está en StationArrivalsMatcher.
class StationArrivalsClient
{
    private const TIME_ZONE = 'Europe/Madrid';

    public function __construct(private array $aConfig)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->aConfig['station_arrivals']['url']);
    }

    public function host(): string
    {
        return (string)parse_url($this->aConfig['station_arrivals']['url'], PHP_URL_HOST);
    }

    // Los trenes de cada andén: [siguiente parada => [['time' => unix, 'destination' => nombre]], o null si ese andén
    // no respondió]. Vacío si la estación no tiene código, si no responde ninguno o si lo guardado es ya viejo.
    public function arrivals(string $sStopId, array $aNextStopIds, int $iNow): array
    {
        $aSettings = $this->aConfig['station_arrivals'];
        $aCodes = $aSettings['codes'];
        if (!isset($aCodes[$sStopId])) {
            return [];
        }
        $aUrls = [];
        foreach ($aNextStopIds as $sNextStopId) {
            if (isset($aCodes[$sNextStopId])) {
                $aUrls[(string)$sNextStopId] = strtr($aSettings['url'], ['{from}' => $aCodes[$sStopId], '{to}' => $aCodes[$sNextStopId]]);
            }
        }
        if (empty($aUrls)) {
            return [];
        }
        ksort($aUrls);
        $sKey = 'station_arrivals_' . $this->aConfig['network'] . '_' . $sStopId . '_' . implode('_', array_keys($aUrls));
        $aCached = Cache::remember($sKey, $aSettings['cache_ttl_seconds'], function () use ($aUrls, $aSettings) {
            return self::fetched(Http::getAll($aUrls, $aSettings['http_timeout_seconds']), time());
        });
        if (!isset($aCached['at'], $aCached['directions']) || $iNow - (int)$aCached['at'] > $aSettings['max_age_seconds']) {
            return [];
        }
        return $aCached['directions'];
    }

    // Las respuestas de los andenes ya leídas, con la hora a la que se pidieron. Si no vale ninguna, un error: la
    // caché no guarda el fallo y vuelve a probar.
    public static function fetched(array $aBodies, int $iNow): array
    {
        $aDirections = [];
        foreach ($aBodies as $sNextStopId => $sBody) {
            $aDirections[(string)$sNextStopId] = null;
            if ($sBody !== null) {
                $aDirections[(string)$sNextStopId] = self::parse($sBody);
            }
        }
        if (empty(array_filter($aDirections, fn($aTrains) => $aTrains !== null))) {
            throw new \RuntimeException('Ningún andén respondió');
        }
        return ['at' => $iNow, 'directions' => $aDirections];
    }

    // Los trenes de una respuesta («direction» es el destino y «time» la llegada, en hora de Madrid), o null si no
    // tiene la forma esperada. Una lista vacía es que no viene ningún tren (fin del servicio).
    public static function parse(string $sBody): array|null
    {
        $aAnswer = json_decode($sBody, true);
        if (!\is_array($aAnswer) || !\is_array($aAnswer['trains'] ?? null)) {
            return null;
        }
        $Zone = new \DateTimeZone(self::TIME_ZONE);
        $aTrains = [];
        foreach ($aAnswer['trains'] as $aTrain) {
            $sTime = $aTrain['time'] ?? null;
            $sDestination = $aTrain['direction'] ?? null;
            if (!\is_string($sTime) || !\is_string($sDestination) || $sDestination === '') {
                continue;
            }
            $Time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $sTime, $Zone);
            if ($Time === false) {
                continue;
            }
            $aTrains[] = ['time' => $Time->getTimestamp(), 'destination' => $sDestination];
        }
        return $aTrains;
    }
}
