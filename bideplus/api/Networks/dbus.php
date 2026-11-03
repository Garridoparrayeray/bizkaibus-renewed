<?php

// Dbus, los autobuses urbanos de Donostia / San Sebastián. Horario y directo de Open Data Euskadi (Moveuskadi): el
// retraso de cada autobús y dónde va cada uno.
return [
    'slug' => 'dbus',
    'order' => 10,
    'db' => 'dbus',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-02',

    'brand' => [
        'icons' => 'icons-dbus',
        'theme_color' => '#0141AD',
        'title' => ['DBUS', 'DONOSTIA'],
        'logomark' => '<img src="/icons-dbus/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-dbus/icon-192.png" alt="">',
        'tile_color' => '#0141AD',
        'swatch_color' => '#0141AD',
        'palette' => [
            'bg' => '#eef0f3',
            'border' => '#d3d6dd',
            'primary' => '#0141AD',
            'primary-light' => '#8daada',
            'primary-dark' => '#01286b',
            'primary-tint' => '#ebf0f8',
            'text' => '#1c2027',
            'text-secondary' => '#585e66',
            'backdrop' => 'rgba(0, 6, 17, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Dbus+',
            'tile' => 'Autobuses de Donostia',
            'switcher' => 'Horarios y tiempo real de Dbus en Donostia',
            'subtitle' => 'Horarios y tiempo real de Dbus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Dbus ni con el Ayuntamiento de Donostia / San Sebastián. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Dbus+',
            'tile' => 'Donostiako autobusak',
            'switcher' => 'Dbusen ordutegiak eta denbora erreala Donostian',
            'subtitle' => 'Dbusen ordutegiak eta denbora erreala',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Dbusekin eta Donostiako Udalarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Dbus / Open Data Euskadi, Moveuskadiren bidez (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Dbus+',
            'tile' => 'Donostia buses',
            'switcher' => 'Dbus timetables and real time in Donostia',
            'subtitle' => 'Dbus timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Dbus or with Donostia / San Sebastián City Council. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Dbus / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Dbus+ · Autobuses de Donostia / San Sebastián en tiempo real por línea y parada | Bide+',
        'description' => 'Consulta los próximos autobuses de Dbus en Donostia / San Sebastián en tiempo real, sus horarios por línea y parada y dónde va cada autobús en el mapa.',
    ],
    'attribution' => 'Datos: Dbus / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0)',

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

    'live' => ['trip-updates', 'vehicle-positions'],

    // El directo trae el viaje del horario de cada autobús: se empareja por viaje.
    'gtfs_rt' => [
        'trip_updates_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/dbus/gtfsrt_dbus_trip_updates.pb',
        'vehicle_positions_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/dbus/gtfsrt_dbus_vehicle_positions.pb',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/dbus/gtfsrt_dbus_alerts.pb',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/dbus/gtfs_dbus.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
