<?php

// Avisa por IndexNow (Bing, Yandex y los buscadores que lo comparten; ChatGPT y Copilot buscan con Bing) de las
// páginas principales de Bide+ en los cuatro idiomas (el inicio, el mapa y cada app) y de las paradas y líneas que
// han cambiado hoy (su lastmod en scripts/sitemap-lastmod.json). El resto, en sitemap-index.xml. Se lanza después de
// cada despliegue; si falla, no para nada.
// Uso: php scripts/indexnow.php [--dry-run]

declare(strict_types=1);

$sRoot = dirname(__DIR__);
require_once $sRoot . '/api/Core/Networks.php';
require_once $sRoot . '/api/Core/Lang.php';
$aSite = require $sRoot . '/api/Config/site.php';
$bDryRun = in_array('--dry-run', $argv, true);
// Lo más que admite IndexNow en una petición.
const INDEXNOW_MAX_URLS = 10000;

$aPages = [$aSite['url'] . '/', $aSite['url'] . '/mapa'];
foreach (\Core\Networks::slugs() as $sSlug) {
    $aPages[] = $aSite['url'] . '/?red=' . $sSlug;
}
$sStatePath = __DIR__ . '/sitemap-lastmod.json';
if (is_file($sStatePath)) {
    date_default_timezone_set('Europe/Madrid');
    foreach (json_decode((string)file_get_contents($sStatePath), true) ?? [] as $sPath => [$sHash, $sDate]) {
        if ($sDate === date('Y-m-d')) {
            $aPages[] = $aSite['url'] . $sPath;
        }
    }
}
$aUrls = [];
foreach ($aPages as $sPage) {
    foreach (\Core\Lang::LANGS as $sLang) {
        $aUrls[] = \Core\Lang::url($sPage, $sLang);
    }
}

$sHost = (string)parse_url($aSite['url'], PHP_URL_HOST);
$sBody = json_encode([
    'host' => $sHost,
    'key' => $aSite['indexnow_key'],
    'keyLocation' => $aSite['url'] . '/' . $aSite['indexnow_key'] . '.txt',
    'urlList' => array_slice($aUrls, 0, INDEXNOW_MAX_URLS),
], JSON_UNESCAPED_SLASHES);
if ($bDryRun) {
    echo $sBody . "\n";
    exit(0);
}
$Context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json; charset=utf-8\r\n",
    'content' => $sBody,
    'timeout' => 20,
    'ignore_errors' => true,
]]);
$sResponse = @file_get_contents('https://api.indexnow.org/indexnow', false, $Context);
$sStatus = $http_response_header[0] ?? 'sin respuesta';
echo 'IndexNow: ' . min(count($aUrls), INDEXNOW_MAX_URLS) . ' páginas → ' . $sStatus . "\n";
if ($sResponse === false || !preg_match('#\s20[02]\s#', $sStatus)) {
    echo trim((string)$sResponse) . "\n";
    exit(1);
}
