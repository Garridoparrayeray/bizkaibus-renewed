<?php

// Servidor local: php -S localhost:8011 dev-router.php
// Reproduce vercel.json: las mismas cabeceras de seguridad y las mismas rutas bloqueadas.

$sUri = urldecode((string)strtok($_SERVER['REQUEST_URI'], '?#'));

// Un byte nulo o de control en la ruta nunca es un fichero ni una ruta válida: fuera antes de tocar el disco
// (realpath() con un byte nulo aborta). En producción (Vercel) estas rutas ya acaban en «no encontrado».
if ($sUri === '' || preg_match('/[\x00-\x1f]/', $sUri)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    return true;
}

// La ruta normalizada, sin «//», «/./» ni «..»: los bloqueos de abajo miran esta, y escribir la misma ruta de otra
// forma no los salta. Un tramo que empieza por punto (.env, .vercel, .git…) no se sirve nunca, salvo .well-known.
$aSegments = [];
foreach (explode('/', $sUri) as $sSegment) {
    if ($sSegment === '..') {
        array_pop($aSegments);
    } elseif ($sSegment !== '' && $sSegment !== '.') {
        $aSegments[] = $sSegment;
    }
}
$sTrailing = '';
if ($aSegments !== [] && str_ends_with($sUri, '/')) {
    $sTrailing = '/';
}
$sUri = '/' . implode('/', $aSegments) . $sTrailing;
if (preg_match('#/[.](?!well-known(/|$))#', $sUri)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    return true;
}

// Las rutas de vercel.json con «has» solo valen en ese dominio (la copia de prueba): aquí, con la cabecera Host.
function routeHostMatches(array $aRoute, string $sHost): bool
{
    foreach ($aRoute['has'] ?? [] as $aCondition) {
        if (($aCondition['type'] ?? '') === 'host' && $aCondition['value'] !== $sHost) {
            return false;
        }
    }
    return true;
}

$sHost = $_SERVER['HTTP_HOST'] ?? '';
$aVercel = json_decode((string)file_get_contents(__DIR__ . '/vercel.json'), true);
foreach ($aVercel['routes'] ?? [] as $aRoute) {
    // Las que llevan a un fichero (/sitemap.xml, el robots.txt de la copia de prueba, la ruta vieja de Leaflet)
    // cambian la ruta que se sirve.
    $bToFile = isset($aRoute['dest']) && !str_ends_with($aRoute['dest'], '.php');
    if ($bToFile && routeHostMatches($aRoute, $sHost) && preg_match('#^' . $aRoute['src'] . '$#', $sUri)) {
        $sUri = preg_replace('#^' . $aRoute['src'] . '$#', $aRoute['dest'], $sUri);
    }
    if (empty($aRoute['headers']) || !preg_match('#' . $aRoute['src'] . '#', $sUri) || !routeHostMatches($aRoute, $sHost)) {
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

// El planificador está en el inicio; la página suelta de sus pantallas lleva a él, como en vercel.json (los enlaces
// compartidos van detrás de «#», que el navegador conserva).
if ($sUri === '/planificador/pantallas/' || $sUri === '/planificador/pantallas/index.html') {
    header('Location: /', true, 308);
    return true;
}

if ($sUri === '/api/shell.php' || preg_match('#^/(data|scripts|tests|engine)(/|$)#', $sUri) || preg_match('#^/api/[A-Z][^/]*/#', $sUri) || preg_match('#^/(dev-router[.]php|bizkaibus-renewed[.]code-workspace)$#', $sUri)) {
    http_response_code(404);
    readfile(__DIR__ . '/404.html');
    return true;
}

$sFile = realpath(__DIR__ . $sUri);
$bServable = $sFile !== false && str_starts_with($sFile, __DIR__ . DIRECTORY_SEPARATOR);
// Sin el planificador construido (scripts/build-planner.php, al desplegar), sus pantallas, su JS y su WebAssembly salen
// del motor tal cual, como los copia el despliegue: el inicio importa sus módulos de /planificador/pantallas.
if (($sFile === false || !is_file($sFile)) && preg_match('#^/planificador/(pantallas|js|dist)/([^?]+)$#', $sUri, $aPlanner)) {
    $sPlannerRoot = realpath(__DIR__ . '/engine/planner/' . $aPlanner[1]);
    $sFile = realpath(__DIR__ . '/engine/planner/' . $aPlanner[1] . '/' . $aPlanner[2]);
    $bServable = $sPlannerRoot !== false && $sFile !== false && str_starts_with($sFile, $sPlannerRoot . DIRECTORY_SEPARATOR);
}
if ($sUri !== '/' && $bServable && is_file($sFile)) {
    $aTypes = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json', 'webmanifest' => 'application/manifest+json', 'xml' => 'application/xml', 'txt' => 'text/plain; charset=utf-8',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'webp' => 'image/webp',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'pdf' => 'application/pdf', 'wasm' => 'application/wasm',
    ];
    $sExtension = strtolower(pathinfo($sFile, PATHINFO_EXTENSION));
    if ($sExtension === 'php') {
        http_response_code(404);
        readfile(__DIR__ . '/404.html');
        return true;
    }
    header('Content-Type: ' . ($aTypes[$sExtension] ?? 'application/octet-stream'));
    header('Accept-Ranges: bytes');
    $iSize = (int)filesize($sFile);
    // Un tramo (Range), como Vercel: el mapa base PMTiles se lee a trozos. «bytes=-N» son los N últimos.
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $aRange) && $aRange[1] . $aRange[2] !== '') {
        $iFirst = (int)$aRange[1];
        $iLast = $iSize - 1;
        if ($aRange[1] === '') {
            $iFirst = max(0, $iSize - (int)$aRange[2]);
        } elseif ($aRange[2] !== '') {
            $iLast = min($iLast, (int)$aRange[2]);
        }
        if ($iFirst > $iLast) {
            http_response_code(416);
            header('Content-Range: bytes */' . $iSize);
            return true;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $iFirst . '-' . $iLast . '/' . $iSize);
        header('Content-Length: ' . ($iLast - $iFirst + 1));
        echo (string)file_get_contents($sFile, false, null, $iFirst, $iLast - $iFirst + 1);
        return true;
    }
    header('Content-Length: ' . $iSize);
    readfile($sFile);
    return true;
}

if (str_starts_with($sUri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// Como en vercel.json, el resto lo decide shell.php: la página, su 301 a la dirección buena o el 404.
if (isset($_COOKIE['lento'])) {
    usleep((int) $_COOKIE['lento'] * 1000);
}
require __DIR__ . '/api/shell.php';
return true;
