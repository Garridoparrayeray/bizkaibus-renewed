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
        'logomark' => '<img src="/icons-launion/glyph.png" alt="La Unión+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-launion/icon-192.png" alt="La Unión+" aria-hidden="true">',
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
            'dataPublished' => 'Solo su horario.',
            'dataMethod' => 'Horario oficial.',
            'dataLimits' => 'Sin retrasos ni cancelaciones.',
        ],
        'eu' => [
            'name' => 'La Unión+',
            'tile' => 'Bilbo - Gasteiz autobusak',
            'switcher' => 'La Uniónen ordutegiak Bizkaia eta Vitoria-Gasteiz artean',
            'subtitle' => 'La Uniónen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Autobuses La Uniónekin loturarik gabea. Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
            'dataPublished' => 'Bere ordutegia bakarrik.',
            'dataMethod' => 'Ordutegi ofiziala.',
            'dataLimits' => 'Atzerapenik eta bertan behera utzitakorik gabe.',
            'attribution' => 'Datuak: Autobuses La Unión, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'La Unión+',
            'tile' => 'Bilbao - Vitoria buses',
            'switcher' => 'La Unión timetables between Bizkaia and Vitoria-Gasteiz',
            'subtitle' => 'La Unión timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Autobuses La Unión. Official scheduled timetable: it does not reflect delays or cancellations.',
            'dataPublished' => 'Only its timetable.',
            'dataMethod' => 'The official timetable.',
            'dataLimits' => 'No delays or cancellations.',
            'attribution' => 'Data: Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
        'fr' => [
            'name' => 'La Unión+',
            'tile' => 'Bus Bilbao - Vitoria',
            'switcher' => 'Horaires de La Unión entre la Bizkaia et Vitoria-Gasteiz',
            'subtitle' => 'Horaires de La Unión',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain bus.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Autobuses La Unión. Horaire théorique officiel : il ne reflète ni les retards ni les suppressions.',
            'dataPublished' => 'Seulement son horaire.',
            'dataMethod' => 'Horaire officiel.',
            'dataLimits' => 'Ni retards ni suppressions.',
            'attribution' => 'Données : Autobuses La Unión, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        // El nombre del operador, el que se busca: va en los títulos y descripciones de cada parada y línea.
        // Dónde están sus paradas, para la dirección de cada una en los datos estructurados (país en ISO 3166).
        'region' => 'Euskadi',
        'country' => 'ES',
        'operator' => 'La Unión',
        'title' => 'La Unión+ · Autobuses Bilbao - Vitoria por línea y parada',
        'description' => 'Horarios de Autobuses La Unión entre Bizkaia y Vitoria-Gasteiz por línea y parada: Bilbao, Margen Izquierda y Derecha, Leioa, Algorta y Loiu.',
    ],
    'attribution' => 'Datos: Autobuses La Unión, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

    'features' => [
        // Publica los nombres en mayúsculas: en la app y en los buscadores van en formato título (Core\DisplayName).
        'title_case_names' => ['keep' => ['UPV', 'EHU', 'BBK', 'BEC', 'BBVA', 'CC']],
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

    // Silla de ruedas, para el planificador (build-packs): fleet y stops, all|some|unknown; gtfs, trust|ignore.
    // El GTFS (1 o 2) manda salvo con ignore, y esto nunca dice «no». Con unknown_stops_with_accessible_fleet = yes,
    // las paradas sin dato de una flota toda accesible cuentan como accesibles.
    'accessibility' => [
        'fleet' => 'unknown',
        'stops' => 'unknown',
        'gtfs' => 'trust',
        'unknown_stops_with_accessible_fleet' => 'no',
        'source' => null,
        'checked' => null,
        'note' => 'La web y el GTFS no casan: el GTFS marca los viajes como no accesibles y la web del operador '
            . 'dice otra cosa. Se respeta el GTFS hasta aclararlo.',
    ],

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
