<?php
// Plantilla de las apps de cada red. Los datos de la página (título, descripción, JSON-LD) vienen de app-page.php.

use Core\Networks;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <base href="/">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($aAppNames[$sNetworkSlug]) ?>">
    <title><?= htmlspecialchars($sTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta name="author" content="<?= htmlspecialchars($aSite['author']) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="google-site-verification" content="<?= htmlspecialchars($aSite['gsc_verification']) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($sCanonical) ?>">
    <script type="application/ld+json"><?= json_encode($aJsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($aSite['name']) ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($aSite['locale']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($sCanonical) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($sTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($sImage) ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="<?= htmlspecialchars($sImageAlt) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($sTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($sDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($sImage) ?>">
    <link rel="icon" href="/<?= $sFaviconFolder ?>/icon-192.png">
    <link rel="apple-touch-icon" href="/<?= $sFaviconFolder ?>/apple-touch-icon.png">
    <link rel="preload" href="/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/style-splash.css">
    <script src="/js/splash.js"></script>
    <script src="/js/i18n.js"></script>
    <script src="/js/networks.js"></script>
    <link rel="stylesheet" href="/lib/leaflet/leaflet.css">
    <script src="/js/theme.js"></script>
    <script src="/js/boot.js"></script>
    <script src="/js/bide-mode.js"></script>
    <link rel="stylesheet" href="/style-bide.css">
    <link rel="stylesheet" href="/style-miamor.css">
</head>
<body class="page-app">
    <div class="splash" aria-hidden="true"><?= $sWordmark ?></div>

    <main class="app-container">

        <div id="net-banner" class="net-banner" role="status" hidden>
            <span data-i18n="app.offline.banner">Sin conexión con el servidor. Puede que veas datos guardados.</span>
            <button id="net-retry" type="button" data-i18n="app.retry">Reintentar</button>
        </div>

        <noscript>
            <h2><?= htmlspecialchars($sTitle) ?></h2>
            <p><?= htmlspecialchars($sDescription) ?></p>
<?php if ($sRelatedHeading !== null && !empty($aRelated)): ?>
            <h3><?= htmlspecialchars($sRelatedHeading) ?></h3>
            <ul>
<?php foreach ($aRelated as $aRow):
    $sRelatedLabel = $aRow['name'];
    if ($sRelatedType === 'lines') {
        $sRelatedLabel = $aRow['code'] . ' ' . $aRow['name'];
    }
?>
                <li><a href="/<?= $sRelatedType ?>/<?= rawurlencode((string)$aRow['id']) ?><?= Networks::urlSuffix($sNetworkSlug) ?>"><?= htmlspecialchars($sRelatedLabel) ?></a></li>
<?php endforeach; ?>
            </ul>
<?php endif; ?>
            <p><a href="/">Todas las apps de Bide+</a> · Para consultar los horarios en directo, activa JavaScript.</p>
        </noscript>

<?= bideRail($sNetworkSlug) ?>

        <header>
            <div class="home-link-wrap">
                <button id="home-link" class="home-link" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="network-switcher">
                    <span id="app-logomark" class="app-logomark" aria-hidden="true"><?= $aNetwork['brand']['logomark'] ?></span>
                    <hgroup>
                        <h1 id="app-title"><?= htmlspecialchars($aNetwork['brand']['title'][0]) ?><span id="app-title-mark" class="app-title-mark">+</span></h1>
                        <span id="app-title-city" class="app-title-city"><?= htmlspecialchars($aNetwork['brand']['title'][1]) ?></span>
                        <p id="app-subtitle" data-i18n="net.<?= $sNetworkSlug ?>.subtitle"><?= htmlspecialchars(Networks::text($aNetwork, 'subtitle')) ?></p>
                    </hgroup>
                </button>

                <nav id="network-switcher" class="network-switcher" aria-label="Cambiar red de transporte">
                    <?php foreach (Networks::all() as $sOtherSlug => $aOther): if ($sOtherSlug === $sNetworkSlug) continue; ?>
                    <a class="net-card" id="net-card-<?= $sOtherSlug ?>" href="/?red=<?= $sOtherSlug ?>">
                        <span class="net-card-logomark" aria-hidden="true"><?= $aOther['brand']['card_logomark'] ?></span>
                        <span class="net-card-text">
                            <strong data-i18n="net.<?= $sOtherSlug ?>.name"><?= htmlspecialchars(Networks::text($aOther, 'name')) ?></strong>
                            <span data-i18n="net.<?= $sOtherSlug ?>.switcher"><?= htmlspecialchars(Networks::text($aOther, 'switcher')) ?></span>
                        </span>
                    </a>
<?php endforeach; ?>
                    <a class="net-card" id="net-card-menu" href="/">
                        <span class="net-card-logomark" aria-hidden="true">
                            <img class="is-full" src="/icons-bide-rojo/icon-192.png" alt="">
                        </span>
                        <span class="net-card-text">
                            <strong>Bide+</strong>
                            <span data-i18n="switcher.allApps">Todas las apps</span>
                        </span>
                    </a>
                </nav>

                <script src="/js/header-brand.js"></script>
            </div>

            <span class="header-actions">
                <?= bideLangSwitch() ?>
                <button id="lang-toggle" class="btn-icon btn-lang lang-toggle" type="button" aria-label="Aldatu hizkuntza / Cambiar idioma">EU</button>
                <button id="menu-open" class="btn-icon menu-open" type="button" aria-label="Abrir menú">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
                </button>
            </span>
        </header>

        <div class="layout">
            <aside class="sidebar">
            <form id="search-form" class="search-form" autocomplete="off">
                <input id="search-input" class="search-input" type="search" data-i18n-attr="aria-label:app.search.placeholder,placeholder:app.search.placeholder" aria-label="Buscar parada, línea o destino" placeholder="Buscar parada, línea o destino..." minlength="2">
                <button type="submit" aria-label="Buscar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <ul id="search-results" class="search-results" hidden></ul>
            </form>
            <button id="nearby-btn" class="nearby-btn" type="button">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7.5"/><line x1="12" y1="1.5" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22.5" y2="12"/></svg>
                <span data-i18n="app.nearby">Cerca de mí</span>
            </button>
                <div id="favorites-panel" class="glass favorites-panel">
                    <header>
                        <h2 data-i18n="app.favorites.title">Tus favoritos</h2>
                        <button id="favorites-close" class="btn-icon favorites-close" type="button" aria-label="Cerrar favoritos">
                            <?= BIDE_ICONS['close'] ?>
                        </button>
                    </header>
                    <ul id="favorites-list" class="favorites-list">
                        <li id="favorites-empty" class="favorites-empty" data-i18n="app.favorites.empty">Busca una parada o línea y guárdala para verla aquí.</li>
                    </ul>
                </div>

            </aside>

            <div class="main">
                <article id="live-card" class="live-card glass" hidden>
                    <header>
                        <span id="live-badge" class="badge">Solo horario</span>
                        <span class="header-actions">
                            <button id="live-favorite" class="btn-icon" type="button" aria-label="Guardar parada en favoritos"></button>
                            <button id="live-close" class="btn-icon" type="button" aria-label="Cerrar">
                                <?= BIDE_ICONS['close'] ?>
                            </button>
                        </span>
                    </header>
                    <h3 id="live-line" class="live-line"></h3>
                    <p id="live-headsign" class="live-headsign"></p>
                    <button id="live-open-detail" class="time-display" type="button" aria-label="Ver detalle del trayecto">
                        <strong id="live-minutes">–</strong><span>min</span><sub id="live-delay" class="delay-sub" hidden></sub>
                    </button>
                    <button id="live-timetable-link" type="button" class="platform-timetable-link live-timetable-link" data-i18n="app.fullSchedule">Ver horario completo</button>
                    <footer>
                        <p>
                            <span id="live-status-dot" class="status-dot"></span><span id="live-status-text"></span>
                            <button id="live-incidents-link" class="live-incidents-link" type="button" hidden data-i18n="app.incidents">Incidencias</button>
                        </p>
                    </footer>
                    <ul id="live-more-list" class="live-more-list"></ul>
                </article>

                <div id="platform-panel" class="platform-panel" hidden>
                    <header>
                        <h3 id="platform-panel-stop" class="platform-panel-stop"></h3>
                        <span class="header-actions">
                            <button id="platform-favorite" class="btn-icon" type="button" aria-label="Guardar estación en favoritos"></button>
                            <button id="platform-close" class="btn-icon" type="button" aria-label="Cerrar">
                                <?= BIDE_ICONS['close'] ?>
                            </button>
                        </span>
                    </header>
                    <button id="platform-timetable-link" type="button" class="platform-timetable-link" data-i18n="app.fullSchedule">Ver horario completo</button>
                    <div id="platform-timetable-lines" class="platform-timetable-lines" hidden></div>
                    <p id="platform-notice" class="platform-notice" role="status" hidden></p>
                    <div id="platform-columns" class="platform-columns" aria-live="polite"></div>
                </div>
                <p id="live-empty" class="live-empty" role="status" data-i18n="net.<?= $sNetworkSlug ?>.liveEmpty"><?= htmlspecialchars(Networks::text($aNetwork, 'liveEmpty')) ?></p>

            </div>

            <div class="detail">
        <section id="timetable-section" class="timetable glass timetable-section" hidden>
            <header>
                <h2 data-i18n="app.checkSchedules">Consultar Horarios</h2>
                <span class="header-actions">
                    <button id="timetable-favorite" class="btn-icon" type="button" aria-label="Guardar línea en favoritos"></button>
                    <button id="timetable-close" class="btn-icon" type="button" aria-label="Cerrar">
                        <?= BIDE_ICONS['close'] ?>
                    </button>
                </span>
            </header>
            <p id="timetable-line" class="timetable-line"></p>

            <div id="map-search-bar" class="map-search-bar">
                <div class="map-search-box">
                    <svg class="map-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input id="map-search-input" class="map-search-input" type="search" autocomplete="off" placeholder="Buscar línea para ver en el mapa…" data-i18n-attr="placeholder:map.search.placeholder" aria-label="Buscar línea para ver en el mapa">
                    <button id="map-search-clear" type="button" class="btn-icon map-search-clear" aria-label="Limpiar búsqueda" hidden>
                        <?= BIDE_ICONS['close'] ?>
                    </button>
                </div>
                <ul id="map-search-results" class="map-search-results" hidden></ul>
                <div id="map-active-chips" class="map-active-chips"></div>
                <button id="map-clear-all" type="button" class="btn-sm map-clear-btn map-clear-all" hidden data-i18n="map.clearAll">Quitar todas</button>
            </div>

            <div id="line-map" class="line-map"></div>
            <p id="line-map-empty" class="line-map-empty" data-i18n="app.noLiveBuses">No hay autobuses de esta línea circulando ahora mismo.</p>

            <div class="filters">
                <input id="filter-date" class="filter-date" type="date" aria-label="Fecha">
                <input id="filter-hour-from" type="time" aria-label="Hora desde">
                <input id="filter-hour-to" type="time" aria-label="Hora hasta">
            </div>

            <p id="timetable-note" class="timetable-note" role="status" hidden></p>
            <p id="timetable-range" class="timetable-range" hidden></p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col" data-i18n="app.departure">Salida</th>
                            <th scope="col" data-i18n="app.destination">Destino</th>
                            <th scope="col" data-i18n="app.state">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="timetable-body"></tbody>
                </table>
            </div>
            <p id="timetable-empty" class="timetable-empty" hidden data-i18n="app.noDepartures">No hay salidas programadas en ese rango.</p>

            <button id="schedule-text-toggle" class="schedule-text-toggle" type="button" data-i18n="app.officialSchedule2026">Ver horario oficial 2026</button>
        </section>
                <p id="detail-empty" class="detail-empty" data-i18n="detail.empty">Elige una línea para ver aquí su mapa y su horario.</p>
            </div>
        </div>

<?= bideViews() ?>

        <p id="attribution" class="attribution"><?= htmlspecialchars($aNetwork['attribution']) ?></p>
        <p id="disclaimer" class="disclaimer"><?= htmlspecialchars(Networks::text($aNetwork, 'disclaimer')) ?></p>
    </main>

<?= bideInstallBanner() ?>

    <footer id="app-footer" class="app-footer">
        <div class="bide-footer-brand">
            <span class="bide-wordmark">BIDE<span>+</span></span>
            <p data-i18n="bide.footer.text">Horarios y tiempo real del transporte público de Euskadi. Proyecto independiente y no oficial, sin relación con los operadores ni con las administraciones que publican los datos.</p>
        </div>
        <?= bideAppsLinks() ?>
        <div class="bide-footer-author">
            <p class="bide-footer-title"><span data-i18n="app.madeBy">Hecho por</span> Yeray Garrido</p>
            <p class="bide-footer-links">
                <a href="https://www.linkedin.com/in/yeray-garrido" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                <a href="https://www.yeraygarrido.dev/" target="_blank" rel="noopener noreferrer">Portfolio</a>
                <a href="https://github.com/Garridoparrayeray/bizkaibus-renewed" target="_blank" rel="noopener noreferrer" data-i18n="info.source">Código abierto en GitHub</a>
                <button id="legal-open" class="legal-open" type="button" data-i18n="app.legalNotice">Aviso legal y privacidad</button>
            </p>
        </div>
    </footer>

<?= bideTabbar() ?>

<?php require __DIR__ . '/legal.php'; ?>

    <dialog id="schedule-modal" class="schedule-modal">
        <button id="schedule-modal-close" class="btn-icon schedule-modal-close" type="button" aria-label="Cerrar">
            <?= BIDE_ICONS['close'] ?>
        </button>
        <h3 id="schedule-modal-line" class="schedule-modal-line"></h3>
        <div id="schedule-modal-content"></div>
    </dialog>

    <dialog id="vehicle-modal" class="vehicle-modal">
        <button id="modal-close" class="btn-icon modal-close" type="button" aria-label="Cerrar">
            <?= BIDE_ICONS['close'] ?>
        </button>
        <span id="modal-badge" class="badge"></span>
        <h3 id="modal-line" class="modal-line"></h3>
        <p id="modal-headsign" class="modal-headsign"></p>
        <p id="modal-vehicle" class="modal-vehicle"></p>
        <button id="modal-alerts-toggle" class="modal-alerts-toggle" type="button">Ver incidencias</button>
        <ul id="modal-alerts" class="modal-alerts" hidden></ul>
        <ol id="modal-stops" class="modal-stops"></ol>
    </dialog>

    <dialog id="side-menu" class="side-menu">
        <button id="menu-close" class="btn-icon menu-close" type="button" aria-label="Cerrar menú">
            <?= BIDE_ICONS['close'] ?>
        </button>
        <a class="pill bide-menu-apps" href="/"><?= BIDE_ICONS['apps'] ?><span data-i18n="bide.allApps">Todas las apps</span></a>
        <button id="menu-favorites-open" class="pill menu-favorites-open" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>
            <span data-i18n="app.favorites.title">Tus favoritos</span>
        </button>
        <h3 data-i18n="app.myLinesIncidents">Incidencias de mis líneas</h3>
        <label class="alerts-toggle"><input type="checkbox" id="alerts-toggle"><span data-i18n="app.notifyMe">Avisarme de las incidencias nuevas de mis líneas favoritas</span></label>
        <p id="alerts-note" class="alerts-note" role="status" hidden></p>
        <p id="menu-alerts-empty" class="menu-alerts-empty" data-i18n="app.noFavoriteIncidents">Guarda alguna línea en favoritos para ver aquí sus incidencias activas.</p>
        <ul id="menu-alerts-list" class="menu-alerts-list"></ul>
    </dialog>

    <script src="/lib/leaflet/leaflet.js"></script>
    <script src="js/api.js"></script>
    <script src="js/alerts-store.js"></script>
    <script src="js/app.js"></script>
    <script src="/js/bide.js"></script>
    <script src="/js/sw-register.js"></script>
    <script defer src="/_vercel/insights/script.js"></script>
</body>
</html>
