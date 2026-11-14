<?php
// El planificador (/planificador) como una página más de la app: su cabecera, tema, idioma y pie, y dentro las
// pantallas del motor tal cual (planificador/pantallas, que pone el despliegue). Del contenedor leen las direcciones
// de paradas y líneas y «Tus redes» por defecto.
use Core\Lang;
use Core\Networks;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';
$sLang = Lang::current();
$aTitles = [
    'es' => 'Cómo llegar en transporte público por Euskal Herria | Bide+',
    'eu' => 'Nola iritsi garraio publikoan Euskal Herrian zehar | Bide+',
    'en' => 'Journey planner for public transport in the Basque Country | Bide+',
    'fr' => 'Itinéraires en transports en commun au Pays basque | Bide+',
];
$aDescriptions = [
    'es' => 'Dices de dónde sales y a dónde vas, y Bide+ te propone el viaje mezclando bus, metro, tren y tranvía de todas las redes de Euskal Herria, con cada transbordo y qué hacer si pierdes uno.',
    'eu' => 'Esan nondik irten eta nora zoazen, eta Bide+-k bidaia proposatuko dizu Euskal Herriko sare guztietako autobus, metro, tren eta tranbiak nahastuz, aldaketa bakoitzarekin eta bat galtzen baduzu zer egin.',
    'en' => 'Say where you are leaving from and where you are going, and Bide+ plans the trip mixing bus, metro, train and tram across every Basque network, with each change and what to do if you miss one.',
    'fr' => 'Indiquez votre départ et votre destination : Bide+ vous propose le trajet en combinant bus, métro, train et tramway de tous les réseaux d’Euskal Herria, avec chaque correspondance.',
];
$sTitle = $aTitles[$sLang];
$sDescription = $aDescriptions[$sLang];
$sCanonicalEs = $aSite['url'] . '/planificador';
$sCanonical = Lang::url($sCanonicalEs, $sLang);
$sOgImage = $aSite['url'] . '/icons-bide-rojo/og-image.png';
$aJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $sTitle,
    'description' => $sDescription,
    'url' => $sCanonical,
    'inLanguage' => $sLang,
    'isPartOf' => ['@type' => 'WebSite', 'name' => $aSite['name'], 'url' => $aSite['url'] . '/'],
];
// Las mismas direcciones que el resto de la app ({network}, {stop} y {line} los rellenan las pantallas).
$sStopUrl = '/stops/{stop}?red={network}';
$sLineUrl = '/lines/{line}?red={network}';
?>
<!DOCTYPE html>
<html lang="<?= $sLang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($sTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($sCanonical) ?>">
    <?= Lang::alternates($sCanonicalEs) ?>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($aSite['name']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($sCanonical) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($sTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($sOgImage) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="manifest" href="/manifest-bide.json">
    <link rel="icon" href="/icons-bide-rojo/bide-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons-bide-rojo/apple-touch-icon.png">
    <link rel="preload" href="/fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/css/style-bide.css">
    <link rel="stylesheet" href="/css/style-map.css">
    <link rel="stylesheet" href="/css/style-networks.css">
    <link rel="stylesheet" href="/planificador/pantallas/base.css">
    <link rel="stylesheet" href="/planificador/pantallas/home.css">
    <link rel="stylesheet" href="/planificador/pantallas/options.css">
    <link rel="stylesheet" href="/planificador/pantallas/trip.css">
    <link rel="stylesheet" href="/planificador/pantallas/sheet.css">
    <link rel="stylesheet" href="/css/style-planner.css">
    <link rel="stylesheet" href="/css/style-miamor.css">
    <script src="/js/theme.js"></script>
    <script src="/js/i18n.js"></script>
    <script type="module" src="/planificador/pantallas/app.js"></script>
    <script src="/js/sw-register.js" defer></script>
    <script type="application/ld+json"><?= json_encode($aJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="page-planner" data-screen="home" data-state="loading">
    <header class="map-top">
        <a class="map-home" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><?= BIDE_ICONS['back'] ?><?= $sWordmark ?></a>
        <h1 data-i18n="plan.title">Cómo llegar</h1>
    </header>
    <main class="layout planner-main" id="main" tabindex="-1" data-planner-screens data-stop-url="<?= htmlspecialchars($sStopUrl) ?>" data-line-url="<?= htmlspecialchars($sLineUrl) ?>" data-home-networks="<?= htmlspecialchars(implode(',', Networks::defaultFavorites())) ?>">
        <noscript><p class="planner-noscript" data-i18n="plan.noscript">El planificador calcula el viaje en este aparato y necesita JavaScript. Las paradas, líneas y horarios de cada red también están en sus apps de Bide+.</p></noscript>
    </main>
    <footer class="planner-page-foot">
        <span class="bide-wordmark">BIDE<span>+</span></span>
        <p data-i18n="menu.footer">Datos abiertos del operador de cada red (las fuentes y sus licencias, en Info). Proyecto independiente y no oficial, sin relación con los operadores ni con las administraciones que publican los datos.</p>
    </footer>
</body>
</html>
