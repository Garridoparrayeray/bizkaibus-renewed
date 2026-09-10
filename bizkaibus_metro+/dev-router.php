<?php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri === '/api/shell.php') {
    http_response_code(404);
    return true;
}

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/api/shell.php';
