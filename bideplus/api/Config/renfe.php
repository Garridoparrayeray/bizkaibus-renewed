<?php

return [
    'network'                   => 'renfe',
    'db_path'                   => __DIR__ . '/../../data/renfe.sqlite',

    'schedule_source_published' => date('Y-m-d'),

    'renfe_alerts' => [
        'url' => 'https://gtfsrt.renfe.com/alerts.json',
        'cache_ttl_seconds' => 120,
        'http_timeout_seconds' => 8,
    ],

    'attribution'               => 'Datos: Renfe Cercanías / NAP (Punto de Acceso Nacional de Transporte)',
];
