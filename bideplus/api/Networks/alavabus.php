<?php

// Álavabus, los autobuses interurbanos de Álava (y el transporte comarcal). Horario y directo de Open Data Euskadi
// (Moveuskadi): el retraso de cada autobús y dónde va (GTFS-Realtime).
return [
    'slug' => 'alavabus',
    'order' => 11,
    'db' => 'alavabus',
    'kind' => 'bus',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-02',

    'brand' => [
        'icons' => 'icons-alavabus',
        'theme_color' => '#A8034F',
        'title' => ['ÁLAVABUS', 'ARABA'],
        'logomark' => '<img src="/icons-alavabus/glyph.png" alt="Álavabus+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-alavabus/icon-192.png" alt="Álavabus+" aria-hidden="true">',
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
            'liveSource' => 'Álavabus',
            'name' => 'Álavabus+',
            'tile' => 'Autobuses de Álava',
            'switcher' => 'Horarios y tiempo real de Álavabus en Álava',
            'subtitle' => 'Horarios y tiempo real de Álavabus',
            'liveEmpty' => 'Busca una parada para ver el próximo autobús.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Álavabus ni con la Diputación Foral de Álava. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'liveSource' => 'Álavabusek',
            'name' => 'Álavabus+',
            'tile' => 'Arabako autobusak',
            'switcher' => 'Álavabusen ordutegiak eta denbora errealean Araban',
            'subtitle' => 'Álavabusen ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu parada bat hurrengo autobusa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Álavabusekin eta Arabako Foru Aldundiarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: Álavabus / Arabako Foru Aldundia, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'liveSource' => 'Álavabus',
            'name' => 'Álavabus+',
            'tile' => 'Álava buses',
            'switcher' => 'Álavabus timetables and real time in Álava',
            'subtitle' => 'Álavabus timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next bus.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Álavabus or with the Provincial Council of Álava. Live times are the operator\'s forecast.',
            'attribution' => 'Data: Álavabus / Provincial Council of Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
        'fr' => [
            'liveSource' => 'Álavabus',
            'name' => 'Álavabus+',
            'tile' => 'Bus d’Álava',
            'switcher' => 'Horaires et temps réel d’Álavabus en Álava',
            'subtitle' => 'Horaires et temps réel d’Álavabus',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain bus.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Álavabus ni avec la Députation forale d’Álava. Les temps en direct sont une prévision de l’opérateur.',
            'attribution' => 'Données : Álavabus / Députation forale d’Álava, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Álavabus+ · Autobuses de Álava en tiempo real por línea y parada | Bide+',
        'description' => 'Consulta los próximos autobuses de Álavabus, los interurbanos de Álava, en tiempo real, sus horarios por línea y parada y dónde va cada autobús en el mapa.',
    ],
    'attribution' => 'Datos: Álavabus / Diputación Foral de Álava, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

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

    // El workflow «Ritmo de los buses» guarda este directo (capturas-alavabus-AAAA-MM) para que el motor aprenda de él
    // con el GPS de cada autobús. La app todavía no lo usa: primero se mide si acierta más que lo de ahora.
    'capture' => 'gtfs-rt',
    // El feed llega vacío a ratos aunque haya servicio (4 y 5/11/2026, con más de 30 buses en marcha): «Vigilar el
    // directo» lo apunta como aviso, no como fallo.
    'live_often_empty' => true,

    // El directo trae el viaje del horario de cada autobús: se empareja por viaje. Sin incidencias: su feed GTFS-RT llega
    // vacío y el SIRI (SX.xml) solo trae mensajes de prueba (3/11/2026).
    'gtfs_rt' => [
        'trip_updates_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/alavabus/tripUpdate.bin',
        'vehicle_positions_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/alavabus/position.bin',
        'matching' => 'trip',
        // Su feed escribe la hora de aquí como si fuera UTC: sus horas llegan adelantadas una hora (dos en verano).
        'local_clock' => true,
        // Sus horas previstas fallan a menudo: donde el autobús manda su posición, se usa la posición.
        'prefer_vehicles' => true,
        // A veces da a un autobús el número de un viaje de hace horas: un retraso de más de 45 min (el tope del GPS) no
        // vale y el viaje sale con su horario.
        'max_feed_gap_seconds' => 45 * 60,
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

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
