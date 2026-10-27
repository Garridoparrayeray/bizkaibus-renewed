<?php

return [
    'slug' => 'metro',
    'order' => 2,
    'db' => 'metrobilbao',
    'kind' => 'metro',

    'brand' => [
        'icons' => 'icons-metro',
        'theme_color' => '#C8102E',
        'title' => ['METRO', ''],
        'logomark' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.3" fill="#C8102E"/><circle cx="9.25" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/><circle cx="12" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/><circle cx="14.75" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/></svg>',
    ],

    'texts' => [
        'es' => [
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Horarios de Metro Bilbao',
            'subtitle' => 'Horarios de Metro Bilbao',
            'liveEmpty' => 'Busca una estación para ver el próximo metro.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A. ni con el Consorcio de Transportes de Bizkaia. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Metro+',
            'tile' => 'Bilboko Metroa',
            'switcher' => 'Bilboko Metroaren ordutegiak',
            'subtitle' => 'Bilboko Metroaren ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo metroa ikusteko.',
        ],
    ],

    'seo' => [
        'title' => 'Metro+ · Horarios de Metro Bilbao: líneas L1, L2 y L3, estaciones y avisos | Bide+',
        'description' => 'Consulta los horarios de Metro Bilbao por línea y estación, con los próximos metros y los avisos de servicio.',
    ],
    'attribution' => 'Datos: Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0): horario y tiempo real',

    'features' => [
        'platforms' => true,
        'headsign_from_last_stop' => true,
        'stop_hints' => false,
        'line_map' => false,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => false,
    ],

    'live' => ['trip-updates'],

    'metro_alerts' => [
        'url' => 'https://cms.metrobilbao.eus/es/get/open_data/json/avisos/es',
        'cache_ttl_seconds' => 60,
        'http_timeout_seconds' => 8,
    ],

    'siri' => [
        'vehicle_monitoring_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/metro_bilbao/siri_metro_bilbao_vehicle_monitoring.xml',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/metro_bilbao/siri_metro_bilbao_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'gtfs_rt' => [
        'trip_updates_url' => 'https://ctb-gtfs-rt.s3.eu-south-2.amazonaws.com/metro-bilbao-trip-updates.pb',
        'cache_ttl_seconds' => 15,
        'http_timeout_seconds' => 6,
        'max_age_seconds' => 300,
        'match_tolerance_seconds' => 600,
    ],

    'direction_reference_stop_id' => 7,

    'gtfs' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip',
        'fallback_source' => 'https://nap.transportes.gob.es/api/Fichero/download/1066',
        'agency_id' => null,
        'stops' => 'metro',
        'skip_geocode' => true,
        'merge_calendars' => true,
        'required' => true,
    ],
];
