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
            Response::json(['alerts' => $Client->fetchAlerts()]);
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
