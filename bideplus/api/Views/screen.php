<?php
// Pantalla de una parada (/pantalla/{id}?red=…): las próximas salidas a pantalla completa, para dejarla puesta en una
// tele o una tablet de un bar, una tienda o una oficina. Se refresca sola (js/screen.js) y no se indexa.

use Core\Config;
use Core\Database;
use Core\Networks;
use Models\Stop;

$sNetworkSlug = Networks::fromQuery($_GET['red'] ?? null);
$aNetwork = Networks::get($sNetworkSlug);
$sStopId = rawurldecode(substr($sPath, strlen('/pantalla/')));

Config::set($sNetworkSlug);
$aStop = null;
if ($sStopId !== '') {
    $aStop = (new Stop(Database::connection()))->find($sStopId);
}
$sStopName = 'Parada no encontrada';
$sFound = '0';
if ($aStop === null) {
    http_response_code(404);
} else {
    $sStopName = $aStop['name'];
    $sFound = '1';
}
$sAppName = Networks::text($aNetwork, 'name');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($sStopName . ' · ' . $sAppName) ?></title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="<?= htmlspecialchars($aNetwork['brand']['theme_color']) ?>">
    <link rel="icon" href="/<?= htmlspecialchars($aNetwork['brand']['icons']) ?>/icon-192.png">
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/style-screen.css">
    <script src="/js/i18n.js"></script>
    <script src="/js/networks.js"></script>
    <script src="/js/api.js"></script>
    <script type="module" src="/js/screen.js"></script>
</head>
<body class="screen" data-stop-id="<?= htmlspecialchars((string)($aStop['id'] ?? $sStopId)) ?>" data-stop-found="<?= $sFound ?>">
    <header class="screen-header">
        <div class="screen-place">
            <p class="screen-app"><?= htmlspecialchars($sAppName) ?></p>
            <h1 class="screen-stop"><?= htmlspecialchars($sStopName) ?></h1>
        </div>
        <time class="screen-clock" data-screen-clock></time>
    </header>
    <main class="screen-main">
        <p class="screen-heading" data-i18n="screen.title">Próximas salidas</p>
        <ol class="screen-list" data-screen-list></ol>
        <p class="screen-empty" data-screen-empty data-i18n="screen.noDepartures" hidden>No hay más salidas por ahora</p>
    </main>
    <footer class="screen-footer">
        <span class="screen-status" data-screen-status></span>
        <span class="screen-brand" data-i18n="screen.footer">Horarios y tiempo real en bideplus.vercel.app</span>
    </footer>
</body>
</html>
