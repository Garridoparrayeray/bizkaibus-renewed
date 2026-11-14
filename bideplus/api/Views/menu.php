<?php
use Core\Lang;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sLang = Lang::current();
// Lo que se busca: horarios y tiempo real, los nombres de las redes y el territorio (Euskadi, Navarra, Iparralde).
$aTitles = [
    'es' => 'Bide+ · Horarios y tiempo real de Bizkaibus, Metro, Renfe y más en Euskadi',
    'eu' => 'Bide+ · Bizkaibus, Metroa, Renfe eta gehiago: ordutegiak eta denbora errealean Euskadin',
    'en' => 'Bide+ · Bizkaibus, Metro Bilbao, Renfe and more: Basque Country timetables and real time',
    'fr' => 'Bide+ · Horaires et temps réel au Pays basque : Txik Txak, SNCF, Bizkaibus, Metro Bilbao…',
];
$aDescriptions = [
    'es' => 'Horarios y tiempo real del transporte público de Euskal Herria (Euskadi, Navarra e Iparralde): Bizkaibus, Metro Bilbao, Euskotren, Renfe, Bilbobus, Lurraldebus, Dbus, Tuvisa, Álavabus, La Unión, Villavesas, Txik Txak, SNCF y los tranvías de Bilbao y Vitoria, en una sola app gratis.',
    'eu' => 'Euskal Herriko garraio publikoaren ordutegiak eta denbora errealean (Euskadi, Nafarroa eta Iparraldea): Bizkaibus, Bilboko Metroa, Euskotren, Renfe, Bilbobus, Lurraldebus, Dbus, Tuvisa, Álavabus, La Unión, Villavesak, Txik Txak, SNCF eta Bilbo eta Gasteizko tranbiak, doako aplikazio bakarrean.',
    'en' => 'Timetables and real time for public transport across the Basque Country (Euskadi, Navarre and the Northern Basque Country): Bizkaibus, Metro Bilbao, Euskotren, Renfe, Bilbobus, Lurraldebus, Dbus, Tuvisa, Álavabus, La Unión, Villavesas, Txik Txak, SNCF and the Bilbao and Vitoria trams, in one free app.',
    'fr' => 'Horaires et temps réel des transports en commun d’Euskal Herria (Pays basque nord, Euskadi et Navarre) : Txik Txak, SNCF, Bizkaibus, Metro Bilbao, Euskotren, Renfe, Dbus, Lurraldebus, Villavesas et les tramways de Bilbao et Vitoria-Gasteiz, dans une seule application gratuite.',
];
$aKeywords = [
    'es' => 'horarios autobús, tiempo real, Bizkaibus, Metro Bilbao, Euskotren, Renfe Cercanías, Bilbobus, Dbus, Tuvisa, Lurraldebus, Álavabus, Villavesas, Txik Txak, SNCF, transporte público, Euskal Herria, Euskadi, País Vasco, Navarra, Iparralde, Bilbao, Donostia, San Sebastián, Vitoria-Gasteiz, Pamplona, Bayona',
    'eu' => 'autobus ordutegiak, denbora errealean, Bizkaibus, Bilboko Metroa, Euskotren, Renfe Aldiriak, Bilbobus, Dbus, Tuvisa, Lurraldebus, Álavabus, Villavesak, Txik Txak, SNCF, garraio publikoa, Euskal Herria, Euskadi, Nafarroa, Iparraldea, Bilbo, Donostia, Gasteiz, Iruñea, Baiona',
    'en' => 'bus timetables, real time, Bizkaibus, Metro Bilbao, Euskotren, Renfe Cercanías, Bilbobus, Dbus, Tuvisa, Lurraldebus, Álavabus, Villavesas, Txik Txak, SNCF, public transport, Basque Country, Euskal Herria, Euskadi, Navarre, Bilbao, San Sebastián, Vitoria-Gasteiz, Pamplona, Bayonne, Biarritz',
    'fr' => 'horaires bus, temps réel, Txik Txak, SNCF, TER, Bizkaibus, Metro Bilbao, Euskotren, Renfe, Dbus, Lurraldebus, Villavesas, transports en commun, Pays basque, Euskal Herria, Iparralde, Euskadi, Navarre, Bayonne, Biarritz, Anglet, Saint-Jean-de-Luz, Hendaye, Bilbao, Saint-Sébastien, Pampelune',
];
$sTitle = $aTitles[$sLang];
$sDescription = $aDescriptions[$sLang];
$sCanonicalEs = $aSite['url'] . '/';
$sCanonical = Lang::url($sCanonicalEs, $sLang);
$sOgImage = $aSite['url'] . '/icons-bide-rojo/og-image.png';
$sOgAlt = 'Bide+: bus, metro, tren y tranvía de Euskal Herria en un solo sitio';
$sWordmark = require __DIR__ . '/wordmark.php';

