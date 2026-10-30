<?php
// Páginas HTML: el menú de Bide+ (/), el mapa de la red (/mapa) y la app de cada red (/?red=, /stops/{id}, /lines/{id}).

require __DIR__ . '/autoload.php';

// Sin parse_url: confunde «/lines/tolosaldea:4/…» con un host y un puerto.
$sPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?#');
// bideplusmiamor.vercel.app va directa al tema, sin pasar por el menú de apps: es un dominio dedicado.
$bMiamorDomain = ($_SERVER['HTTP_HOST'] ?? '') === 'bideplusmiamor.vercel.app';

if ($sPath === '/mapa') {
    require __DIR__ . '/Views/network-map.php';
    exit;
}
if (!$bMiamorDomain && ($sPath === '/' || $sPath === '') && !isset($_GET['red']) && !isset($_GET['tema'])) {
    require __DIR__ . '/Views/menu.php';
    exit;
}

require __DIR__ . '/Views/app-page.php';
require __DIR__ . '/Views/app.php';
