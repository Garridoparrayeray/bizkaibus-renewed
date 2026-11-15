<?php
// Datos de la página de una app: título, descripción, imagen, URL canónica, JSON-LD y el contenido que pinta el
// servidor (#page-content): en /stops/{id} y /lines/{id} el de la parada o la línea; en /?red=, sus líneas.

use Core\Config;
use Core\Database;
use Core\DisplayName;
use Core\Lang;
use Core\Networks;
use Core\Seo;
use Models\LineModel;
use Models\Stop;
use Services\Calendar;
use Services\StopDaySchedule;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';

// Recorridos (uno por destino) que salen en la página de una línea.
const LINE_PAGE_ROUTES = 4;
// Las líneas que se nombran en la descripción de una parada.
const STOP_DESCRIPTION_LINES = 8;

$sNetworkSlug = Networks::fromQuery($_GET['red'] ?? null);
$aNetwork = Networks::get($sNetworkSlug);
$sLang = Lang::current();
$aAppNames = array_map(fn($aOne) => Networks::text($aOne, 'name', $sLang), Networks::all());
$sOperator = $aNetwork['seo']['operator'];
$Name = fn(string $sName): string => DisplayName::of($sName, $aNetwork);
// Un enlace de la app en el idioma de la página: /stops/{id} o /lines/{id}, con ?red= si no es la red por defecto.
$AppLink = fn(string $sType, string $sId): string => Lang::url('/' . $sType . '/' . rawurlencode($sId) . Networks::urlSuffix($sNetworkSlug), $sLang);

$sSiteUrl = $aSite['url'];
$aTitles = [];
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
    foreach (Lang::LANGS as $sTextLang) {
        $aTitles[$sTextLang] = $aNetwork['seo']['title'];
        if ($sTextLang !== 'es') {
            $sGeneric = Networks::text($aNetwork, 'name', $sTextLang) . ' · ' . Networks::text($aNetwork, 'switcher', $sTextLang);
            $aTitles[$sTextLang] = Seo::title([$sGeneric . ' | Bide+', $sGeneric]);
        }
    }
    $sTitle = $aTitles[$sLang];
    $sDescription = $aNetwork['seo']['description'];
    if ($sLang !== 'es') {
        $sDescription = Lang::t('seo.networkDescription', $sLang, ['switcher' => Networks::text($aNetwork, 'switcher', $sLang)]);
    }
    // Al compartir cualquier página (parada, línea, red), la vista previa es siempre la imagen de Bide+.
    $sImage = $aSite['url'] . '/icons-bide-rojo/og-image.png';
    $iImageWidth = 1200;
    $iImageHeight = 630;
    $sImageAlt = 'Bide+: bus, metro, tren y tranvía de Euskal Herria en un solo sitio';
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

// Uno de los recorridos de map.splits de la ficha (Metro publica L1 y L2 como una sola línea), o null.
function appPageSplit(array $aNetwork, string $sLineId, string $sCode): array|null
{
    foreach ($aNetwork['map']['splits'] ?? [] as $aSplit) {
        if ($aSplit['code'] === $sCode && (string)$aSplit['line'] === $sLineId) {
            return $aSplit;
        }
    }
    return null;
}

