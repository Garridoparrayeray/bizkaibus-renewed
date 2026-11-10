<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features).
return [
    'slug' => 'navarra',
    'order' => 17,
    'db' => 'navarra',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-09',

    'brand' => [
        'icons' => 'icons-navarra',
        'theme_color' => '#C8102E',
        'title' => ['NAVARRA BUS', ''],
        'logomark' => '<img src="/icons-navarra/glyph.png" alt="Navarra bus+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-navarra/icon-192.png" alt="Navarra bus+" aria-hidden="true">',
        'tile_color' => '#C8102E',
        'swatch_color' => '#C8102E',
        'palette' => [
            'bg' => '#f4eeef',
            'border' => '#dfd4d5',
            'primary' => '#C8102E',
            'primary-light' => '#e693a1',
            'primary-dark' => '#7c0a1d',
            'primary-tint' => '#fbecee',
            'text' => '#281d1f',
            'text-secondary' => '#685a5c',
            'backdrop' => 'rgba(20, 2, 5, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Navarra bus+',
            'tile' => 'Horarios del autobús de Navarra',
            'switcher' => 'Horarios del autobús de Navarra',
            'subtitle' => 'Horarios del autobús de Navarra',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Gobierno de Navarra. Horario programado oficial: no refleja retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'Navarra bus+',
            'tile' => 'Autobusaren ordutegiak',
            'switcher' => 'Autobusaren ordutegiak',
            'subtitle' => 'Autobusaren ordutegiak',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Gobierno de Navarra erakundearekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'attribution' => 'Datuak: Gobierno de Navarra (GTFS)',
        ],
        'en' => [
            'name' => 'Navarra bus+',
            'tile' => 'Navarra bus timetables',
            'switcher' => 'Navarra bus timetables',
            'subtitle' => 'Navarra bus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Gobierno de Navarra. Official scheduled timetable: it does not reflect delays or cancellations.',
            'attribution' => 'Data: Gobierno de Navarra (GTFS)',
        ],
        'fr' => [
            'name' => 'Navarra bus+',
            'tile' => 'Horaires des bus de Navarre',
            'switcher' => 'Horaires des bus de Navarre',
            'subtitle' => 'Horaires des bus de Navarre',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain bus.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec le Gobierno de Navarra. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.',
            'attribution' => 'Données : Gobierno de Navarra (GTFS)',
        ],
    ],

    'seo' => [
        'title' => 'Navarra bus+ · Horarios del autobús de Navarra',
        'description' => 'Consulta los horarios del autobús de Navarra por parada y línea.',
    ],
    'attribution' => 'Datos: Gobierno de Navarra (GTFS)',

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'stops',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    // Sin datos en directo: el horario oficial.
    'live' => [],

    // Hueco del directo: cuando el operador publique sus feeds (o dé una clave), se ponen aquí sus direcciones y se añade
    // 'trip-updates' y 'vehicle-positions' a live. Sin más cambios.
    'gtfs_rt' => [
        'trip_updates_url' => null,
        'vehicle_positions_url' => null,
        'alerts_url' => null,
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://datosabiertos.navarra.es/dataset/ebfb5edd-0cd9-4b31-b0d9-8bc2d25e2493/resource/d3c1c89a-5d4c-4c25-97b5-66da663d3691/download/gtfs.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => false,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
