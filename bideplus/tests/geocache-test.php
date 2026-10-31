<?php

$sCachePath = sys_get_temp_dir() . '/geocache-test-' . getmypid() . '.json';
putenv('GEOCACHE_PATH=' . $sCachePath);
putenv('GEOCODE_PAUSE_US=0');
putenv('GEOCODE_MAX_NEW=2');
putenv('GEOCODE_MAX_RETRIES=1');

require __DIR__ . '/../scripts/build-database.php';

$aFailures = [];
$Expect = function (string $sName, bool $bOk) use (&$aFailures): void {
    $sLabel = 'OK  ';
    if (!$bOk) {
        $sLabel = 'MAL ';
    }
    echo $sLabel . $sName . "\n";
    if (!$bOk) {
        $aFailures[] = $sName;
    }
};

file_put_contents($sCachePath, json_encode([
    '43.001,-3.001' => 'Zona conocida',
    '43.002,-3.002' => '',
    '43.003,-3.003' => '',
]));

$aStops = [
    1 => ['lat' => 43.001, 'lon' => -3.001],
    2 => ['lat' => 43.002, 'lon' => -3.002],
    3 => ['lat' => 43.003, 'lon' => -3.003],
    4 => ['lat' => 43.004, 'lon' => -3.004],
    5 => ['lat' => 43.005, 'lon' => -3.005],
    6 => ['lat' => 43.006, 'lon' => -3.006],
    7 => ['lat' => 43.007, 'lon' => -3.007],
];

$aCalls = [];
$Lookup = function (float $dLat, float $dLon) use (&$aCalls): ?string {
    $sKey = round($dLat, 3) . ',' . round($dLon, 3);
    $aCalls[] = $sKey;
    return match ($sKey) {
        '43.002,-3.002' => 'Recuperada al reintentar',
        '43.004,-3.004' => null,
        '43.005,-3.005' => '',
        default => 'Nueva ' . $sKey,
    };
};

ob_start();
$aResult = geocodeStops($aStops, false, $Lookup);
ob_end_clean();
$aCache = json_decode(file_get_contents($sCachePath), true);

$Expect('la celda ya conocida no se vuelve a consultar', !in_array('43.001,-3.001', $aCalls, true));
$Expect('las celdas cacheadas vacias se reintentan (maximo 1 por ejecucion)', count(array_intersect($aCalls, ['43.002,-3.002', '43.003,-3.003'])) === 1);
$Expect('se respeta el tope de consultas nuevas (2)', count(array_diff($aCalls, ['43.002,-3.002', '43.003,-3.003'])) === 2);
$Expect('un fallo (null) no se guarda en la cache', !array_key_exists('43.004,-3.004', $aCache));
$Expect('la celda reintentada con exito queda rellena', ($aCache['43.002,-3.002'] ?? '') === 'Recuperada al reintentar');
$Expect('la celda vacia sin reintentar se mantiene vacia', ($aCache['43.003,-3.003'] ?? null) === '');
$Expect('la parada de una celda conocida recibe su zona', $aResult[1]['area'] === 'Zona conocida');
$Expect('la parada de una celda sin resolver queda con zona vacia', $aResult[7]['area'] === '');
$Expect('una respuesta vacia legitima ("") sí se guarda', array_key_exists('43.005,-3.005', $aCache) && $aCache['43.005,-3.005'] === '');

$aCalls = [];
ob_start();
geocodeStops($aStops, false, $Lookup);
ob_end_clean();
$Expect('la segunda ejecucion sigue avanzando con el resto de celdas', count($aCalls) > 0);

$aSkipped = geocodeStops($aStops, true, $Lookup);
$Expect('con skip (Metro) no se consulta nada y la zona queda vacia', $aSkipped[1]['area'] === '');

@unlink($sCachePath);
if (!empty($aFailures)) {
    echo "\nRESULTADO geocache: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO geocache: OK\n";
exit(0);
