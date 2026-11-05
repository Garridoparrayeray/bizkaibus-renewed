<?php
use Core\Lang;
use Core\Networks;

$aSite = require __DIR__ . '/../Config/site.php';
require_once __DIR__ . '/bide-components.php';
$sWordmark = require __DIR__ . '/wordmark.php';
$sLang = Lang::current();
$aTitles = [
    'es' => 'Estado del transporte en Euskadi, en directo | Bide+',
    'eu' => 'Euskadiko garraioaren egoera, zuzenean | Bide+',
    'en' => 'Basque Country public transport status, live | Bide+',
];
$aDescriptions = [
    'es' => 'Cuántos trenes, metros, tranvías y autobuses van en directo ahora en cada red de Euskadi, cuántos van en hora y cuánto acierta Bide+ frente al horario.',
    'eu' => 'Zenbat tren, metro, tranbia eta autobus dabiltzan zuzenean orain Euskadiko sare bakoitzean, zenbat doazen orduan eta Bide+-k ordutegiak baino zenbat gehiago asmatzen duen.',
    'en' => 'How many trains, metros, trams and buses are live right now on each Basque network, how many are on time and how much better Bide+ does than the timetable.',
];
$sTitle = $aTitles[$sLang];
$sDescription = $aDescriptions[$sLang];
$sCanonicalEs = $aSite['url'] . '/estado';
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
$aPrecision = [
    ['range' => '0–3', 'bide' => 96, 'timetable' => 24],
    ['range' => '3–6', 'bide' => 90, 'timetable' => 24],
    ['range' => '6–10', 'bide' => 82, 'timetable' => 23],
    ['range' => '10–20', 'bide' => 67, 'timetable' => 25],
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
    <link rel="stylesheet" href="/css/style-bide.css">
    <link rel="stylesheet" href="/css/style-map.css">
    <link rel="stylesheet" href="/css/style-networks.css">
    <link rel="stylesheet" href="/css/style-estado.css">
    <link rel="stylesheet" href="/css/style-miamor.css">
    <script src="/js/theme.js"></script>
    <script src="/js/i18n.js"></script>
    <script src="/js/estado.js" defer></script>
    <script type="application/ld+json"><?= json_encode($aJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="page-status">
    <header class="map-top">
        <a class="map-home" href="/" data-i18n-attr="aria-label:bide.home" aria-label="Bide+, todas las apps"><?= BIDE_ICONS['back'] ?><?= $sWordmark ?></a>
        <h1 data-i18n="status.title">Estado de las redes</h1>
    </header>

    <main class="status-main">
        <section class="status-intro">
            <h2 data-i18n="status.heading">Ahora en las redes</h2>
            <p id="status-summary" data-i18n="status.loading">Preguntando a cada red…</p>
        </section>

        <ul class="status-grid">
<?php foreach (Networks::all() as $sSlug => $aNetwork):
    $sIcon = '/' . $aNetwork['brand']['icons'] . '/icon-192.png';
    $sName = htmlspecialchars(Networks::text($aNetwork, 'name')); ?>
            <li>
                <a class="status-tile tile--<?= $sSlug ?>" href="/?red=<?= $sSlug ?>" data-red="<?= $sSlug ?>" data-kind="<?= htmlspecialchars($aNetwork['kind'] ?? 'bus') ?>">
                    <img class="status-mark" src="<?= $sIcon ?>" alt="<?= $sName ?>" aria-hidden="true">
                    <span class="status-tile-top">
                        <img class="status-icon" src="<?= $sIcon ?>" alt="<?= $sName ?>" aria-hidden="true" width="44" height="44">
                        <span class="status-alerts" hidden></span>
                    </span>
                    <span class="status-name"><?= $sName ?></span>
                    <span class="status-count"><strong>·</strong><span></span></span>
                </a>
            </li>
<?php endforeach; ?>
        </ul>

        <section class="status-precision" aria-labelledby="status-precision-title">
            <h2 id="status-precision-title" data-i18n="status.precision.title">Bide+ acierta 4 veces más que el horario</h2>
            <p class="status-lead" data-i18n="status.precision.lead">Llegadas de Bizkaibus que caen a menos de 2 minutos del paso real del autobús, según lo lejos que esté.</p>
            <div class="status-legend">
                <span><i class="is-bide"></i>Bide+</span>
                <span><i class="is-timetable"></i><span data-i18n="status.precision.timetable">Horario publicado</span></span>
            </div>
            <div class="status-chart" role="table" data-i18n-attr="aria-label:status.precision.aria" aria-label="Acierto a ±2 minutos según lo lejos que esté el autobús">
<?php foreach ($aPrecision as $aRow): ?>
                <div class="status-chart-row" role="row">
                    <span class="status-chart-label" role="rowheader"><?= $aRow['range'] ?> min</span>
                    <div class="status-chart-bars" role="cell">
                        <div class="status-bar is-bide" data-value="<?= $aRow['bide'] ?>"><span><?= $aRow['bide'] ?> %</span></div>
                        <div class="status-bar is-timetable" data-value="<?= $aRow['timetable'] ?>"><span><?= $aRow['timetable'] ?> %</span></div>
                    </div>
                </div>
<?php endforeach; ?>
            </div>
            <p class="status-source" data-i18n="status.precision.source">2,37 millones de llegadas en 17 días de octubre de 2026, cada una contrastada con el paso real del autobús por la parada.</p>
        </section>

        <p class="status-foot" data-i18n="status.foot">Con los datos abiertos de cada operador, cada minuto. Bide+ es un proyecto independiente, sin relación con los operadores.</p>
    </main>
</body>
</html>
