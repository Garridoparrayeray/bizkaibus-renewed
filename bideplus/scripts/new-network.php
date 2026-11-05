<?php

// Crea una red nueva entera con una orden: su configuración (api/Networks/<red>.php, con «Nuevo» unos días), un icono
// provisional con su inicial y su «+» sobre su color (icons-<red>/), los archivos que salen de la configuración
// (js/networks.js, css/style-networks.css y los enlaces de 404.html), su base de datos, el mapa de la red, los
// horarios sin conexión y el sitemap. Después solo queda revisar los textos y, cuando los haya, poner sus iconos de
// verdad.
//
// Uso:
//   php scripts/new-network.php --slug=getxo --name="Getxo Bus+" --color=#E30613 --gtfs=https://…/gtfs.zip
//                               [--kind=bus|metro|tram|train] [--city=Getxo] [--operator="Getxo Udala"]
//                               [--trip-updates=https://…pb] [--vehicle-positions=https://…pb] [--alerts=https://…pb]
//                               [--matching=trip|stops] [--sin-datos]
// Con los feeds GTFS-Realtime del operador, la red sale ya en directo: retrasos, vehículos en el mapa e incidencias.
// --sin-datos solo crea la configuración y los iconos (sin descargar el GTFS ni construir nada).

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const ROOT = __DIR__ . '/..';
const KINDS = ['bus', 'metro', 'tram', 'train'];
const MATCHINGS = ['trip', 'stops'];
// Opción del script => clave de gtfs_rt.
const GTFS_RT_FEEDS = ['trip-updates' => 'trip_updates_url', 'vehicle-positions' => 'vehicle_positions_url', 'alerts' => 'alerts_url'];
const VEHICLE = [
    'bus' => ['autobús', 'autobusa', 'bus'],
    'metro' => ['metro', 'metroa', 'metro'],
    'tram' => ['tranvía', 'tranbia', 'tram'],
    'train' => ['tren', 'trena', 'train'],
];

function fail(string $sMessage): never
{
    fwrite(STDERR, $sMessage . "\n");
    exit(1);
}

function options(): array
{
    $aOptions = getopt('', ['slug:', 'name:', 'color:', 'gtfs:', 'kind:', 'city:', 'operator:', 'trip-updates:', 'vehicle-positions:', 'alerts:', 'matching:', 'sin-datos']);
    foreach (['slug', 'name', 'color', 'gtfs'] as $sRequired) {
        if (empty($aOptions[$sRequired])) {
            fail("Falta --$sRequired. Uso: php scripts/new-network.php --slug=getxo --name=\"Getxo Bus+\" --color=#E30613 --gtfs=https://…/gtfs.zip");
        }
    }
    $aOptions += ['kind' => 'bus', 'city' => '', 'operator' => '', 'matching' => 'trip'];
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $aOptions['slug'])) {
        fail('--slug: minúsculas, números y guiones (por ejemplo, getxo o tranvia-vitoria).');
    }
    if (Networks::exists($aOptions['slug'])) {
        fail("Ya hay una red «{$aOptions['slug']}» en api/Networks.");
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $aOptions['color'])) {
        fail('--color: un color en hexadecimal de 6 cifras, como #E30613.');
    }
    if (!in_array($aOptions['kind'], KINDS, true)) {
        fail('--kind: ' . implode(', ', KINDS) . '.');
    }
    if (!preg_match('#^https?://#', $aOptions['gtfs'])) {
        fail('--gtfs: la dirección del ZIP del GTFS (https://…).');
    }
    foreach (array_keys(GTFS_RT_FEEDS) as $sFeed) {
        if (isset($aOptions[$sFeed]) && !preg_match('#^https?://#', $aOptions[$sFeed])) {
            fail("--$sFeed: la dirección del feed GTFS-Realtime (https://…).");
        }
    }
    if (!in_array($aOptions['matching'], MATCHINGS, true)) {
        fail('--matching: trip (el feed usa los viajes del horario) o stops (se empareja por paradas y hora).');
    }
    return $aOptions;
}

