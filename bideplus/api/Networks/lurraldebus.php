<?php

return [
    'slug' => 'lurraldebus',
    'order' => 5,
    'db' => 'lurraldebus',
    'kind' => 'bus',

    'brand' => [
        'icons' => 'icons-lurraldebus',
        'theme_color' => '#0062A8',
        'title' => ['LURRALDEBUS', ''],
        'logomark' => '<img class="is-icon" src="/icons-lurraldebus/icon-192.png" alt="">',
        'card_logomark' => '<img src="/icons-lurraldebus/icon-192.png" alt="">',
    ],

    'texts' => [
        'es' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Autobuses de Gipuzkoa',
            'switcher' => 'Horarios de Lurraldebus en Gipuzkoa',
            'subtitle' => 'Horarios de Lurraldebus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Lurraldebus ni con la Diputación Foral de Gipuzkoa. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.',
        ],
        'eu' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Gipuzkoako autobusak',
            'switcher' => 'Lurraldebusen ordutegiak Gipuzkoan',
            'subtitle' => 'Lurraldebusen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
        ],
    ],

    'seo' => [
        'title' => 'Lurraldebus+ · Horarios de los autobuses de Gipuzkoa por línea y parada | Bide+',
        'description' => 'Consulta los horarios de Lurraldebus en Gipuzkoa por línea y parada: Ekialdebus, Gipuzkoana, Goierrialdea, Pesa, TBH, Tolosaldea y Euskotren bus.',
    ],
    'attribution' => 'Datos: Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi)',

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => true,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    // Motor propio en engine/lurraldebus. Los feeds en directo de los operadores llegan vacíos (27/10/2026):
    // se activa con 'live' => ['vehicle-monitoring'] cuando traigan buses.
    'live' => [],
    'engine' => 'lurraldebus',

    'siri' => [
        'vehicle_monitoring_urls' => [
            'ekialdebus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/siri_ekialdebus_vehicle_monitoring.xml',
            'goierrialdea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/siri_goierrialdea_vehicle_monitoring.xml',
            'gipuzkoana' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/siri_guipuzkoana_vehicle_monitoring.xml',
            'pesa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/siri_pesa_vehicle_monitoring.xml',
            'tbh' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/siri_tbh_vehicle_monitoring.xml',
        ],
        'alerts_urls' => [
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/siri_ekialdebus_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/siri_goierrialdea_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/siri_guipuzkoana_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/siri_pesa_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/siri_tbh_situation_exchange.xml',
        ],
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 4,
    ],

    // Un GTFS por operador; build-database.php los junta (ver mergeGtfsZips).
    'gtfs' => [
        'sources' => [
            'ekialdebus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/gtfs_ekialdebus.zip',
            'euskotrenbus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/euskotren_bus/gtfs_euskotren_bus.zip',
            'goierrialdea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/gtfs_goierrialdea.zip',
            'gipuzkoana' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/gtfs_guipuzkoana.zip',
            'pesa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/gtfs_pesa.zip',
            'tbh' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/gtfs_tbh.zip',
            'tolosaldea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosaldea/gtfs_tolosaldea.zip',
        ],
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'required' => false,
    ],
];
