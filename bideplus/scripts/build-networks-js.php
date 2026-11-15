<?php

// Genera js/networks.js con la parte de api/Networks que necesita el navegador.
// Uso: php scripts/build-networks-js.php [--output=ruta]

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';

use Core\Lang;
use Core\Networks;
use Services\Calendar;

$aOptions = getopt('', ['output:']);
$sOutput = $aOptions['output'] ?? __DIR__ . '/../js/networks.js';

$aOut = [];
foreach (Networks::all() as $sSlug => $aNetwork) {
    $aTexts = $aNetwork['texts'];
    $aTexts['es']['attribution'] ??= $aNetwork['attribution'];
    $aOut[$sSlug] = [
        'kind' => $aNetwork['kind'],
        'icons' => $aNetwork['brand']['icons'],
        'themeColor' => $aNetwork['brand']['theme_color'],
        'title' => $aNetwork['brand']['title'],
        'favoritesKey' => $aNetwork['db'] . '_favorites',
        'operator' => $aNetwork['seo']['operator'],
        'live' => !empty($aNetwork['live']),
        'features' => $aNetwork['features'],
        'texts' => $aTexts,
    ];
}

// Las listas de apps de las preguntas frecuentes en cada idioma (net.apps, net.apps.live…).
$aApps = [];
foreach (Lang::LANGS as $sLang) {
    foreach (Networks::APP_LISTS as $sList) {
        $aApps[$sLang][$sList] = Lang::t($sList, $sLang);
    }
}

$sJson = json_encode($aOut, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sApps = json_encode($aApps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$sDefault = json_encode(Networks::defaultSlug());
$sTimeZone = json_encode(Calendar::TIME_ZONE, JSON_UNESCAPED_SLASHES);
$sJs = <<<JS
// Generado por scripts/build-networks-js.php a partir de api/Networks. No editar a mano.
(function (global) {
    const networks = $sJson;
    const apps = $sApps;
    const order = Object.keys(networks);
    global.BideNetworks = {
        DEFAULT: $sDefault,
        // La zona horaria de los horarios (Services\\Calendar::TIME_ZONE), sea cual sea la del móvil.
        TIME_ZONE: $sTimeZone,
        all: networks,
        slugs: order,
        get: function (slug) { return networks[slug] || networks[$sDefault]; },
        exists: function (slug) { return Object.prototype.hasOwnProperty.call(networks, slug); },
        // En el idioma elegido si hay diccionario (páginas); en castellano si no (service worker).
        name: function (slug) {
            if (!networks[slug]) return slug;
            if (global.I18n) return global.I18n.t('net.' + slug + '.name');
            return networks[slug].texts.es.name;
        },
    };
    // Los textos de cada red entran en el diccionario como net.<red>.<clave>, y las listas de apps como net.apps…:
    // aquí si js/i18n.js ya está y, si no (en las apps va con defer, después), los mete él al cargarse.
    global.BideNetworks.addTexts = function (i18n) {
        Object.keys(apps).forEach(function (lang) {
            i18n.extend(lang, apps[lang]);
        });
        order.forEach(function (slug) {
            Object.keys(networks[slug].texts).forEach(function (lang) {
                const table = {};
                Object.keys(networks[slug].texts[lang]).forEach(function (key) {
                    table['net.' + slug + '.' + key] = networks[slug].texts[lang][key];
                });
                i18n.extend(lang, table);
            });
        });
    };
    if (global.I18n && global.I18n.extend) global.BideNetworks.addTexts(global.I18n);
})(self);

JS;

file_put_contents($sOutput, $sJs);
echo 'networks.js: ' . count($aOut) . " redes\n";
