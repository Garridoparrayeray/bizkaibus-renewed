<?php
// Datos de la página de una app: título, descripción, imagen, URL canónica y JSON-LD. En /stops/{id} y /lines/{id}
// salen de la parada o la línea, con sus enlaces relacionados para quien no tiene JavaScript. Lo usa app.php.

use Core\Config;
use Core\Database;
use Core\Networks;
use Models\LineModel;
use Models\Stop;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';

$sNetworkSlug = Networks::fromQuery($_GET['red'] ?? null);
$aNetwork = Networks::get($sNetworkSlug);
$aAppNames = array_map(fn($aOne) => Networks::text($aOne, 'name'), Networks::all());

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
            $sTitle = $aRecord['name'] . ' · Horarios y próximas salidas | ' . $sAppName;
            $sDescription = 'Próximas salidas y horarios de ' . $sAppName . ' en ' . $sStopLabel . '.';
            if (!empty($aRelated)) {
                // Renfe publica una línea por sentido: el mismo código sale dos veces.
                $aCodes = array_values(array_unique(array_column($aRelated, 'code')));
                $sDescription .= ' Líneas: ' . implode(', ', array_slice($aCodes, 0, 8));
                if (count($aCodes) > 8) {
                    $sDescription .= '…';
                }
                $sDescription .= '.';
            }
            if (!empty($aNetwork['live'])) {
                $sDescription .= ' Llegadas en tiempo real.';
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
            $sRelatedHeading = 'Líneas que paran en ' . $aRecord['name'];
            $sRelatedType = 'lines';
        } else {
            $sTitle = 'Línea ' . $aRecord['code'] . ' ' . $aRecord['name'] . ' · Horarios | ' . $sAppName;
            $sDescription = 'Horarios, recorrido y paradas de la línea ' . $aRecord['code'] . ' (' . $aRecord['name'] . ') de ' . $sAppName . '.';
            if (Networks::feature($aNetwork, 'line_map') && Networks::hasLive($aNetwork, 'vehicle-monitoring')) {
                $sDescription .= ' Mapa con los autobuses en tiempo real.';
            }
            $aRelated = (new LineModel(Database::connection()))->stopsByName($aRecord['id']);
            $sBreadcrumbName = 'Línea ' . $aRecord['code'];
            $sRelatedHeading = 'Paradas de la línea ' . $aRecord['code'];
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

$sAppUrl = $aSite['url'] . '/?red=' . $sNetworkSlug;
$aWebSite = ['@type' => 'WebSite', 'name' => $aSite['name'], 'url' => $aSite['url'] . '/'];
$aApp = [
    '@type' => 'SoftwareApplication',
    'name' => $aAppNames[$sNetworkSlug],
    'description' => $sDescription,
    'url' => $sCanonical,
    'image' => $sImage,
    'applicationCategory' => 'TravelApplication',
    'operatingSystem' => 'Web, Android, iOS',
    'inLanguage' => 'es',
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    'author' => ['@type' => 'Person', 'name' => $aSite['author'], 'url' => $aSite['author_url']],
    'isPartOf' => $aWebSite,
];
$aJsonLd = ['@context' => 'https://schema.org', '@graph' => [$aApp]];
if ($sBreadcrumbName !== null) {
    $aApp['url'] = $sAppUrl;
    $aApp['description'] = 'Horarios de ' . $aAppNames[$sNetworkSlug] . '.';
    $aWebPage = ['@type' => 'WebPage', 'name' => $sTitle, 'description' => $sDescription, 'url' => $sCanonical, 'inLanguage' => 'es', 'isPartOf' => $aWebSite];
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
