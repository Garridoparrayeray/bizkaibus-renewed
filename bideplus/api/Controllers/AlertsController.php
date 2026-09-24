<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\MetroAlertsClient;
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

        if (!isset($aConfig['siri'])) {
            Response::json(['alerts' => []]);
            return;
        }

        $Client = new SiriAlertsClient($aConfig);

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
