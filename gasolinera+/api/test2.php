<?php
$pdo = new PDO('sqlite:../data/gasolinera.sqlite');
$stations = $pdo->query('SELECT * FROM stations')->fetchAll(PDO::FETCH_ASSOC);
$prices = $pdo->query('SELECT ideess, carburante, precio FROM current_prices')->fetchAll(PDO::FETCH_ASSOC);

$pricesById = [];
foreach ($prices as $p) {
    $pricesById[$p['ideess']][$p['carburante']] = $p['precio'];
}

foreach ($stations as &$st) {
    if (isset($pricesById[$st['ideess']])) {
        $st['precios'] = $pricesById[$st['ideess']];
    } else {
        $st['precios'] = new stdClass();
    }
    // Drop internal normalized fields to save space
    unset($st['municipio_normalizado'], $st['direccion_normalizada'], $st['rotulo_normalizado'], $st['localidad_normalizada']);
}
unset($st);

$json = json_encode($stations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents('../data/stations-all-today.json', $json);
echo "Size: " . round(filesize('../data/stations-all-today.json') / 1024, 2) . " KB\n";
