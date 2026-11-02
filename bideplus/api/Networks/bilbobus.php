<?php

// Bilbobus, los autobuses urbanos de Bilbao. Horario del GTFS que publica el Consorcio de Transportes de Bizkaia; su
// directo llega vacío, así que va con el horario oficial.
return [
    'slug' => 'bilbobus',
    'order' => 8,
    'db' => 'bilbobus',
    'kind' => 'bus',

    'brand' => [
        'icons' => 'icons-bilbobus',
        'theme_color' => '#C0362C',
        'title' => ['BILBOBUS', 'BILBAO'],
        'logomark' => '<img class="is-icon" src="/icons-bilbobus/icon-192.png" alt="">',
        'card_logomark' => '<img src="/icons-bilbobus/icon-192.png" alt="">',
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
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Bilbobus ni con el Ayuntamiento de Bilbao. Horario programado oficial: no refleja retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'Bilbobus+',
            'tile' => 'Bilboko autobusak',
            'switcher' => 'Bilbobusen ordutegiak Bilbon',
            'subtitle' => 'Bilbobusen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Bilbobusekin eta Bilboko Udalarekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'attribution' => 'Datuak: Bilbobus / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Bilbobus+',
            'tile' => 'Bilbao buses',
            'switcher' => 'Bilbobus timetables in Bilbao',
            'subtitle' => 'Bilbobus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Bilbobus or with Bilbao City Council. Official scheduled timetable: it does not reflect delays or cancellations.',
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
        'trip_detail' => 'stops',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => [],

    'gtfs' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bilbobus.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        // Los rótulos llevan delante el tipo de horario: «PT-Semana / (PLAZA BIRIBILA)».
        'headsign_pattern' => '/\(([^)]+)\)\s*$/',
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
