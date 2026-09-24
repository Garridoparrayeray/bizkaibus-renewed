<?php
$site = require __DIR__ . '/../Config/site.php';
$title = 'Bide+ · Tu red de transporte público';
$description = 'Consulta los horarios y el tiempo real del transporte público desde un único menú. Elige tu app y sal de casa sin esperas.';
$canonical = $site['url'] . '/';
$ogImage = $site['url'] . '/icons-bide-rojo/og-image.png';
$ogAlt = 'Logo de Bide+ con los iconos de Bizkaibus+, Metro+ y Euskotren+';
$wordmark = require __DIR__ . '/wordmark.php';

$apps = [
    ['name' => 'Bizkaibus+', 'description' => 'Horarios y tiempo real de Bizkaibus', 'url' => $site['url'] . '/?red=bus'],
    ['name' => 'Metro+', 'description' => 'Horarios de Metro Bilbao', 'url' => $site['url'] . '/?red=metro'],
    ['name' => 'Euskotren+', 'description' => 'Horarios de Euskotren', 'url' => $site['url'] . '/?red=euskotren'],
    ['name' => 'Tranvía Bilbao+', 'description' => 'Horarios del tranvía de Bilbao', 'url' => $site['url'] . '/?red=tranvia-bilbao'],
    ['name' => 'Tranvía Vitoria+', 'description' => 'Horarios del tranvía de Vitoria-Gasteiz', 'url' => $site['url'] . '/?red=tranvia-vitoria'],
];

$author = ['@type' => 'Person', 'name' => $site['author'], 'url' => $site['author_url']];
$items = [];
foreach ($apps as $i => $app) {
    $items[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'item' => [
            '@type' => 'SoftwareApplication',
            'name' => $app['name'],
            'description' => $app['description'],
            'url' => $app['url'],
            'applicationCategory' => 'TravelApplication',
            'operatingSystem' => 'Web, Android, iOS',
            'inLanguage' => 'es',
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'author' => $author,
        ],
    ];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $canonical . '#website',
            'name' => $site['name'],
            'url' => $canonical,
            'description' => $description,
            'inLanguage' => 'es',
            'publisher' => $author,
        ],
        [
            '@type' => 'ItemList',
            'name' => 'Apps de movilidad de Bide+',
            'itemListElement' => $items,
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="es" data-splash="first">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="author" content="<?= htmlspecialchars($site['author']) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($site['name']) ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($site['locale']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonical) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($ogAlt) ?>">

    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Bide+">
    <meta name="theme-color" content="#FFFFFF">
    <link rel="manifest" href="/manifest-bide.json">
    <link rel="icon" href="/icons-bide-rojo/bide-icon.svg" type="image/svg+xml">
    <link rel="icon" href="/icons-bide-rojo/icon-192.png" sizes="192x192" type="image/png">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/icons-bide-rojo/apple-touch-icon.png">

    <link rel="preload" href="/fonts/hanken-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/style-splash.css">
    <script src="/js/splash.js"></script>
    <script src="/js/menu-view.js"></script>
    <link rel="stylesheet" href="/style-menu.css">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body>
    <div class="splash" aria-hidden="true"><?= $wordmark ?></div>
    <main>
        <header>
            <h1><?= $wordmark ?></h1>
            <div class="view-toggle" role="group" aria-label="Forma de mostrar las apps">
                <button type="button" data-view-btn="tiles" aria-pressed="true" aria-label="Ver como fichas grandes" title="Fichas grandes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>
                </button>
                <button type="button" data-view-btn="list" aria-pressed="false" aria-label="Ver como lista compacta" title="Lista compacta">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="9" y1="7" x2="20" y2="7"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="17" x2="20" y2="17"/><circle cx="4.5" cy="7" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="17" r="1"/></svg>
                </button>
            </div>
            <p>Movilidad en Euskadi</p>
        </header>

        <section id="tr" aria-label="Apps de movilidad">
            <ul class="tiles">
                <li>
                    <a class="tile tile--bus" data-app="bus" data-name="Bizkaibus+" href="/?red=bus">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="/icons-pro/icon-192.png" alt="">
                        <img class="appicon" src="/icons-pro/icon-192.png" alt="" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                        <span class="txt"><strong>Bizkaibus+</strong><span>Horarios y tiempo real</span><span class="last">Última que abriste</span></span>
                    </a>
                </li>
                <li>
                    <a class="tile tile--metro" data-app="metro" data-name="Metro+" href="/?red=metro">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="/icons-metro/icon-192.png" alt="">
                        <img class="appicon" src="/icons-metro/icon-192.png" alt="" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                        <span class="txt"><strong>Metro+</strong><span>Metro Bilbao</span><span class="last">Última que abriste</span></span>
                    </a>
                </li>
                <li>
                    <a class="tile tile--euskotren" data-app="euskotren" data-name="Euskotren+" href="/?red=euskotren">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="/icons-euskotren/icon-192.png" alt="">
                        <img class="appicon" src="/icons-euskotren/icon-192.png" alt="" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                        <span class="txt"><strong>Euskotren+</strong><span>Tren con horarios y tiempo real</span><span class="last">Última que abriste</span></span>
                    </a>
                </li>
                <li>
                    <div class="tile soon" aria-disabled="true">
                        <span class="glyph" aria-hidden="true">R</span>
                        <span class="txt"><strong>Renfe Cercanías+</strong><span>Próximamente</span></span>
                    </div>
                </li>
                <li>
                    <div class="tile soon" aria-disabled="true">
                        <span class="glyph" aria-hidden="true">L</span>
                        <span class="txt"><strong>Lurraldebus+</strong><span>Próximamente</span></span>
                    </div>
                </li>
                <li>
                    <a class="tile tile--tranvia-bilbao" data-app="tranvia-bilbao" data-name="Tranvía Bilbao+" href="/?red=tranvia-bilbao">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="/icons-tranvia-bilbao/icon-192.png" alt="">
                        <img class="appicon" src="/icons-tranvia-bilbao/icon-192.png" alt="" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                        <span class="txt"><strong>Tranvía<span class="tile-mark">+</span></strong><span class="tile-city">Bilbao</span><span>Horarios del tranvía</span><span class="last">Última que abriste</span></span>
                    </a>
                </li>
                <li>
                    <a class="tile tile--tranvia-vitoria" data-app="tranvia-vitoria" data-name="Tranvía Vitoria+" href="/?red=tranvia-vitoria">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="/icons-tranvia-vitoria/icon-192.png" alt="">
                        <img class="appicon" src="/icons-tranvia-vitoria/icon-192.png" alt="" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
                        <span class="txt"><strong>Tranvía<span class="tile-mark">+</span></strong><span class="tile-city">Vitoria</span><span>Horarios del tranvía</span><span class="last">Última que abriste</span></span>
                    </a>
                </li>
            </ul>
        </section>


        <footer>Proyecto independiente de Yeray Garrido. Horarios a partir de los datos abiertos de Euskadi y Metro Bilbao.</footer>
    </main>
    <script src="/js/menu.js" defer></script>
</body>
</html>