// Mezcla dos colores (#rrggbb): $dWeight es la parte del segundo.
function mix(string $sA, string $sB, float $dWeight): string
{
    $aA = sscanf($sA, '#%02x%02x%02x');
    $aB = sscanf($sB, '#%02x%02x%02x');
    $aOut = [];
    foreach ([0, 1, 2] as $i) {
        $aOut[] = (int)round($aA[$i] * (1 - $dWeight) + $aB[$i] * $dWeight);
    }
    return vsprintf('#%02x%02x%02x', $aOut);
}

// Paleta de la app a partir de un color, con las mismas proporciones que las redes que ya hay. Se puede retocar a mano.
function palette(string $sColor): array
{
    [$iR, $iG, $iB] = sscanf($sColor, '#%02x%02x%02x');
    return [
        'bg' => mix('#f5f5f5', $sColor, 0.03),
        'border' => mix('#e0e0e0', $sColor, 0.06),
        'primary' => strtoupper($sColor),
        'primary-light' => mix($sColor, '#ffffff', 0.55),
        'primary-dark' => mix($sColor, '#000000', 0.38),
        'primary-tint' => mix($sColor, '#ffffff', 0.92),
        'text' => mix('#1e1e1e', $sColor, 0.06),
        'text-secondary' => mix('#606060', $sColor, 0.08),
        'backdrop' => sprintf('rgba(%d, %d, %d, .5)', (int)round($iR * 0.1), (int)round($iG * 0.1), (int)round($iB * 0.1)),
    ];
}

// Las fuentes en directo y el bloque gtfs_rt, según los feeds que se pasen.
function liveConfig(array $aOptions): string
{
    $aLive = [];
    $sUrls = '';
    foreach (GTFS_RT_FEEDS as $sOption => $sKey) {
        if (empty($aOptions[$sOption])) {
            continue;
        }
        $sUrls .= "        '$sKey' => " . phpString($aOptions[$sOption]) . ",\n";
        if ($sOption !== 'alerts') {
            $aLive[] = "'" . $sOption . "'";
        }
    }
    if ($sUrls === '') {
        return "    // Sin datos en directo: el horario oficial.\n    'live' => [],\n";
    }
    $sMatching = "        'matching' => '{$aOptions['matching']}',\n";
    if ($aOptions['matching'] === 'stops') {
        $sMatching .= "        'match_tolerance_seconds' => 600,\n";
    }
    return "    'live' => [" . implode(', ', $aLive) . "],\n\n"
        . "    'gtfs_rt' => [\n" . $sUrls . $sMatching
        . "        'cache_ttl_seconds' => 30,\n        'http_timeout_seconds' => 8,\n        'max_age_seconds' => 300,\n    ],\n";
}

function phpString(string $sValue): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $sValue) . "'";
}

