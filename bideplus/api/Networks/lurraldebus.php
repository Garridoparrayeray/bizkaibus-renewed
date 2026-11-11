<?php

return [
    'slug' => 'lurraldebus',
    'order' => 5,
    'db' => 'lurraldebus',
    'kind' => 'bus',

    'brand' => [
        'icons' => 'icons-lurraldebus',
        'theme_color' => '#2A9AB2',
        'title' => ['LURRALDEBUS', ''],
        'logomark' => '<img class="is-large" src="/icons-lurraldebus/glyph.png" alt="Lurraldebus+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-lurraldebus/icon-192.png" alt="Lurraldebus+" aria-hidden="true">',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#2A9AB2',
        'swatch_color' => '#2A9AB2',
        'palette' => [
            'bg' => '#f3f8f9',
            'border' => '#d4e6ea',
            'primary' => '#1E7C95',
            'primary-light' => '#8DC63F',
            'primary-dark' => '#145A6C',
            'primary-tint' => '#E2F2F5',
            'text' => '#152230',
            'text-secondary' => '#556372',
            'backdrop' => 'rgba(8, 16, 26, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Autobuses de Gipuzkoa',
            'switcher' => 'Horarios de Lurraldebus en Gipuzkoa',
            'subtitle' => 'Horarios de Lurraldebus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Lurraldebus ni con la Diputación Foral de Gipuzkoa. Las llegadas se calculan con la posición GPS de cada autobús; sin datos del autobús, horario oficial.',
            'dataPublished' => 'La posición GPS de cada autobús de sus 8 operadores, aunque a menudo llega vacía.',
            'dataMethod' => 'El mismo algoritmo que Bizkaibus.',
            'dataLimits' => 'Cuando su feed llega vacío, horario oficial.',
        ],
        'eu' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Gipuzkoako autobusak',
            'switcher' => 'Lurraldebusen ordutegiak Gipuzkoan',
            'subtitle' => 'Lurraldebusen ordutegiak',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Lurraldebusekin eta Gipuzkoako Foru Aldundiarekin loturarik gabea. Iritsierak autobus bakoitzaren GPS kokapenarekin kalkulatzen dira; autobusaren daturik gabe, ordutegi ofiziala.',
            'dataPublished' => 'Autobus bakoitzaren GPS kokapena bere 8 operadoreetan, nahiz eta askotan hutsik iritsi.',
            'dataMethod' => 'Bizkaibusen algoritmo bera.',
            'dataLimits' => 'Jarioa hutsik datorrenean, ordutegi ofiziala.',
            'attribution' => 'Datuak: Lurraldebus / Gipuzkoako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Gipuzkoa buses',
            'switcher' => 'Lurraldebus timetables in Gipuzkoa',
            'subtitle' => 'Lurraldebus timetables',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Lurraldebus or with the Provincial Council of Gipuzkoa. Arrivals are calculated from the GPS position of every bus; without data from the bus, the official timetable.',
            'dataPublished' => 'The GPS position of every bus for its 8 operators, although it often comes empty.',
            'dataMethod' => 'The same algorithm as Bizkaibus.',
            'dataLimits' => 'When its feed comes empty, the official timetable.',
            'attribution' => 'Data: Lurraldebus / Provincial Council of Gipuzkoa, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
        'fr' => [
            'name' => 'Lurraldebus+',
            'tile' => 'Bus du Gipuzkoa',
            'switcher' => 'Horaires de Lurraldebus au Gipuzkoa',
            'subtitle' => 'Horaires de Lurraldebus',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain bus.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Lurraldebus ni avec la Députation forale du Gipuzkoa. Les arrivées sont calculées avec la position GPS de chaque bus ; sans données du bus, horaire officiel.',
            'dataPublished' => 'La position GPS de chaque bus de ses 8 opérateurs, même si elle arrive souvent vide.',
            'dataMethod' => 'Le même algorithme que Bizkaibus.',
            'dataLimits' => 'Quand son flux arrive vide, horaire officiel.',
            'attribution' => 'Données : Lurraldebus / Députation forale du Gipuzkoa, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Lurraldebus+ · Horarios de los autobuses de Gipuzkoa por línea y parada | Bide+',
        'description' => 'Consulta los horarios de Lurraldebus en Gipuzkoa por línea y parada: Ekialdebus, Gipuzkoana, Goierrialdea, Pesa, TBH, Tolosaldea y Euskotren bus.',
    ],
    'attribution' => 'Datos: Lurraldebus / Diputación Foral de Gipuzkoa, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

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

    // Motor propio en engine/lurraldebus, que hereda el de Bizkaibus. Los feeds de los operadores llegan vacíos
    // (9/11/2026, los 8): mientras tanto la app enseña el horario oficial y el mapa no dice que siga a nadie.
    'live' => ['vehicle-monitoring'],
    // El feed llega vacío a ratos aunque haya servicio: «Vigilar el directo» lo apunta como aviso, no como fallo.
    'live_often_empty' => true,
    'engine' => 'lurraldebus',

    'siri' => [
        'vehicle_monitoring_urls' => [
            'ekialdebus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/siri_ekialdebus_vehicle_monitoring.xml',
            'goierrialdea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/siri_goierrialdea_vehicle_monitoring.xml',
            'gipuzkoana' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/siri_guipuzkoana_vehicle_monitoring.xml',
            'pesa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/siri_pesa_vehicle_monitoring.xml',
            'tbh' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/siri_tbh_vehicle_monitoring.xml',
            'euskotrenbus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/euskotren_bus/siri_euskobus_vehicle_monitoring.xml',
            'tolosaldea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosaldea/siri_tolosaldea_vehicle_monitoring.xml',
            'tolosa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosa/siri_tolosa_vehicle_monitoring.xml',
        ],
        'alerts_urls' => [
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/siri_ekialdebus_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/siri_goierrialdea_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/siri_guipuzkoana_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/siri_pesa_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/siri_tbh_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/euskotren_bus/siri_euskobus_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosaldea/siri_tolosaldea_situation_exchange.xml',
            'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosa/siri_tolosa_situation_exchange.xml',
        ],
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 4,
    ],

    // Un GTFS por operador; build-database.php los junta (ver mergeGtfsZips).
    'gtfs' => [
        'sources' => [
            'ekialdebus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/ekialdebus/gtfs_ekialdebus.zip',
            'euskotrenbus' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/euskotren_bus/gtfs_euskotren_bus.zip',
            'goierrialdea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/goierrialdea/gtfs_goierrialdea.zip',
            'gipuzkoana' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/guipuzkoana/gtfs_guipuzkoana.zip',
            'pesa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/pesa/gtfs_pesa.zip',
            'tbh' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tbh/gtfs_tbh.zip',
            'tolosaldea' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosaldea/gtfs_tolosaldea.zip',
            // El autobús urbano de Tolosa (Hiribusa), también de Lurraldebus.
            'tolosa' => 'https://opendata.euskadi.eus/transport/moveuskadi/lurraldebus/tolosa/gtfs_tolosa.zip',
        ],
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'required' => false,
    ],
];
