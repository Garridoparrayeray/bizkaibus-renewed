<?php

$menuPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (($menuPath === '/' || $menuPath === '') && !isset($_GET['red']) && !isset($_GET['tema'])) {
    require __DIR__ . '/Views/menu.php';
    exit;
}

$site = require __DIR__ . '/Config/site.php';
$wordmark = require __DIR__ . '/Views/wordmark.php';

$bIsMetroShare     = isset($_GET['red']) && $_GET['red'] === 'metro';
$bIsEuskoTrenShare = isset($_GET['red']) && $_GET['red'] === 'euskotren';

$networkSlug = 'bus';
if ($bIsMetroShare) {
    $sOgTitle       = 'Metro+ · Horarios de Metro Bilbao';
    $sOgDescription = 'Consulta los horarios de Metro Bilbao por línea y estación, con los avisos de servicio, sin vueltas.';
    $sOgImage       = $site['url'] . '/icons-metro/icon-512.png';
    $sFaviconFolder = 'icons-metro';
    $networkSlug    = 'metro';
} elseif ($bIsEuskoTrenShare) {
    $sOgTitle       = 'Euskotren+ · Horarios de Euskotren';
    $sOgDescription = 'Consulta los horarios de Euskotren, trenes y tranvía, por línea y estación, sin vueltas.';
    $sOgImage       = $site['url'] . '/icons-euskotren/icon-512.png';
    $sFaviconFolder = 'icons-euskotren';
    $networkSlug    = 'euskotren';
} else {
    $sOgTitle       = 'BizkaiBus+ · Horarios y tiempo real de Bizkaibus';
    $sOgDescription = 'Consulta los horarios, las líneas, las paradas y las llegadas en tiempo real de Bizkaibus, sin vueltas.';
    $sOgImage       = $site['url'] . '/icons-pro/icon-512.png';
    $sFaviconFolder = 'icons-pro';
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$ogUrl = $site['url'] . $path;
if ($path === '/' || $path === '') {
    $ogUrl = $site['url'] . '/?red=' . $networkSlug;
}
$ogAlt = 'Icono de ' . $sOgTitle;

function findRecord(string $network, string $type, string $id): ?array
{
    require_once __DIR__ . '/Core/Config.php';
    require_once __DIR__ . '/Core/Database.php';
    \Core\Config::set($network);
    $pdo = \Core\Database::connection();
    if ($type === 'stops') {
        $stmt = $pdo->prepare('SELECT * FROM stops WHERE id = ?');
    } else {
        $stmt = $pdo->prepare('SELECT code, name FROM lines WHERE id = ?');
    }
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return $row;
}

$isNotFound = false;
try {
    if (preg_match('#^/(stops|lines)/([^/]+)/?$#', $path, $matches)) {
        $recordType = $matches[1];
        $recordId = urldecode($matches[2]);
        $record = findRecord($networkSlug, $recordType, $recordId);

        if ($record === null && !isset($_GET['red'])) {
            foreach (['metro', 'euskotren'] as $otherNetwork) {
                if (findRecord($otherNetwork, $recordType, $recordId) !== null) {
                    header('Location: /' . $recordType . '/' . $matches[2] . '?red=' . $otherNetwork, true, 301);
                    exit;
                }
            }
        }

        if ($record === null) {
            $isNotFound = true;
        } elseif ($recordType === 'stops') {
            $sOgTitle = $record['name'] . ' - Próximas salidas';
            $desc = 'Consulta los horarios y tiempos de espera en ' . $record['name'] . '.';
            if (isset($record['stop_desc']) && $record['stop_desc'] !== '') {
                $desc .= ' (' . $record['stop_desc'] . ')';
            }
            $sOgDescription = $desc;
        } else {
            $sOgTitle = 'Línea ' . $record['code'] . ' - ' . $record['name'];
            $sOgDescription = 'Horarios y recorrido de la línea ' . $record['code'] . ' (' . $record['name'] . ').';
        }

        if ($networkSlug !== 'bus') {
            $ogUrl .= '?red=' . $networkSlug;
        }
    }
} catch (\Throwable $t) {
}

if ($isNotFound) {
    http_response_code(404);
    readfile(__DIR__ . '/../404.html');
    exit;
}

$appNames = ['bus' => 'BizkaiBus+', 'metro' => 'Metro+', 'euskotren' => 'Euskotren+'];
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => $appNames[$networkSlug],
    'description' => $sOgDescription,
    'url' => $ogUrl,
    'image' => $sOgImage,
    'applicationCategory' => 'TravelApplication',
    'operatingSystem' => 'Web, Android, iOS',
    'inLanguage' => 'es',
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
    'author' => ['@type' => 'Person', 'name' => $site['author'], 'url' => $site['author_url']],
    'isPartOf' => ['@type' => 'WebSite', 'name' => $site['name'], 'url' => $site['url'] . '/'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <base href="/">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BizkaiBus+">
    <title><?= htmlspecialchars($sOgTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($sOgDescription) ?>">
    <meta name="author" content="<?= htmlspecialchars($site['author']) ?>">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?= htmlspecialchars($ogUrl) ?>">
    <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= htmlspecialchars($site['name']) ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($site['locale']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogUrl) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($sOgTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($sOgDescription) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($sOgImage) ?>">
    <meta property="og:image:width" content="512">
    <meta property="og:image:height" content="512">
    <meta property="og:image:alt" content="<?= htmlspecialchars($ogAlt) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($sOgTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($sOgDescription) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($sOgImage) ?>">
    <link rel="icon" href="/<?= $sFaviconFolder ?>/icon-192.png">
    <link rel="apple-touch-icon" href="/<?= $sFaviconFolder ?>/apple-touch-icon.png">
    <link rel="preload" href="/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/fonts/fonts.css">
    <link rel="stylesheet" href="/style-splash.css">
    <script src="/js/splash.js"></script>
    <link rel="stylesheet" href="/lib/leaflet/leaflet.css">
    <script>
        (function () {
            var params = new URLSearchParams(location.search);
            var qTema = params.get('tema');
            // bideplusmiamor.vercel.app es un dominio dedicado, sin toggle ni
            // forma de salir del tema: siempre "mi amor" en ese dominio.
            var isMiamorDomain = location.hostname === 'bideplusmiamor.vercel.app';
            var isMiamor = isMiamorDomain || qTema === 'miamor';
            if (qTema === 'miamor' || qTema === 'pro') {
                params.delete('tema');
                var qs = params.toString();
                history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
            }
            window.__bbTheme = isMiamor ? 'miamor' : 'pro';

            var redParam = params.get('red');
            window.__bbNetwork = (redParam === 'metro' || redParam === 'euskotren') ? redParam : 'bus';
            var isMetro     = window.__bbNetwork === 'metro';
            var isEuskoTren = window.__bbNetwork === 'euskotren';

            var title      = 'BizkaiBus+';
            var manifest   = isMiamor ? 'manifest-miamor.json' : 'manifest.json';
            var themeColor = isMiamor ? '#db2777' : '#01573C';
            var touchIcon  = isMiamor ? 'icons/apple-touch-icon.png' : 'icons-pro/apple-touch-icon.png';
            var icon       = isMiamor ? 'icons/icon-192.png' : 'icons-pro/icon-192.png';
            var stylesheet = isMiamor ? 'style.css' : 'style-app.css';

            if (isMetro) {
                title = 'Metro+';
                if (isMiamor) {
                    themeColor = '#db2777';
                } else {
                    manifest   = 'manifest-metro.json';
                    touchIcon  = 'icons-metro/apple-touch-icon.png';
                    icon       = 'icons-metro/icon-192.png';
                    themeColor = '#C8102E';
                }
            } else if (isEuskoTren) {
                title      = 'Euskotren+';
                manifest   = 'manifest-euskotren.json';
                touchIcon  = 'icons-euskotren/apple-touch-icon.png';
                icon       = 'icons-euskotren/icon-192.png';
                themeColor = '#003F8C';
            }

            if (isMiamor && !isMetro && !isEuskoTren) {
                title += ' | Para el amor de mi vida';
            }

            if (isMetro)     document.documentElement.classList.add('is-metro');
            if (isEuskoTren) document.documentElement.classList.add('is-euskotren');

            document.write(
                '<title>' + title + '</title>' +
                '<link rel="manifest" href="' + manifest + '">' +
                '<meta name="theme-color" content="' + themeColor + '">' +
                '<link rel="apple-touch-icon" href="' + touchIcon + '">' +
                '<link rel="icon" href="' + icon + '">' +
                '<link rel="stylesheet" href="' + stylesheet + '">'
            );
        })();
    </script>
</head>
<body>
    <div class="splash" aria-hidden="true"><?= $wordmark ?></div>

    <main class="app-container">

        <div id="net-banner" class="net-banner" role="status" hidden>
            <span>Sin conexión con el servidor. Puede que veas datos guardados.</span>
            <button id="net-retry" type="button">Reintentar</button>
        </div>

        <noscript>
            <p><?= htmlspecialchars($sOgDescription) ?> Esta app necesita JavaScript para mostrar los horarios. <a href="/">Volver a Bide+</a>.</p>
        </noscript>

        <header>
            <div class="home-link-wrap">
                <button id="home-link" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="network-switcher">
                    <span id="app-logomark" aria-hidden="true">
                        <svg class="logo-bus" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2.5C7 4 4 8.8 4 13.5A8 8 0 0 0 12 21.5A8 8 0 0 0 20 13.5C20 8.8 17 4 12 2.5Z" fill="#9CCD64"/>
                            <path d="M12 6V19" stroke="#01573C" stroke-width="1.3" stroke-linecap="round"/>
                        </svg>
                        <svg class="logo-metro" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9.3" fill="#C8102E"/>
                            <circle cx="9.25" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                            <circle cx="12" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                            <circle cx="14.75" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                        </svg>
                        <img class="logo-euskotren" src="/icons-euskotren/icon-192.png" alt="">
                    </span>
                    <hgroup>
                        <h1 id="app-title">BizkaiBus<span id="app-title-mark">+</span></h1>
                        <p id="app-subtitle">Horarios y tiempo real de Bizkaibus</p>
                    </hgroup>
                </button>

                <nav id="network-switcher" aria-label="Cambiar red de transporte">
                    <a class="net-card" id="net-card-bus" href="/?red=bus">
                        <span class="net-card-logomark" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 2.5C7 4 4 8.8 4 13.5A8 8 0 0 0 12 21.5A8 8 0 0 0 20 13.5C20 8.8 17 4 12 2.5Z" fill="#9CCD64"/>
                                <path d="M12 6V19" stroke="#01573C" stroke-width="1.3" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span class="net-card-text">
                            <strong>BizkaiBus+</strong>
                            <span>Horarios y tiempo real de Bizkaibus</span>
                        </span>
                    </a>
                    <a class="net-card" id="net-card-metro" href="/?red=metro">
                        <span class="net-card-logomark" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="9.3" fill="#C8102E"/>
                                <circle cx="9.25" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                                <circle cx="12" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                                <circle cx="14.75" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                            </svg>
                        </span>
                        <span class="net-card-text">
                            <strong>Metro+</strong>
                            <span>Horarios de Metro Bilbao</span>
                        </span>
                    </a>
                    <a class="net-card" id="net-card-euskotren" href="/?red=euskotren">
                        <span class="net-card-logomark" aria-hidden="true">
                            <img src="/icons-euskotren/icon-192.png" alt="">
                        </span>
                        <span class="net-card-text">
                            <strong>Euskotren+</strong>
                            <span>Horarios de Euskotren</span>
                        </span>
                    </a>
                    <a class="net-card" id="net-card-menu" href="/">
                        <span class="net-card-logomark" aria-hidden="true">
                            <img class="is-full" src="/icons-bide-rojo/icon-192.png" alt="">
                        </span>
                        <span class="net-card-text">
                            <strong>Bide+</strong>
                            <span>Todas las apps</span>
                        </span>
                    </a>
                </nav>

                <script>
                    (function () {
                        var net = window.__bbNetwork;
                        var isMiamorActive = window.__bbTheme === 'miamor';
                        var temaSuffix = isMiamorActive ? '&tema=miamor' : '';

                        if (net === 'metro') {
                            document.getElementById('app-logomark').classList.add('is-metro');
                            document.getElementById('app-title').firstChild.textContent = 'METRO';
                            document.getElementById('app-subtitle').textContent = 'Horarios de Metro Bilbao';
                        } else if (net === 'euskotren') {
                            document.getElementById('app-logomark').classList.add('is-euskotren');
                            document.getElementById('app-title').firstChild.textContent = 'EUSKOTREN';
                            document.getElementById('app-subtitle').textContent = 'Horarios de Euskotren';
                        }
                        if (isMiamorActive && net === 'bus') {
                            document.getElementById('app-subtitle').textContent = 'Para el amor de mi vida';
                        }

                        var currentCardId = net === 'metro' ? 'net-card-metro'
                            : net === 'euskotren' ? 'net-card-euskotren'
                            : 'net-card-bus';
                        var currentCard = document.getElementById(currentCardId);
                        if (currentCard) currentCard.hidden = true;

                        if (temaSuffix) {
                            ['net-card-bus', 'net-card-metro', 'net-card-euskotren'].forEach(function (id) {
                                var card = document.getElementById(id);
                                if (card && card !== currentCard) card.href += temaSuffix;
                            });
                        }
                    })();
</script>
            </div>

            <span class="header-actions">
                <button id="menu-open" class="btn-icon" type="button" aria-label="Abrir menú">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
                </button>
            </span>
        </header>

        <div class="layout">
            <aside class="sidebar">
            <form id="search-form" autocomplete="off">
                <input id="search-input" type="search" aria-label="Buscar parada, línea o destino" placeholder="Buscar parada, línea o destino..." minlength="2">
                <button type="submit" aria-label="Buscar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <ul id="search-results" hidden></ul>
            </form>
            <button id="nearby-btn" class="nearby-btn" type="button">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="7.5"/><line x1="12" y1="1.5" x2="12" y2="4"/><line x1="12" y1="20" x2="12" y2="22.5"/><line x1="1.5" y1="12" x2="4" y2="12"/><line x1="20" y1="12" x2="22.5" y2="12"/></svg>
                <span>Cerca de mí</span>
            </button>
                <div id="favorites-panel" class="glass">
                    <header>
                        <h2>Tus favoritos</h2>
                        <button id="favorites-close" class="btn-icon" type="button" aria-label="Cerrar favoritos">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                        </button>
                    </header>
                    <ul id="favorites-list">
                        <li id="favorites-empty">Busca una parada o línea y guárdala para verla aquí.</li>
                    </ul>
                </div>

            </aside>

            <div class="main">
                <article id="live-card" class="live-card glass" hidden>
                    <header>
                        <span id="live-badge" class="badge">Programado</span>
                        <span class="header-actions">
                            <button id="live-favorite" class="btn-icon" type="button" aria-label="Guardar parada en favoritos"></button>
                            <button id="live-close" class="btn-icon" type="button" aria-label="Cerrar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                            </button>
                        </span>
                    </header>
                    <h3 id="live-line"></h3>
                    <p id="live-headsign"></p>
                    <button id="live-open-detail" class="time-display" type="button" aria-label="Ver detalle del trayecto">
                        <strong id="live-minutes">–</strong><span>min</span>
                    </button>
                    <button id="live-timetable-link" type="button" class="platform-timetable-link">Ver horario completo</button>
                    <footer>
                        <p>
                            <span id="live-status-dot" class="status-dot"></span><span id="live-status-text"></span>
                            <button id="live-incidents-link" type="button" hidden>Incidencias</button>
                        </p>
                    </footer>
                    <ul id="live-more-list"></ul>
                </article>

                <div id="platform-panel" class="platform-panel" hidden>
                    <header>
                        <h3 id="platform-panel-stop"></h3>
                        <span class="header-actions">
                            <button id="platform-favorite" class="btn-icon" type="button" aria-label="Guardar estación en favoritos"></button>
                            <button id="platform-close" class="btn-icon" type="button" aria-label="Cerrar">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                            </button>
                        </span>
                    </header>
                    <button id="platform-timetable-link" type="button" class="platform-timetable-link">Ver horario completo</button>
                    <div id="platform-timetable-lines" class="platform-timetable-lines" hidden></div>
                    <p id="platform-notice" class="platform-notice" role="status" hidden></p>
                    <div id="platform-columns" aria-live="polite"></div>
                </div>
                <p id="live-empty" role="status">Busca una parada para ver el próximo autobús.</p>

        <section id="timetable-section" class="timetable glass" hidden>
            <header>
                <h2>Consultar Horarios</h2>
                <span class="header-actions">
                    <button id="timetable-favorite" class="btn-icon" type="button" aria-label="Guardar línea en favoritos"></button>
                    <button id="timetable-close" class="btn-icon" type="button" aria-label="Cerrar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
                    </button>
                </span>
            </header>
            <p id="timetable-line"></p>

            <div id="line-map"></div>
            <p id="line-map-empty">No hay autobuses de esta línea circulando ahora mismo.</p>

            <div class="filters">
                <input id="filter-date" type="date" aria-label="Fecha">
                <input id="filter-hour-from" type="time" aria-label="Hora desde">
                <input id="filter-hour-to" type="time" aria-label="Hora hasta">
            </div>

            <p id="timetable-note" class="timetable-note" role="status" hidden></p>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Salida</th>
                            <th scope="col">Destino</th>
                            <th scope="col">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="timetable-body"></tbody>
                </table>
            </div>
            <p id="timetable-empty" hidden>No hay salidas programadas en ese rango.</p>

            <button id="schedule-text-toggle" type="button">Ver horario oficial 2026</button>
        </section>
            </div>
        </div>

        <p id="attribution">Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)</p>
        <p id="disclaimer">Proyecto independiente y no oficial, sin relación con Bizkaibus ni con la Diputación Foral de Bizkaia.</p>
    </main>

    <footer id="dev-footer">
        <p>Hecho por Yeray Garrido</p>
        <p>
            <a href="https://www.linkedin.com/in/yeray-garrido" target="_blank" rel="noopener noreferrer">LinkedIn</a>
            <a href="https://www.yeraygarrido.dev/" target="_blank" rel="noopener noreferrer">Portfolio</a>
            <a href="https://github.com/Garridoparrayeray" target="_blank" rel="noopener noreferrer">GitHub</a>
            <button id="legal-open" type="button">Aviso legal y privacidad</button>
        </p>
    </footer>

    <dialog id="legal-panel">
        <button id="legal-close" class="btn-icon" type="button" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <h3>Aviso Legal, Privacidad y Cookies</h3>
        <p>En estricto cumplimiento del <strong>Artículo 18 de la Constitución Española</strong> (derecho a la intimidad), el <strong>Reglamento General de Protección de Datos (RGPD)</strong>, la <strong>LSSI-CE</strong> y la <strong>Ley 37/2007 de reutilización de la información del sector público</strong>, informamos de lo siguiente:</p>
        <p><strong>Identidad del responsable:</strong> Proyecto independiente desarrollado sin ánimo de lucro por Yeray Garrido. BizkaiBus+, Metro+ y Euskotren+ son proyectos independientes, sin afiliación ni respaldo de Bizkaibus, Metro Bilbao S.A., Euskotren ni la Diputación Foral de Bizkaia.</p>
        <p><strong>Privacidad y Analíticas:</strong> Utilizamos <strong>Vercel Web Analytics</strong> (herramienta respetuosa con la privacidad y libre de cookies) para recoger estadísticas básicas y anónimas de uso (visitas, país, dispositivo). Vercel procesa las direcciones IP temporalmente para generar estas métricas agrupadas, actuando como encargado del tratamiento. Aparte de esto, la app <strong>no recopila, almacena ni cede ningún dato personal tuyo</strong>.</p>
        <p><strong>Política de Cookies y almacenamiento local:</strong> No usamos cookies de terceros ni de rastreo. Únicamente empleamos el almacenamiento de tu propio dispositivo (<code>localStorage</code> e <code>IndexedDB</code>) para guardar tus paradas "Favoritas", el "Tema" y, si los activas, tus avisos de incidencias por línea; todo queda solo en tu dispositivo. Al ser almacenamiento puramente técnico y solicitado por ti, está exento de banner de consentimiento según el Art. 22.2 de la LSSI.</p>
        <p><strong>Ubicación:</strong> Si pulsas «Cerca de mí», tu navegador te pide permiso y la ubicación se envía solo para buscar las paradas más cercanas, sin guardarse. Esta búsqueda necesita conexión a internet.</p>
        <p><strong>Modo sin conexión:</strong> La app guarda en tu dispositivo lo último que consultaste con conexión (paradas y horarios de líneas ya vistos), para que puedas volver a verlo sin internet. No se actualiza mientras estés sin conexión, y los horarios en tiempo real, la posición de los buses en el mapa, los avisos de incidencias y «Cerca de mí» necesitan conexión: no funcionan en modo sin conexión ni con datos que no hayas consultado antes.</p>
        <p><strong>Fuentes de datos y exención de responsabilidad:</strong> Los horarios estáticos y, cuando existe, el tiempo real proceden de las fuentes oficiales de cada operador: BizkaiBus+ (Bizkaibus / Open Data Bizkaia, CC-BY 4.0), Metro+ (Metro Bilbao / Open Data Metro Bilbao) y Euskotren+ (Euskotren / Open Data Euskadi, CC-BY 4.0), publicados sin alterarlos. El mapa en vivo de Bizkaibus usa teselas de © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>, cuyos datos también se han usado para asignar zona o barrio a las paradas. La posición de los vehículos y el tiempo estimado de llegada en tiempo real son una estimación (contrastada con el horario oficial dentro de un margen de tolerancia) y pueden no coincidir exactamente con la realidad: no los uses como única referencia para no perder un servicio. No garantizamos la exactitud, actualidad ni disponibilidad continua de estos datos; esta aplicación es meramente informativa y su uso es responsabilidad exclusiva de quien la utiliza.</p>
        <p><strong>Contacto.</strong> <a href="https://www.yeraygarrido.dev/" target="_blank" rel="noopener noreferrer">yeraygarrido.dev</a></p>
    </dialog>

    <dialog id="schedule-modal">
        <button id="schedule-modal-close" class="btn-icon" type="button" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <h3 id="schedule-modal-line"></h3>
        <div id="schedule-modal-content"></div>
    </dialog>

    <dialog id="vehicle-modal">
        <button id="modal-close" class="btn-icon" type="button" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <span id="modal-badge" class="badge"></span>
        <h3 id="modal-line"></h3>
        <p id="modal-headsign"></p>
        <p id="modal-vehicle"></p>
        <button id="modal-alerts-toggle" type="button">Ver incidencias</button>
        <ul id="modal-alerts" hidden></ul>
        <ol id="modal-stops"></ol>
    </dialog>

    <dialog id="side-menu">
        <button id="menu-close" class="btn-icon" type="button" aria-label="Cerrar menú">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <button id="menu-favorites-open" class="pill" type="button">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7.5-4.6-10-9.3C.6 8.1 2.3 5 5.6 5 8 5 10 6.6 12 9c2-2.4 4-4 6.4-4 3.3 0 5 3.1 3.6 6.7C19.5 16.4 12 21 12 21z"/></svg>
            Tus favoritos
        </button>
        <h3>Incidencias de mis líneas</h3>
        <label class="alerts-toggle"><input type="checkbox" id="alerts-toggle"><span>Avisarme de las incidencias nuevas de mis líneas favoritas</span></label>
        <p id="alerts-note" class="alerts-note" role="status" hidden></p>
        <p id="menu-alerts-empty">Guarda alguna línea en favoritos para ver aquí sus incidencias activas.</p>
        <ul id="menu-alerts-list"></ul>
    </dialog>

    <script src="/lib/leaflet/leaflet.js"></script>
    <script src="js/api.js"></script>
    <script src="js/alerts-store.js"></script>
    <script src="js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
            let bbSwRefreshed = false;
            const bbHadController = !!navigator.serviceWorker.controller;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (!bbHadController || bbSwRefreshed) return;
                bbSwRefreshed = true;
                location.reload();
            });
        }
</script>
    <script defer src="/_vercel/insights/script.js"></script>
</body>
</html>