function config(array $aOptions): string
{
    $sSlug = $aOptions['slug'];
    $sName = $aOptions['name'];
    $sCity = $aOptions['city'];
    $sOperator = $aOptions['operator'];
    if ($sOperator === '') {
        $sOperator = 'el operador';
    }
    [$sEs, $sEu, $sEn] = VEHICLE[$aOptions['kind']];
    $bLive = !empty($aOptions['trip-updates']);
    $sDisclaimerEs = 'Horario programado oficial: no refleja retrasos ni cancelaciones.';
    $sDisclaimerEu = 'Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.';
    $sDisclaimerEn = 'Official scheduled timetable: it does not reflect delays or cancellations.';
    $sTripDetail = 'stops';
    if ($bLive) {
        $sDisclaimerEs = 'Los tiempos en directo son una previsión del operador.';
        $sDisclaimerEu = 'Zuzeneko denborak operadorearen aurreikuspena dira.';
        $sDisclaimerEn = 'Live times are the operator\'s forecast.';
        $sTripDetail = 'vehicle';
    }
    $sLiveConfig = liveConfig($aOptions);
    $sWhere = '';
    $sWhereEn = '';
    if ($sCity !== '') {
        $sWhere = ' de ' . $sCity;
        $sWhereEn = $sCity . ' ';
    }
    $iOrder = 1 + max(array_column(Networks::all(), 'order'));
    // Título de la cabecera: el nombre en mayúsculas, sin el «+».
    $sTitle = phpString(mb_strtoupper(preg_replace('/\+$/', '', $sName)));
    $Quote = 'phpString';
    $aPalette = palette($aOptions['color']);
    $sPalette = '';
    foreach ($aPalette as $sKey => $sValue) {
        $sPalette .= "            '$sKey' => '$sValue',\n";
    }

    // En la cabecera, la marca en blanco (glyph.png) si se ha podido dibujar; si no, el icono entero.
    $sAlt = htmlspecialchars($sName, ENT_QUOTES);
    $sLogomark = '<img class="is-icon" src="/icons-' . $sSlug . '/icon-192.png" alt="' . $sAlt . '" aria-hidden="true">';
    if (is_file(ROOT . '/icons-' . $sSlug . '/glyph.png')) {
        $sLogomark = '<img src="/icons-' . $sSlug . '/glyph.png" alt="' . $sAlt . '" aria-hidden="true">';
    }
    $sAdded = (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
    return <<<PHP
<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features).
return [
    'slug' => '$sSlug',
    'order' => $iOrder,
    'db' => '{$sSlug}',
    'kind' => '{$aOptions['kind']}',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '{$sAdded}',

    'brand' => [
        'icons' => 'icons-$sSlug',
        'theme_color' => '{$aPalette['primary']}',
        'title' => [$sTitle, ''],
        'logomark' => '$sLogomark',
        'card_logomark' => '<img src="/icons-$sSlug/icon-192.png" alt="$sAlt" aria-hidden="true">',
        'tile_color' => '{$aPalette['primary']}',
        'swatch_color' => '{$aPalette['primary']}',
        'palette' => [
$sPalette        ],
    ],

    'texts' => [
        'es' => [
            'name' => {$Quote($sName)},
            'tile' => {$Quote('Horarios del ' . $sEs . $sWhere)},
            'switcher' => {$Quote('Horarios del ' . $sEs . $sWhere)},
            'subtitle' => {$Quote('Horarios del ' . $sEs . $sWhere)},
            'liveEmpty' => {$Quote('Busca una parada para ver el próximo ' . $sEs . '.')},
            'disclaimer' => {$Quote('Proyecto independiente y no oficial, sin relación con ' . $sOperator . '. ' . $sDisclaimerEs)},
        ],
        'eu' => [
            'name' => {$Quote($sName)},
            'tile' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'switcher' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'subtitle' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'liveEmpty' => {$Quote('Bilatu geraleku bat hurrengo ' . $sEu . ' ikusteko.')},
            'disclaimer' => {$Quote('Proiektu independentea eta ez-ofiziala, ' . $sOperator . ' erakundearekin loturarik gabea. ' . $sDisclaimerEu)},
            'attribution' => {$Quote('Datuak: ' . $sOperator . ' (GTFS)')},
        ],
        'en' => [
            'name' => {$Quote($sName)},
            'tile' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'switcher' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'subtitle' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'liveEmpty' => {$Quote('Search for a stop to see the next ' . $sEn . '.')},
            'disclaimer' => {$Quote('An independent, unofficial project, not affiliated with ' . $sOperator . '. ' . $sDisclaimerEn)},
            'attribution' => {$Quote('Data: ' . $sOperator . ' (GTFS)')},
        ],
    ],

    'seo' => [
        'title' => {$Quote($sName . ' · Horarios del ' . $sEs . $sWhere)},
        'description' => {$Quote('Consulta los horarios del ' . $sEs . $sWhere . ' por parada y línea.')},
    ],
    'attribution' => {$Quote('Datos: ' . $sOperator . ' (GTFS)')},

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => '$sTripDetail',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

$sLiveConfig
    'gtfs' => [
        'source' => {$Quote($aOptions['gtfs'])},
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => false,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];

PHP;
}

// Lanza scripts de scripts/ con sus argumentos, uno detrás de otro; para si alguno falla.
function run(array $aScripts): void
{
    foreach ($aScripts as $sScript) {
        [$sFile, $sArgs] = array_pad(explode(' ', $sScript, 2), 2, '');
        $sCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $sFile);
        if ($sArgs !== '') {
            $sCommand .= ' ' . escapeshellarg($sArgs);
        }
        passthru($sCommand, $iStatus);
        if ($iStatus !== 0) {
            fail("Falló scripts/$sScript");
        }
    }
}

