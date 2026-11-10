<?php

// Lista las redes del registro para los flujos de GitHub Actions, una por línea:
// «red base_de_datos obligatoria(1|0)». Con --slugs, solo los nombres separados por espacios. Con --build-hash=<ficha>,
// la huella de lo que la ficha le dice al constructor: no cambia si solo cambian su imagen, sus textos o su fuente.

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const COSMETIC_KEYS = ['brand', 'texts', 'seo', 'attribution', 'order'];

foreach ($argv as $sArgument) {
    if (str_starts_with($sArgument, '--build-hash=')) {
        $aConfig = require substr($sArgument, strlen('--build-hash='));
        echo sha1(json_encode(array_diff_key($aConfig, array_flip(COSMETIC_KEYS)))) . "\n";
        exit;
    }
}

if (in_array('--slugs', $argv, true)) {
    echo implode(' ', Networks::slugs()) . "\n";
    exit;
}
foreach (Networks::all() as $sSlug => $aNetwork) {
    $iRequired = 0;
    if (!empty($aNetwork['gtfs']['required'])) {
        $iRequired = 1;
    }
    echo $sSlug . ' ' . $aNetwork['db'] . ' ' . $iRequired . "\n";
}
