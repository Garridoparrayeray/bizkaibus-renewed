<?php
// Datos de la página de una app: título, descripción, imagen, URL canónica y JSON-LD. En /stops/{id} y /lines/{id}
// salen de la parada o la línea, con sus enlaces relacionados para quien no tiene JavaScript. Lo usa app.php.

use Core\Config;
use Core\Database;
use Core\Lang;
use Core\Networks;
use Models\LineModel;
use Models\Stop;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';

$sNetworkSlug = Networks::fromQuery($_GET['red'] ?? null);
$aNetwork = Networks::get($sNetworkSlug);
$sLang = Lang::current();
$aAppNames = array_map(fn($aOne) => Networks::text($aOne, 'name', $sLang), Networks::all());
// Los textos de la página para los buscadores, en cada idioma ({...} se rellena con la parada, la línea o la red).
$aPageTexts = [
    'es' => [
        'networkDescription' => '', 'stopTitle' => '{stop} · Horarios y próximas salidas | {app}',
        'stopDescription' => 'Próximas salidas y horarios de {app} en {place}.', 'lines' => ' Líneas: {list}.',
        'live' => ' Llegadas en tiempo real.', 'lineTitle' => 'Línea {code} {name} · Horarios | {app}',
        'lineDescription' => 'Horarios, recorrido y paradas de la línea {code} ({name}) de {app}.',
        'busMap' => ' Mapa con los autobuses en tiempo real.', 'trainMap' => ' Mapa con los trenes en tiempo real.',
        'line' => 'Línea {code}', 'linesAt' => 'Líneas que paran en {stop}', 'stopsOf' => 'Paradas de la línea {code}',
        'appDescription' => 'Horarios de {app}.',
    ],
    'eu' => [
        'networkDescription' => '{switcher}: geltoki eta linea bakoitzaren hurrengo irteerak, ordutegiak eta abisuak.',
        'stopTitle' => '{stop} · Ordutegiak eta hurrengo irteerak | {app}',
        'stopDescription' => '{app}: {place} geltokiko hurrengo irteerak eta ordutegiak.', 'lines' => ' Lineak: {list}.',
        'live' => ' Iritsierak denbora errealean.', 'lineTitle' => '{code} {name} linea · Ordutegiak | {app}',
        'lineDescription' => '{app}: {code} ({name}) linearen ordutegiak, ibilbidea eta geltokiak.',
        'busMap' => ' Autobusak denbora errealean erakusten dituen mapa.', 'trainMap' => ' Trenak denbora errealean erakusten dituen mapa.',
        'line' => '{code} linea', 'linesAt' => '{stop} geltokian gelditzen diren lineak', 'stopsOf' => '{code} linearen geltokiak',
        'appDescription' => '{app}: ordutegiak.',
    ],
    'en' => [
        'networkDescription' => '{switcher}: next departures, timetables and alerts for every stop and line.',
        'stopTitle' => '{stop} · Timetable and next departures | {app}',
        'stopDescription' => 'Next departures and timetables for {app} at {place}.', 'lines' => ' Lines: {list}.',
        'live' => ' Real-time arrivals.', 'lineTitle' => 'Line {code} {name} · Timetable | {app}',
        'lineDescription' => 'Timetable, route and stops of line {code} ({name}), {app}.',
        'busMap' => ' Map with the buses in real time.', 'trainMap' => ' Map with the trains in real time.',
        'line' => 'Line {code}', 'linesAt' => 'Lines stopping at {stop}', 'stopsOf' => 'Stops on line {code}',
        'appDescription' => '{app} timetables.',
    ],
];
$PageText = function (string $sKey, array $aVars = []) use ($aPageTexts, $sLang): string {
    $aReplace = [];
    foreach ($aVars as $sName => $sValue) {
        $aReplace['{' . $sName . '}'] = (string)$sValue;
    }
    return strtr($aPageTexts[$sLang][$sKey], $aReplace);
};

