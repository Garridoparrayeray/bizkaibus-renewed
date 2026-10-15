<?php
// Laboratorio de Bide+, parte PHP: lee el feed real, ejecuta el algoritmo de la app (RealtimeMatcher) y lo compara con las
// fuentes oficiales (el horario publicado y el retraso que da el propio feed). Guarda cada lectura en data/live-log.jsonl
// y, cuando el bus pasa por la parada, mide quien acertó. Uso: php -S localhost:8090 desde la raiz del repo y abrir /lab/.
date_default_timezone_set('Europe/Madrid');
spl_autoload_register(function ($c) { $p = __DIR__ . '/../bideplus/api/' . str_replace('\\', '/', $c) . '.php'; if (is_file($p)) require $p; });
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
const FEED = 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions.xml';
const AHEAD = [0, 2, 5]; // paradas por delante de la próxima que se registran en cada lectura
$sLog = __DIR__ . '/data/live-log.jsonl';
$sAction = $_GET['action'] ?? 'poll';

function fail(string $s, int $c = 500): never { http_response_code($c); echo json_encode(['error' => $s], JSON_UNESCAPED_UNICODE); exit; }
function median(array $a): float { sort($a); return $a ? (float)$a[intdiv(count($a), 2)] : NAN; }

function verify(string $sLog): array {
    $aBy = [];
    foreach (is_file($sLog) ? file($sLog, FILE_IGNORE_NEW_LINES) : [] as $s) { $e = json_decode($s, true); if ($e) $aBy[$e['v'] . '|' . $e['j']][] = $e; }
    $aErr = []; $iPairs = 0;
    foreach ($aBy as $aEs) {
        usort($aEs, fn($a, $b) => $a['t'] <=> $b['t']);
        foreach ($aEs as $i => $e) foreach ($e['p'] as [$iSeq, $iSched, $iOff, $iOurs, $sLayer]) {
            for ($n = $i + 1; $n < count($aEs); $n++) {
                if ($aEs[$n]['t'] - $aEs[$n - 1]['t'] > 150) break; // hueco: no se sabe cuándo pasó
                if ($aEs[$n]['o'] > $iSeq) {
                    $iActual = ($aEs[$n]['t'] + $aEs[$n - 1]['t']) / 2; $h = $iActual - $e['t'];
                    if ($h >= 0 && $h < 1200) { $iPairs++; $b = $h < 300 ? '0-5 min' : ($h < 600 ? '5-10 min' : '10-20 min');
                        foreach (['horario' => $iSched, 'oficial' => $iOff, 'bideplus' => $iOurs] as $m => $v) { $aErr[$b][$m][] = $v - $iActual; $aErr['todo'][$m][] = $v - $iActual; if ($b !== '10-20 min') $aErr['capa ' . $sLayer][$m][] = $v - $iActual; } }
                    break;
                }
            }
        }
    }
    $aOut = [];
    foreach ($aErr as $g => $aM) foreach ($aM as $m => $a) {
        $aAbs = array_map('abs', $a);
        $aOut[$g][$m] = ['n' => count($a), 'mediana' => round(median($aAbs)), 'sesgo' => round(median($a)), 'media' => round(array_sum($aAbs) / count($aAbs)),
            'dentro2' => round(100 * count(array_filter($aAbs, fn($x) => $x <= 120)) / count($aAbs), 1)];
    }
    return ['pares' => $iPairs, 'grupos' => $aOut];
}

if ($sAction === 'verify') { echo json_encode(verify($sLog), JSON_UNESCAPED_UNICODE); exit; }
if ($sAction === 'reset') { @unlink($sLog); echo '{"ok":true}'; exit; }

