<?php

// Bilbobus, los autobuses urbanos de Bilbao. Horario y directo (SIRI) del Consorcio de Transportes de Bizkaia, con el
// motor de Bizkaibus. Sus feeds llegan vacíos también en hora punta (3/11/2026): horario oficial hasta que traigan
// autobuses; entonces el motor los usa y la captura (cada 10 minutos) aprende sus tramos.
return [
    'slug' => 'bilbobus',
    'order' => 8,
    'db' => 'bilbobus',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-02',

    'brand' => [
        'icons' => 'icons-bilbobus',
        'theme_color' => '#C0362C',
        'title' => ['BILBOBUS', 'BILBAO'],
        'logomark' => '<img src="/icons-bilbobus/glyph.png" alt="Bilbobus+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-bilbobus/icon-192.png" alt="Bilbobus+" aria-hidden="true">',
        'tile_color' => '#C0362C',
        'swatch_color' => '#C0362C',
        'palette' => [
            'bg' => '#f3efef',
            'border' => '#ded6d5',
            'primary' => '#C0362C',
            'primary-light' => '#e3a5a0',
            'primary-dark' => '#77211b',
            'primary-tint' => '#faefee',
            'text' => '#281f1f',
            'text-secondary' => '#685d5c',
            'backdrop' => 'rgba(19, 5, 4, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Bilbobus+',
            'tile' => 'Autobuses de Bilbao',
            'switcher' => 'Horarios de Bilbobus en Bilbao',
            'subtitle' => 'Horarios de Bilbobus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Bilbobus ni con el Ayuntamiento de Bilbao. Las llegadas se calculan con la posición GPS de cada autobús; sin datos del autobús, horario oficial.',
            'dataPublished' => 'La posición GPS de cada autobús, con cortes frecuentes.',
            'dataMethod' => 'El mismo algoritmo que Bizkaibus.',
            'dataLimits' => 'Cuando su feed llega vacío, horario oficial.',
        ],
        'eu' => [
            'name' => 'Bilbobus+',
            'tile' => 'Bilboko autobusak',
            'switcher' => 'Bilbobusen ordutegiak Bilbon',
            'subtitle' => 'Bilbobusen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Bilbobusekin eta Bilboko Udalarekin loturarik gabea. Iritsierak autobus bakoitzaren GPS kokapenarekin kalkulatzen dira; autobusaren daturik gabe, ordutegi ofiziala.',
            'dataPublished' => 'Autobus bakoitzaren GPS kokapena, sarritan etenda.',
            'dataMethod' => 'Bizkaibusen algoritmo bera.',
            'dataLimits' => 'Jarioa hutsik datorrenean, ordutegi ofiziala.',
            'attribution' => 'Datuak: Bilbobus / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Bilbobus+',
            'tile' => 'Bilbao buses',
            'switcher' => 'Bilbobus timetables in Bilbao',
            'subtitle' => 'Bilbobus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Bilbobus or with Bilbao City Council. Arrivals are calculated from the GPS position of every bus; without data from the bus, the official timetable.',
            'dataPublished' => 'The GPS position of every bus, with frequent outages.',
            'dataMethod' => 'The same algorithm as Bizkaibus.',
            'dataLimits' => 'When its feed comes empty, the official timetable.',
            'attribution' => 'Data: Bilbobus / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Bilbobus+ · Horarios de los autobuses de Bilbao por línea y parada | Bide+',
        'description' => 'Consulta los horarios de Bilbobus, los autobuses urbanos de Bilbao, por línea y parada, con el recorrido de cada línea en el mapa.',
    ],
    'attribution' => 'Datos: Bilbobus / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0)',

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

    'live' => ['vehicle-monitoring'],
    // El feed llega vacío a ratos aunque haya servicio: «Vigilar el directo» lo apunta como aviso, no como fallo.
    'live_often_empty' => true,
    'engine' => 'bilbobus',

    // El feed del CTB y la copia de Open Data Euskadi. Avisos: el CTB no publica ninguno para Bilbobus.
    'siri' => [
        'vehicle_monitoring_url' => 'https://ctb-siri.s3.eu-south-2.amazonaws.com/bilbobus-vehicle-positions.xml',
        'vehicle_monitoring_fallback_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/bilbobus/siri_bilbobus_vehicle_monitoring.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    // El GTFS del CTB es la versión más reciente; el de Open Data Euskadi, la misma con un par de días de retraso.
    'gtfs' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bilbobus.zip',
        'fallback_source' => 'https://opendata.euskadi.eus/transport/moveuskadi/bilbobus/gtfs_bilbobus.zip',
        'agency_id' => null,
        'stops' => 'generic',
        // Los rótulos llevan delante el tipo de horario: «PT-Semana / (PLAZA BIRIBILA)».
        'headsign_pattern' => '/\(([^)]+)\)\s*$/',
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
