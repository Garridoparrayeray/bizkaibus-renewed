<?php
// Local-only entry point for `php -S localhost:8000 dev-router.php`.
// Mimics the vercel.json rewrite (/api/* -> api/index.php) that Vercel applies in production.

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/index.html');
