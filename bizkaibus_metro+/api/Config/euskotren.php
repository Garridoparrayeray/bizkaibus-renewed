<?php

return [
    'network'                   => 'euskotren',
    'db_path'                   => __DIR__ . '/../../data/euskotren.sqlite',

    'schedule_source_published' => date('Y-m-d'),

    'siri' => [
        'vehicle_monitoring_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_vehicle_monitoring.xml',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    // Sin direction_reference_stop_id a propósito: a diferencia de Metro+
    // (GTFS sin andenes, necesita la dirección genérica "hacia/desde"),
    // Euskotren sí tiene el andén real de cada parada (ver
    // loadStopsEuskotren() en build-database.php), así que
    // StopsController::departures() agrupa por andén real en vez de por
    // dirección — más preciso y no necesita ninguna parada de referencia.

    'attribution'               => 'Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)',
];
