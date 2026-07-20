<?php

return [
    'db_path' => __DIR__ . '/../../data/bizkaibus.sqlite',

    'siri' => [
        'alerts_url' => 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-service-alerts.xml',
        'vehicle_monitoring_url' => 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bizkaibus-trip-updates.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'schedule_text' => [
        // Endpoint legado: horario oficial en texto libre por línea (actualizado, pero sin estructurar).
        'url' => 'http://apps.bizkaia.eus/BBOA000M/rest/BBOA/GetLineasHorarios',
        'cache_ttl_seconds' => 3600,
        'http_timeout_seconds' => 8,
    ],

    'schedule_source_published' => '2025-01-24',

    'attribution' => 'Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)',
];
