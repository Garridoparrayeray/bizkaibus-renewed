<?php

$cachePath = sys_get_temp_dir() . '/geocache-test-' . getmypid() . '.json';
putenv('GEOCACHE_PATH=' . $cachePath);
putenv('GEOCODE_PAUSE_US=0');
putenv('GEOCODE_MAX_NEW=2');
putenv('GEOCODE_MAX_RETRIES=1');

require __DIR__ . '/../scripts/build-database.php';

$failures = [];
$expect = function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'MAL ') . $name . "\n";
    if (!$ok) {
        $failures[] = $name;
    }
};

file_put_contents($cachePath, json_encode([
    '43.001,-3.001' => 'Zona conocida',
    '43.002,-3.002' => '',
    '43.003,-3.003' => '',
]));

$stops = [
    1 => ['lat' => 43.001, 'lon' => -3.001],
    2 => ['lat' => 43.002, 'lon' => -3.002],
    3 => ['lat' => 43.003, 'lon' => -3.003],
    4 => ['lat' => 43.004, 'lon' => -3.004],
    5 => ['lat' => 43.005, 'lon' => -3.005],
    6 => ['lat' => 43.006, 'lon' => -3.006],
    7 => ['lat' => 43.007, 'lon' => -3.007],
];

$calls = [];
$lookup = function (float $lat, float $lon) use (&$calls): ?string {
    $key = round($lat, 3) . ',' . round($lon, 3);
    $calls[] = $key;
    return match ($key) {
        '43.002,-3.002' => 'Recuperada al reintentar',
        '43.004,-3.004' => null,
        '43.005,-3.005' => '',
        default => 'Nueva ' . $key,
    };
};

ob_start();
$result = geocodeStops($stops, false, $lookup);
ob_end_clean();
$cache = json_decode(file_get_contents($cachePath), true);

$expect('la celda ya conocida no se vuelve a consultar', !in_array('43.001,-3.001', $calls, true));
$expect('las celdas cacheadas vacias se reintentan (maximo 1 por ejecucion)', count(array_intersect($calls, ['43.002,-3.002', '43.003,-3.003'])) === 1);
$expect('se respeta el tope de consultas nuevas (2)', count(array_diff($calls, ['43.002,-3.002', '43.003,-3.003'])) === 2);
$expect('un fallo (null) no se guarda en la cache', !array_key_exists('43.004,-3.004', $cache));
$expect('la celda reintentada con exito queda rellena', ($cache['43.002,-3.002'] ?? '') === 'Recuperada al reintentar');
$expect('la celda vacia sin reintentar se mantiene vacia', ($cache['43.003,-3.003'] ?? null) === '');
$expect('la parada de una celda conocida recibe su zona', $result[1]['area'] === 'Zona conocida');
$expect('la parada de una celda sin resolver queda con zona vacia', $result[7]['area'] === '');
$expect('una respuesta vacia legitima ("") sí se guarda', array_key_exists('43.005,-3.005', $cache) && $cache['43.005,-3.005'] === '');

$calls = [];
ob_start();
geocodeStops($stops, false, $lookup);
ob_end_clean();
$expect('la segunda ejecucion sigue avanzando con el resto de celdas', count($calls) > 0);

$skipped = geocodeStops($stops, true, $lookup);
$expect('con skip (Metro) no se consulta nada y la zona queda vacia', $skipped[1]['area'] === '');

@unlink($cachePath);
echo "\nRESULTADO geocache: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
