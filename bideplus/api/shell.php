<?php
// Páginas HTML: el menú de Bide+ (/), el mapa de la red (/mapa), el estado de las redes (/estado), la pantalla de una
// parada (/pantalla/{id}) y la app de cada red (/?red=, /stops/{id}, /lines/{id}).

require __DIR__ . '/autoload.php';

// Sin parse_url: confunde «/lines/tolosaldea:4/…» con un host y un puerto.
$sPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?#');
// bideplusmiamor.vercel.app: el tema «mi amor» en todas las páginas (lo pone js/theme.js); aquí solo cambia la vista
// previa.
$bMiamorDomain = ($_SERVER['HTTP_HOST'] ?? '') === 'bideplusmiamor.vercel.app';

// Estas páginas solo cambian con la dirección (y ?red) y al desplegar, que vacía la caché de Vercel: el CDN las sirve
// sin ejecutar PHP. Solo las que salen bien, y no las del dominio «mi amor», que tienen otra vista previa.
header_register_callback(function () use ($bMiamorDomain) {
    if (http_response_code() !== 200 || $bMiamorDomain) {
        return;
    }
    header('Cache-Control: public, max-age=0, s-maxage=' . Core\CacheSeconds::STATIC . ', stale-while-revalidate=' . (Core\CacheSeconds::STATIC * 2));
    header('Vary: Host');
});

$sShellHash = '';
if (is_file(__DIR__ . '/Config/shell-hash.php')) {
    $sShellHash = (string)require __DIR__ . '/Config/shell-hash.php';
}
if ($sShellHash !== '') {
    header('X-Bide-Shell: ' . $sShellHash);
}

// Con ?lang=eu o ?lang=en, la página sale ya traducida: así los buscadores ven la versión en euskera y en inglés.
$sLang = \Core\Lang::current();
if ($sLang !== 'es') {
    ob_start(function (string $sHtml) use ($sLang): string {
        // La página de error (404.html) es estática y en castellano: se deja tal cual.
        if (http_response_code() !== 200) {
            return $sHtml;
        }
        return \Core\Lang::translateHtml($sHtml, $sLang);
    });
}

if ($sPath === '/mapa') {
    require __DIR__ . '/Views/network-map.php';
    exit;
}
if ($sPath === '/estado') {
    require __DIR__ . '/Views/estado.php';
    exit;
}
if (str_starts_with($sPath, '/pantalla/')) {
    require __DIR__ . '/Views/screen.php';
    exit;
}
if (($sPath === '/' || $sPath === '') && !isset($_GET['red'])) {
    require __DIR__ . '/Views/menu.php';
    exit;
}

require __DIR__ . '/Views/app-page.php';
require __DIR__ . '/Views/app.php';
