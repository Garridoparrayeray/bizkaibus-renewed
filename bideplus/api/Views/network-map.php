<?php
use Core\Lang;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';
$sLang = Lang::current();
$aTitles = [
    'es' => 'Mapa de la red · Todas las líneas de transporte de Euskadi | Bide+',
    'eu' => 'Sarearen mapa · Euskadiko garraio-linea guztiak | Bide+',
    'en' => 'Network map · Every public transport line in the Basque Country | Bide+',
    'fr' => 'Plan du réseau · Toutes les lignes de transport du Pays basque | Bide+',
];
$aDescriptions = [
    'es' => 'Mapa del transporte público de Euskal Herria: todas las líneas de Metro Bilbao, Bizkaibus, Euskotren, Renfe, Lurraldebus, Txik Txak, Villavesas y los tranvías de Bilbao y Vitoria, con sus paradas, transbordos y el esquema de cada línea.',
    'eu' => 'Euskal Herriko garraio publikoaren mapa: Bilboko Metroa, Bizkaibus, Euskotren, Renfe, Lurraldebus, Txik Txak, Villavesak eta Bilbo eta Gasteizko tranbien linea guztiak, geltokiekin, loturekin eta linea bakoitzaren eskemarekin.',
    'en' => 'Public transport map of the Basque Country: every line of Metro Bilbao, Bizkaibus, Euskotren, Renfe, Lurraldebus, Txik Txak, Villavesas and the Bilbao and Vitoria trams, with their stops, transfers and each line\'s diagram.',
    'fr' => 'Plan des transports en commun d’Euskal Herria : toutes les lignes de Txik Txak, Metro Bilbao, Bizkaibus, Euskotren, Renfe, Lurraldebus, Villavesas et des tramways de Bilbao et Vitoria-Gasteiz, avec leurs arrêts, correspondances et le schéma de chaque ligne.',
];
$sTitle = $aTitles[$sLang];
$sDescription = $aDescriptions[$sLang];
$sCanonicalEs = $aSite['url'] . '/mapa';
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
    <link rel="stylesheet" href="/lib/leaflet/leaflet.css">
    <link rel="stylesheet" href="/css/style-bide.css">
    <link rel="stylesheet" href="/css/style-map.css">
    <link rel="stylesheet" href="/css/style-networks.css">
    <link rel="stylesheet" href="/css/style-miamor.css">
    <script src="/js/theme.js"></script>
    <script src="/js/i18n.js"></script>
    <script src="/lib/leaflet/leaflet.js" defer></script>
    <script src="/js/planner-link.js" defer></script>
    <script src="/js/network-map.js" defer></script>
    <script type="application/ld+json"><?= json_encode($aJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="page-map">
    <header class="map-top">
        <a class="map-home" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><?= BIDE_ICONS['back'] ?><?= $sWordmark ?></a>
        <h1 data-i18n="map.title">Mapa de la red</h1>
    </header>
    <nav class="map-filters" id="map-filters" data-i18n-attr="aria-label:map.filters" aria-label="Redes que se ven en el mapa"></nav>
    <div class="map-search-bar" id="map-search-bar">
        <div class="map-search-box">
            <svg class="map-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input id="map-search-input" class="map-search-input" type="search" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="map-search-results" placeholder="Buscar línea o parada…" data-i18n-attr="placeholder:map.search.placeholder,aria-label:map.search.placeholder" aria-label="Buscar línea o parada">
            <button id="map-search-clear" type="button" class="map-search-clear" aria-label="Limpiar búsqueda" data-i18n-attr="aria-label:map.search.clear" hidden>
                <?= BIDE_ICONS['close'] ?>
            </button>
        </div>
        <select id="map-line" hidden aria-hidden="true" tabindex="-1"></select>
        <ul id="map-search-results" class="map-search-results" role="listbox" hidden></ul>
    </div>
    <main class="map-main">
        <div id="network-map" class="network-map" role="application" data-i18n-attr="aria-label:map.title" aria-label="Mapa de la red"></div>
        <p class="map-loading" id="map-loading" data-i18n="map.loading">Cargando líneas…</p>
        <p class="map-hint" id="map-hint" data-i18n="map.empty.hint">Elige una red arriba o busca una línea o parada.</p>
        <aside class="map-panel" id="map-panel" hidden>
            <button type="button" class="map-panel-close" id="map-panel-close" data-i18n-attr="aria-label:map.close" aria-label="Cerrar"><?= BIDE_ICONS['close'] ?></button>
            <div class="map-panel-body" id="map-panel-body"></div>
        </aside>
    </main>
    <noscript><p class="map-noscript">Todas las líneas de Metro Bilbao, Bizkaibus, Euskotren, Renfe Cercanías, Lurraldebus y los tranvías de Bilbao y Vitoria en un mapa, con sus paradas y transbordos. Para verlo hay que activar JavaScript; las líneas de cada red también están en sus apps de <a href="/">Bide+</a>.</p></noscript>
</body>
</html>
