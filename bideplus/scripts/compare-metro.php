<?php

declare(strict_types=1);

// Compara las salidas que enseña Bide+ con las que enseña la web de Metro Bilbao, estación por estación. Solo para
// medir, a mano y en horas de servicio: la app no usa esa web, solo datos abiertos.
//
// Uso: php scripts/compare-metro.php [--base=https://www.bideplus.eus] [--raw] [ORIGEN/DESTINO ...]
//   ORIGEN y DESTINO son los códigos de tres letras que usa la web de Metro (MOY/SIN). Sin pares, un puñado del tronco.
//   --raw enseña lo que contesta Metro sin interpretar (para ajustar el script si cambia su forma).
// Cada tren de Metro se empareja con la salida de Bide+ del mismo destino cuya hora exacta (con segundos) más se le
// acerca, y se dice cuánto se apartan. Al final: cuántos coinciden a ±60 s y a ±120 s, y cuáles faltan o sobran.

date_default_timezone_set('Europe/Madrid');

const METRO_API = 'https://api.metrobilbao.eus/metro/real-time/';
const GTFS_URL = 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip';
const DEFAULT_PAIRS = ['MOY/SIN', 'SIN/MOY', 'ABA/IND', 'IND/ABA', 'BOL/SAN', 'SAN/BOL'];
const HTTP_TIMEOUT_SECONDS = 20;
const DESTINATION_KEYS = ['direction', 'destination', 'destiny', 'headsign'];
const MAX_MATCH_SECONDS = 240;
const WITHIN_SECONDS = 60;
const NEAR_SECONDS = 120;
const EXTRA_MAX_MINUTES = 20;

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

// La hora exacta de llegada que da Metro («2026-11-12T12:22:08», hora de Madrid), o null si no la trae.
function metroTime(array $aTrain): int|null
{
    $iTime = strtotime((string)($aTrain['time'] ?? ''));
    if ($iTime === false) {
        return null;
    }
    return $iTime;
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

// La salida de Bide+ del mismo destino cuya hora más se acerca a la de Metro: [posición, segundos de diferencia con
// signo (Bide+ menos Metro)], o null si no hay ninguna a menos de MAX_MATCH_SECONDS.
function closest(int $iTime, string $sDestination, array $aOurs, array $aTaken): array|null
{
    $aBest = null;
    foreach ($aOurs as $iAt => $aDeparture) {
        $aHeadsigns = explode(' · ', mb_strtolower((string)($aDeparture['headsign'] ?? '')));
        if (isset($aTaken[$iAt]) || !in_array(mb_strtolower($sDestination), $aHeadsigns, true)) {
            continue;
        }
        $iGap = (int)$aDeparture['etaAt'] - $iTime;
        if (abs($iGap) <= MAX_MATCH_SECONDS && ($aBest === null || abs($iGap) < abs($aBest[1]))) {
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
$aGaps = [];
$aTotal = ['metro' => 0, 'missing' => 0, 'extra' => 0];

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
    echo "\n$sPair (parada $sStop): Metro " . count($aMetro) . " trenes, Bide+ " . count($aOurs) . " salidas\n";

    // Bide+ lista unas pocas salidas: lo que Metro anuncia más allá de la última no cuenta como falta.
    $iHorizon = 0;
    if (!empty($aOurs)) {
        $iHorizon = max(array_column($aOurs, 'etaAt')) + MAX_MATCH_SECONDS;
    }
    $aTaken = [];
    foreach ($aMetro as $aTrain) {
        $iTime = metroTime((array)$aTrain);
        if ($iTime === null || $iTime > $iHorizon) {
            continue;
        }
        $aTotal['metro']++;
        $aFound = closest($iTime, metroDestination((array)$aTrain), $aOurs, $aTaken);
        if ($aFound === null) {
            $aTotal['missing']++;
            echo sprintf("  FALTA  Metro %s → %s\n", date('H:i:s', $iTime), metroDestination((array)$aTrain));
            continue;
        }
        $aTaken[$aFound[0]] = true;
        $aGaps[] = $aFound[1];
        echo sprintf("  ok     Metro %s → %s (%s vagones) | Bide+ %+d s (%s)\n", date('H:i:s', $iTime), metroDestination((array)$aTrain),
            $aTrain['wagons'] ?? '?', $aFound[1], $aOurs[$aFound[0]]['status'] ?? '?');
    }
    foreach ($aOurs as $iAt => $aDeparture) {
        $iMinutes = (int)$aDeparture['etaMinutes'];
        if (!isset($aTaken[$iAt]) && $iMinutes >= 1 && $iMinutes <= EXTRA_MAX_MINUTES) {
            $aTotal['extra']++;
            echo sprintf("  SOBRA  Bide+ %2d min → %s (%s)\n", $iMinutes, $aDeparture['headsign'] ?? '?', $aDeparture['status']);
        }
    }
}

$iMatched = count($aGaps);
$iWithin = count(array_filter($aGaps, fn($iGap) => abs($iGap) <= WITHIN_SECONDS));
$iNear = count(array_filter($aGaps, fn($iGap) => abs($iGap) <= NEAR_SECONDS));
$aAbsolute = array_map('abs', $aGaps);
sort($aAbsolute);
$iMedian = 0;
$iNinetieth = 0;
if ($iMatched > 0) {
    $iMedian = $aAbsolute[intdiv($iMatched, 2)];
    $iNinetieth = $aAbsolute[(int)floor($iMatched * 0.9)];
}
echo "\nResumen: {$aTotal['metro']} trenes de Metro, {$iMatched} con su pareja en Bide+ (mismo destino); "
    . "a ±" . WITHIN_SECONDS . " s $iWithin, a ±" . NEAR_SECONDS . " s $iNear; diferencia mediana $iMedian s, p90 $iNinetieth s; "
    . "faltan en Bide+ {$aTotal['missing']}, sobran en Bide+ (de 1 a " . EXTRA_MAX_MINUTES . " min) {$aTotal['extra']}\n";