$sSiteUrl = $aSite['url'];
if ($bMiamorDomain) {
    // Los que muestran la vista previa de un enlace (WhatsApp...) no ejecutan JavaScript: el texto se decide aquí.
    $sTitle = 'Para el amor de mi vida 💕';
    $sDescription = 'Horarios y tiempo real de tu transporte, hecho con cariño para ti.';
    $sSiteUrl = 'https://bideplusmiamor.vercel.app';
    $sImage = $sSiteUrl . '/icons/icon-512.png';
    $iImageWidth = 512;
    $iImageHeight = 512;
    $sImageAlt = 'Icono de ' . $sTitle;
    $sFaviconFolder = 'icons';
} else {
    $sTitle = $aNetwork['seo']['title'];
    $sDescription = $aNetwork['seo']['description'];
    if ($sLang !== 'es') {
        $sSwitcher = Networks::text($aNetwork, 'switcher', $sLang);
        $sTitle = $aAppNames[$sNetworkSlug] . ' · ' . $sSwitcher . ' | Bide+';
        $sDescription = $PageText('networkDescription', ['switcher' => $sSwitcher]);
    }
    // Al compartir cualquier página (parada, línea, red), la vista previa es siempre la imagen de Bide+.
    $sImage = $aSite['url'] . '/icons-bide-rojo/og-image.png';
    $iImageWidth = 1200;
    $iImageHeight = 630;
    $sImageAlt = 'Bide+: bus, metro, tren y tranvía de Euskadi en un solo sitio';
    $sFaviconFolder = $aNetwork['brand']['icons'];
}
// Imagen apaisada: vista previa grande en las redes que lo distinguen.
$sTwitterCard = 'summary';
if ($iImageWidth > $iImageHeight) {
    $sTwitterCard = 'summary_large_image';
}

$sCanonical = $sSiteUrl . $sPath;
if ($sPath === '/' || $sPath === '') {
    $sCanonical = $sSiteUrl . '/?red=' . $sNetworkSlug;
    if ($bMiamorDomain) {
        $sCanonical = $sSiteUrl . '/';
    }
}

// Parada o línea de la URL, en la red pedida. Las paradas se buscan también con ceros a la izquierda (Renfe).
function appPageRecord(string $sNetworkSlug, string $sType, string $sId): array|null
{
    Config::set($sNetworkSlug);
    if ($sType === 'stops') {
        return (new Stop(Database::connection()))->find($sId);
    }
    return (new LineModel(Database::connection()))->find($sId);
}

