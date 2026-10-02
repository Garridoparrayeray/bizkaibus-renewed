<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\MetroAlertsClient;
use Services\RenfeAlertsClient;
use Services\SiriAlertsClient;

class AlertsController
{
    public function index(Request $Req): void
    {
        $aConfig = Config::current();

        $sNetwork = 'bus';
        if (isset($aConfig['network'])) {
            $sNetwork = $aConfig['network'];
        }

        if ($sNetwork === 'metro') {
            $Client = new MetroAlertsClient($aConfig);
            $aAlerts = $Client->fetchAlerts();

            if (isset($aConfig['siri'])) {
                $SiriClient = new SiriAlertsClient($aConfig);
                $aSiriAlerts = $SiriClient->fetchAlerts();
                foreach ($aSiriAlerts as $aSiri) {
                    $aAlerts[] = [
                        'title' => $aSiri['summary'] ?? 'Aviso SIRI',
                        'description' => $aSiri['description'] ?? '',
                        'severity' => $aSiri['severity'] ?? 'normal',
                    ];
                }
            }
            Response::json(['alerts' => $aAlerts]);
            return;
        }

        if (isset($aConfig['renfe_alerts'])) {
            $Client = new RenfeAlertsClient($aConfig);
        } elseif (isset($aConfig['siri'])) {
            $Client = new SiriAlertsClient($aConfig);
        } else {
            Response::json(['alerts' => []]);
            return;
        }

        $sStopFilter = $Req->query('stop');
        if ($sStopFilter !== null && $Client instanceof RenfeAlertsClient) {
            Response::json(['alerts' => $Client->alertsForStop($sStopFilter)]);
            return;
        }

        $sLineFilter = $Req->query('line');
        if ($sLineFilter !== null) {
            $aByLine = $Client->alertsByLine();
            $aAlerts = [];
            if (isset($aByLine[$sLineFilter])) {
                $aAlerts = $aByLine[$sLineFilter];
            }
            Response::json(['alerts' => $aAlerts]);
            return;
        }

        Response::json(['alerts' => $Client->fetchAlerts()]);
    }
}
