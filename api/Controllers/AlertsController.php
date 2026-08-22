<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\SiriAlertsClient;

class AlertsController
{
    public function index(Request $request): void
    {
        $config = require __DIR__ . '/../Config/config.php';
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
