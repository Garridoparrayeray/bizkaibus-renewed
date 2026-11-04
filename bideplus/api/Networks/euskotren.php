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
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
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
            'switcher' => 'Horarios y tiempo real de Euskotren',
            'subtitle' => 'Horarios y tiempo real de Euskotren',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Euskotren+',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'switcher' => 'Euskotrenen ordutegiak eta denbora errealean',
            'subtitle' => 'Euskotrenen ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Euskotren+',
            'tile' => 'Timetables and real time',
            'switcher' => 'Euskotren timetables and real time',
            'subtitle' => 'Euskotren timetables and real time',
            'liveEmpty' => 'Search for a station to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Euskotren+ · Horarios y tiempo real de trenes y tranvía de Euskotren por línea y estación | Bide+',
        'description' => 'Consulta los horarios y el tiempo real de Euskotren, trenes y tranvía, por línea y estación en Bizkaia y Gipuzkoa.',
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
        // Cada viaje del feed trae su viaje del horario y su hora en las paradas que le quedan: se empareja por el viaje
        // (y, si ninguno está en el horario, por paradas y hora con este margen).
        'matching' => 'trip',
        'match_tolerance_seconds' => 600,
    ],

    'siri' => [
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    // Mapa de la red: los colores del plano del operador (el GTFS trae el mismo para todas las líneas).
    'map' => [
        'colors' => ['E1' => '#0057A6', 'E2' => '#B5BD00', 'E3' => '#4FB3E8', 'E3a' => '#4FB3E8', 'E4' => '#1B3F8B', 'L3' => '#E5007E'],
    ],

    'gtfs' => [
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1263',
        'fallback_source' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfs_euskotren.zip',
        'agency_id' => 'ES:Euskotren:Operator:EUS_Tren:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => true,
    ],
];
