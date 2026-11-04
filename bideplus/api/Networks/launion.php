<?php

// La Unión, los autobuses de Autobuses La Unión entre Bizkaia y Vitoria-Gasteiz (Margen Izquierda y Derecha, Bilbao,
// Leioa, Algorta y el aeropuerto de Loiu). Horario de Open Data Euskadi (Moveuskadi); no publica directo, así que va
// con el horario oficial.
return [
    'slug' => 'launion',
    'order' => 12,
    'db' => 'launion',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-03',

    'brand' => [
        'icons' => 'icons-launion',
        'theme_color' => '#30609D',
        'title' => ['LA UNIÓN', 'BILBAO · GASTEIZ'],
        'logomark' => '<img src="/icons-launion/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-launion/icon-192.png" alt="">',
        'tile_color' => '#30609D',
        'swatch_color' => '#30609D',
        'palette' => [
            'bg' => '#eff1f2',
            'border' => '#d5d8dc',
            'primary' => '#30609D',
            'primary-light' => '#a2b7d3',
            'primary-dark' => '#1e3c61',
            'primary-tint' => '#eef2f7',
            'text' => '#1f2226',
            'text-secondary' => '#5c6065',
            'backdrop' => 'rgba(5, 10, 16, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'La Unión+',
            'tile' => 'Autobuses Bilbao - Vitoria',
            'switcher' => 'Horarios de La Unión entre Bizkaia y Vitoria-Gasteiz',
            'subtitle' => 'Horarios de La Unión',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Autobuses La Unión. Horario programado oficial: no refleja retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'La Unión+',
            'tile' => 'Bilbo - Gasteiz autobusak',
            'switcher' => 'La Uniónen ordutegiak Bizkaia eta Vitoria-Gasteiz artean',
            'subtitle' => 'La Uniónen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Autobuses La Uniónekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'attribution' => 'Datuak: Autobuses La Unión, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'La Unión+',
            'tile' => 'Bilbao - Vitoria buses',
            'switcher' => 'La Unión timetables between Bizkaia and Vitoria-Gasteiz',
            'subtitle' => 'La Unión timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Autobuses La Unión. Official scheduled timetable: it does not reflect delays or cancellations.',
            'attribution' => 'Data: Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'La Unión+ · Horarios de los autobuses Bilbao - Vitoria por línea y parada | Bide+',
        'description' => 'Consulta los horarios de Autobuses La Unión entre Bizkaia y Vitoria-Gasteiz (Bilbao, Margen Izquierda y Derecha, Leioa, Algorta y el aeropuerto de Loiu) por línea y parada, con el recorrido de cada línea en el mapa.',
    ],
    'attribution' => 'Datos: Autobuses La Unión, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

    'features' => [
        'platforms' => false,
        // El GTFS pone «U» como destino de todos los viajes: el destino es su última parada.
        'headsign_from_last_stop' => true,
        'stop_hints' => true,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'stops',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => [],

    'gtfs' => [
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/la_union/gtfs_la_union.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        // Cada parada lleva ya su pueblo en el nombre («ALGORTA - TELLETXE 1»).
        'skip_geocode' => true,
        'feed_window' => true,
        'required' => false,
    ],
];
