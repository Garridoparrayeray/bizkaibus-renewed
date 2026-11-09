<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features).
return [
    'slug' => 'txiktxak',
    'order' => 14,
    'db' => 'txiktxak',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-09',

    'brand' => [
        'icons' => 'icons-txiktxak',
        'theme_color' => '#E2001A',
        'title' => ['TXIK TXAK', ''],
        'logomark' => '<img src="/icons-txiktxak/glyph.png" alt="Txik Txak+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-txiktxak/icon-192.png" alt="Txik Txak+" aria-hidden="true">',
        'tile_color' => '#E2001A',
        'swatch_color' => '#E2001A',
        'palette' => [
            'bg' => '#f4eeee',
            'border' => '#e0d3d4',
            'primary' => '#E2001A',
            'primary-light' => '#f28c98',
            'primary-dark' => '#8c0010',
            'primary-tint' => '#fdebed',
            'text' => '#2a1c1e',
            'text-secondary' => '#6a585a',
            'backdrop' => 'rgba(23, 0, 3, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Txik Txak+',
            'tile' => 'Horarios del autobús de Iparralde',
            'switcher' => 'Horarios del autobús de Iparralde',
            'subtitle' => 'Horarios del autobús de Iparralde',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Syndicat des Mobilités Pays Basque-Adour. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Txik Txak+',
            'tile' => 'Autobusaren ordutegiak',
            'switcher' => 'Autobusaren ordutegiak',
            'subtitle' => 'Autobusaren ordutegiak',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Syndicat des Mobilités Pays Basque-Adour erakundearekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Syndicat des Mobilités Pays Basque-Adour (GTFS)',
        ],
        'en' => [
            'name' => 'Txik Txak+',
            'tile' => 'Iparralde bus timetables',
            'switcher' => 'Iparralde bus timetables',
            'subtitle' => 'Iparralde bus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Syndicat des Mobilités Pays Basque-Adour. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Syndicat des Mobilités Pays Basque-Adour (GTFS)',
        ],
    ],

    'seo' => [
        'title' => 'Txik Txak+ · Horarios del autobús de Iparralde',
        'description' => 'Consulta los horarios del autobús de Iparralde por parada y línea.',
    ],
    'attribution' => 'Datos: Syndicat des Mobilités Pays Basque-Adour (GTFS)',

    // Para la prueba de integridad: la zona de sus paradas y, si hace falta, lo que dura como mucho un viaje.
    'checks' => ['bounds' => [42.9, 43.8, -1.9, -0.7]],

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates'],

    'gtfs_rt' => [
        // Sin posiciones publicadas: cuando las haya, su dirección va aquí y 'vehicle-positions' se añade a live.
        'vehicle_positions_url' => null,
        'trip_updates_url' => 'https://www.data.gouv.fr/api/1/datasets/r/a228fac6-b0ab-44f5-b2e8-a6e47b9c1b98',
        'alerts_url' => 'https://www.data.gouv.fr/api/1/datasets/r/29c3470e-5846-42d2-a9d6-e1168287143e',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://www.data.gouv.fr/api/1/datasets/r/a6c39f5b-9e05-4dc6-be13-f2620ff73216',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => false,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
