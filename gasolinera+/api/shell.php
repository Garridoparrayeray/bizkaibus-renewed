<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Gasolinera+">
    <title>Gasolinera+</title>
    <meta name="description" content="Precios de gasolina y diésel cerca de ti, actualizados a diario.">
    <meta property="og:title" content="Gasolinera+">
    <meta property="og:description" content="Precios de gasolina y diésel cerca de ti, actualizados a diario.">
    <meta property="og:type" content="website">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#B8860B">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <link rel="icon" href="/icons/icon-192.png">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <link rel="stylesheet" href="/style.css">
</head>
<body>

    <main class="app-container">

        <header>
            <div class="home-link-wrap">
                <span id="app-logomark" aria-hidden="true">
                    <svg class="logo-gasolinera" viewBox="0 0 512 512">
                        <rect width="512" height="512" rx="116" fill="#8A5A00"/>
                        <g transform="translate(112,112) scale(4.5)">
                            <path d="M32 3c1.6 11.6 10.4 16.2 15.6 24.6 5.6 9.1 2.4 22.3-7.3 27.7-2.7 1.6-5.5 2.3-8.3 2.4 5.5-4.6 7.1-11 3.7-16.3-2.1-3.2-5.6-5.1-5.8-10-3.3 3.3-4.6 7.3-3.9 11.5-4.4-2.7-6.4-6.9-6.5-11.8-4.2 4.6-5.6 10-4.2 15.9C10.8 41.5 10.2 33.8 13.5 26.5 17.4 18 30 14.7 32 3z" fill="#F2B705"/>
                        </g>
                    </svg>
                </span>
                <span>
                    <strong id="app-title">GASOLINERA<span class="plus">+</span></strong>
                    <span id="app-subtitle">Precios cerca de ti</span>
                </span>
            </div>
            <span class="header-actions">
                <button id="favorites-open" class="btn-icon" type="button" aria-label="Ver favoritas">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20.5C12 20.5 3.5 15.4 3.5 9.5C3.5 6.5 5.8 4.5 8.5 4.5C10.1 4.5 11.3 5.3 12 6.5C12.7 5.3 13.9 4.5 15.5 4.5C18.2 4.5 20.5 6.5 20.5 9.5C20.5 15.4 12 20.5 12 20.5Z"/></svg>
                    <span id="favorites-count" class="count-badge" hidden>0</span>
                </button>
                <button id="compare-open" class="btn-icon" type="button" aria-label="Ver comparador">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="10" width="6" height="11"/><rect x="15" y="4" width="6" height="17"/></svg>
                    <span id="compare-count" class="count-badge" hidden>0</span>
                </button>
            </span>
        </header>

        <section id="national-stats">
            <button id="national-stats-toggle" type="button" aria-expanded="false">
                <span class="national-stats__figures">
                    <span><small>Gasóleo A hoy en España</small><strong id="national-gasoleo-a">—</strong></span>
                    <span><small>Gasolina 95 hoy en España</small><strong id="national-gasolina-95">—</strong></span>
                </span>
                <svg id="national-stats-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div id="national-stats-detail" hidden>
                <canvas id="national-chart" height="120"></canvas>
                <p id="national-stats-note"></p>
            </div>
        </section>

        <form id="search-form" autocomplete="off">
            <input id="search-input" type="search" placeholder="Buscar municipio, dirección o marca…" aria-label="Buscar gasolinera">
            <button type="submit" class="btn-icon" aria-label="Buscar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
        </form>
        <button id="back-to-nearby" type="button" hidden>« Volver a gasolineras cerca de mí</button>

        <div id="filters-bar">
            <select id="filter-fuel" aria-label="Carburante">
                <option value="gasoleo_a">Gasóleo A</option>
                <option value="gasolina_95_e5">Gasolina 95 E5</option>
                <option value="gasoleo_premium">Gasóleo Premium</option>
                <option value="gasolina_98_e5">Gasolina 98 E5</option>
                <option value="adblue">AdBlue</option>
                <option value="glp">GLP</option>
                <optgroup label="Alternativos y renovables">
                    <option value="diesel_renovable">Diésel Renovable</option>
                    <option value="gasolina_renovable">Gasolina Renovable</option>
                    <option value="biodiesel">Biodiésel</option>
                    <option value="bioetanol">Bioetanol</option>
                    <option value="gnc">Gas Natural Comprimido</option>
                    <option value="gnl">Gas Natural Licuado</option>
                    <option value="biogas_natural_comprimido">Biogás Natural Comprimido</option>
                    <option value="biogas_natural_licuado">Biogás Natural Licuado</option>
                    <option value="hidrogeno">Hidrógeno</option>
                </optgroup>
            </select>
            <select id="filter-radius" aria-label="Radio de búsqueda">
                <option value="1">1 km</option>
                <option value="3">3 km</option>
                <option value="5" selected>5 km</option>
                <option value="10">10 km</option>
                <option value="25">25 km</option>
            </select>
            <select id="filter-sort" aria-label="Ordenar por">
                <option value="price">Más barata primero</option>
                <option value="distance">Más cercana primero</option>
            </select>
            <select id="filter-open" aria-label="Apertura">
                <option value="">Cualquier horario</option>
                <option value="now">Abierto ahora</option>
                <option value="24h">Abierto 24h</option>
            </select>
        </div>

        <div id="view-toggle" role="tablist">
            <button id="view-list-btn" type="button" role="tab" aria-selected="true">Lista</button>
            <button id="view-map-btn" type="button" role="tab" aria-selected="false">Mapa</button>
            <button id="view-stats-btn" type="button" role="tab" aria-selected="false">Estadísticas</button>
        </div>

        <p id="geo-fallback" hidden>
            No hemos podido acceder a tu ubicación. Puedes buscar por municipio o dirección arriba, o
            <button id="geo-retry" type="button">reintentar la ubicación</button>.
        </p>

        <section id="view-list">
            <p id="stations-geocoded-note" hidden></p>
            <ul id="stations-list"></ul>
            <p id="stations-empty" hidden>No hay gasolineras que coincidan con la búsqueda.</p>
            <nav id="stations-pagination" hidden aria-label="Paginación de resultados">
                <button id="pagination-prev" type="button" class="pill">« Anterior</button>
                <span id="pagination-status"></span>
                <button id="pagination-next" type="button" class="pill">Siguiente »</button>
            </nav>
        </section>

        <section id="view-map" hidden>
            <div id="map-controls">
                <button id="heatmap-toggle" type="button" class="pill" aria-pressed="false">Mapa de calor de precio</button>
                <button id="locate-me" type="button" class="pill">Mi ubicación</button>
            </div>
            <div id="map-wrap">
                <div id="map"></div>
                <div id="map-legend">
                    <span>Barata</span>
                    <span id="map-legend-gradient"></span>
                    <span>Cara</span>
                </div>
            </div>
        </section>

        <section id="view-stats" hidden>
            <div class="stats-block">
                <h3>Filtros</h3>
                <div class="stats-controls">
                    <select id="stats-fuel" aria-label="Carburante para las estadísticas">
                        <option value="gasoleo_a">Gasóleo A</option>
                        <option value="gasolina_95_e5">Gasolina 95 E5</option>
                        <option value="gasoleo_premium">Gasóleo Premium</option>
                        <option value="gasolina_98_e5">Gasolina 98 E5</option>
                        <option value="adblue">AdBlue</option>
                        <option value="glp">GLP</option>
                        <optgroup label="Alternativos y renovables">
                            <option value="diesel_renovable">Diésel Renovable</option>
                            <option value="gasolina_renovable">Gasolina Renovable</option>
                            <option value="biodiesel">Biodiésel</option>
                            <option value="bioetanol">Bioetanol</option>
                            <option value="gnc">Gas Natural Comprimido</option>
                            <option value="gnl">Gas Natural Licuado</option>
                            <option value="biogas_natural_comprimido">Biogás Natural Comprimido</option>
                            <option value="biogas_natural_licuado">Biogás Natural Licuado</option>
                            <option value="hidrogeno">Hidrógeno</option>
                        </optgroup>
                    </select>
                    <div id="stats-group-toggle" role="tablist">
                        <button type="button" class="pill" data-group="day" aria-pressed="true">Diario</button>
                        <button type="button" class="pill" data-group="month" aria-pressed="false">Mensual</button>
                    </div>
                </div>
                <div class="stats-date-range">
                    <label>Desde <input id="stats-from" type="date"></label>
                    <label>Hasta <input id="stats-to" type="date"></label>
                </div>
                <p id="stats-range-note"></p>
            </div>

            <div class="stats-block">
                <h3>Media nacional</h3>
                <p id="stats-national-variation"></p>
                <canvas id="stats-national-chart" height="180"></canvas>
            </div>

            <div class="stats-block">
                <h3>Comparativa entre carburantes</h3>
                <canvas id="stats-by-fuel-chart" height="220"></canvas>
            </div>

            <div class="stats-block">
                <h3>Precio medio por provincia hoy</h3>
                <div id="stats-province-chart-wrap">
                    <canvas id="stats-province-chart"></canvas>
                </div>
            </div>

            <div class="stats-block">
                <h3>Distribución de precios hoy</h3>
                <p id="stats-distribution-note"></p>
                <canvas id="stats-distribution-chart" height="200"></canvas>
            </div>

            <div class="stats-block">
                <h3>Por gasolinera</h3>
                <div id="stats-station-search-wrap">
                    <input id="stats-station-search" type="search" placeholder="Buscar una gasolinera…" aria-label="Buscar gasolinera para sus estadísticas" autocomplete="off">
                    <ul id="stats-station-results"></ul>
                </div>
                <div id="stats-station-detail" hidden>
                    <p id="stats-station-name"></p>
                    <p id="stats-station-variation"></p>
                    <canvas id="stats-station-chart" height="180"></canvas>
                </div>
            </div>
        </section>

    </main>

    <dialog id="station-modal">
        <div id="modal-top-actions">
            <button id="modal-close" class="btn-icon" type="button" aria-label="Cerrar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
            </button>
            <button id="modal-favorite-toggle" class="btn-icon" type="button" aria-label="Añadir a favoritas" aria-pressed="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20.5C12 20.5 3.5 15.4 3.5 9.5C3.5 6.5 5.8 4.5 8.5 4.5C10.1 4.5 11.3 5.3 12 6.5C12.7 5.3 13.9 4.5 15.5 4.5C18.2 4.5 20.5 6.5 20.5 9.5C20.5 15.4 12 20.5 12 20.5Z"/></svg>
            </button>
        </div>
        <span id="modal-rotulo" class="badge"></span>
        <h3 id="modal-direccion"></h3>
        <p id="modal-municipio"></p>
        <p id="modal-horario"></p>
        <ul id="modal-fuels"></ul>
        <div id="modal-zone-comparison"></div>
        <canvas id="modal-chart" height="140"></canvas>
        <div id="modal-actions">
            <a id="modal-directions" class="pill" target="_blank" rel="noopener">Cómo llegar</a>
            <button id="modal-compare-toggle" type="button" class="pill">Añadir a comparar</button>
        </div>
    </dialog>

    <dialog id="marker-popup-template" hidden></dialog>

    <dialog id="favorites-panel">
        <button id="favorites-close" class="btn-icon" type="button" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <h3>Gasolineras favoritas</h3>
        <p id="favorites-empty">Añade gasolineras desde su ficha para tenerlas siempre a mano aquí.</p>
        <ul id="favorites-list"></ul>
    </dialog>

    <dialog id="compare-panel">
        <button id="compare-close" class="btn-icon" type="button" aria-label="Cerrar">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
        <h3>Comparar gasolineras</h3>
        <p id="compare-empty">Añade gasolineras desde su ficha o desde la lista para compararlas aquí, una junto a otra.</p>
        <div id="compare-table"></div>
    </dialog>

    <p id="toast" hidden></p>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="/js/api.js"></script>
    <script src="/js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
        }
    </script>
</body>
</html>