$aPlace = null;
$sBreadcrumbName = null;
$sRelatedHeading = null;
$sRelatedType = null;
$aRelated = [];
$bNotFound = false;
try {
    if (preg_match('#^/(stops|lines)/([^/]+)/?$#', $sPath, $aMatches)) {
        $sType = $aMatches[1];
        $sId = urldecode($aMatches[2]);
        $aRecord = appPageRecord($sNetworkSlug, $sType, $sId);
        // Un enlace sin ?red= a una parada o línea de otra red lleva a esa red.
        if ($aRecord === null && !isset($_GET['red'])) {
            foreach (array_diff(Networks::slugs(), [$sNetworkSlug]) as $sOtherSlug) {
                if (appPageRecord($sOtherSlug, $sType, $sId) !== null) {
                    header('Location: /' . $sType . '/' . $aMatches[2] . '?red=' . $sOtherSlug, true, 301);
                    exit;
                }
            }
        }

        $sAppName = $aAppNames[$sNetworkSlug];
        if ($aRecord === null) {
            $bNotFound = true;
        } elseif ($sType === 'stops') {
            $sStopLabel = $aRecord['name'];
            if (($aRecord['area'] ?? '') !== '') {
                $sStopLabel .= ' (' . $aRecord['area'] . ')';
            }
            $aRelated = (new Stop(Database::connection()))->linesServing($aRecord['id']);
            $sTitle = $PageText('stopTitle', ['stop' => $aRecord['name'], 'app' => $sAppName]);
            $sDescription = $PageText('stopDescription', ['app' => $sAppName, 'place' => $sStopLabel]);
            if (!empty($aRelated)) {
                // Renfe publica una línea por sentido: el mismo código sale dos veces.
                $aCodes = array_values(array_unique(array_column($aRelated, 'code')));
                $sList = implode(', ', array_slice($aCodes, 0, 8));
                if (count($aCodes) > 8) {
                    $sList .= '…';
                }
                $sDescription .= $PageText('lines', ['list' => $sList]);
            }
            if (!empty($aNetwork['live'])) {
                $sDescription .= $PageText('live');
            }
            $aStopTypes = ['bus' => 'BusStop', 'metro' => 'SubwayStation', 'train' => 'TrainStation', 'tram' => 'TrainStation'];
            $aPlace = [
                '@type' => $aStopTypes[$aNetwork['kind']] ?? 'Place',
                'name' => $aRecord['name'],
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)$aRecord['lat'], 'longitude' => (float)$aRecord['lon']],
            ];
            if (($aRecord['area'] ?? '') !== '') {
                $aPlace['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $aRecord['area'], 'addressRegion' => 'Euskadi', 'addressCountry' => 'ES'];
            }
            $sBreadcrumbName = $aRecord['name'];
            $sRelatedHeading = $PageText('linesAt', ['stop' => $aRecord['name']]);
            $sRelatedType = 'lines';
        } else {
            $aLineVars = ['code' => $aRecord['code'], 'name' => $aRecord['name'], 'app' => $sAppName];
            $sTitle = $PageText('lineTitle', $aLineVars);
            $sDescription = $PageText('lineDescription', $aLineVars);
            if (Networks::feature($aNetwork, 'line_map') && Networks::hasLive($aNetwork, 'vehicle-monitoring')) {
                $sDescription .= $PageText('busMap');
            } elseif (Networks::feature($aNetwork, 'line_map') && Networks::hasLive($aNetwork, 'vehicle-positions')) {
                $sDescription .= $PageText('trainMap');
            }
            $aRelated = (new LineModel(Database::connection()))->stopsByName($aRecord['id']);
            $sBreadcrumbName = $PageText('line', ['code' => $aRecord['code']]);
            $sRelatedHeading = $PageText('stopsOf', ['code' => $aRecord['code']]);
            $sRelatedType = 'stops';
        }
        $sCanonical .= Networks::urlSuffix($sNetworkSlug);
    }
} catch (\Throwable $Ex) {
    // Sin base de datos la página se sirve igual, con los textos de la red.
    error_log('Bide+ página: ' . $Ex->getMessage());
}

if ($bNotFound) {
    http_response_code(404);
    readfile(__DIR__ . '/../../404.html');
    exit;
}

// La canónica en el idioma de la página; la castellana es la de referencia de las otras dos (hreflang).
$sCanonicalEs = $sCanonical;
$sCanonical = Lang::url($sCanonicalEs, $sLang);
$sAppUrl = Lang::url($aSite['url'] . '/?red=' . $sNetworkSlug, $sLang);
$aWebSite = ['@type' => 'WebSite', 'name' => $aSite['name'], 'url' => $aSite['url'] . '/'];
$aApp = [
    '@type' => 'SoftwareApplication',
    'name' => $aAppNames[$sNetworkSlug],
    'description' => $sDescription,
    'url' => $sCanonical,
    'image' => $sImage,
    'applicationCategory' => 'TravelApplication',
    'operatingSystem' => 'Web, Android, iOS',
    'inLanguage' => $sLang,
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    'author' => ['@type' => 'Person', 'name' => $aSite['author'], 'url' => $aSite['author_url']],
    'isPartOf' => $aWebSite,
];
$aJsonLd = ['@context' => 'https://schema.org', '@graph' => [$aApp]];
if ($sBreadcrumbName !== null) {
    $aApp['url'] = $sAppUrl;
    $aApp['description'] = $PageText('appDescription', ['app' => $aAppNames[$sNetworkSlug]]);
    $aWebPage = ['@type' => 'WebPage', 'name' => $sTitle, 'description' => $sDescription, 'url' => $sCanonical, 'inLanguage' => $sLang, 'isPartOf' => $aWebSite];
    if ($aPlace !== null) {
        $aWebPage['about'] = $aPlace;
    }
    $aJsonLd['@graph'] = [
        $aWebPage,
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => $aSite['name'], 'item' => $aSite['url'] . '/'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $aAppNames[$sNetworkSlug], 'item' => $sAppUrl],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $sBreadcrumbName, 'item' => $sCanonical],
        ]],
        $aApp,
    ];
}
