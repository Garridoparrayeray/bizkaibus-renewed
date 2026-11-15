<?php

// La tabla de las apps de README.md y bideplus/README.md, desde el registro (api/Networks): su nombre, qué muestra y
// de dónde salen sus datos, entre las marcas <!-- apps --> y <!-- /apps -->. Con --comprobar no escribe nada y
// termina con error si alguna está desfasada (lo mira tests/static-checks.py).
// Uso: php scripts/build-readme.php [--comprobar]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

const READMES = [__DIR__ . '/../../README.md', __DIR__ . '/../README.md'];
const START = '<!-- apps: la genera bideplus/scripts/build-readme.php -->';
const END = '<!-- /apps -->';

$aRows = ['| App | Qué muestra | Datos |', '|---|---|---|'];
foreach (Networks::all() as $aNetwork) {
    $aTexts = $aNetwork['texts']['es'];
    $sData = preg_replace('/^Datos:\s*/', '', $aNetwork['attribution']);
    $aRows[] = '| **' . $aTexts['name'] . '** | ' . $aTexts['switcher'] . ' | ' . str_replace('|', '\|', $sData) . ' |';
}
$sTable = START . "\n" . implode("\n", $aRows) . "\n" . END;

$bCheck = isset(getopt('', ['comprobar'])['comprobar']);
$aStale = [];
foreach (READMES as $sFile) {
    $sText = (string)file_get_contents($sFile);
    $sQuoted = preg_quote(START, '/') . '.*?' . preg_quote(END, '/');
    if (!preg_match('/' . $sQuoted . '/s', $sText)) {
        fwrite(STDERR, 'Sin las marcas de la tabla de apps: ' . realpath($sFile) . "\n");
        exit(1);
    }
    $sNew = (string)preg_replace('/' . $sQuoted . '/s', str_replace(['\\', '$'], ['\\\\', '\\$'], $sTable), $sText);
    if ($sNew !== $sText) {
        $aStale[] = basename(dirname(realpath($sFile))) . '/' . basename($sFile);
    }
    if (!$bCheck) {
        file_put_contents($sFile, $sNew);
    }
}
if ($bCheck && $aStale !== []) {
    echo 'desfasada en ' . implode(', ', $aStale) . "\n";
    exit(1);
}
echo count($aRows) - 2 . " apps\n";
