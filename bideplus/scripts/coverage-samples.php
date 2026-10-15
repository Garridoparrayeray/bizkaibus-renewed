<?php
// Cobertura del feed en vivo por linea: de los viajes que el horario dice que estan en ruta, cuantos aparecen en el feed.
// Uso: php scripts/coverage-samples.php <carpeta con capturas s01.xml, s02.xml... o vm_*.xml> <coverage-samples.jsonl> [cada-N-capturas=15]
// Añade al fichero una fila por linea y por captura medida: {"date","t","line","esperados","conDato","gps","otraSalida"}.
// Se mide una de cada N capturas (el feed cambia cada minuto y medir cada una no aporta nada nuevo).
date_default_timezone_set('Europe/Madrid');
spl_autoload_register(function ($c) { $p = __DIR__ . '/../api/' . str_replace('\\', '/', $c) . '.php'; if (is_file($p)) require $p; });
$sDir = $argv[1] ?? ''; $sOut = $argv[2] ?? ''; $iEvery = max(1, (int)($argv[3] ?? 15));
if (!is_dir($sDir) || $sOut === '') { fwrite(STDERR, "Uso: php scripts/coverage-samples.php <carpeta con capturas> <coverage-samples.jsonl> [cada-N]\n"); exit(1); }
$aFiles = array_merge(glob(rtrim($sDir, '/') . '/s*.xml') ?: [], glob(rtrim($sDir, '/') . '/vm_*.xml') ?: []); sort($aFiles);
if (!$aFiles) { echo "Sin capturas en $sDir\n"; exit(0); }
// Fecha de servicio de las capturas (no la de hoy), igual que eval-eta.php.
$sDate = preg_match('/<RecordedAtTime>(\d{4}-\d{2}-\d{2})/', (string)file_get_contents($aFiles[0], false, null, 0, 4096), $m) ? $m[1] : date('Y-m-d');
$Pdo = Core\Database::connection(); $JM = new Models\ServiceJourney($Pdo);
$Fh = fopen($sOut, 'ab'); $iRows = 0; $iSnaps = 0;
foreach ($aFiles as $i => $sFile) {
    if ($i % $iEvery !== 0) continue;
    $aVm = Services\SiriVehicleMonitoringClient::parse((string)file_get_contents($sFile), $sDate);
    $iT = Services\CoverageProbe::latestSeconds($aVm); if ($iT === 0) continue;
    foreach (Services\CoverageProbe::measure($aVm, $JM, $Pdo, $sDate, $iT) as $a) {
        fwrite($Fh, json_encode(['date' => $sDate, 't' => $iT, 'line' => $a['codigo'], 'esperados' => $a['esperados'], 'conDato' => $a['conDato'], 'gps' => $a['gps'], 'otraSalida' => $a['otraSalida']]) . "\n"); $iRows++;
    }
    $iSnaps++;
}
fclose($Fh);
echo "Cobertura: $iSnaps capturas medidas, $iRows filas añadidas a $sOut\n";
