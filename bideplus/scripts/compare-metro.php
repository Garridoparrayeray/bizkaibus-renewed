<?php

declare(strict_types=1);

// Compara las salidas que enseña Bide+ con las que enseña la web de Metro Bilbao, estación por estación. Solo para
// medir, a mano y en horas de servicio: la app no usa esa web, solo datos abiertos.
//
// Uso: php scripts/compare-metro.php [--base=https://www.bideplus.eus] [--raw] [ORIGEN/DESTINO ...]
//   ORIGEN y DESTINO son los códigos de tres letras que usa la web de Metro (MOY/SIN). Sin pares, un puñado del tronco.
//   --raw enseña lo que contesta Metro sin interpretar (para ajustar el script si cambia su forma).
// Por cada par: los trenes de Metro y los de Bide+ en esa estación, y para cada tren de Metro el de Bide+ que más se le
// acerca en minutos. Al final: cuántos coinciden a ±1 min, cuántos a ±2, cuántos faltan en Bide+ y cuántos sobran.

date_default_timezone_set('Europe/Madrid');

const METRO_API = 'https://api.metrobilbao.eus/metro/real-time/';
const GTFS_URL = 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip';
const DEFAULT_PAIRS = ['MOY/SIN', 'SIN/MOY', 'ABA/IND', 'IND/ABA', 'BOL/SAN', 'SAN/BOL'];
const HTTP_TIMEOUT_SECONDS = 20;
const MINUTES_KEYS = ['estimated', 'minutes', 'eta', 'arrival', 'waitTime'];
const DESTINATION_KEYS = ['direction', 'destination', 'destiny', 'headsign'];

function get(string $sUrl): string
{
    $Context = stream_context_create(['http' => ['timeout' => HTTP_TIMEOUT_SECONDS, 'ignore_errors' => true]]);
    $sBody = @file_get_contents($sUrl, false, $Context);
    if ($sBody === false) {
        fwrite(STDERR, "No responde: $sUrl\n");
        exit(1);
    }
    return $sBody;
}

// Nombre de cada estación -> su id en Bide+, del GTFS oficial (la web de Metro usa códigos de tres letras distintos
// de los del GTFS, así que se casan por el nombre que ella misma devuelve).
function stationIds(): array
{
    $sZip = sys_get_temp_dir() . '/compare_metro_gtfs.zip';
    if (!is_file($sZip) || time() - filemtime($sZip) > 86400) {
        file_put_contents($sZip, get(GTFS_URL));
    }
    $Zip = new ZipArchive();
    if ($Zip->open($sZip) !== true) {
        fwrite(STDERR, "No se puede abrir el GTFS\n");
        exit(1);
    }
    $aLines = preg_split('/\r?\n/', (string)$Zip->getFromName('stops.txt'), -1, PREG_SPLIT_NO_EMPTY);
    $aColumns = str_getcsv(ltrim((string)array_shift($aLines), "\xEF\xBB\xBF"));
    $aIds = [];
    foreach ($aLines as $sLine) {
        $aRow = array_combine($aColumns, str_getcsv($sLine));
        if ($aRow['location_type'] === '1') {
            $aIds[mb_strtolower(trim($aRow['stop_name']))] = (string)(int)$aRow['stop_id'];
        }
    }
    return $aIds;
}

function argument(array $aArgv, string $sName, string $sDefault): string
{
    $aFound = preg_grep('/^--' . preg_quote($sName, '/') . '=/', $aArgv);
    if (empty($aFound)) {
        return $sDefault;
    }
    return explode('=', reset($aFound), 2)[1];
}

// Minutos que dice un tren de Metro (un número, o una hora «HH:MM»), o null si no se entiende.
function metroMinutes(array $aTrain): int|null
{
    foreach (MINUTES_KEYS as $sKey) {
        if (!isset($aTrain[$sKey])) {
            continue;
        }
        $Value = $aTrain[$sKey];
        if (is_numeric($Value)) {
            return (int)$Value;
        }
        if (is_string($Value) && preg_match('/^(\d{1,2}):(\d{2})/', $Value, $aMatch)) {
            $iTarget = (new DateTime('today'))->setTime((int)$aMatch[1], (int)$aMatch[2])->getTimestamp();
            return (int)round(($iTarget - time()) / 60);
        }
    }
    return null;
}

// Las salidas de Bide+ del sentido que va a los destinos que da Metro: el grupo de sentido con más destinos en común.
function sameDirection(array $aOurs, array $aMetro): array
{
    $aDestinations = array_map(fn($aTrain) => mb_strtolower(metroDestination((array)$aTrain)), $aMetro);
    $aScore = [];
    foreach ($aOurs as $aDeparture) {
        $sGroup = (string)($aDeparture['direction'] ?? '');
        $aScore[$sGroup] ??= 0;
        foreach (explode(' · ', mb_strtolower((string)($aDeparture['headsign'] ?? ''))) as $sPart) {
            $aScore[$sGroup] += (int)in_array($sPart, $aDestinations, true);
        }
    }
    if (empty($aScore) || max($aScore) === 0) {
        return $aOurs;
    }
    $sBest = (string)array_search(max($aScore), $aScore, true);
    return array_values(array_filter($aOurs, fn($aDeparture) => (string)($aDeparture['direction'] ?? '') === $sBest));
}

