<?php

// Tuvisa, los autobuses urbanos de Vitoria-Gasteiz. Horario y directo de Open Data Euskadi (Moveuskadi): el retraso de
// cada autobús, sus incidencias y dónde va cada uno.
return [
    'slug' => 'tuvisa',
    'order' => 9,
    'db' => 'tuvisa',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-02',

    'brand' => [
        'icons' => 'icons-tuvisa',
        'theme_color' => '#00796B',
        'title' => ['TUVISA', 'VITORIA-GASTEIZ'],
        'logomark' => '<img src="/icons-tuvisa/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-tuvisa/icon-192.png" alt="">',
        'tile_color' => '#00796B',
        'swatch_color' => '#00796B',
        'palette' => [
            'bg' => '#eef1f1',
            'border' => '#d3dad9',
            'primary' => '#00796B',
            'primary-light' => '#8cc3bc',
            'primary-dark' => '#004b42',
            'primary-tint' => '#ebf4f3',
            'text' => '#1c2323',
            'text-secondary' => '#586261',
            'backdrop' => 'rgba(0, 12, 11, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Tuvisa+',
            'tile' => 'Autobuses de Vitoria-Gasteiz',
            'switcher' => 'Horarios y tiempo real de Tuvisa en Vitoria-Gasteiz',
            'subtitle' => 'Horarios y tiempo real de Tuvisa',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Tuvisa ni con el Ayuntamiento de Vitoria-Gasteiz. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'name' => 'Tuvisa+',
            'tile' => 'Gasteizko autobusak',
            'switcher' => 'Tuvisaren ordutegiak eta denbora erreala Gasteizen',
            'subtitle' => 'Tuvisaren ordutegiak eta denbora erreala',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Tuvisarekin eta Vitoria-Gasteizko Udalarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Tuvisa / Open Data Euskadi, Moveuskadiren bidez (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Tuvisa+',
            'tile' => 'Vitoria-Gasteiz buses',
            'switcher' => 'Tuvisa timetables and real time in Vitoria-Gasteiz',
            'subtitle' => 'Tuvisa timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Tuvisa or with Vitoria-Gasteiz City Council. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Tuvisa / Open Data Euskadi, via Moveuskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Tuvisa+ · Autobuses de Vitoria-Gasteiz en tiempo real por línea y parada | Bide+',
        'description' => 'Consulta los próximos autobuses de Tuvisa en Vitoria-Gasteiz en tiempo real, sus horarios por línea y parada, las incidencias y dónde va cada autobús en el mapa.',
    ],
    'attribution' => 'Datos: Tuvisa / Open Data Euskadi, vía Moveuskadi (CC-BY 4.0)',

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
        'trip_updates_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/tuvisa/gtfsrt_tuvisa_trip_updates.pb',
        'vehicle_positions_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/tuvisa/gtfsrt_tuvisa_vehicle_positions.pb',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/tuvisa/gtfsrt_tuvisa_alerts.pb',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/tuvisa/gtfs_tuvisa.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
