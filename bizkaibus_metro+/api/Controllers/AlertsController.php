<?php

namespace Controllers;

use Core\Config;
use Core\Request;
use Core\Response;
use Services\MetroAlertsClient;
use Services\SiriAlertsClient;

class AlertsController
{
    /**
     * Avisos activos de la red, ruta /alerts. Bifurca por Config::current()['network']:
     * MetroAlertsClient para metro, que devuelve la lista global del CMS de Metro
     * Bilbao sin filtro por línea, porque el metro no tiene ese concepto; y
     * SiriAlertsClient para bus, que consume SIRI-SX de Bizkaibus y admite
     * filtrar por ?line=. Sin ese parámetro, devuelve todos los avisos.
     */
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
