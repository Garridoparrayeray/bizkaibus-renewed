<?php

// Genera style-networks.css con los colores de cada red de api/Networks.
// Uso: php scripts/build-networks-css.php [--output=ruta]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

$aOptions = getopt('', ['output:']);
$sOutput = $aOptions['output'] ?? __DIR__ . '/../style-networks.css';

// Solo colores y nombres de variable: nada que pueda romper el CSS.
function cssValue(string $sValue): string
{
    if (!preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9., ]+\))$/', $sValue)) {
        throw new InvalidArgumentException('Color no válido: ' . $sValue);
    }
    return $sValue;
}

function cssRule(string $sSelector, array $aDeclarations): string
{
    $sRule = $sSelector . " {\n";
    foreach ($aDeclarations as $sProperty => $sValue) {
        $sRule .= '    ' . $sProperty . ': ' . cssValue($sValue) . ";\n";
    }
    return $sRule . '}';
}

$aTiles = [];
$aSwatches = [];
$aPalettes = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $aBrand = $aNetwork['brand'];
    $aTiles[] = cssRule('.tile--' . $sSlug, ['--c' => $aBrand['tile_color'] ?? $aBrand['theme_color']]);
    if (isset($aBrand['swatch_color'])) {
        $aSwatches[] = cssRule('html.bide .bide-swatch--' . $sSlug, ['background' => $aBrand['swatch_color']]);
    }
    if (!empty($aBrand['palette'])) {
        $aVars = [];
        foreach ($aBrand['palette'] as $sName => $sValue) {
            if (!preg_match('/^[a-z][a-z-]*$/', $sName)) {
                throw new InvalidArgumentException('Variable no válida: ' . $sName);
            }
            $aVars['--' . $sName] = $sValue;
        }
        $aPalettes[] = cssRule('html.is-' . $sSlug, $aVars);
    }
}

$sCss = "/* Generado por scripts/build-networks-css.php: no lo edites a mano. */\n\n"
    . "/* ===== Ficha de cada red en el inicio ===== */\n" . implode("\n", $aTiles) . "\n\n"
    . "/* ===== Cuadradito de cada red en Bide+ ===== */\n" . implode("\n", $aSwatches) . "\n\n"
    . "/* ===== Paleta de la app de cada red ===== */\n" . implode("\n", $aPalettes) . "\n";

file_put_contents($sOutput, $sCss);
echo 'style-networks.css: ' . count($aTiles) . " redes\n";
