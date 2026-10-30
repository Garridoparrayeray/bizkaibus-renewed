<?php
$site = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$wordmark = require __DIR__ . '/wordmark.php';
$title = 'Mapa de la red · Todas las líneas de transporte de Euskadi | Bide+';
$description = 'Mapa con todas las líneas de Metro Bilbao, Bizkaibus, Euskotren, Renfe Cercanías, Lurraldebus y los tranvías de Bilbao y Vitoria, con sus paradas, transbordos y el esquema de cada línea.';
$canonical = $site['url'] . '/mapa';
$ogImage = $site['url'] . '/icons-bide-rojo/og-image.png';
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => $title,
    'description' => $description,
    'url' => $canonical,
    'inLanguage' => 'es',
    'isPartOf' => ['@type' => 'WebSite', 'name' => $site['name'], 'url' => $site['url'] . '/'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($site['name']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
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
    <link rel="stylesheet" href="/style-bide.css">
    <link rel="stylesheet" href="/style-map.css">
    <link rel="stylesheet" href="/style-miamor.css">
    <script src="/js/theme.js"></script>
    <script src="/js/i18n.js"></script>
    <script src="/lib/leaflet/leaflet.js" defer></script>
    <script src="/js/network-map.js" defer></script>
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="page-map">
    <header class="map-top">
        <a class="map-home" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><?= BIDE_ICONS['back'] ?><?= $wordmark ?></a>
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
