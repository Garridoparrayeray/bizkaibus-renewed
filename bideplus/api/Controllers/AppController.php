<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\Changelog;
use Services\Push;

// La versión de la app (su última novedad) y lo que trae cada versión posterior a la que tiene el móvil, para el aviso
// de actualización de Ajustes (js/app-update.js). «desde» es la versión del móvil y «lang», el idioma de los textos.
class AppController
{
    public function version(Request $Req, array $aParams): void
    {
        $iSince = max(0, (int)$Req->queryInt('desde', 0));
        Response::json(['version' => Changelog::version(), 'notes' => Changelog::newerThan($iSince, Push::lang($Req->query('lang')))]);
    }
}
