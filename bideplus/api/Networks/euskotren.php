<?php

return [
    'slug' => 'euskotren',
    'order' => 3,
    'db' => 'euskotren',
    'kind' => 'train',

    'brand' => [
        'icons' => 'icons-euskotren',
        'theme_color' => '#003F8C',
        'title' => ['EUSKOTREN', ''],
        'logomark' => '<img src="/icons-euskotren/icon-192.png" alt="">',
        // Colores (scripts/build-networks-css.php los pasa a style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#003F8C',
        'swatch_color' => '#003F8C',
        'palette' => [
            'bg' => '#f0f4f8',
            'border' => '#d0dce8',
            'primary' => '#003F8C',
            'primary-light' => '#5B9BD5',
            'primary-dark' => '#002560',
            'primary-tint' => '#E6F0FA',
            'text' => '#171d26',
            'text-secondary' => '#4d5966',
            'backdrop' => 'rgba(10, 14, 20, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Euskotren+',
            'tile' => 'Horarios y tiempo real',
            'switcher' => 'Horarios de Euskotren',
            'subtitle' => 'Horarios de Euskotren',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Euskotren+',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'switcher' => 'Euskotrenen ordutegiak',
            'subtitle' => 'Euskotrenen ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'attribution' => 'Datuak: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Euskotren+',
            'tile' => 'Timetables and real time',
            'switcher' => 'Euskotren timetables',
            'subtitle' => 'Euskotren timetables',
            'liveEmpty' => 'Search for a station to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Euskotren+ · Horarios de trenes y tranvía de Euskotren por línea y estación | Bide+',
        'description' => 'Consulta los horarios de Euskotren, trenes y tranvía, por línea y estación en Bizkaia y Gipuzkoa.',
    ],
    'attribution' => 'Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)',

    'features' => [
        'platforms' => true,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates'],

    // Previsión de Euskotren (GTFS-Realtime, cada 1-2 min): trenes y los dos tranvías van en el mismo fichero.
    'gtfs_rt' => [
        'trip_updates_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfsrt_euskotren_trip_updates.pb',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
        'match_tolerance_seconds' => 600,
    ],

    'siri' => [
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'gtfs' => [
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1263',
        'fallback_source' => 'ftp://ftp.geo.euskadi.net/cartografia/Transporte/Moveuskadi/Euskotren/google_transit.zip',
        'agency_id' => 'ES:Euskotren:Operator:EUS_Tren:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => true,
    ],
];
