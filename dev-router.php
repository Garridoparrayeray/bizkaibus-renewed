<?php
// Punto de entrada solo para local con `php -S localhost:8000 dev-router.php`.
// Imita los rewrites de vercel.json (/api/* -> api/index.php, / -> index.php)
// que aplica Vercel en producción.

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if ($uri !== '/' && is_file(__DIR__ . $uri)) {
    return false;
}

if (str_starts_with($uri, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}

require __DIR__ . '/index.php';
