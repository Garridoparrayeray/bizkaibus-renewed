<?php

// Genera style-networks.css con los colores de cada red de api/Networks: la ficha del menú (tile_color), el cuadradito
// de Bide+ (swatch_color) y la paleta de la app (palette, variables CSS sobre html.is-<red>). Así una red nueva solo
// toca su archivo de configuración. Las páginas lo cargan después de style-bide.css y antes de style-miamor.css.
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

$aTiles = [];
$aSwatches = [];
$aPalettes = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $aBrand = $aNetwork['brand'];
    $aTiles[] = '.tile--' . $sSlug . ' { --c: ' . cssValue($aBrand['tile_color'] ?? $aBrand['theme_color']) . '; }';
    if (isset($aBrand['swatch_color'])) {
        $aSwatches[] = 'html.bide .bide-swatch--' . $sSlug . ' { background: ' . cssValue($aBrand['swatch_color']) . '; }';
    }
    if (!empty($aBrand['palette'])) {
        $aVars = [];
        foreach ($aBrand['palette'] as $sName => $sValue) {
            if (!preg_match('/^[a-z][a-z-]*$/', $sName)) {
                throw new InvalidArgumentException('Variable no válida: ' . $sName);
            }
            $aVars[] = '    --' . $sName . ': ' . cssValue($sValue) . ';';
        }
        $aPalettes[] = 'html.is-' . $sSlug . " {\n" . implode("\n", $aVars) . "\n}";
    }
}

$sCss = "/* Generado por scripts/build-networks-css.php desde api/Networks: no lo edites a mano. */\n\n"
    . "/* Ficha de cada red en el menú de inicio. */\n" . implode("\n", $aTiles) . "\n\n"
    . "/* Cuadradito de cada red en las listas de Bide+ (favoritos, avisos). */\n" . implode("\n", $aSwatches) . "\n\n"
    . "/* Paleta de la app de cada red (la red por defecto usa la de style-app.css). */\n" . implode("\n", $aPalettes) . "\n";

file_put_contents($sOutput, $sCss);
echo 'style-networks.css: ' . count($aTiles) . " redes\n";
