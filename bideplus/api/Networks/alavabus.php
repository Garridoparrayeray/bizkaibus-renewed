<?php

// Álavabus, los autobuses interurbanos de Álava (y el transporte comarcal). Horario de Open Data Euskadi (Moveuskadi);
// no publica directo, así que va con el horario oficial.
return [
    'slug' => 'alavabus',
    'order' => 11,
    'db' => 'alavabus',
    'kind' => 'bus',
    // Recién llegada: «Nuevo» en su ficha del menú de Bide+ hasta este día.
    'new_until' => '2026-12-02',

    'brand' => [
        'icons' => 'icons-alavabus',
        'theme_color' => '#A8034F',
        'title' => ['ÁLAVABUS', 'ARABA'],
        'logomark' => '<img class="is-icon" src="/icons-alavabus/icon-192.png" alt="">',
        'card_logomark' => '<img src="/icons-alavabus/icon-192.png" alt="">',
        'tile_color' => '#A8034F',
        'swatch_color' => '#A8034F',
        'palette' => [
            'bg' => '#f3eef0',
            'border' => '#ddd3d7',
            'primary' => '#A8034F',
            'primary-light' => '#d88eb0',
            'primary-dark' => '#680231',
            'primary-tint' => '#f8ebf1',
            'text' => '#261c21',
            'text-secondary' => '#66595f',
            'backdrop' => 'rgba(17, 0, 8, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Álavabus+',
            'tile' => 'Autobuses de Álava',
            'switcher' => 'Horarios de Álavabus en Álava',
            'subtitle' => 'Horarios de Álavabus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Álavabus ni con la Diputación Foral de Álava. Horario programado oficial: no refleja retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'Álavabus+',
            'tile' => 'Arabako autobusak',
            'switcher' => 'Álavabusen ordutegiak Araban',
            'subtitle' => 'Álavabusen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Álavabusekin eta Arabako Foru Aldundiarekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'attribution' => 'Datuak: Álavabus / Arabako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Álavabus+',
            'tile' => 'Álava buses',
            'switcher' => 'Álavabus timetables in Álava',
            'subtitle' => 'Álavabus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Álavabus or with the Provincial Council of Álava. Official scheduled timetable: it does not reflect delays or cancellations.',
            'attribution' => 'Data: Álavabus / Provincial Council of Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Álavabus+ · Horarios de los autobuses de Álava por línea y parada | Bide+',
        'description' => 'Consulta los horarios de Álavabus, los autobuses interurbanos de Álava, por línea y parada, con el recorrido de cada línea en el mapa.',
    ],
    'attribution' => 'Datos: Álavabus / Diputación Foral de Álava, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

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
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/alavabus/gtfs_alavabus.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
