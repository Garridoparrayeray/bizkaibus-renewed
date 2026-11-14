<?php

// Puerta del planificador antes de desplegar: el manifiesto (redes, andar, accesible, lugares y lo del mapa del viaje)
// y las consultas de planner-queries.json con la consola del motor (el mismo WebAssembly que producción), frente a las
// de --previous.
// Uso: php tests/planner-check.php --data=dir [--engine=planner] [--except=slug] [--previous=a.json] [--out=b.json]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const QUERIES_FILE = __DIR__ . '/planner-queries.json';
const DEFAULT_ENGINE = __DIR__ . '/../engine/planner';
const CONSOLE_TOOL = '/tools/planner.mjs';
// Una consulta colgada no puede parar el flujo: se corta a los 60 segundos y cuenta como error.
const QUERY_TIMEOUT_SECONDS = '60';
// Se consulta el primer martes después del día base de los paquetes: laborable y casi siempre el mismo que ayer.
const QUERY_WEEKDAY = 'tuesday';
const SECONDS_PER_MINUTE = 60;
const SECONDS_PER_HOUR = 3600;
const WORSE_MINUTES = 15;
const WORSE_SHARE = 0.25;
// Una red que pierde más de un 30 % de sus viajes, o un índice de lugares que pierde un 30 % de sus entradas, no pasa.
const DROP_SHARE = 0.3;
const WALK_KEYS = ['walk' => false, 'walk_accessible' => true];
// Lo del mapa del viaje: los recorridos de los vehículos y las calles. Cada uno, un índice con su huella.
const MAP_KEYS = ['shapes', 'walk_graph'];
const PLACE_LINE = '/^-?\d+\.\d+,-?\d+\.\d+\t/m';
const EXIT_FAILED = 1;

// La consola del motor con estos argumentos: su salida, o '' si no ha respondido.
function runConsole(string $sEngine, string $sData, array $aArguments): string
{
    $aCommand = array_merge(['timeout', QUERY_TIMEOUT_SECONDS, 'node', $sEngine . CONSOLE_TOOL, '--data', $sData], $aArguments);
    $Process = proc_open($aCommand, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $aPipes);
    $sOutput = (string)stream_get_contents($aPipes[1]);
    stream_get_contents($aPipes[2]);
    proc_close($Process);
    return $sOutput;
}

// Lanza una consulta y devuelve su respuesta, o un estado de error si no ha podido.
function plan(string $sEngine, string $sData, string $sDate, array $aQuery): array
{
    $aArguments = ['--from', $aQuery['from'], '--to', $aQuery['to'], '--date', $sDate, '--time', $aQuery['time'], '--json'];
    if (!empty($aQuery['arrive'])) {
        $aArguments[] = '--arrive';
    }
    if (!empty($aQuery['accessible'])) {
        $aArguments[] = '--accessible';
    }
    if (isset($aQuery['network'])) {
        array_push($aArguments, '--only', $aQuery['network']);
    }
    $aAnswer = json_decode(runConsole($sEngine, $sData, $aArguments), true);
    if (!is_array($aAnswer)) {
        return ['status' => 'error', 'error' => 'la consola no responde', 'options' => []];
    }
    return $aAnswer;
}

// Lo que se compara de una respuesta: opciones, las que van en transporte (o en la red pedida) y minutos de la mejor.
function summary(array $aQuery, array $aAnswer): array
{
    $aOptions = $aAnswer['options'] ?? [];
    $aRides = array_filter($aOptions, static fn(array $aOption): bool => $aOption['rides'] > 0);
    if (isset($aQuery['network'])) {
        $aRides = array_filter($aRides, static fn(array $aOption): bool => in_array($aQuery['network'], array_column($aOption['legs'], 'network'), true));
    }
    [$iHours, $iMinutes] = array_map('intval', explode(':', $aQuery['time']));
    $iAsked = $iHours * SECONDS_PER_HOUR + $iMinutes * SECONDS_PER_MINUTE;
    $iMinutesNeeded = null;
    if ($aOptions !== [] && !empty($aQuery['arrive'])) {
        $iMinutesNeeded = intdiv($iAsked - max(array_column($aOptions, 'depart')), SECONDS_PER_MINUTE);
    } elseif ($aOptions !== []) {
        $iMinutesNeeded = intdiv(min(array_column($aOptions, 'arrive')) - $iAsked, SECONDS_PER_MINUTE);
    }
    return [
        'status' => $aAnswer['status'] ?? 'error',
        'error' => $aAnswer['error'] ?? '',
        'options' => count($aOptions),
        'transit' => count($aRides),
        'minutes' => $iMinutesNeeded,
    ];
}

