<?php

// Crea una red nueva: su configuración (api/Networks/<red>.php), unos iconos provisionales (icons-<red>/) y los
// archivos que salen de la configuración (js/networks.js y style-networks.css). Después hay que revisar los textos,
// poner los iconos de verdad y construir su base de datos. Paso a paso: docs/nueva-red.md.
//
// Uso:
//   php scripts/new-network.php --slug=getxo --name="Getxo Bus+" --color=#E30613 --gtfs=https://…/gtfs.zip
//                               [--kind=bus|metro|tram|train] [--city=Getxo] [--operator="Getxo Udala"]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const ROOT = __DIR__ . '/..';
const KINDS = ['bus', 'metro', 'tram', 'train'];
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
    $aOptions = getopt('', ['slug:', 'name:', 'color:', 'gtfs:', 'kind:', 'city:', 'operator:']);
    foreach (['slug', 'name', 'color', 'gtfs'] as $sRequired) {
        if (empty($aOptions[$sRequired])) {
            fail("Falta --$sRequired. Uso: php scripts/new-network.php --slug=getxo --name=\"Getxo Bus+\" --color=#E30613 --gtfs=https://…/gtfs.zip");
        }
    }
    $aOptions += ['kind' => 'bus', 'city' => '', 'operator' => ''];
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

    return <<<PHP
<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features); la explicación de cada campo está en docs/nueva-red.md.
return [
    'slug' => '$sSlug',
    'order' => $iOrder,
    'db' => '{$sSlug}',
    'kind' => '{$aOptions['kind']}',

    'brand' => [
        'icons' => 'icons-$sSlug',
        'theme_color' => '{$aPalette['primary']}',
        'title' => [$sTitle, ''],
        'logomark' => '<img src="/icons-$sSlug/icon-192.png" alt="">',
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
            'disclaimer' => {$Quote('Proyecto independiente y no oficial, sin relación con ' . $sOperator . '. Horario programado oficial: no refleja retrasos ni cancelaciones.')},
        ],
        'eu' => [
            'name' => {$Quote($sName)},
            'tile' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'switcher' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'subtitle' => {$Quote(ucfirst($sEu) . 'ren ordutegiak')},
            'liveEmpty' => {$Quote('Bilatu geraleku bat hurrengo ' . $sEu . ' ikusteko.')},
        ],
        'en' => [
            'name' => {$Quote($sName)},
            'tile' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'switcher' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'subtitle' => {$Quote(ucfirst($sWhereEn . $sEn) . ' timetables')},
            'liveEmpty' => {$Quote('Search for a stop to see the next ' . $sEn . '.')},
            'disclaimer' => {$Quote('An independent, unofficial project, not affiliated with ' . $sOperator . '. Official scheduled timetable: it does not reflect delays or cancellations.')},
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
        'trip_detail' => 'stops',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    // Sin datos en directo: el horario oficial. Para el directo, ver docs/nueva-red.md.
    'live' => [],

    'gtfs' => [
        'source' => {$Quote($aOptions['gtfs'])},
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => false,
        'required' => false,
    ],
];

PHP;
}

$aOptions = options();
$sConfigPath = ROOT . '/api/Networks/' . $aOptions['slug'] . '.php';
file_put_contents($sConfigPath, config($aOptions));

// Iconos provisionales (los de Bide+), para que la app funcione desde el primer momento.
$sIcons = ROOT . '/icons-' . $aOptions['slug'];
if (!is_dir($sIcons)) {
    mkdir($sIcons);
}
foreach (['icon-192.png', 'icon-512.png', 'apple-touch-icon.png'] as $sIcon) {
    $sSource = ROOT . '/icons-bide-rojo/' . $sIcon;
    if (!is_file($sSource)) {
        $sSource = ROOT . '/icons-bide-rojo/icon-192.png';
    }
    copy($sSource, $sIcons . '/' . $sIcon);
}

foreach (['build-networks-js.php', 'build-networks-css.php'] as $sScript) {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $sScript), $iStatus);
    if ($iStatus !== 0) {
        fail("Falló scripts/$sScript");
    }
}

$sSlug = $aOptions['slug'];
echo <<<TXT

Red «{$aOptions['name']}» creada:
  api/Networks/$sSlug.php   configuración (revisa los textos)
  icons-$sSlug/             iconos provisionales (cámbialos por los suyos: 192 y 512 px, y apple-touch-icon de 180)

Siguientes pasos:
  1. php scripts/build-database.php --network=$sSlug     (descarga el GTFS y crea data/$sSlug.sqlite)
  2. php scripts/build-sitemap.php
  3. php -S 127.0.0.1:8011 dev-router.php  y abre http://127.0.0.1:8011/?red=$sSlug
  4. python3 tests/static-checks.py

TXT;
