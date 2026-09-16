<?php

$bIsMetroShare     = isset($_GET['red']) && $_GET['red'] === 'metro';
$bIsEuskoTrenShare = isset($_GET['red']) && $_GET['red'] === 'euskotren';

if ($bIsMetroShare) {
    $sOgTitle       = 'Metro+';
    $sOgDescription = 'Horarios de Metro Bilbao, sin vueltas.';
    $sOgImage       = 'https://bizkaibus-renewed.vercel.app/icons-metro/icon-512.png';
} elseif ($bIsEuskoTrenShare) {
    $sOgTitle       = 'Euskotren+';
    $sOgDescription = 'Horarios de Euskotren, sin vueltas.';
    $sOgImage       = 'https://bizkaibus-renewed.vercel.app/icons-euskotren/icon-512.png';
} else {
    $sOgTitle       = 'BizkaiBus+';
    $sOgDescription = 'Horarios y tiempo real de Bizkaibus, sin vueltas.';
    $sOgImage       = 'https://bizkaibus-renewed.vercel.app/icons-pro/icon-512.png';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="BizkaiBus+">
    <title><?= $sOgTitle ?></title>
    <meta name="description" content="<?= $sOgDescription ?>">
    <meta property="og:title" content="<?= $sOgTitle ?>">
    <meta property="og:description" content="<?= $sOgDescription ?>">
    <meta property="og:image" content="<?= $sOgImage ?>">
    <meta property="og:type" content="website">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script>
        (function () {
            var params = new URLSearchParams(location.search);
            var qTema = params.get('tema');
            var isMiamor = qTema === 'miamor';
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
            var stylesheet = isMiamor ? 'style.css' : 'style-pro.css';

            if (isMetro) {
                title = 'Metro+';
                if (isMiamor) {
                    themeColor = '#db2777';
                } else {
                    manifest   = 'manifest-metro.json';
                    touchIcon  = 'icons-metro/apple-touch-icon.png';
                    icon       = 'icons-metro/icon-192.png';
                    themeColor = '#C8102E';
                    stylesheet = 'style-metro.css';
                }
            } else if (isEuskoTren) {
                title      = 'Euskotren+';
                manifest   = 'manifest-euskotren.json';
                touchIcon  = 'icons-euskotren/apple-touch-icon.png';
                icon       = 'icons-euskotren/icon-192.png';
                themeColor = '#003F8C';
                stylesheet = 'style-euskotren.css';
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

    <main class="app-container">

        <header>
            <div class="home-link-wrap">
                <button id="home-link" type="button">
                    <span id="app-logomark" aria-hidden="true">
                        <!-- BizkaiBus logo: leaf -->
                        <svg class="logo-bus" viewBox="0 0 24 24" fill="none">
                            <path d="M12 2.5C7 4 4 8.8 4 13.5A8 8 0 0 0 12 21.5A8 8 0 0 0 20 13.5C20 8.8 17 4 12 2.5Z" fill="#9CCD64"/>
                            <path d="M12 6V19" stroke="#01573C" stroke-width="1.3" stroke-linecap="round"/>
                        </svg>
                        <!-- Metro logo: 3 rings -->
                        <svg class="logo-metro" viewBox="0 0 24 24" fill="none">
                            <circle cx="12" cy="12" r="9.3" fill="#C8102E"/>
                            <circle cx="9.25" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                            <circle cx="12" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                            <circle cx="14.75" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/>
                        </svg>
                        <!-- Euskotren logo: train front face -->
                        <svg class="logo-euskotren" viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="4" width="18" height="13" rx="3" fill="#003F8C"/>
                            <rect x="5" y="6" width="14" height="6" rx="1.5" fill="#5B9BD5"/>
                            <circle cx="7.5" cy="15.5" r="1.8" fill="#5B9BD5"/>
                            <circle cx="16.5" cy="15.5" r="1.8" fill="#5B9BD5"/>
                            <rect x="11" y="13" width="2" height="4" rx="0.5" fill="#5B9BD5"/>
                        </svg>
                    </span>
                    <hgroup>
                        <h1 id="app-title">BizkaiBus<span id="app-title-mark">+</span></h1>
                        <p id="app-subtitle">Horarios y tiempo real de Bizkaibus</p>
                    </hgroup>
                </button>

                <!-- Switcher de red: tarjetas apiladas (mismo lenguaje visual que el
                     switch bus↔metro original), reveladas al pulsar el logo vía JS.
                     Solo se muestran las redes DISTINTAS de la actual, igual que el
                     enlace único original solo apuntaba "a la otra" red. -->
                <nav id="network-switcher" aria-label="Cambiar red de transporte">
                    <a class="net-card" id="net-card-bus" href="/">
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
                            <svg viewBox="0 0 24 24" fill="none">
                                <rect x="3" y="4" width="18" height="13" rx="3" fill="#003F8C"/>
                                <rect x="5" y="6" width="14" height="6" rx="1.5" fill="#5B9BD5"/>
                                <circle cx="7.5" cy="15.5" r="1.8" fill="#5B9BD5"/>
                                <circle cx="16.5" cy="15.5" r="1.8" fill="#5B9BD5"/>
                                <rect x="11" y="13" width="2" height="4" rx="0.5" fill="#5B9BD5"/>
                            </svg>
                        </span>
                        <span class="net-card-text">
                            <strong>Euskotren+</strong>
                            <span>Horarios de Euskotren</span>
                        </span>
                    </a>
                </nav>

                <script>
                    (function () {
                        var net = window.__bbNetwork;
                        var isMiamorActive = window.__bbTheme === 'miamor';
                        var temaSuffix = isMiamorActive ? '&tema=miamor' : '';

                        // Title / subtitle
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

                        // La tarjeta de la red actual no tiene sentido como destino: se oculta,
                        // igual que el switch original nunca se mostraba a sí mismo.
                        var currentCardId = net === 'metro' ? 'net-card-metro'
                            : net === 'euskotren' ? 'net-card-euskotren'
                            : 'net-card-bus';
                        var currentCard = document.getElementById(currentCardId);
                        if (currentCard) currentCard.hidden = true;

                        // Append tema suffix a los destinos que sí se muestran
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

        <form id="search-form" autocomplete="off">
            <input id="search-input" type="text" placeholder="Buscar parada, línea o destino..." minlength="2">
            <button type="submit" aria-label="Buscar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <ul id="search-results" hidden></ul>
        </form>

        <div class="content-grid">
            <div class="live-column">
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

                <!-- Panel de andén (Metro+ únicamente): un cuadro por sentido
                     de circulación, como los paneles físicos reales de
                     estación. Reemplaza a #live-card para esta red (ver
                     js/app.js selectStop()). -->
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
                    <!-- Estaciones con varias líneas (algunas de Euskotren
                         tienen hasta 5): en vez de un único botón que solo
                         puede apuntar a una, una línea por botón. -->
                    <div id="platform-timetable-lines" class="platform-timetable-lines" hidden></div>
                    <div id="platform-columns"></div>
                </div>
                <p id="live-empty">Busca una parada para ver el próximo autobús.</p>
            </div>
        </div>

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
                <input id="filter-date" type="date">
                <input id="filter-hour-from" type="time">
                <input id="filter-hour-to" type="time">
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Salida</th>
                            <th>Destino</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody id="timetable-body"></tbody>
                </table>
            </div>
            <p id="timetable-empty" hidden>No hay salidas programadas en ese rango.</p>

            <button id="schedule-text-toggle" type="button">Ver horario oficial 2026</button>
        </section>
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
        </p>
    </footer>
    <a id="theme-toggle-link" href="#" class="theme-toggle-link" aria-label="Cambiar tema"></a>
    <script>
        (function () {
            var isMiamorActive = window.__bbTheme === 'miamor';
            var redSuffix = '';
            if (window.__bbNetwork === 'metro') {
                redSuffix = '?red=metro';
            }
            var themeToggleLink = document.getElementById('theme-toggle-link');
            if (isMiamorActive) {
                themeToggleLink.href = '/' + redSuffix;
                themeToggleLink.setAttribute('aria-label', 'Volver al tema normal');
            } else {
                var joiner = '?';
                if (redSuffix) {
                    joiner = '&';
                }
                themeToggleLink.href = '/' + redSuffix + joiner + 'tema=miamor';
                themeToggleLink.setAttribute('aria-label', 'Cambiar al tema mi amor');
            }
        })();
</script>

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
        <p id="menu-alerts-empty">Guarda alguna línea en favoritos para ver aquí sus incidencias activas.</p>
        <ul id="menu-alerts-list"></ul>
    </dialog>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="js/api.js"></script>
    <script src="js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
            let bbSwRefreshed = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (bbSwRefreshed) return;
                bbSwRefreshed = true;
                location.reload();
            });
        }
</script>
    <script defer src="/_vercel/insights/script.js"></script>
</body>
</html>
