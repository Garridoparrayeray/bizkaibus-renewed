<?php

return [
    'slug' => 'tranvia-vitoria',
    'order' => 7,
    'db' => 'tranviavitoria',
    'kind' => 'tram',

    'brand' => [
        'icons' => 'icons-tranvia-vitoria',
        'theme_color' => '#60AE27',
        'title' => ['TRANVÍA', 'VITORIA'],
        'logomark' => '<img src="/icons-tranvia-vitoria/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-tranvia-vitoria/icon-192.png" alt="">',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#60AE27',
        'swatch_color' => '#3f7f1c',
        'palette' => [
            'bg' => '#f5f8f3',
            'border' => '#dfe8da',
            'primary' => '#3B7D23',
            'primary-light' => '#ECFAE2',
            'primary-dark' => '#2A5612',
            'primary-tint' => '#EEF7E7',
            'text' => '#1e2a19',
            'text-secondary' => '#5e6b58',
            'backdrop' => 'rgba(12, 20, 10, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Tranvía Vitoria+',
            'tile' => 'Horarios y tiempo real',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Vitoria',
            'switcher' => 'Horarios y tiempo real del tranvía de Vitoria-Gasteiz',
            'subtitle' => 'Horarios y tiempo real del tranvía',
            'liveEmpty' => 'Busca una parada para ver el próximo tranvía.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Tranbia Gasteiz+',
            'tile_title' => 'Tranbia',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'tile_city' => 'Gasteiz',
            'switcher' => 'Gasteizko tranbiaren ordutegiak eta denbora errealean',
            'subtitle' => 'Tranbiaren ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo tranbia ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Euskotren (Gasteizko tranbia) / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Tranvía Vitoria+',
            'tile' => 'Timetables and real time',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Vitoria',
            'switcher' => 'Vitoria-Gasteiz tram timetables and real time',
            'subtitle' => 'Tram timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next tram.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Tranvía Vitoria+ · Horarios y tiempo real del tranvía de Vitoria-Gasteiz',
        'description' => 'Consulta los horarios y el tiempo real del tranvía de Vitoria-Gasteiz por parada.',
    ],
    'attribution' => 'Datos: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)',

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
        'matching' => 'trip',
        'match_tolerance_seconds' => 600,
    ],

    'siri' => [
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'gtfs' => [
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1263',
        'fallback_source' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfs_euskotren.zip',
        'agency_id' => 'ES:Euskotren:Operator:EUS_TrGa:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => false,
    ],
];
