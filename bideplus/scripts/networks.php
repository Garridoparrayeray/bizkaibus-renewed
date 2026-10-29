<?php

// Lista las redes del registro para los flujos de GitHub Actions, una por línea:
// «red base_de_datos obligatoria(1|0)». Con --slugs, solo los nombres separados por espacios.

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

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
