<?php

// Servidor local: php -S localhost:8011 dev-router.php
// Reproduce vercel.json: las mismas cabeceras de seguridad y las mismas rutas bloqueadas.

$sUri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$aVercel = json_decode((string)file_get_contents(__DIR__ . '/vercel.json'), true);
foreach ($aVercel['routes'] ?? [] as $aRoute) {
    if (empty($aRoute['headers'])) {
        continue;
    }
    foreach ($aRoute['headers'] as $sName => $sValue) {
        // En http://localhost no hay HTTPS: HSTS y la subida a https romperían el servidor local.
        if ($sName === 'Strict-Transport-Security') {
            continue;
        }
        if ($sName === 'Content-Security-Policy') {
            $sValue = trim(str_replace('upgrade-insecure-requests', '', $sValue), '; ');
        }
        header($sName . ': ' . $sValue);
    }
}

if ($sUri === '/api/shell.php' || preg_match('#^/(data|scripts|tests|test_gtfs)(/|$)#', $sUri) || preg_match('#^/(dev-router[.]php|bizkaibus-renewed[.]code-workspace)$#', $sUri)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    return true;
}

if ($sUri === '/sitemap.xml' || preg_match('#^/sitemap-(apps|bus|metro|euskotren|tranvia-bilbao|tranvia-vitoria|renfe)[.]xml$#', $sUri, $aSitemap)) {
    if (isset($aSitemap[1])) {
        $_GET['red'] = $aSitemap[1];
    }
    require __DIR__ . '/api/sitemap.php';
    return true;
}

$sFile = realpath(__DIR__ . $sUri);
if ($sUri !== '/' && $sFile !== false && is_file($sFile) && str_starts_with($sFile, __DIR__ . DIRECTORY_SEPARATOR)) {
    $aTypes = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'pdf' => 'application/pdf',
    ];
    $sExtension = strtolower(pathinfo($sFile, PATHINFO_EXTENSION));
    if ($sExtension === 'php') {
        http_response_code(404);
        readfile(__DIR__ . '/404.html');
        return true;
    }
    header('Content-Type: ' . ($aTypes[$sExtension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($sFile));
    readfile($sFile);
    return true;
}

if (str_starts_with($sUri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

if ($sUri === '/' || str_starts_with($sUri, '/lines/') || str_starts_with($sUri, '/stops/')) {
    if (isset($_COOKIE['lento'])) {
        usleep((int) $_COOKIE['lento'] * 1000);
    }
    require __DIR__ . '/api/shell.php';
    return true;
}

http_response_code(404);
readfile(__DIR__ . '/404.html');
