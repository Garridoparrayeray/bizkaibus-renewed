<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\MetroAlertsClient;
use Services\RenfeAlertsClient;
use Services\SiriAlertsClient;

// Incidencias de la red: /api/alerts (todas, ?line= o, en Renfe, ?stop=). Las fuentes son los clientes de
// Services (Metro, Renfe y SIRI).
class AlertsController
{
    public function index(Request $Req): void
    {
        Response::json(['alerts' => $this->alerts($Req->query('line'), $Req->query('stop'))]);
    }

    // Avisos de la red actual: todos, los de una línea (?line=) o, en Renfe, los de una estación (?stop=).
    // También los usan las notificaciones de incidencias (Services\IncidentAlerts).
    public function alerts(string|null $sLine, string|null $sStop): array
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
}
