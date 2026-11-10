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
        'theme_color' => '#268991',
        'title' => ['TXIK TXAK', ''],
        'logomark' => '<img src="/icons-txiktxak/glyph.png" alt="Txik Txak+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-txiktxak/icon-192.png" alt="Txik Txak+" aria-hidden="true">',
        'tile_color' => '#268991',
        'swatch_color' => '#268991',
        'palette' => [
            'bg' => '#eef4f4',
            'border' => '#d3e0e1',
            'primary' => '#268991',
            'primary-light' => '#8cc7cc',
            'primary-dark' => '#14555b',
            'primary-tint' => '#e8f5f6',
            'text' => '#1c292a',
            'text-secondary' => '#586a6b',
            'backdrop' => 'rgba(0, 20, 22, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'el Syndicat des Mobilités Pays Basque-Adour',
            'name' => 'Txik Txak+',
            'tile' => 'Autobuses de Iparralde',
            'switcher' => 'Horarios y tiempo real de los autobuses de Iparralde',
            'subtitle' => 'Horarios y tiempo real',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Syndicat des Mobilités Pays Basque-Adour. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'liveSource' => 'Syndicat des Mobilités Pays Basque-Adour erakundeak',
            'name' => 'Txik Txak+',
            'tile' => 'Iparraldeko autobusak',
            'switcher' => 'Iparraldeko autobusen ordutegiak eta denbora errealean',
            'subtitle' => 'Ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Syndicat des Mobilités Pays Basque-Adour erakundearekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Syndicat des Mobilités Pays Basque-Adour, transport.data.gouv.fr-en bidez (Open Database License, ODbL 1.0)',
        ],
        'en' => [
            'liveSource' => 'the Syndicat des Mobilités Pays Basque-Adour',
            'name' => 'Txik Txak+',
            'tile' => 'Buses in the Northern Basque Country',
            'switcher' => 'Timetables and real time for buses in the Northern Basque Country',
            'subtitle' => 'Timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Syndicat des Mobilités Pays Basque-Adour. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Syndicat des Mobilités Pays Basque-Adour, via transport.data.gouv.fr (Open Database License, ODbL 1.0)',
        ],
        'fr' => [
            'liveSource' => 'le Syndicat des Mobilités Pays Basque-Adour',
            'name' => 'Txik Txak+',
            'tile' => 'Bus d’Iparralde',
            'switcher' => 'Horaires et temps réel des bus d’Iparralde',
            'subtitle' => 'Horaires et temps réel',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain bus.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec le Syndicat des Mobilités Pays Basque-Adour. Les temps en direct sont une prévision de l’opérateur.',
            'attribution' => 'Données : Syndicat des Mobilités Pays Basque-Adour, via transport.data.gouv.fr (Open Database License, ODbL 1.0)',
        ],
    ],

    'seo' => [
        'title' => 'Txik Txak+ · Horarios y tiempo real de los autobuses de Iparralde',
        'description' => 'Consulta los horarios de los autobuses Txik Txak de Iparralde (Baiona, Biarritz, Angelu, Donibane Lohizune, Hendaia) por parada y línea, con su retraso en tiempo real.',
    ],
    'attribution' => 'Datos: Syndicat des Mobilités Pays Basque-Adour, vía transport.data.gouv.fr (Open Database License, ODbL 1.0)',

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
        // El feed sigue dando las paradas por las que el bus ya ha pasado: la siguiente es la primera con hora por venir.
        'keeps_passed_stops' => true,
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://www.data.gouv.fr/api/1/datasets/r/a6c39f5b-9e05-4dc6-be13-f2620ff73216',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
