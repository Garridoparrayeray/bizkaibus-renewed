<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\Changelog;
use Services\Push;

// Versión de la app y novedades posteriores a «desde», en el idioma «lang».
class AppController
{
    public function version(Request $Req, array $aParams): void
    {
        $iSince = max(0, (int)$Req->queryInt('desde', 0));
        Response::json(['version' => Changelog::version(), 'notes' => Changelog::newerThan($iSince, Push::lang($Req->query('lang')))]);
    }
}
