<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\GtfsRealtimeClient;
use Services\MetroAlertsClient;
use Services\ReducedService;
use Services\RenfeAlertsClient;
use Services\SiriAlertsClient;

// Incidencias de la red: /api/alerts (todas, ?line= o, en Renfe, ?stop=), con el servicio reducido de la red
// (Services\ReducedService), que también da /api/reduced. Las fuentes son los clientes de Metro, Renfe, GTFS-RT y SIRI.
class AlertsController
{
    public function index(Request $Req): void
    {
        Response::json(['alerts' => $this->alerts($Req->query('line'), $Req->query('stop')), 'reducedService' => ReducedService::current()]);
    }

    // Solo el servicio reducido de la red (/api/reduced): lo pide la app al arrancar y el planificador, red a red.
    public function reduced(Request $Req): void
    {
        Response::json(ReducedService::current());
    }

    // Avisos de la red actual: todos, los de una línea (?line=) o, en Renfe, los de una estación (?stop=). Con
    // $bUpcoming, también los que aún no han empezado. Los usan los avisos de incidencias y el servicio reducido.
    public function alerts(string|null $sLine, string|null $sStop, bool $bUpcoming = false): array
    {
        return self::withoutRepeats($this->sourceAlerts($sLine, $sStop, $bUpcoming));
    }

    // Cada aviso una vez: el SIRI de Metro repite el mismo decenas de veces, sin nada que los distinga.
    public static function withoutRepeats(array $aAlerts): array
    {
        $aSeen = [];
        $aOut = [];
        foreach ($aAlerts as $aAlert) {
            $sKey = json_encode([
                $aAlert['summary'] ?? $aAlert['title'] ?? '',
                $aAlert['description'] ?? '',
                $aAlert['lineRefs'] ?? [],
                $aAlert['stopRefs'] ?? [],
            ]);
            if (!isset($aSeen[$sKey])) {
                $aSeen[$sKey] = true;
                $aOut[] = $aAlert;
            }
        }
        return $aOut;
    }

    private function sourceAlerts(string|null $sLine, string|null $sStop, bool $bUpcoming): array
    {
        $aConfig = Config::current();

        if (isset($aConfig['metro_alerts'])) {
            $aAlerts = (new MetroAlertsClient($aConfig))->fetchAlerts();
            if (isset($aConfig['siri'])) {
                foreach ((new SiriAlertsClient($aConfig))->fetchAlerts() as $aSiri) {
                    $aAlerts[] = [
                        'title' => $aSiri['summary'] ?? 'Aviso SIRI',
                        'description' => $aSiri['description'] ?? '',
                        'severity' => $aSiri['severity'] ?? 'normal',
                    ];
                }
            }
            return $aAlerts;
        }

        if (isset($aConfig['gtfs_rt']['alerts_url'])) {
            return self::gtfsRtAlerts((new GtfsRealtimeClient($aConfig))->fetchAlerts($bUpcoming), $sLine);
        }

        if (isset($aConfig['renfe_alerts'])) {
            $Client = new RenfeAlertsClient($aConfig);
        } elseif (isset($aConfig['siri'])) {
            $Client = new SiriAlertsClient($aConfig);
        } else {
            return [];
        }

        if ($sStop !== null && $Client instanceof RenfeAlertsClient) {
            return $Client->alertsForStop($sStop);
        }
        if ($sLine !== null) {
            return $Client->alertsByLine()[$sLine] ?? [];
        }
        return $Client->fetchAlerts();
    }

    // Con ?line=, solo las que nombran esa línea.
    private static function gtfsRtAlerts(array $aAlerts, string|null $sLine): array
    {
        $aOut = [];
        foreach ($aAlerts as $aAlert) {
            if ($sLine !== null && !in_array($sLine, $aAlert['lineRefs'], true)) {
                continue;
            }
            $aOut[] = [
                'summary' => $aAlert['summary'],
                'description' => $aAlert['description'],
                'lineRefs' => $aAlert['lineRefs'],
                'stopRefs' => $aAlert['stopRefs'],
                'startTime' => $aAlert['startTime'],
                'endTime' => $aAlert['endTime'],
            ];
        }
        return $aOut;
    }
}