// Icono provisional: la inicial de la red y su «+» en blanco sobre su color (icon.svg) y en negro (glyph.svg), pasados
// a PNG con scripts/render-icons.mjs. Sin Node o sin navegador, los iconos de Bide+.
function makeIcons(string $sSlug, string $sName, string $sColor): void
{
    $sDir = ROOT . '/icons-' . $sSlug;
    if (!is_dir($sDir)) {
        mkdir($sDir);
    }
    $sLetter = htmlspecialchars(mb_strtoupper(mb_substr(trim($sName), 0, 1)));
    $sMark = '<text x="226" y="372" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="300" font-weight="700">'
        . $sLetter . '</text><rect x="354" y="113" width="104" height="30" rx="5"/><rect x="391" y="76" width="30" height="104" rx="5"/>';
    $sSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">';
    file_put_contents($sDir . '/icon.svg', $sSvg . '<rect width="512" height="512" rx="112" fill="' . $sColor . '"/><g fill="#FFFFFF">' . $sMark . '</g></svg>' . "\n");
    file_put_contents($sDir . '/glyph.svg', $sSvg . '<g fill="#000000">' . $sMark . '</g></svg>' . "\n");
    passthru('node ' . escapeshellarg(__DIR__ . '/render-icons.mjs') . ' ' . escapeshellarg($sSlug), $iStatus);
    if ($iStatus === 0) {
        // La imagen para compartir lleva todas las apps: con la nueva, se vuelve a dibujar.
        passthru('node ' . escapeshellarg(__DIR__ . '/render-images.mjs') . ' og');
        return;
    }
    fwrite(STDERR, "Sin navegador para dibujar el icono: van los de Bide+ (node scripts/render-icons.mjs $sSlug más tarde).\n");
    foreach (['icon-192.png', 'icon-512.png', 'apple-touch-icon.png'] as $sIcon) {
        copy(ROOT . '/icons-bide-rojo/' . $sIcon, $sDir . '/' . $sIcon);
    }
}

$aOptions = options();
makeIcons($aOptions['slug'], $aOptions['name'], $aOptions['color']);
file_put_contents(ROOT . '/api/Networks/' . $aOptions['slug'] . '.php', config($aOptions));
run(['build-networks-js.php', 'build-networks-css.php', 'build-404.php']);
if (!isset($aOptions['sin-datos'])) {
    run(['build-database.php --network=' . $aOptions['slug'], 'build-network-map.php', 'build-offline-data.php', 'build-sitemap.php']);
}

$sSlug = $aOptions['slug'];
echo <<<TXT

Red «{$aOptions['name']}» creada y lista: /?red=$sSlug
  api/Networks/$sSlug.php   su configuración: revisa los textos en los tres idiomas
  icons-$sSlug/             icono provisional (para el de verdad: dibuja icon.svg y glyph.svg y
                            node scripts/render-icons.mjs $sSlug)
  data/$sSlug.sqlite        su horario, con el mapa, los horarios sin conexión y el sitemap ya al día

Para probarla: php -S 127.0.0.1:8011 dev-router.php y abre http://127.0.0.1:8011/?red=$sSlug
Después: python3 tests/static-checks.py, y subirla (rama, pull request y fusionar: se publica sola).

TXT;
