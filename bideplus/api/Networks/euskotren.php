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
    ],

    'texts' => [
        'es' => [
            'name' => 'Euskotren+',
            'tile' => 'Tren con horarios oficiales',
            'switcher' => 'Horarios de Euskotren',
            'subtitle' => 'Horarios de Euskotren',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.',
        ],
        'eu' => [
            'name' => 'Euskotren+',
            'tile' => 'Trena, ordutegi ofizialekin',
            'switcher' => 'Euskotrenen ordutegiak',
            'subtitle' => 'Euskotrenen ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
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
        'trip_detail' => 'stops',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    // Sin datos en directo fiables: se muestra el horario oficial.
    'live' => [],

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
        'required' => true,
    ],
];
