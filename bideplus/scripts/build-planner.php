<?php

// El planificador que se despliega en /planificador/: pantallas, WebAssembly y datos del motor, con las bases del
// día. Los paquetes y los recorridos del mapa salen siempre; las tablas de andar, los lugares y las calles del mapa se
// reutilizan de ayer (--previous) si valen, y si no, --walk los rehace con --osm y --near. Opciones en «Uso».

declare(strict_types=1);

const DEFAULT_ENGINE = __DIR__ . '/../engine';
// Lo que se publica del motor, en las mismas rutas relativas (la página suelta del motor carga sus fuentes con
// ../../docs/…; el inicio de la app importa sus módulos de /planificador/pantallas).
const ENGINE_FILES = ['planner/pantallas', 'planner/js', 'planner/dist', 'docs/manual/hoja-de-ruta/fonts'];
// Sin estos ficheros no hay planificador: si el motor los mueve, se para aquí y no se despliega uno roto.
const REQUIRED_FILES = ['planificador/pantallas/engine.js', 'planificador/pantallas/place-field.js', 'planificador/pantallas/link.js',
    'planificador/pantallas/share.js', 'planificador/pantallas/worker.js', 'planificador/js/bideplan.js', 'planificador/dist/bideplan.wasm'];
const DATA_DIR = 'planificador/build/data';
const ORIGIN_FILE = 'planificador/origen.json';
const WALK_FILES = ['walk.bdwk', 'walk-accesible.bdwk', 'places', 'walk'];
const MANIFEST_KEYS = ['walk', 'walk_accessible', 'places', 'walk_graph'];
// Si cambia una de estas herramientas o el núcleo que lee la tabla, la de andar y los lugares de ayer ya no valen.
const WALK_TOOLS = ['tools/build-walk.php', 'tools/build-places.php', 'tools/osm.php', 'tools/pack-format.php',
    'tools/places-addresses.php', 'tools/places-areas.php', 'tools/places-hubs.php', 'tools/places-zones.php',
    'tools/build-walk-graph.php', 'dist/bideplan.wasm'];
const WALK_MAX_AGE_SECONDS = 7 * 86400;
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

const USAGE = <<<'TXT'
Uso: php scripts/build-planner.php --out=carpeta --databases=carpeta [--except=slug,slug] [--previous=carpeta] [--engine=motor]
     php scripts/build-planner.php --out=carpeta --walk --osm=a.osm.pbf,b.osm.pbf [--near=c.osm.pbf,d.osm.pbf]
         [--engine=motor]
--osm: los mapas de Euskal Herria (enteros); --near: los de alrededor, que solo dan lugares a 1500 m de una parada.
TXT;

function fail(string $sMessage): never
{
    fwrite(STDERR, $sMessage . "\n");
    exit(1);
}

function run(array $aCommand): void
{
    passthru(implode(' ', array_map('escapeshellarg', $aCommand)), $iStatus);
    if ($iStatus !== 0) {
        fail('Ha fallado: ' . implode(' ', $aCommand));
    }
}

function readJson(string $sPath): array
{
    $aValue = json_decode((string)@file_get_contents($sPath), true);
    if (!is_array($aValue)) {
        return [];
    }
    return $aValue;
}

function writeJson(string $sPath, array $aValue): void
{
    file_put_contents($sPath, json_encode($aValue, JSON_FLAGS) . "\n");
}

function copyTree(string $sFrom, string $sTo): void
{
    if (!file_exists($sFrom)) {
        fail('Falta ' . $sFrom);
    }
    if (is_file($sFrom)) {
        copy($sFrom, $sTo);
        return;
    }
    @mkdir($sTo, 0775, true);
    foreach (scandir($sFrom) as $sName) {
        if ($sName !== '.' && $sName !== '..') {
            copyTree($sFrom . '/' . $sName, $sTo . '/' . $sName);
        }
    }
}

function toolsHash(string $sEngine): string
{
    $sDigest = '';
    foreach (WALK_TOOLS as $sTool) {
        $sDigest .= $sTool . ' ' . sha1_file($sEngine . '/planner/' . $sTool) . "\n";
    }
    return sha1($sDigest);
}

// Por red, cuántas paradas tiene su paquete y su huella: lo mismo que la tabla de andar guarda y el núcleo comprueba.
function stopsHash(string $sEngine, string $sData): array
{
    require_once $sEngine . '/planner/tools/pack-format.php';
    $aStops = [];
    foreach (readJson($sData . '/manifest.json')['networks'] as $aNetwork) {
        $aPack = readPack($sData . '/' . $aNetwork['file']);
        $aStops[$aPack['slug']] = $aPack['stops'] . ':' . $aPack['hash'];
    }
    ksort($aStops);
    return $aStops;
}

// Por qué no vale la tabla de andar de ayer con los paquetes de hoy, o '' si vale (aunque tenga una semana).
function staleReason(array $aPrevious, array $aOrigin, string $sPreviousData): string
{
    if ($aPrevious === []) {
        return 'no hay datos de ayer';
    }
    foreach (WALK_FILES as $sFile) {
        if (!file_exists($sPreviousData . '/' . $sFile)) {
            return 'a los datos de ayer les falta ' . $sFile;
        }
    }
    if (($aPrevious['tools'] ?? '') !== $aOrigin['tools']) {
        return 'han cambiado las herramientas del motor';
    }
    if (($aPrevious['stops'] ?? []) !== $aOrigin['stops']) {
        return 'han cambiado las paradas de alguna red';
    }
    return '';
}

