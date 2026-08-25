<?php
// Punto de entrada solo para local con `php -S localhost:8000 dev-router.php`.
// Imita los rewrites de vercel.json (/api/* -> api/index.php, / -> api/shell.php)
// que aplica Vercel en producción. shell.php vive dentro de api/ porque
// Vercel solo reconoce como Serverless Function un fichero PHP que esté
// físicamente en ese directorio, no basta con declararlo en "functions".

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
