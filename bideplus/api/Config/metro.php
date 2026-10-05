<?php

return [
    'network' => 'metro',
    'db_path' => __DIR__ . '/../../data/metrobilbao.sqlite',

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

    'schedule_source_published' => date('Y-m-d'),

    'direction_reference_stop_id' => 7,

    'attribution' => 'Datos: Metro Bilbao / Open Data Metro Bilbao (metrobilbao.eus) · Tiempo real: Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0)',
];
