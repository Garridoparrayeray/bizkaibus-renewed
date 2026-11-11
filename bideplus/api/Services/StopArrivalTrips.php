<?php

namespace Services;

use Core\Cache;
use Core\Http;
use Models\ServiceJourney;

// Trenes completos a partir de un feed SIRI que solo da llegadas sueltas a cada parada. Es el respaldo de las redes
// cuyo GTFS-Realtime manda todos los viajes con el mismo número (Metro Bilbao los manda con el «0», y el Consorcio los
// junta en uno solo): cada llegada se une a la de la parada siguiente de su recorrido que llega justo cuando el
// horario dice que un tren tarda en ir de una a otra. Solo usa el feed y el horario de la propia red; lo que cambia
// de una red a otra va en su ficha (siri.vehicle_monitoring_url, gtfs_rt.siri_fallback).
// Devuelve lo mismo que GtfsRealtimeClient::fetchTripUpdates(): [['stops' => [[parada, hora unix], ...]], ...].
class StopArrivalTrips
{
    // Cuánto puede apartarse lo que tarda un tren de lo que dice el horario para unir dos llegadas, y cuántas
    // paradas tiene que tener un tren para valer.
    private const LINK_SLACK_SECONDS = 90;
    private const MIN_STOPS = 2;
    private const SEGMENTS_CACHE_SECONDS = 3600;

    // El feed pone la parada en uno de estos elementos y la hora de llegada en uno de estos otros.
    private const STOP_ELEMENTS = ['StopPointRef', 'DestinationRef'];
    private const TIME_ELEMENTS = ['ExpectedArrivalTime', 'AimedArrivalTime', 'DestinationAimedArrivalTime'];

    public function __construct(private array $aConfig, private ServiceJourney $JourneyModel)
    {
    }

    public function enabled(): bool
    {
        return !empty($this->aConfig['gtfs_rt']['siri_fallback']) && !empty($this->aConfig['siri']['vehicle_monitoring_url']);
    }

    public function trips(int $iNow): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $aSiri = $this->aConfig['siri'];
        $aArrivals = Cache::rememberFeed('siri_arrivals_' . $this->aConfig['network'], $aSiri['cache_ttl_seconds'], function () use ($aSiri) {
            return self::parseArrivals(Http::get($aSiri['vehicle_monitoring_url'], $aSiri['http_timeout_seconds']));
        });
        $aArrivals = self::recent($aArrivals, $iNow - $this->aConfig['gtfs_rt']['max_age_seconds']);
        $aSegments = Cache::remember('segments_' . $this->aConfig['network'], self::SEGMENTS_CACHE_SECONDS, function () {
            return $this->JourneyModel->segmentSeconds();
        });
        return self::chain($aArrivals, $aSegments);
    }

    // Las llegadas del feed: [['stop' => id, 'time' => hora unix, 'recorded' => hora de la lectura], ...].
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
            $aArrivals[] = ['stop' => self::stopId($sStop), 'time' => $iTime, 'recorded' => $iRecorded];
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

    // Une las llegadas en trenes. Cada par de llegadas (A, B) con B siguiente de A en un recorrido se puntúa por lo
    // que se aparta su tiempo del que dice el horario; se aceptan de menor a mayor error, una llegada no puede tener
    // dos siguientes ni dos anteriores (en una bifurcación, cada tren sigue por donde le cuadra la hora).
    public static function chain(array $aArrivals, array $aSegments): array
    {
        $aByStop = [];
        foreach ($aArrivals as $iIndex => $aArrival) {
            $aByStop[$aArrival['stop']][] = $iIndex;
        }
        $aLinks = [];
        foreach ($aSegments as $sEdge => $iSeconds) {
            [$sFrom, $sTo] = explode('|', $sEdge);
            foreach ($aByStop[$sFrom] ?? [] as $iFrom) {
                foreach ($aByStop[$sTo] ?? [] as $iTo) {
                    $iError = abs($aArrivals[$iTo]['time'] - $aArrivals[$iFrom]['time'] - $iSeconds);
                    if ($iError <= self::LINK_SLACK_SECONDS) {
                        $aLinks[] = [$iError, $iFrom, $iTo];
                    }
                }
            }
        }
        usort($aLinks, fn($aA, $aB) => $aA[0] <=> $aB[0]);
        $aNext = [];
        $aHasPrevious = [];
        foreach ($aLinks as [$iError, $iFrom, $iTo]) {
            if (isset($aNext[$iFrom]) || isset($aHasPrevious[$iTo])) {
                continue;
            }
            $aNext[$iFrom] = $iTo;
            $aHasPrevious[$iTo] = true;
        }
        return self::walk($aArrivals, $aNext, $aHasPrevious);
    }

    // Recorre cada cadena desde su primera llegada. Donde el tren llega a la terminal y vuelve por donde vino
    // (A, B, A), el viaje acaba allí y la vuelta es otro, que sale de esa terminal.
    private static function walk(array $aArrivals, array $aNext, array $aHasPrevious): array
    {
        $aTrips = [];
        foreach (array_keys($aArrivals) as $iStart) {
            if (isset($aHasPrevious[$iStart])) {
                continue;
            }
            $aStops = [];
            for ($iCurrent = $iStart; $iCurrent !== null; $iCurrent = $aNext[$iCurrent] ?? null) {
                $aStop = [$aArrivals[$iCurrent]['stop'], $aArrivals[$iCurrent]['time']];
                $iCount = \count($aStops);
                if ($iCount >= self::MIN_STOPS && $aStops[$iCount - 2][0] === $aStop[0]) {
                    $aTrips[] = ['stops' => $aStops];
                    $aStops = [$aStops[$iCount - 1]];
                }
                $aStops[] = $aStop;
            }
            $aTrips[] = ['stops' => $aStops];
        }
        return array_values(array_filter($aTrips, fn($aTrip) => \count($aTrip['stops']) >= self::MIN_STOPS));
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