$aApps = [];
foreach (\Core\Networks::all() as $sSlug => $aNetwork) {
    $aApps[] = ['name' => \Core\Networks::text($aNetwork, 'name', $sLang), 'description' => \Core\Networks::text($aNetwork, 'switcher', $sLang), 'url' => Lang::url($aSite['url'] . '/?red=' . $sSlug, $sLang)];
}

$aAuthor = ['@type' => 'Person', 'name' => $aSite['author'], 'url' => $aSite['author_url']];
$aItems = [];
foreach ($aApps as $i => $aApp) {
    $aItems[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'item' => [
            '@type' => 'SoftwareApplication',
            'name' => $aApp['name'],
            'description' => $aApp['description'],
            'url' => $aApp['url'],
            'applicationCategory' => 'TravelApplication',
            'operatingSystem' => 'Web, Android, iOS',
            'inLanguage' => $sLang,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
            'author' => $aAuthor,
        ],
    ];
}
$aJsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            '@id' => $sCanonical . '#website',
            'name' => $aSite['name'],
            'url' => $sCanonical,
            'description' => $sDescription,
            'inLanguage' => $sLang,
            'publisher' => $aAuthor,
            'keywords' => $aKeywords[$sLang],
            'availableLanguage' => Lang::LANGS,
            'spatialCoverage' => [
                ['@type' => 'Place', 'name' => 'Euskal Herria'],
                ['@type' => 'AdministrativeArea', 'name' => 'Euskadi'],
                ['@type' => 'AdministrativeArea', 'name' => 'Navarra'],
                ['@type' => 'Place', 'name' => 'Iparralde'],
            ],
            'sameAs' => [$aSite['instagram_url']],
        ],
        [
            '@type' => 'ItemList',
            'name' => Lang::t('menu.section', $sLang),
            'itemListElement' => $aItems,
        ],
        bideFaqJsonLd($sLang),
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= $sLang ?>" data-splash="first" data-new-days="<?= \Core\NewBadge::days() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($sTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($aKeywords[$sLang]) ?>">
    <meta name="author" content="<?= htmlspecialchars($aSite['author']) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="google-site-verification" content="<?= htmlspecialchars($aSite['gsc_verification']) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($sCanonical) ?>">
    <?= Lang::alternates($sCanonicalEs) ?>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($aSite['name']) ?>">
    <meta property="og:locale" content="<?= Lang::LOCALES[$sLang] ?>">
    <meta property="og:url" content="<?= htmlspecialchars($sCanonical) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($sTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($sOgImage) ?>">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="<?= htmlspecialchars($sOgAlt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($sTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($sOgImage) ?>">
    <meta name="twitter:image:alt" content="<?= htmlspecialchars($sOgAlt) ?>">

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
    <link rel="stylesheet" href="/css/style-splash.css">
    <script src="/js/splash.js"></script>
    <script src="/js/menu-view.js"></script>
    <script src="/js/theme.js"></script>
    <script src="/js/bide-mode.js"></script>
    <script src="/js/notification-open.js"></script>
    <script src="/js/i18n.js"></script>
    <script src="/js/linkify.js"></script>
    <script defer src="/js/notice.js"></script>
    <script src="/js/networks.js"></script>
    <link rel="stylesheet" href="/css/style-menu.css">
    <link rel="stylesheet" href="/css/style-bide.css">
    <link rel="stylesheet" href="/css/style-networks.css">
    <link rel="stylesheet" href="/css/style-miamor.css">
    <script type="application/ld+json"><?= json_encode($aJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="page-menu">
    <div class="splash" aria-hidden="true"><?= $sWordmark ?></div>
    <main>
<?= bideRail(null) ?>

        <header>
            <div class="menu-brand">
                <h1><?= $sWordmark ?></h1>
                <p data-i18n="menu.tagline">Movilidad en Euskal Herria</p>
            </div>
            <div class="header-controls">
                <button type="button" id="lang-toggle" class="lang-toggle" data-i18n="menu.lang.toggle" hidden>Euskaraz</button>
                <?= bideLangSwitch() ?>
                <div class="view-toggle bide-seg" role="group" aria-label="Forma de mostrar las apps">
                    <button type="button" data-view-btn="tiles" aria-pressed="true" data-i18n-attr="aria-label:menu.view.tiles,title:menu.view.tilesTitle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>
                    </button>
                    <button type="button" data-view-btn="list" aria-pressed="false" data-i18n-attr="aria-label:menu.view.list,title:menu.view.listTitle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><line x1="9" y1="7" x2="20" y2="7"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="17" x2="20" y2="17"/><circle cx="4.5" cy="7" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="17" r="1"/></svg>
                    </button>
                </div>
                <?= bideMenuButton() ?>
            </div>
        </header>

        <form class="planner" action="/planificador" method="get" aria-labelledby="planner-title" data-planner-card>
            <div class="planner-head">
                <h2 id="planner-title" data-i18n="menu.planner.title">¿A dónde vas?</h2>
            </div>
            <div class="planner-body">
                <div class="planner-places">
                    <label class="planner-place">
                        <span class="planner-dot planner-dot--from" aria-hidden="true"></span>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.fromLabel">Origen</span>
                        <input class="planner-input" name="de_texto" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="next" maxlength="120" data-planner-from placeholder="Tu ubicación">
                    </label>
                    <label class="planner-place planner-place--to">
                        <span class="planner-dot planner-dot--to" aria-hidden="true"></span>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.toLabel">Destino</span>
                        <input class="planner-input" name="a_texto" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go" maxlength="120" data-planner-to placeholder="Destino: parada, calle o sitio">
                    </label>
                    <input type="hidden" name="a" value="ubicacion" data-planner-here disabled>
                    <button type="button" class="planner-swap" data-planner-swap data-i18n-attr="aria-label:menu.planner.swap" aria-label="Intercambiar origen y destino"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 4V20M12 8L8 4L4 8M16 20V4M20 16L16 20L12 16"/></svg></button>
                </div>
                <fieldset class="planner-when">
                    <legend class="bide-visually-hidden" data-i18n="menu.planner.when">Cuándo</legend>
                    <label><input type="radio" name="cuando" value="ahora" checked><span data-i18n="menu.planner.now">Ahora</span></label>
                    <label><input type="radio" name="cuando" value="salir"><span data-i18n="menu.planner.leave">Salir a</span></label>
                    <label><input type="radio" name="cuando" value="llegar"><span data-i18n="menu.planner.arrive">Llegar a</span></label>
                </fieldset>
                <div class="planner-time" data-planner-time hidden>
                    <label>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.day">Día</span>
                        <select name="dia" data-planner-day><option value="" data-i18n="menu.planner.today">Hoy</option></select>
                    </label>
                    <label>
                        <span class="bide-visually-hidden" data-i18n="menu.planner.time">Hora</span>
                        <input type="time" name="hora" step="60" data-planner-hour>
                    </label>
                </div>
                <button class="planner-go" type="submit" data-i18n="menu.planner.search">Buscar ruta</button>
            </div>
        </form>
        <script src="/js/menu-planner.js"></script>

        <section id="tr" class="menu-apps" data-i18n-attr="aria-label:menu.section">
            <div class="nets-head">
                <h2 data-i18n="menu.nets.title">Tus redes</h2>
                <button type="button" id="nets-edit" class="nets-edit" aria-pressed="false" data-i18n="menu.nets.edit">Editar</button>
            </div>
            <p id="nets-hint" class="nets-hint" data-i18n="menu.nets.hint" hidden>Toca «Añadir» en las redes que uses. Con «Quitar» dejan de salir aquí arriba.</p>
            <ul class="tiles" id="tiles-fav" data-default="<?= implode(',', \Core\Networks::defaultFavorites()) ?>">
<?php foreach (\Core\Networks::all() as $sSlug => $aNetwork):
    $sName = \Core\Networks::text($aNetwork, 'name');
    $sIcon = '/' . $aNetwork['brand']['icons'] . '/icon-192.png'; ?>
                <li data-scope="<?= htmlspecialchars($aNetwork['scope'] ?? 'main') ?>">
                    <a class="tile tile--<?= $sSlug ?>" data-app="<?= $sSlug ?>" data-name="<?= htmlspecialchars($sName) ?>" href="/?red=<?= $sSlug ?>">
                        <svg class="go" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="7" y1="17" x2="17" y2="7"/><polyline points="8 7 17 7 17 16"/></svg>
                        <img class="mark" src="<?= $sIcon ?>" alt="<?= htmlspecialchars($sName) ?>" aria-hidden="true">
                        <img class="appicon" src="<?= $sIcon ?>" alt="<?= htmlspecialchars($sName) ?>" aria-hidden="true" width="56" height="56">
                        <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 6 15 12 9 18"/></svg>
<?php if (\Core\Networks::isNew($aNetwork)): ?>
                        <span class="tile-new" data-i18n="bide.new">Nuevo</span>
<?php endif; ?>
<?php if (!empty($aNetwork['texts']['es']['tile_city'])): ?>
                        <span class="txt"><strong><span data-i18n="net.<?= $sSlug ?>.tile_title"><?= htmlspecialchars($aNetwork['texts']['es']['tile_title']) ?></span><span>+</span></strong><span class="tile-city" data-i18n="net.<?= $sSlug ?>.tile_city"><?= htmlspecialchars($aNetwork['texts']['es']['tile_city']) ?></span><span data-i18n="net.<?= $sSlug ?>.tile"><?= htmlspecialchars(\Core\Networks::text($aNetwork, 'tile')) ?></span><span class="last" data-i18n="app.last">Última que abriste</span></span>
<?php else: ?>
                        <span class="txt"><strong data-i18n="net.<?= $sSlug ?>.name"><?= htmlspecialchars($sName) ?></strong><span data-i18n="net.<?= $sSlug ?>.tile"><?= htmlspecialchars(\Core\Networks::text($aNetwork, 'tile')) ?></span><span class="last" data-i18n="app.last">Última que abriste</span></span>
<?php endif; ?>
                    </a>
                    <button type="button" class="tile-pin" data-pin="<?= $sSlug ?>" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" aria-hidden="true"><line x1="6" y1="12" x2="18" y2="12"/><line class="tile-pin-plus" x1="12" y1="6" x2="12" y2="18"/></svg><span class="tile-pin-text"></span></button>
                </li>
<?php endforeach; ?>
            </ul>
            <p id="nets-empty" class="nets-empty" data-i18n="menu.nets.empty" hidden>Aún no tienes redes. Pulsa Editar y añade las que uses.</p>
            <button type="button" id="nets-all" class="nets-all" aria-expanded="false" aria-controls="tiles-rest" hidden>
                <span><strong data-i18n="menu.nets.all">Todas las redes</strong><span id="nets-all-count"></span></span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9L12 15L18 9"/></svg>
            </button>
            <ul class="tiles" id="tiles-rest" hidden></ul>
            <button type="button" id="nets-local" class="nets-all nets-local" aria-expanded="false" aria-controls="tiles-local" disabled>
                <span><strong data-i18n="menu.nets.local">Redes locales</strong><span data-i18n="menu.nets.localText">Los autobuses urbanos de tu pueblo</span></span>
                <span class="planner-soon" id="nets-local-soon" data-i18n="bide.upcoming.title">Próximamente</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9L12 15L18 9"/></svg>
            </button>
            <ul class="tiles" id="tiles-local" hidden></ul>
            <div class="menu-links">
                <a class="menu-map-link" href="/mapa"><?= BIDE_ICONS['mapa'] ?><span><strong data-i18n="map.title">Mapa de la red</strong><span data-i18n="map.subtitle">Todas las líneas, sus paradas y transbordos</span></span><?= BIDE_ICONS['next'] ?></a>
                <a class="menu-map-link" href="/estado"><?= BIDE_ICONS['estado'] ?><span><strong data-i18n="status.title">Estado de las redes</strong><span data-i18n="status.subtitle">Qué va en directo ahora en cada red</span></span><?= BIDE_ICONS['next'] ?></a>
            </div>
            <?= bideDonateLink($aSite['donate_url'] ?? '', 'bide-button bide-donate-button menu-donate', BIDE_ICONS['cafe'] . '<span data-i18n="bide.donate.button">Invitar a un café en Ko-fi</span>') ?>
        </section>
        <div id="nets-toast" class="nets-toast" role="status" aria-live="polite" hidden>
            <span id="nets-toast-text"></span>
            <button type="button" id="nets-toast-undo" data-i18n="menu.nets.undo">Deshacer</button>
        </div>
        <script src="/js/menu-favorites.js"></script>

<?= bideViews() ?>
<?= bideMenuSheet() ?>

<?= bideInstallBanner() ?>

    </main>
    <footer class="menu-footer">
        <div class="bide-footer-brand">
            <span class="bide-wordmark">BIDE<span>+</span></span>
            <p data-i18n="menu.footer">Datos abiertos del operador de cada red (las fuentes y sus licencias, en Info). Proyecto independiente y no oficial, sin relación con los operadores ni con las administraciones que publican los datos.</p>
        </div>
        <?= bideAppsLinks() ?>
        <?= bideFooterAuthor('<button type="button" class="bide-footer-legal" data-bide-legal data-i18n="app.legalNotice">Aviso legal y privacidad</button>') ?>
    </footer>
<?= bideTabbar() ?>
<?php require __DIR__ . '/legal.php'; ?>
    <script src="/js/menu.js" defer></script>
    <script src="/js/sw-register.js" defer></script>
    <script src="/js/menu-lang.js"></script>
    <script src="/js/api.js"></script>
    <script src="/js/alerts-store.js"></script>
    <script src="/js/bide.js"></script>
    <script src="/js/new-badge.js"></script>
    <script src="/js/offline.js"></script>
    <script src="/js/bide-info.js"></script>
    <script type="module" src="/js/settings.js"></script>
</body>
</html>