function tellWorkflow(string $sName, string $sValue): void
{
    $sOutput = (string)getenv('GITHUB_OUTPUT');
    if ($sOutput !== '') {
        file_put_contents($sOutput, $sName . '=' . $sValue . "\n", FILE_APPEND);
    }
}

// Pantallas y paquetes del día; de ayer, la tabla de andar y los lugares si siguen valiendo.
function buildPacks(array $aOptions, string $sOut, string $sEngine): void
{
    if (!isset($aOptions['databases'])) {
        fail(USAGE);
    }
    foreach (ENGINE_FILES as $sPath) {
        copyTree($sEngine . '/' . $sPath, $sOut . '/' . str_replace('planner/', 'planificador/', $sPath));
    }
    foreach (REQUIRED_FILES as $sPath) {
        if (!is_file($sOut . '/' . $sPath) || filesize($sOut . '/' . $sPath) === 0) {
            fail('El motor no trae ' . $sPath);
        }
    }
    // Las redes de --except no entran en el planificador (build-packs --skip); lo demás sigue al manifest.
    $sData = $sOut . '/' . DATA_DIR;
    $sDatabases = '--databases=' . $aOptions['databases'];
    run([PHP_BINARY, $sEngine . '/planner/tools/build-packs.php', '--app=' . dirname(__DIR__), $sDatabases,
        '--skip=' . ($aOptions['except'] ?? ''), '--out=' . $sData]);
    run([PHP_BINARY, $sEngine . '/planner/tools/build-shapes.php', $sDatabases, '--data=' . $sData]);
    $aOrigin = ['generated' => time(), 'tools' => toolsHash($sEngine), 'stops' => stopsHash($sEngine, $sData)];
    $sPrevious = rtrim($aOptions['previous'] ?? '', '/');
    $aPrevious = [];
    if ($sPrevious !== '') {
        $aPrevious = readJson($sPrevious . '/' . ORIGIN_FILE);
    }
    $sReason = staleReason($aPrevious, $aOrigin, $sPrevious . '/' . DATA_DIR);
    // Con una semana se rehacen, pero se quedan los de ayer por si no se pueden bajar los mapas (andar_viejo=1).
    $bOld = $sReason === '' && time() - (int)($aPrevious['walk_built'] ?? 0) >= WALK_MAX_AGE_SECONDS;
    if ($sReason === '') {
        foreach (WALK_FILES as $sFile) {
            copyTree($sPrevious . '/' . DATA_DIR . '/' . $sFile, $sData . '/' . $sFile);
        }
        $aManifest = readJson($sData . '/manifest.json');
        $aManifest += array_intersect_key(readJson($sPrevious . '/' . DATA_DIR . '/manifest.json'), array_flip(MANIFEST_KEYS));
        writeJson($sData . '/manifest.json', $aManifest);
        $aOrigin += array_intersect_key($aPrevious, array_flip(['walk_built', 'maps']));
        echo 'Andar y lugares: los de ayer, del ' . gmdate('Y-m-d', $aOrigin['walk_built']) . "\n";
    }
    if ($bOld) {
        echo "Andar y lugares: tienen una semana, se rehacen\n";
    } elseif ($sReason !== '') {
        echo 'Andar y lugares: hay que rehacerlos (' . $sReason . ")\n";
    }
    tellWorkflow('andar', (string)(int)($bOld || $sReason !== ''));
    tellWorkflow('andar_viejo', (string)(int)$bOld);
    writeJson($sOut . '/' . ORIGIN_FILE, $aOrigin);
}

// Las tablas de andar (la normal y la accesible), el índice de lugares y las calles del mapa del viaje, con los mapas
// de --osm; los de --near solo van a los lugares, recortados a lo que queda cerca de una parada.
function buildWalk(array $aOptions, string $sOut, string $sEngine): void
{
    if (!isset($aOptions['osm'])) {
        fail(USAGE);
    }
    $sData = $sOut . '/' . DATA_DIR;
    $sTools = $sEngine . '/planner/tools/';
    $aMaps = ['--osm=' . $aOptions['osm'], '--data=' . $sData];
    $aNear = [];
    if (($aOptions['near'] ?? '') !== '') {
        $aNear = ['--near=' . $aOptions['near']];
    }
    // La accesible primero: cada una deja su fichero y su entrada del manifiesto (walk_accessible y walk).
    run(array_merge([PHP_BINARY, $sTools . 'build-walk.php', '--accessible'], $aMaps));
    run(array_merge([PHP_BINARY, $sTools . 'build-walk.php'], $aMaps));
    run(array_merge([PHP_BINARY, $sTools . 'build-places.php'], $aMaps, $aNear));
    run(array_merge([PHP_BINARY, $sTools . 'build-walk-graph.php'], $aMaps));
    $aOrigin = readJson($sOut . '/' . ORIGIN_FILE);
    $aOrigin['walk_built'] = time();
    $sMaps = $aOptions['osm'] . ',' . ($aOptions['near'] ?? '');
    $aOrigin['maps'] = array_map('basename', array_values(array_filter(explode(',', $sMaps))));
    writeJson($sOut . '/' . ORIGIN_FILE, $aOrigin);
}

$aOptions = getopt('', ['out:', 'databases::', 'except::', 'previous::', 'engine::', 'walk', 'osm::', 'near::']);
if (!isset($aOptions['out'])) {
    fail(USAGE);
}
$sOut = rtrim($aOptions['out'], '/');
$sEngine = rtrim($aOptions['engine'] ?? DEFAULT_ENGINE, '/');
if (isset($aOptions['walk'])) {
    buildWalk($aOptions, $sOut, $sEngine);
} else {
    buildPacks($aOptions, $sOut, $sEngine);
}
