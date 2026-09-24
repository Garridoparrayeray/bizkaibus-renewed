<?php

return [
    'network'                   => 'tranvia-vitoria',
    'db_path'                   => __DIR__ . '/../../data/tranviavitoria.sqlite',

    'schedule_source_published' => date('Y-m-d'),

    'siri' => [
        'vehicle_monitoring_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_vehicle_monitoring.xml',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'attribution'               => 'Datos: Euskotren (Tranvía Vitoria) / Open Data Euskadi (CC-BY 4.0)',
];
