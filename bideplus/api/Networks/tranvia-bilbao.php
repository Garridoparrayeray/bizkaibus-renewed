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
    ],

    'texts' => [
        'es' => [
            'name' => 'Tranvía Bilbao+',
            'tile' => 'Horarios del tranvía',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Bilbao',
            'switcher' => 'Horarios del tranvía de Bilbao',
            'subtitle' => 'Horarios del tranvía',
            'liveEmpty' => 'Busca una parada para ver el próximo tranvía.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.',
        ],
        'eu' => [
            'name' => 'Tranbia Bilbo+',
            'tile' => 'Tranbiaren ordutegiak',
            'tile_city' => 'Bilbo',
            'switcher' => 'Bilboko tranbiaren ordutegiak',
            'subtitle' => 'Tranbiaren ordutegiak',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo tranbia ikusteko.',
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
        'agency_id' => 'ES:Euskotren:Operator:EUS_TrBi:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        'required' => false,
    ],
];
