<?php

// Genera js/networks.js con la parte de api/Networks que necesita el navegador.
// Uso: php scripts/build-networks-js.php [--output=ruta]

declare(strict_types=1);

require __DIR__ . '/../api/Core/Networks.php';

use Core\Networks;

$aOptions = getopt('', ['output:']);
$sOutput = $aOptions['output'] ?? __DIR__ . '/../js/networks.js';

$aOut = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $aOut[$sSlug] = [
        'kind' => $aNetwork['kind'],
        'icons' => $aNetwork['brand']['icons'],
        'themeColor' => $aNetwork['brand']['theme_color'],
        'title' => $aNetwork['brand']['title'],
        'favoritesKey' => $aNetwork['db'] . '_favorites',
        'live' => !empty($aNetwork['live']),
        'features' => $aNetwork['features'],
        'attribution' => $aNetwork['attribution'],
        'texts' => $aNetwork['texts'],
    ];
}

$sJson = json_encode($aOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sJs = <<<JS
// Generado por scripts/build-networks-js.php a partir de api/Networks. No editar a mano.
(function (global) {
    var networks = $sJson;
    var order = Object.keys(networks);
    global.BideNetworks = {
        DEFAULT: 'bus',
        all: networks,
        slugs: order,
        get: function (slug) { return networks[slug] || networks.bus; },
        exists: function (slug) { return Object.prototype.hasOwnProperty.call(networks, slug); },
        name: function (slug) {
            if (networks[slug]) return networks[slug].texts.es.name;
            return slug;
        },
    };
    // Los textos de cada red entran en el diccionario como net.<red>.<clave>.
    if (global.I18n && global.I18n.extend) {
        order.forEach(function (slug) {
            Object.keys(networks[slug].texts).forEach(function (lang) {
                var table = {};
                Object.keys(networks[slug].texts[lang]).forEach(function (key) {
                    table['net.' + slug + '.' + key] = networks[slug].texts[lang][key];
                });
                global.I18n.extend(lang, table);
            });
        });
    }
})(self);

JS;

file_put_contents($sOutput, $sJs);
echo 'networks.js: ' . count($aOut) . " redes\n";