// Por qué no vale una consulta, o '' si vale.
function problem(array $aQuery, array $aToday, array|null $aYesterday): string
{
    if ($aToday['status'] !== 'ok') {
        return 'error del planificador: ' . strtok((string)($aToday['error'] ?? ''), "\n");
    }
    if ($aToday['options'] === 0) {
        return 'sin opciones';
    }
    if ($aToday['transit'] === 0 && isset($aQuery['network'])) {
        return 'ninguna opción va en ' . $aQuery['network'];
    }
    if ($aToday['transit'] === 0 && empty($aQuery['walk_only_ok'])) {
        return 'solo andando';
    }
    if ($aYesterday === null || $aYesterday['status'] !== 'ok' || $aYesterday['minutes'] === null) {
        return '';
    }
    $dAllowed = $aYesterday['minutes'] + max(WORSE_MINUTES, $aYesterday['minutes'] * WORSE_SHARE);
    if ($aToday['minutes'] > $dAllowed) {
        return 'empeora: ' . $aToday['minutes'] . ' min y ayer ' . $aYesterday['minutes'];
    }
    return '';
}

// Que un fichero del manifiesto esté y tenga los bytes que dice.
function fileProblem(string $sData, array $aEntry): string
{
    $sPath = $sData . '/' . $aEntry['file'];
    if (!is_file($sPath) || filesize($sPath) !== $aEntry['bytes']) {
        return 'falta ' . $aEntry['file'] . ' o no tiene los bytes del manifiesto';
    }
    return '';
}

// Lo que falla del manifiesto: redes que faltan o pierden viajes, y la tabla de andar, la accesible o los lugares.
function manifestProblems(array $aManifest, string $sData, array $aExcept, array $aPrevious): array
{
    $aProblems = [];
    $aPacks = array_column($aManifest['networks'], null, 'slug');
    foreach (array_diff(Networks::slugs(), $aExcept) as $sSlug) {
        $aPack = $aPacks[$sSlug] ?? null;
        $iBefore = $aPrevious['networks'][$sSlug] ?? 0;
        if ($aPack === null || $aPack['trips'] === 0) {
            $aProblems[] = $sSlug . ': no está en los paquetes o no tiene viajes';
        } elseif ($aPack['trips'] < $iBefore * (1 - DROP_SHARE)) {
            $aProblems[] = $sSlug . ': ' . $aPack['trips'] . ' viajes y ayer ' . $iBefore;
        } elseif (fileProblem($sData, $aPack) !== '') {
            $aProblems[] = $sSlug . ': ' . fileProblem($sData, $aPack);
        }
    }
    // Una red de --except que aparece es que el motor no la ha dejado fuera: no se despliega.
    foreach (array_intersect($aExcept, array_keys($aPacks)) as $sSlug) {
        $aProblems[] = $sSlug . ': está en los paquetes y debía quedar fuera';
    }
    foreach (WALK_KEYS as $sKey => $bStepFree) {
        if (!isset($aManifest[$sKey]) || $aManifest[$sKey]['step_free'] !== $bStepFree) {
            $aProblems[] = 'el manifiesto no trae ' . $sKey;
        } elseif (fileProblem($sData, $aManifest[$sKey]) !== '') {
            $aProblems[] = $sKey . ': ' . fileProblem($sData, $aManifest[$sKey]);
        }
    }
    foreach (MAP_KEYS as $sKey) {
        $sIndex = $sData . '/' . ($aManifest[$sKey]['index'] ?? '');
        $aIndex = json_decode((string)@file_get_contents($sIndex), true);
        if (!isset($aManifest[$sKey]) || !is_array($aIndex) || ($aIndex['build'] ?? '') !== $aManifest[$sKey]['build']) {
            $aProblems[] = $sKey . ': falta su índice o no es el del manifiesto';
        }
    }
    $iPlacesBefore = $aPrevious['places'] ?? 0;
    if (!isset($aManifest['places'])) {
        $aProblems[] = 'el manifiesto no trae places';
    } elseif (fileProblem($sData, $aManifest['places']) !== '') {
        $aProblems[] = 'places: ' . fileProblem($sData, $aManifest['places']);
    } elseif ($aManifest['places']['count'] < $iPlacesBefore * (1 - DROP_SHARE)) {
        $aProblems[] = 'places: ' . $aManifest['places']['count'] . ' lugares y ayer ' . $iPlacesBefore;
    }
    return $aProblems;
}