if ($sAction === 'coverage') {
    // Cobertura: de los viajes que el horario dice que están en marcha, cuántos aparecen en el feed (y con GPS).
    $sCovLog = __DIR__ . '/data/coverage-log.jsonl';
    if (($_GET['op'] ?? '') === 'reset') { @unlink($sCovLog); echo '{"ok":true}'; exit; }
    try {
        $sCache = sys_get_temp_dir() . '/bideplus-lab-feed.xml';
        if (!is_file($sCache) || time() - filemtime($sCache) > 20) {
            $sXml = @file_get_contents(FEED, false, stream_context_create(['http' => ['timeout' => 15]]));
            if ($sXml === false) fail('No se pudo leer el feed de Bizkaibus (¿hay conexión a internet?).', 502);
            file_put_contents($sCache, $sXml);
        }
        $sDate = date('Y-m-d'); $aVm = Services\SiriVehicleMonitoringClient::parse(file_get_contents($sCache), $sDate);
        $iT = Services\CoverageProbe::latestSeconds($aVm);
        if (!$iT) fail('El feed no trae posiciones ahora mismo.', 502);
        $Pdo = Core\Database::connection(); $JM = new Models\ServiceJourney($Pdo);
        $aLines = Services\CoverageProbe::measure($aVm, $JM, $Pdo, $sDate, $iT);
        $iTot = array_sum(array_column($aLines, 'esperados')); $iCon = array_sum(array_column($aLines, 'conDato')); $iGps = array_sum(array_column($aLines, 'gps')); $iOtra = array_sum(array_column($aLines, 'otraSalida'));
        $aNow = ['t' => $iT, 'fecha' => $sDate, 'esperados' => $iTot, 'conDato' => $iCon, 'gps' => $iGps, 'otraSalida' => $iOtra];
        $sLastCov = __DIR__ . '/data/.last-cov-t';
        if (($_GET['log'] ?? '1') !== '0' && (is_file($sLastCov) ? (int)file_get_contents($sLastCov) : 0) !== $iT) {
            file_put_contents($sCovLog, json_encode($aNow + ['lineas' => array_map(fn($a) => [$a['codigo'], $a['esperados'], $a['conDato'], $a['otraSalida']], $aLines)]) . "\n", FILE_APPEND | LOCK_EX);
            file_put_contents($sLastCov, (string)$iT);
        }
        $aAcc = []; $iSnaps = 0; $aDays = [];
        foreach (is_file($sCovLog) ? file($sCovLog, FILE_IGNORE_NEW_LINES) : [] as $s) { $h = json_decode($s, true); if (!$h) continue; $iSnaps++; $aDays[$h['fecha']] = true;
            foreach ($h['lineas'] as [$cod, $esp, $con, $otr]) { $aAcc[$cod]['esperados'] = ($aAcc[$cod]['esperados'] ?? 0) + $esp; $aAcc[$cod]['conDato'] = ($aAcc[$cod]['conDato'] ?? 0) + $con; $aAcc[$cod]['otraSalida'] = ($aAcc[$cod]['otraSalida'] ?? 0) + $otr; } }
        foreach ($aLines as &$a) $a['acumulado'] = $aAcc[$a['codigo']] ?? null; unset($a);
        echo json_encode(['ahora' => $aNow, 'lineas' => $aLines, 'lecturas' => $iSnaps, 'dias' => count($aDays)], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $x) { fail($x->getMessage()); }
    exit;
}

try {
    $sCache = sys_get_temp_dir() . '/bideplus-lab-feed.xml';
    if (!is_file($sCache) || time() - filemtime($sCache) > 20) {
        $Ctx = stream_context_create(['http' => ['timeout' => 15]]); $sXml = @file_get_contents(FEED, false, $Ctx);
        if ($sXml === false) fail('No se pudo leer el feed de Bizkaibus (¿hay conexión a internet?).', 502);
        file_put_contents($sCache, $sXml);
    }
    $sDate = date('Y-m-d');
    $aVm = Services\SiriVehicleMonitoringClient::parse(file_get_contents($sCache), $sDate);
    $iT = 0; foreach ($aVm as $aEs) foreach ($aEs as $e) if (isset($e['locationSeconds'])) $iT = max($iT, $e['locationSeconds']);
    if (!$iT) fail('El feed no trae posiciones ahora mismo.', 502);
    $Pdo = Core\Database::connection(); $JM = new Models\ServiceJourney($Pdo); $M = new Services\RealtimeMatcher($aVm, $JM, $iT, null, $sDate);
    $aBuses = []; $aLines = []; $aLogLines = []; $aStopName = [];
    $fName = function ($id) use ($Pdo, &$aStopName) { if (!isset($aStopName[$id])) { try { $q = $Pdo->prepare('SELECT name FROM stops WHERE id = ?'); $q->execute([$id]); $aStopName[$id] = (string)$q->fetchColumn(); } catch (\Throwable $x) { $aStopName[$id] = (string)$id; } } return $aStopName[$id]; };
    foreach ($aVm as $sKey => $aEs) foreach ($aEs as $e) {
        $j = $M->journeyIdFor($e); if (!$j || !isset($e['order'])) continue;
        $aSt = $JM->stopsWithCoordinates($j); if (!$aSt) continue;
        $aBySeq = array_column($aSt, null, 'seq_order'); $iFirst = (int)$aSt[0]['departure_seconds'];
        if ($iT < $iFirst - 60) continue; // aún no ha salido
        $aP = []; $aRowsOut = [];
        foreach (AHEAD as $iA) {
            $iSeq = (int)$e['order'] + $iA; if (!isset($aBySeq[$iSeq])) continue; $s = $aBySeq[$iSeq]; $iSched = (int)$s['arrival_seconds'];
            $aRow = ['service_journey_id' => $j, 'line_id' => $e['lineId'], 'trip_number' => explode('|', $sKey)[1], 'first_departure_seconds' => $iFirst, 'arrival_seconds' => $iSched, 'stop_seq' => $iSeq];
            $o = $M->enrich([$aRow])[0]; if (($o['status'] ?? '') === 'departed' || !isset($o['etaSeconds'])) continue;
            $sLayer = $M->positionFor($j, $e) ? 'GPS' : (($o['status'] ?? '') === 'live' ? 'parada' : 'horario');
            $iOff = $iSched + (int)$e['delaySeconds'];
            $aP[] = [$iSeq, $iSched, $iOff, (int)$o['etaSeconds'], $sLayer];
            $aRowsOut[] = ['seq' => $iSeq, 'parada' => $fName($s['stop_id']), 'horario' => $iSched, 'oficial' => $iOff, 'bideplus' => (int)$o['etaSeconds'], 'capa' => $sLayer, 'retrasoApp' => $o['delaySeconds'] ?? null];
        }
        if (!$aP) continue;
        $aLogLines[] = json_encode(['t' => $iT, 'v' => $e['vehicleRef'], 'j' => $j, 'o' => (int)$e['order'], 'p' => $aP]);
        $aBuses[] = ['bus' => $e['vehicleRef'], 'linea' => $e['lineId'], 'viaje' => explode('|', $sKey)[1], 'orden' => (int)$e['order'], 'gps' => $e['lat'] !== null, 'retrasoFeed' => (int)$e['delaySeconds'], 'paradas' => $aRowsOut, 'lat' => $e['lat'], 'lon' => $e['lon']];
    }
    $sLast = __DIR__ . '/data/.last-t';
    if (($_GET['log'] ?? '1') !== '0' && (is_file($sLast) ? (int)file_get_contents($sLast) : 0) !== $iT && $aLogLines) {
        file_put_contents($sLog, implode("\n", $aLogLines) . "\n", FILE_APPEND | LOCK_EX); file_put_contents($sLast, (string)$iT);
    }
    echo json_encode(['t' => $iT, 'fecha' => $sDate, 'buses' => $aBuses, 'verificacion' => verify($sLog)], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $x) { fail($x->getMessage()); }
