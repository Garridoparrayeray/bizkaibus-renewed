<?php
// Páginas HTML: el menú de Bide+ (/, con el planificador), el mapa de la red (/mapa), el estado de las redes (/estado),
// la pantalla de una parada (/pantalla/{id}) y la app de cada red (/?red=, /stops/{id}, /lines/{id}).

require __DIR__ . '/autoload.php';

// Sin parse_url: confunde «/lines/tolosaldea:4/…» con un host y un puerto.
$sPath = strtok($_SERVER['REQUEST_URI'] ?? '/', '?#');
// bideplusmiamor.vercel.app: el tema «mi amor» en todas las páginas (lo pone js/theme.js); aquí solo cambia la vista
// previa.
$bMiamorDomain = ($_SERVER['HTTP_HOST'] ?? '') === 'bideplusmiamor.vercel.app';

// Estas páginas solo cambian con la dirección (y ?red) y al desplegar, que vacía la caché de Vercel: el CDN las sirve
// sin ejecutar PHP. Solo las que salen bien, y no las del dominio «mi amor», que tienen otra vista previa. Las que
// llevan el horario de hoy (app-page.php baja $iCacheSeconds) caducan a la medianoche de Madrid, sin copias viejas.
$iCacheSeconds = Core\CacheSeconds::STATIC;
header_register_callback(function () use ($bMiamorDomain, &$iCacheSeconds) {
    if (http_response_code() !== 200 || $bMiamorDomain) {
        return;
    }
    $sStale = ', stale-while-revalidate=' . ($iCacheSeconds * 2);
    if ($iCacheSeconds < Core\CacheSeconds::STATIC) {
        $sStale = '';
    }
    header('Cache-Control: public, max-age=0, s-maxage=' . $iCacheSeconds . $sStale);
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

// El planificador está en el inicio: las direcciones viejas (/planificador?a=…) llevan a él con su consulta.
if ($sPath === '/planificador' || $sPath === '/planificador/') {
    $sLocation = '/';
    $sQuery = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    if ($sQuery !== '') {
        $sLocation .= '?' . $sQuery;
    }
    header('Location: ' . $sLocation, true, 308);
    exit;
}
// Una sola dirección por página (Core\PagePath): las demás formas, con 301 a ella; lo que no es una página, 404. Una
// ?red= que no existe lleva a la portada.
$sCanonicalPath = \Core\PagePath::canonical($sPath);
if ($sCanonicalPath === null) {
    \Core\NotFound::send($sLang);
    exit;
}
// ?red= en mayúsculas (?red=Metro) lleva a la de verdad; una que no existe (o que no es un texto), a la portada.
if (isset($_GET['red']) && (!is_string($_GET['red']) || !\Core\Networks::exists($_GET['red']))) {
    $sRed = '';
    if (is_string($_GET['red'])) {
        $sRed = strtolower($_GET['red']);
    }
    $sTarget = \Core\Lang::url('/', $sLang);
    if (\Core\Networks::exists($sRed)) {
        $sTarget = $sPath . '?' . http_build_query(['red' => $sRed] + $_GET);
    }
    header('Location: ' . $sTarget, true, 301);
    exit;
}
if ($sCanonicalPath !== $sPath) {
    $sQuery = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($sQuery !== '') {
        $sCanonicalPath .= '?' . $sQuery;
    }
    header('Location: ' . $sCanonicalPath, true, 301);
    exit;
}
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
