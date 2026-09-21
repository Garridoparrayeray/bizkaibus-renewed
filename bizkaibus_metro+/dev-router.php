<?php

$sUri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($sUri === '/api/shell.php') {
    http_response_code(404);
    return true;
}

if ($sUri !== '/' && is_file(__DIR__ . $sUri)) {
    return false;
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
