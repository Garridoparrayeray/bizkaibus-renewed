<?php

// Avisa por IndexNow (Bing, Yandex y los buscadores que lo comparten; ChatGPT y Copilot buscan con Bing) de las
// páginas principales de Bide+ en los tres idiomas: el inicio, el mapa y cada app. Las paradas y las líneas las
// encuentran en el sitemap. Se lanza después de cada despliegue; si falla, no para nada.
// Uso: php scripts/indexnow.php [--dry-run]

declare(strict_types=1);

$sRoot = dirname(__DIR__);
require_once $sRoot . '/api/Core/Networks.php';
require_once $sRoot . '/api/Core/Lang.php';
$aSite = require $sRoot . '/api/Config/site.php';
$bDryRun = in_array('--dry-run', $argv, true);

$aPages = [$aSite['url'] . '/', $aSite['url'] . '/mapa'];
foreach (\Core\Networks::slugs() as $sSlug) {
    $aPages[] = $aSite['url'] . '/?red=' . $sSlug;
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
    'urlList' => $aUrls,
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
echo 'IndexNow: ' . count($aUrls) . ' páginas → ' . $sStatus . "\n";
if ($sResponse === false || !preg_match('#\s20[02]\s#', $sStatus)) {
    echo trim((string)$sResponse) . "\n";
    exit(1);
}
