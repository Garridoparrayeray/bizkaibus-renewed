<?php

// Bizkaibus. Cada red de Bide+ se describe en un fichero como este: de aquí salen la API,
// la página de la app, el menú, el sitemap y la reconstrucción diaria de horarios.

return [
    'slug' => 'bus',
    'order' => 1,
    'db' => 'bizkaibus',
    'kind' => 'bus',

    'brand' => [
        'icons' => 'icons-pro',
        'theme_color' => '#01573C',
        'title' => ['BizkaiBus', ''],
        'logomark' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 2.5C7 4 4 8.8 4 13.5A8 8 0 0 0 12 21.5A8 8 0 0 0 20 13.5C20 8.8 17 4 12 2.5Z" fill="#9CCD64"/><path d="M12 6V19" stroke="#01573C" stroke-width="1.3" stroke-linecap="round"/></svg>',
    ],

    'texts' => [
        'es' => [
            'name' => 'Bizkaibus+',
            'tile' => 'Horarios y tiempo real',
            'switcher' => 'Horarios y tiempo real de Bizkaibus',
            'subtitle' => 'Horarios y tiempo real de Bizkaibus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Bizkaibus ni con la Diputación Foral de Bizkaia.',
        ],
        'eu' => [
            'name' => 'Bizkaibus+',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'switcher' => 'Bizkaibusen ordutegiak eta zuzenekoa',
            'subtitle' => 'Bizkaibusen ordutegiak eta zuzenekoa',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
        ],
    ],

    'seo' => [
        'title' => 'BizkaiBus+ · Horarios y tiempo real de Bizkaibus',
        'description' => 'Consulta horarios, líneas, paradas y llegadas en tiempo real de Bizkaibus.',
    ],
    'attribution' => 'Datos: Bizkaibus / Open Data Bizkaia (CC-BY 4.0)',

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => true,
        'line_map' => true,
        'schedule_text' => true,
        'trip_detail' => 'vehicle',
        'alerts' => 'lines',
        'alerts_detail' => true,
    ],

    // Fuentes en directo: 'vehicle-monitoring' (SIRI, con el motor de engine/<engine>) y 'trip-updates' (GTFS-RT).
    'live' => ['vehicle-monitoring'],
    'engine' => 'bizkaibus',

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

    'gtfs' => [
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1061',
        'fallback_source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bizkaibus.zip',
        'agency_id' => '200',
        'stops' => 'bus',
        'skip_geocode' => false,
        'feed_window' => true,
        'required' => true,
    ],
];
