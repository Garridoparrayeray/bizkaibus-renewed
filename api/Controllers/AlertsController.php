<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\MetroAlertsClient;
use Services\SiriAlertsClient;

class AlertsController
{
    public function index(Request $request): void
    {
        $config = Config::current();

        $network = 'bus';
        if (isset($config['network'])) {
            $network = $config['network'];
        }

        if ($network === 'metro') {
            $client = new MetroAlertsClient($config);
            Response::json(['alerts' => $client->fetchAlerts()]);
            return;
        }

        $client = new SiriAlertsClient($config);

        $lineFilter = $request->query('line');
        if ($lineFilter !== null) {
            $byLine = $client->alertsByLine();
            $alerts = [];
            if (isset($byLine[$lineFilter])) {
                $alerts = $byLine[$lineFilter];
            }
            Response::json(['alerts' => $alerts]);
            return;
        }

        Response::json(['alerts' => $client->fetchAlerts()]);
    }
}
