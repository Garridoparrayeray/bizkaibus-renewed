<?php

namespace Services;

use Core\Cache;
use Core\Http;
use Models\ServiceJourney;

// Los trenes del SIRI de llegadas por parada de una red, el respaldo cuando su GTFS-Realtime no responde: cada
// llegada lleva el número de su tren (VehicleJourneyRef), así que un tren son sus llegadas, por hora. Devuelve lo
// mismo que GtfsRealtimeClient::fetchTripUpdates(): [['tripId', 'stops' => [[parada, hora unix], ...]], ...]. Lo
// que cambia de una red a otra va en su ficha (siri.vehicle_monitoring_url, gtfs_rt.siri_fallback).
class StopArrivalTrips
{
    private const SEGMENTS_CACHE_SECONDS = 3600;

    // El feed pone la parada en uno de estos elementos y la hora de llegada en uno de estos otros.
    private const STOP_ELEMENTS = ['StopPointRef', 'DestinationRef'];
    private const TIME_ELEMENTS = ['ExpectedArrivalTime', 'AimedArrivalTime', 'DestinationAimedArrivalTime'];
    private const TRAIN_ELEMENT = 'VehicleJourneyRef';

    public function __construct(private array $aConfig, private ServiceJourney $JourneyModel)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->aConfig['gtfs_rt']['siri_fallback']) && !empty($this->aConfig['siri']['vehicle_monitoring_url']);
    }

    // Lo que tarda un tren entre paradas según el horario de la red: ['A|B' => segundos].
    public function segments(): array
    {
        return Cache::remember('segments_' . $this->aConfig['network'], self::SEGMENTS_CACHE_SECONDS, function () {
            return $this->JourneyModel->segmentSeconds();
        });
    }

    // Los trenes con las llegadas leídas en los últimos gtfs_rt.max_age_seconds (un feed parado no vale).
    public function trains(int $iNow): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $aSiri = $this->aConfig['siri'];
        $aArrivals = Cache::rememberFeed('siri_trains_' . $this->aConfig['network'], $aSiri['cache_ttl_seconds'], function () use ($aSiri) {
            return self::parseArrivals(Http::get($aSiri['vehicle_monitoring_url'], $aSiri['http_timeout_seconds']));
        });
        return self::grouped(self::recent($aArrivals, $iNow - $this->aConfig['gtfs_rt']['max_age_seconds']));
    }

    // Las llegadas de cada tren juntas y por hora; las que no traen número de tren no valen.
    public static function grouped(array $aArrivals): array
    {
        $aByTrain = [];
        foreach ($aArrivals as $aArrival) {
            if ($aArrival['train'] !== '') {
                $aByTrain[$aArrival['train']][] = [$aArrival['stop'], $aArrival['time']];
            }
        }
        $aTrains = [];
        foreach ($aByTrain as $sTrain => $aStops) {
            usort($aStops, fn(array $aA, array $aB) => $aA[1] <=> $aB[1]);
            $aTrains[] = ['tripId' => (string)$sTrain, 'stops' => $aStops];
        }
        return $aTrains;
    }

    // Las llegadas del feed: [['stop' => id, 'time' => hora unix, 'recorded' => hora de la lectura, 'train' => número
    // del tren o ''], ...].
    public static function parseArrivals(string $sXml): array
    {
        $Dom = new \DOMDocument();
        if (!@$Dom->loadXML($sXml, LIBXML_NONET)) {
            return [];
        }
        $aArrivals = [];
        foreach ($Dom->getElementsByTagNameNS('*', 'VehicleActivity') as $Activity) {
            $sStop = self::firstText($Activity, self::STOP_ELEMENTS);
            $iTime = strtotime(self::firstText($Activity, self::TIME_ELEMENTS));
            if ($sStop === '' || $iTime === false) {
                continue;
            }
            $iRecorded = strtotime(self::firstText($Activity, ['RecordedAtTime']));
            if ($iRecorded === false) {
                $iRecorded = $iTime;
            }
            $aArrivals[] = ['stop' => self::stopId($sStop), 'time' => $iTime, 'recorded' => $iRecorded, 'train' => self::firstText($Activity, [self::TRAIN_ELEMENT])];
        }
        return $aArrivals;
    }

    // Las llegadas leídas desde $iSince: un feed parado no vale.
    public static function recent(array $aArrivals, int $iSince): array
    {
        $iNewest = 0;
        foreach ($aArrivals as $aArrival) {
            $iNewest = max($iNewest, $aArrival['recorded']);
        }
        if ($iNewest < $iSince) {
            return [];
        }
        return $aArrivals;
    }

    private static function firstText(\DOMElement $Parent, array $aNames): string
    {
        foreach ($aNames as $sName) {
            $Nodes = $Parent->getElementsByTagNameNS('*', $sName);
            if ($Nodes->length > 0) {
                return trim($Nodes->item(0)->textContent);
            }
        }
        return '';
    }

    // «51.0» es la parada 51: el feed manda los números con decimales.
    private static function stopId(string $sStop): string
    {
        if (preg_match('/^(\d+)\.0+$/', $sStop, $aMatch)) {
            return $aMatch[1];
        }
        return $sStop;
    }
}
