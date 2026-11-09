<?php

// Trenes de larga y media distancia de Renfe que pasan por Euskadi y Navarra (Alvia, Intercity, Media Distancia,
// Regional, AVE…). El horario es el que publica Moveuskadi y el directo, el GTFS-Realtime de Renfe para esos trenes.
return [
    'slug' => 'renfe-ld',
    'order' => 13,
    'db' => 'renfe-ld',
    'kind' => 'train',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-09',

    'brand' => [
        'icons' => 'icons-renfe-ld',
        'theme_color' => '#3F4E63',
        'title' => ['RENFE LD', ''],
        'logomark' => '<img src="/icons-renfe-ld/glyph.png" alt="Renfe Larga Distancia+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-renfe-ld/icon-192.png" alt="Renfe Larga Distancia+" aria-hidden="true">',
        'tile_color' => '#3F4E63',
        'swatch_color' => '#3F4E63',
        'palette' => [
            'bg' => '#f0f0f1',
            'border' => '#d6d7d9',
            'primary' => '#3F4E63',
            'primary-light' => '#a9afb9',
            'primary-dark' => '#27303d',
            'primary-tint' => '#f0f1f3',
            'text' => '#202122',
            'text-secondary' => '#5d5f60',
            'backdrop' => 'rgba(6, 8, 10, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Renfe Larga Distancia+',
            'tile' => 'Larga y media distancia en Euskadi y Navarra',
            'switcher' => 'Horarios y tiempo real de los trenes de larga y media distancia',
            'subtitle' => 'Larga y media distancia en tiempo real',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos son los que publica Renfe en directo.',
            'dataPublished' => 'El retraso de cada tren, los cancelados y su posición, en un directo de toda España.',
            'dataMethod' => 'El retraso que publica Renfe; solo los trenes de hoy.',
            'dataLimits' => 'Algunos trenes no salen en su directo y van con su horario.',
        ],
        'eu' => [
            'name' => 'Renfe Distantzia Luzea+',
            'tile' => 'Distantzia luzeko eta ertaineko trenak Euskadin eta Nafarroan',
            'switcher' => 'Distantzia luzeko eta ertaineko trenen ordutegiak eta denbora errealean',
            'subtitle' => 'Distantzia luzeko eta ertaineko trenak denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Renferekin loturarik gabea. Atzerapenak Renfek zuzenean argitaratzen dituenak dira.',
            'dataPublished' => 'Tren bakoitzaren atzerapena, bertan behera utzitakoak eta haren kokapena, Espainia osoko zuzeneko batean.',
            'dataMethod' => 'Renfek argitaratzen duen atzerapena; gaurko trenak bakarrik.',
            'dataLimits' => 'Tren batzuk ez daude zuzenekoan eta beren ordutegiarekin doaz.',
            'attribution' => 'Datuak: Renfe, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0) eta data.renfe.com (CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Renfe Long Distance+',
            'tile' => 'Long and medium distance trains in the Basque Country and Navarre',
            'switcher' => 'Long and medium distance train timetables and real time',
            'subtitle' => 'Long and medium distance trains in real time',
            'liveEmpty' => 'Search for a station to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Renfe. Delays are those Renfe publishes live.',
            'dataPublished' => 'Each train\'s delay, cancellations and its position, in a live feed for all of Spain.',
            'dataMethod' => 'The delay Renfe publishes; only today\'s trains.',
            'dataLimits' => 'Some trains are missing from the live feed and show their timetable.',
            'attribution' => 'Data: Renfe, via Moveuskadi (Open Data Euskadi, CC-BY 4.0) and data.renfe.com (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Renfe Larga Distancia+ · Horarios y retrasos en tiempo real de los trenes de larga y media distancia',
        'description' => 'Consulta los horarios de los trenes de larga y media distancia de Renfe (Alvia, Intercity, Media Distancia, Regional…) que paran en Euskadi y Navarra, con su retraso en tiempo real.',
    ],
    'attribution' => 'Datos: Renfe, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0) y data.renfe.com (CC-BY 4.0)',

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates', 'vehicle-positions'],

    // Directo de Renfe para estos trenes (GTFS-Realtime): el retraso de cada uno y dónde va. Sus viajes son los del
    // horario (con su fecha), así que se empareja por viaje.
    'gtfs_rt' => [
        'trip_updates_url' => 'https://gtfsrt.renfe.com/trip_updates_LD.pb',
        'vehicle_positions_url' => 'https://gtfsrt.renfe.com/vehicle_positions_LD.pb',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
        // La cabecera de sus posiciones va una hora atrasada (9/11/2026; la de los retrasos, no): vale la hora de la
        // cabecera y también la corregida, por si Renfe lo arregla.
        'clock_offset_seconds' => 3600,
        // El id de sus viajes acaba con su fecha de servicio y arrastra los de ayer: sólo valen los de hoy.
        'trip_date_suffix' => true,
    ],

    'gtfs' => [
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/renfe_media/gtfs_renfe_media.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'name_lines_from_stops' => true,
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
