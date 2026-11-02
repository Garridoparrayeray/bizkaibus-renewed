<?php

return [
    'slug' => 'tranvia-bilbao',
    'order' => 6,
    'db' => 'tranviabilbao',
    'kind' => 'tram',

    'brand' => [
        'icons' => 'icons-tranvia-bilbao',
        'theme_color' => '#4EB848',
        'title' => ['TRANVÍA', 'BILBAO'],
        'logomark' => '<img src="/icons-tranvia-bilbao/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-tranvia-bilbao/icon-192.png" alt="">',
        // Colores (scripts/build-networks-css.php los pasa a style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#4EB848',
        'swatch_color' => '#2f7a2a',
        'palette' => [
            'bg' => '#f4f8f4',
            'border' => '#dde8dc',
            'primary' => '#2F7A2A',
            'primary-light' => '#D8F5C8',
            'primary-dark' => '#1E5420',
            'primary-tint' => '#EAF7E6',
            'text' => '#1c2a1d',
            'text-secondary' => '#5c6b5e',
            'backdrop' => 'rgba(10, 20, 12, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Tranvía Bilbao+',
            'tile' => 'Horarios y tiempo real',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Bilbao',
            'switcher' => 'Horarios del tranvía de Bilbao',
            'subtitle' => 'Horarios del tranvía',
            'liveEmpty' => 'Busca una parada para ver el próximo tranvía.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Tranbia Bilbo+',
            'tile_title' => 'Tranbia',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'tile_city' => 'Bilbo',
            'switcher' => 'Bilboko tranbiaren ordutegiak',
            'subtitle' => 'Tranbiaren ordutegiak',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo tranbia ikusteko.',
            'attribution' => 'Datuak: Euskotren (Bilboko tranbia) / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'tile' => 'Timetables and real time',
            'switcher' => 'Bilbao tram timetables',
            'subtitle' => 'Tram timetables',
            'liveEmpty' => 'Search for a stop to see the next tram.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Tranvía Bilbao+ · Horarios del tranvía de Bilbao',
        'description' => 'Consulta los horarios del tranvía de Bilbao por parada.',
    ],
    'attribution' => 'Datos: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)',

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
        'agency_id' => 'ES:Euskotren:Operator:EUS_TrBi:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => false,
    ],
];
