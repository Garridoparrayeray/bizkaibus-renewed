<?php

return [
    'network' => 'metro',
    'db_path' => __DIR__ . '/../../data/metrobilbao.sqlite',

    'metro_alerts' => [
        'url' => 'https://cms.metrobilbao.eus/es/get/open_data/json/avisos/es',
        'cache_ttl_seconds' => 60,
        'http_timeout_seconds' => 8,
    ],

    'schedule_source_published' => date('Y-m-d'),

    'direction_reference_stop_id' => 7,

    'attribution' => 'Datos: Metro Bilbao / Open Data Metro Bilbao (metrobilbao.eus)',
];