// El contenido que pinta el servidor: lo ven los buscadores (que no llegan a /api/) y quien no tiene JavaScript. Cada
// apartado es un título y sus enlaces; los del horario de hoy llevan además sus horas.
$aContent = ['type' => 'network', 'id' => $sNetworkSlug, 'heading' => null, 'sections' => [], 'empty' => null];
$sBreadcrumbName = null;
$aPlace = null;
$bNoIndex = $bMiamorDomain;
$bNotFound = false;
try {
    if (preg_match('#^/(stops|lines)/([^/]+)(?:/([^/]+))?$#', $sPath, $aMatches)) {
        $sType = $aMatches[1];
        $sId = urldecode($aMatches[2]);
        $aRecord = appPageRecord($sNetworkSlug, $sType, $sId);
        // Un enlace sin ?red= a una parada o línea de otra red lleva a esa red.
        if ($aRecord === null && !isset($_GET['red'])) {
            foreach (array_diff(Networks::slugs(), [$sNetworkSlug]) as $sOtherSlug) {
                if (appPageRecord($sOtherSlug, $sType, $sId) !== null) {
                    header('Location: ' . Lang::url('/' . $sType . '/' . $aMatches[2] . '?red=' . $sOtherSlug, $sLang), true, 301);
                    exit;
                }
            }
        }
        $aSplit = null;
        if ($aRecord !== null && isset($aMatches[3])) {
            $aSplit = appPageSplit($aNetwork, (string)$aRecord['id'], urldecode($aMatches[3]));
        }

        $Pdo = Database::connection();
        if ($aRecord === null || ($sType === 'stops' && isset($aMatches[3])) || (isset($aMatches[3]) && $aSplit === null)) {
            $bNotFound = true;
        } elseif ($sType === 'stops') {
            $sStopName = $Name($aRecord['name']);
            $sStopLabel = $sStopName;
            if (($aRecord['area'] ?? '') !== '') {
                $sStopLabel .= ' (' . Seo::municipality($Name($aRecord['area'])) . ')';
            }
            $aServing = (new Stop($Pdo))->linesServing($aRecord['id']);
            foreach (Lang::LANGS as $sTextLang) {
                $sKeyword = Lang::t('seo.stopTitle', $sTextLang, ['stop' => $sStopLabel, 'operator' => $sOperator]);
                $sShort = Lang::t('seo.stopTitle', $sTextLang, ['stop' => $sStopName, 'operator' => $sOperator]);
                $aTitles[$sTextLang] = Seo::title([$sKeyword . ' | Bide+', $sKeyword, $sShort]);
            }
            $sTitle = $aTitles[$sLang];
            // Renfe publica una línea por sentido: el mismo código sale dos veces.
            $aCodes = array_values(array_unique(array_column($aServing, 'code')));
            $sList = implode(', ', array_slice($aCodes, 0, STOP_DESCRIPTION_LINES));
            if (count($aCodes) > STOP_DESCRIPTION_LINES) {
                $sList .= '…';
            }
            $aSentences = [Lang::t('seo.stopDescription', $sLang, ['operator' => $sOperator, 'place' => $sStopLabel])];
            if (!empty($aCodes)) {
                $aSentences[] = Lang::t('seo.stopLines', $sLang, ['list' => $sList]);
            }
            if (!empty($aNetwork['live'])) {
                $aSentences[] = Lang::t('seo.live', $sLang);
            }
            $sDescription = Seo::description($aSentences, [Lang::t('seo.stopMore', $sLang), Lang::t('seo.stopMoreShort', $sLang)]);
            $aStopTypes = ['bus' => 'BusStop', 'metro' => 'SubwayStation', 'train' => 'TrainStation', 'tram' => 'TrainStation'];
            $aPlace = [
                '@type' => $aStopTypes[$aNetwork['kind']] ?? 'Place',
                'name' => $sStopName,
                'geo' => ['@type' => 'GeoCoordinates', 'latitude' => (float)$aRecord['lat'], 'longitude' => (float)$aRecord['lon']],
            ];
            if (($aRecord['area'] ?? '') !== '') {
                $aPlace['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $Name($aRecord['area']), 'addressRegion' => 'Euskadi', 'addressCountry' => 'ES'];
            }
            $sBreadcrumbName = $sStopName;
            // Sin ninguna línea, la parada no tiene servicio ni nada que leer: fuera del índice (y del sitemap).
            $bNoIndex = $bNoIndex || empty($aServing);

            $aContent = ['type' => 'stop', 'id' => (string)$aRecord['id'], 'heading' => $sStopLabel . ' · ' . $sOperator, 'sections' => [], 'empty' => null];
            $aLinks = [];
            foreach ($aServing as $aLine) {
                $aLinks[] = ['href' => $AppLink('lines', (string)$aLine['id']), 'label' => $aLine['code'] . ' · ' . $Name($aLine['name'])];
            }
            if (!empty($aLinks)) {
                $aContent['sections'][] = ['title' => Lang::t('seo.linesAt', $sLang, ['stop' => $sStopName]), 'items' => $aLinks];
            }
            $aSchedule = [];
            foreach (StopDaySchedule::today($Pdo, (string)$aRecord['id']) as $aGroup) {
                $sLabel = Lang::t('seo.lineTowards', $sLang, ['code' => $aGroup['code'], 'headsign' => $Name($aGroup['headsign'])]);
                $aSchedule[] = ['href' => $AppLink('lines', (string)$aGroup['lineId']), 'label' => $sLabel, 'times' => implode(' · ', $aGroup['times'])];
            }
            $sScheduleTitle = Lang::t('seo.scheduleToday', $sLang, ['date' => Calendar::todayMadrid()->format('d/m/Y')]);
            $aContent['sections'][] = ['title' => $sScheduleTitle, 'items' => $aSchedule];
            if (empty($aSchedule)) {
                $aContent['empty'] = Lang::t('seo.noDepartures', $sLang);
            }
        } else {
            $sLineCode = $aRecord['code'];
            $sLineName = $Name($aRecord['name']);
            $aEnds = null;
            if ($aSplit !== null) {
                $sLineCode = $aSplit['code'];
                $sLineName = $aSplit['name'];
                $aEnds = $aSplit['ends'];
            }
            $aLineVars = ['code' => $sLineCode, 'name' => $sLineName, 'operator' => $sOperator];
            foreach (Lang::LANGS as $sTextLang) {
                $sKeyword = Lang::t('seo.lineTitle', $sTextLang, $aLineVars);
                $aTitles[$sTextLang] = Seo::title([$sKeyword . ' | Bide+', $sKeyword, Lang::t('seo.lineTitleShort', $sTextLang, $aLineVars)]);
            }
            $sTitle = $aTitles[$sLang];
            $aSentences = [Lang::t('seo.lineDescription', $sLang, $aLineVars)];
            if (Networks::feature($aNetwork, 'line_map') && Networks::hasLive($aNetwork, 'vehicle-monitoring')) {
                $aSentences[] = Lang::t('seo.busMap', $sLang);
            } elseif (Networks::feature($aNetwork, 'line_map') && Networks::hasLive($aNetwork, 'vehicle-positions')) {
                $aSentences[] = Lang::t('seo.trainMap', $sLang);
            }
            $sDescription = Seo::description($aSentences, [Lang::t('seo.lineMore', $sLang), Lang::t('seo.lineMoreShort', $sLang)]);
            $sBreadcrumbName = Lang::t('seo.line', $sLang, ['code' => $sLineCode]);

            $aContent = ['type' => 'line', 'id' => (string)$aRecord['id'], 'heading' => Lang::t('seo.lineHeading', $sLang, $aLineVars), 'sections' => [], 'empty' => null];
            foreach ((new LineModel($Pdo))->mainRoutes($aRecord['id'], LINE_PAGE_ROUTES, $aEnds) as $aRoute) {
                $aLinks = [];
                foreach ($aRoute['stops'] as $aStop) {
                    $aLinks[] = ['href' => $AppLink('stops', (string)$aStop['id']), 'label' => $Name($aStop['name'])];
                }
                $aContent['sections'][] = ['title' => Lang::t('seo.towards', $sLang, ['headsign' => $Name($aRoute['headsign'])]), 'items' => $aLinks, 'ordered' => true];
            }
            // La línea que el operador publica junta (Metro): enlaces a la página de cada una de las suyas.
            $aSplitLinks = [];
            foreach ($aNetwork['map']['splits'] ?? [] as $aOther) {
                if ((string)$aOther['line'] === (string)$aRecord['id'] && $aOther !== $aSplit) {
                    $sHref = Lang::url('/lines/' . rawurlencode((string)$aRecord['id']) . '/' . rawurlencode($aOther['code']) . Networks::urlSuffix($sNetworkSlug), $sLang);
                    $aSplitLinks[] = ['href' => $sHref, 'label' => $aOther['code'] . ' · ' . $aOther['name']];
                }
            }
            if (!empty($aSplitLinks)) {
                $aContent['sections'][] = ['title' => Lang::t('seo.linesOf', $sLang, ['operator' => $sOperator]), 'items' => $aSplitLinks];
            }
        }
        $sCanonical .= Networks::urlSuffix($sNetworkSlug);
    } elseif (!$bMiamorDomain) {
        // La página de la red: todas sus líneas, con su enlace.
        Config::set($sNetworkSlug);
        $aLinks = [];
        foreach ((new LineModel(Database::connection()))->all() as $aLine) {
            $aLinks[] = ['href' => $AppLink('lines', (string)$aLine['id']), 'label' => $aLine['code'] . ' · ' . $Name($aLine['name'])];
        }
        foreach ($aNetwork['map']['splits'] ?? [] as $aSplit) {
            $sHref = Lang::url('/lines/' . rawurlencode((string)$aSplit['line']) . '/' . rawurlencode($aSplit['code']) . Networks::urlSuffix($sNetworkSlug), $sLang);
            $aLinks[] = ['href' => $sHref, 'label' => $aSplit['code'] . ' · ' . $aSplit['name']];
        }
        $aContent['sections'][] = ['title' => Lang::t('seo.linesOf', $sLang, ['operator' => $sOperator]), 'items' => $aLinks];
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
    $aApp['description'] = Lang::t('seo.appDescription', $sLang, ['app' => $aAppNames[$sNetworkSlug]]);
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
