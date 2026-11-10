<?php

namespace Controllers;

use Core\Request;
use Core\Response;
use Services\Changelog;
use Services\Notice;
use Services\Push;

// Versión de la app y novedades posteriores a «desde», en el idioma «lang»; y el aviso general, si lo hay.
class AppController
{
    public function version(Request $Req, array $aParams): void
    {
        $iSince = max(0, (int)$Req->queryInt('desde', 0));
        Response::json(['version' => Changelog::version(), 'notes' => Changelog::newerThan($iSince, Push::lang($Req->query('lang')))]);
    }

    public function notice(Request $Req, array $aParams): void
    {
        $aNotice = Notice::published(time());
        if ($aNotice !== null) {
            unset($aNotice['notify']);
        }
        Response::json(['notice' => $aNotice]);
    }
}
