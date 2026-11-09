<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features).
return [
    'slug' => 'villavesas',
    'order' => 16,
    'db' => 'villavesas',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-09',

    'brand' => [
        'icons' => 'icons-villavesas',
        'theme_color' => '#0A7A3E',
        'title' => ['VILLAVESAS', ''],
        'logomark' => '<img src="/icons-villavesas/glyph.png" alt="Villavesas+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-villavesas/icon-192.png" alt="Villavesas+" aria-hidden="true">',
        'tile_color' => '#0A7A3E',
        'swatch_color' => '#0A7A3E',
        'palette' => [
            'bg' => '#eef1f0',
            'border' => '#d3dad6',
            'primary' => '#0A7A3E',
            'primary-light' => '#91c3a8',
            'primary-dark' => '#064c26',
            'primary-tint' => '#ebf4f0',
            'text' => '#1d2420',
            'text-secondary' => '#59625d',
            'backdrop' => 'rgba(1, 12, 6, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Villavesas+',
            'tile' => 'Horarios del autobús de Pamplona',
            'switcher' => 'Horarios del autobús de Pamplona',
            'subtitle' => 'Horarios del autobús de Pamplona',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Mancomunidad de la Comarca de Pamplona (TCC). Horario programado oficial: no refleja retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'Villavesas+',
            'tile' => 'Autobusaren ordutegiak',
            'switcher' => 'Autobusaren ordutegiak',
            'subtitle' => 'Autobusaren ordutegiak',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Mancomunidad de la Comarca de Pamplona (TCC) erakundearekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'attribution' => 'Datuak: Mancomunidad de la Comarca de Pamplona (TCC) (GTFS)',
        ],
        'en' => [
            'name' => 'Villavesas+',
            'tile' => 'Pamplona bus timetables',
            'switcher' => 'Pamplona bus timetables',
            'subtitle' => 'Pamplona bus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Mancomunidad de la Comarca de Pamplona (TCC). Official scheduled timetable: it does not reflect delays or cancellations.',
            'attribution' => 'Data: Mancomunidad de la Comarca de Pamplona (TCC) (GTFS)',
        ],
    ],

    'seo' => [
        'title' => 'Villavesas+ · Horarios del autobús de Pamplona',
        'description' => 'Consulta los horarios del autobús de Pamplona por parada y línea.',
    ],
    'attribution' => 'Datos: Mancomunidad de la Comarca de Pamplona (TCC) (GTFS)',

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
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1177',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => false,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