$aOptions = getopt('', ['data:', 'engine::', 'except::', 'previous::', 'out::']);
if (!isset($aOptions['data'])) {
    fwrite(STDERR, "Falta --data=carpeta con manifest.json\n");
    exit(EXIT_FAILED);
}
$sData = rtrim($aOptions['data'], '/');
$sEngine = rtrim($aOptions['engine'] ?? DEFAULT_ENGINE, '/');
$aManifest = json_decode((string)@file_get_contents($sData . '/manifest.json'), true);
if (!is_array($aManifest) || !isset($aManifest['base_date'])) {
    fwrite(STDERR, 'No hay paquetes en ' . $sData . "\n");
    exit(EXIT_FAILED);
}
$aPrevious = [];
if (isset($aOptions['previous']) && is_file($aOptions['previous'])) {
    $aPrevious = json_decode((string)file_get_contents($aOptions['previous']), true);
}
$aExcept = array_filter(explode(',', $aOptions['except'] ?? ''));
$aFailures = manifestProblems($aManifest, $sData, $aExcept, $aPrevious);
$sDate = (new DateTimeImmutable($aManifest['base_date']))->modify('next ' . QUERY_WEEKDAY)->format('Y-m-d');
$aChecks = json_decode((string)file_get_contents(QUERIES_FILE), true);
$aResults = [];
$aRows = ['| Consulta | Opciones | Minutos | Ayer | Resultado |', '|---|---|---|---|---|'];
$dStart = microtime(true);
foreach ($aChecks['queries'] as $aQuery) {
    $aToday = summary($aQuery, plan($sEngine, $sData, $sDate, $aQuery));
    $aYesterday = $aPrevious['queries'][$aQuery['id']] ?? null;
    $sProblem = problem($aQuery, $aToday, $aYesterday);
    $aResults[$aQuery['id']] = $aToday;
    $sResult = 'bien';
    if ($sProblem !== '') {
        $aFailures[] = $aQuery['id'] . ' (' . $sProblem . ')';
        $sResult = '**' . $sProblem . '**';
    }
    $aRows[] = sprintf('| %s | %d | %s | %s | %s |', $aQuery['id'], $aToday['options'], $aToday['minutes'] ?? '-', $aYesterday['minutes'] ?? '-', $sResult);
}
// Las búsquedas por nombre: cada una tiene que encontrar algún lugar del índice (no solo paradas).
foreach ($aChecks['places'] as $aPlace) {
    $iFound = preg_match_all(PLACE_LINE, runConsole($sEngine, $sData, ['--find', $aPlace['text']]));
    $sResult = 'bien';
    if ($iFound === 0) {
        $aFailures[] = $aPlace['id'] . ' (no encuentra «' . $aPlace['text'] . '»)';
        $sResult = '**no lo encuentra**';
    }
    $aRows[] = sprintf('| %s | %d | - | - | %s |', $aPlace['id'], $iFound, $sResult);
}
$sTable = '### Planificador: ' . count($aResults) . ' consultas del ' . $sDate . "\n\n";
if ($aFailures !== []) {
    $sTable .= 'No pasan: ' . implode('; ', $aFailures) . "\n\n";
}
$sTable .= implode("\n", $aRows) . "\n";
echo $sTable . sprintf("\n%.1f s\n", microtime(true) - $dStart);
if (getenv('GITHUB_STEP_SUMMARY')) {
    file_put_contents((string)getenv('GITHUB_STEP_SUMMARY'), $sTable, FILE_APPEND);
}
if (isset($aOptions['out'])) {
    $aSaved = [
        'date' => $sDate,
        'base_date' => $aManifest['base_date'],
        'networks' => array_column($aManifest['networks'], 'trips', 'slug'),
        'places' => $aManifest['places']['count'] ?? 0,
        'queries' => $aResults,
    ];
    file_put_contents($aOptions['out'], json_encode($aSaved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
if ($aFailures !== []) {
    fwrite(STDERR, 'No pasan ' . count($aFailures) . ': ' . implode(', ', $aFailures) . "\n");
    exit(EXIT_FAILED);
}
