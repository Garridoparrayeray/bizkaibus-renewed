<?php

date_default_timezone_set('Europe/Madrid');
require __DIR__ . '/../../api/autoload.php';
$sDir = $argv[1] ?? ''; $sOut = $argv[2] ?? ''; $iEvery = max(1, (int)($argv[3] ?? 15));
if (!is_dir($sDir) || $sOut === '') { fwrite(STDERR, "Uso: php engine/tools/coverage-samples.php <carpeta con capturas> <coverage-samples.jsonl> [cada-N]\n"); exit(1); }
$aFiles = array_merge(glob(rtrim($sDir, '/') . '/s*.xml') ?: [], glob(rtrim($sDir, '/') . '/vm_*.xml') ?: []); sort($aFiles);
if (!$aFiles) { echo "Sin capturas en $sDir\n"; exit(0); }

$sDate = preg_match('/<RecordedAtTime>(\d{4}-\d{2}-\d{2})/', (string)file_get_contents($aFiles[0], false, null, 0, 4096), $m) ? $m[1] : date('Y-m-d');
$Pdo = Core\Database::connection(); $JM = new Models\ServiceJourney($Pdo);
$Fh = fopen($sOut, 'ab'); $iRows = 0; $iSnaps = 0;
foreach ($aFiles as $i => $sFile) {
    if ($i % $iEvery !== 0) continue;
    $aVm = Services\SiriVehicleMonitoringClient::parse((string)file_get_contents($sFile), $sDate);
    $iT = Engine\CoverageProbe::latestSeconds($aVm); if ($iT === 0) continue;
    foreach (Engine\CoverageProbe::measure($aVm, $JM, $Pdo, $sDate, $iT) as $a) {
        fwrite($Fh, json_encode(['date' => $sDate, 't' => $iT, 'line' => $a['codigo'], 'esperados' => $a['esperados'], 'conDato' => $a['conDato'], 'gps' => $a['gps'], 'otraSalida' => $a['otraSalida']]) . "\n"); $iRows++;
    }
    $iSnaps++;
}
fclose($Fh);
echo "Cobertura: $iSnaps capturas medidas, $iRows filas añadidas a $sOut\n";
