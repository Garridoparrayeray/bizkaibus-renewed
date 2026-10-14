<?php

return [
    'network' => 'bus',
    'db_path' => __DIR__ . '/../../data/bizkaibus.sqlite',

    'siri' => [
        'vehicle_monitoring_url' => 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-vehicle-positions.xml',
        // Solo Open Data Euskadi: trae GPS, hora del dato y retraso. El feed del CTB (bizkaibus-trip-updates.xml) no trae ninguno de los tres y daba «En hora» a todos los buses.
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/bizkaibus/siri_bizkaibus_situation_exchange.xml',
        'alerts_fallback_url' => 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'schedule_text' => [

        'url' => 'http://apps.bizkaia.eus/BBOA000M/rest/BBOA/GetLineasHorarios',
        'cache_ttl_seconds' => 3600,
        'http_timeout_seconds' => 8,
    ],

    'schedule_source_published' => '2025-01-24',

    'attribution' => 'Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)',
];