function metroDestination(array $aTrain): string
{
    foreach (DESTINATION_KEYS as $sKey) {
        $Value = $aTrain[$sKey] ?? null;
        if (is_array($Value)) {
            $Value = $Value['name'] ?? $Value['code'] ?? null;
        }
        if (is_string($Value) && $Value !== '') {
            return $Value;
        }
    }
    return '?';
}

// El tren de la lista que más se acerca en minutos al pedido: [posición, diferencia], o null si la lista está vacía.
function closest(int $iMinutes, array $aMinutesList, array $aTaken): array|null
{
    $aBest = null;
    foreach ($aMinutesList as $iAt => $iOther) {
        if (isset($aTaken[$iAt])) {
            continue;
        }
        $iGap = abs($iOther - $iMinutes);
        if ($aBest === null || $iGap < $aBest[1]) {
            $aBest = [$iAt, $iGap];
        }
    }
    return $aBest;
}

$aArgv = array_slice($argv, 1);
$sBase = rtrim(argument($aArgv, 'base', 'https://www.bideplus.eus'), '/');
$bRaw = in_array('--raw', $aArgv, true);
$aPairs = array_values(array_filter($aArgv, fn($sArg) => str_contains($sArg, '/') && !str_starts_with($sArg, '--')));
if (empty($aPairs)) {
    $aPairs = DEFAULT_PAIRS;
}
$aIds = stationIds();
$aTotal = ['metro' => 0, 'within1' => 0, 'within2' => 0, 'missing' => 0, 'extra' => 0];

foreach ($aPairs as $sPair) {
    $sMetro = get(METRO_API . $sPair);
    if ($bRaw) {
        echo "--- Metro $sPair\n" . $sMetro . "\n";
    }
    $aAnswer = json_decode($sMetro, true) ?? [];
    $sName = mb_strtolower((string)($aAnswer['trip']['fromStation']['name'] ?? ''));
    $sStop = $aIds[$sName] ?? null;
    if ($sStop === null) {
        echo "$sPair: la estación «$sName» no está en el GTFS\n";
        continue;
    }
    $aMetro = $aAnswer['trains'] ?? [];
    $aOurs = sameDirection(json_decode(get($sBase . '/api/stops/' . $sStop . '/departures?red=metro&limit=20'), true)['departures'] ?? [], $aMetro);
    $aOursMinutes = array_column($aOurs, 'etaMinutes');
    echo "\n$sPair (parada $sStop): Metro " . count($aMetro) . " trenes, Bide+ " . count($aOurs) . " salidas\n";

    // Bide+ lista unas pocas salidas: lo que Metro anuncia más allá de la última no cuenta como falta.
    $iOursHorizon = 0;
    if (!empty($aOursMinutes)) {
        $iOursHorizon = max($aOursMinutes);
    }
    $aTaken = [];
    foreach ($aMetro as $aTrain) {
        $iMinutes = metroMinutes((array)$aTrain);
        if ($iMinutes === null) {
            echo '  Metro: tren que no entiendo: ' . json_encode($aTrain, JSON_UNESCAPED_UNICODE) . "\n";
            continue;
        }
        $aTotal['metro']++;
        $aFound = closest($iMinutes, $aOursMinutes, $aTaken);
        if ($iMinutes > $iOursHorizon + 1) {
            $aTotal['metro']--;
            continue;
        }
        if ($aFound === null || $aFound[1] > 2) {
            $aTotal['missing']++;
            echo sprintf("  FALTA  Metro %2d min → %s\n", $iMinutes, metroDestination((array)$aTrain));
            continue;
        }
        $aTaken[$aFound[0]] = true;
        $aTotal['within1'] += (int)($aFound[1] <= 1);
        $aTotal['within2']++;
        echo sprintf("  ok     Metro %2d min → %s (%s vagones) | Bide+ %2d min → %s (%s)\n", $iMinutes, metroDestination((array)$aTrain), $aTrain['wagons'] ?? '?',
            $aOursMinutes[$aFound[0]], $aOurs[$aFound[0]]['headsign'] ?? '?', $aOurs[$aFound[0]]['status'] ?? '?');
    }
    foreach ($aOurs as $iAt => $aDeparture) {
        if (!isset($aTaken[$iAt]) && $aDeparture['etaMinutes'] >= 1 && $aDeparture['etaMinutes'] <= 20) {
            $aTotal['extra']++;
            echo sprintf("  SOBRA  Bide+ %2d min → %s (%s)\n", $aDeparture['etaMinutes'], $aDeparture['headsign'] ?? '?', $aDeparture['status']);
        }
    }
}

echo "\nResumen: {$aTotal['metro']} trenes de Metro; a ±1 min {$aTotal['within1']}, a ±2 min {$aTotal['within2']}, "
    . "faltan en Bide+ {$aTotal['missing']}, sobran en Bide+ (de 1 a 20 min) {$aTotal['extra']}\n";
