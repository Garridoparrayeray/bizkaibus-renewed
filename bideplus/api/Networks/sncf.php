<?php

// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera e inglés), el aviso de
// «disclaimer» y las funciones (features).
return [
    'slug' => 'sncf',
    'order' => 15,
    'db' => 'sncf',
    'kind' => 'train',
    // El día que llegó a Bide+: su ficha del menú lleva «Nuevo» los días de new_days (api/Config/site.php).
    'added' => '2026-11-09',

    'brand' => [
        'icons' => 'icons-sncf',
        'theme_color' => '#0C131F',
        'title' => ['SNCF', ''],
        'logomark' => '<img src="/icons-sncf/glyph.png" alt="SNCF+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-sncf/icon-192.png" alt="SNCF+" aria-hidden="true">',
        'tile_color' => '#0C131F',
        'swatch_color' => '#0C131F',
        'palette' => [
            'bg' => '#eeeeef',
            'border' => '#d3d4d4',
            'primary' => '#0C131F',
            'primary-light' => '#92959a',
            'primary-dark' => '#070c13',
            'primary-tint' => '#ececed',
            'text' => '#1d1d1e',
            'text-secondary' => '#595a5b',
            'backdrop' => 'rgba(1, 2, 3, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'SNCF',
            'name' => 'SNCF+',
            'tile' => 'Trenes de Iparralde',
            'switcher' => 'Horarios y tiempo real de los trenes de Iparralde',
            'subtitle' => 'TGV, Intercités y TER en tiempo real',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con SNCF. Los tiempos en directo son una previsión del operador.',
        ],
        'eu' => [
            'liveSource' => 'SNCFk',
            'name' => 'SNCF+',
            'tile' => 'Iparraldeko trenak',
            'switcher' => 'Iparraldeko trenen ordutegiak eta denbora errealean',
            'subtitle' => 'TGV, Intercités eta TER denbora errealean',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, SNCF erakundearekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'attribution' => 'Datuak: SNCF, transport.data.gouv.fr-en bidez (Open Database License, ODbL 1.0)',
        ],
        'en' => [
            'liveSource' => 'SNCF',
            'name' => 'SNCF+',
            'tile' => 'Trains in the Northern Basque Country',
            'switcher' => 'Timetables and real time for trains in the Northern Basque Country',
            'subtitle' => 'TGV, Intercités and TER in real time',
            'liveEmpty' => 'Search for a stop to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with SNCF. Live times are the operator\'s forecast.',
            'attribution' => 'Data: SNCF, via transport.data.gouv.fr (Open Database License, ODbL 1.0)',
        ],
        'fr' => [
            'liveSource' => 'SNCF',
            'name' => 'SNCF+',
            'tile' => 'Trains d’Iparralde',
            'switcher' => 'Horaires et temps réel des trains d’Iparralde',
            'subtitle' => 'TGV, Intercités et TER en temps réel',
            'liveEmpty' => 'Cherchez une gare pour voir le prochain train.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec SNCF. Les temps en direct sont une prévision de l’opérateur.',
            'attribution' => 'Données : SNCF, via transport.data.gouv.fr (Open Database License, ODbL 1.0)',
        ],
    ],

    'seo' => [
        'title' => 'SNCF+ · Horarios y tiempo real de los trenes de Iparralde',
        'description' => 'Consulta los horarios de los trenes de SNCF (TGV, Intercités y TER) en Hendaia, Donibane Lohizune, Biarritz, Baiona, Kanbo y Donibane Garazi, con su retraso en tiempo real.',
    ],
    'attribution' => 'Datos: SNCF, vía transport.data.gouv.fr (Open Database License, ODbL 1.0)',

    // Para la prueba de integridad: la zona de sus paradas y, si hace falta, lo que dura como mucho un viaje.
    'checks' => ['bounds' => [42.9, 43.8, -1.9, -0.7], 'max_trip_hours' => 16],

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => true,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates'],

    'gtfs_rt' => [
        // Sin posiciones publicadas: cuando las haya, su dirección va aquí y 'vehicle-positions' se añade a live.
        'vehicle_positions_url' => null,
        'trip_updates_url' => 'https://proxy.transport.data.gouv.fr/resource/sncf-gtfs-rt-trip-updates',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    'gtfs' => [
        'source' => 'https://www.data.gouv.fr/api/1/datasets/r/9ae758ec-cd7a-40cd-a890-bb3963224942',
        'fallback_source' => null,
        'agency_id' => null,
        // Estaciones con sus paradas por tipo de tren (TGV, TER…): cada estación se busca una vez.
        'stops' => 'euskotren',
        'skip_geocode' => true,
        // Las estaciones de Iparralde, con sus paradas. Quedan los trenes en los que se sube en ellas, con su destino.
        'keep_stops' => [
            'StopArea:OCE87677005', // Hendaye
            'StopPoint:OCECar TER-87677005',
            'StopPoint:OCEINTERCITES-87677005',
            'StopPoint:OCEOUIGO-87677005',
            'StopPoint:OCETGV INOUI-87677005',
            'StopPoint:OCETrain TER-87677005',
            'StopArea:OCE87677120', // Saint-Jean-de-Luz - Ciboure
            'StopPoint:OCECar TER-87677120',
            'StopPoint:OCEINTERCITES-87677120',
            'StopPoint:OCEOUIGO-87677120',
            'StopPoint:OCETGV INOUI-87677120',
            'StopPoint:OCETrain TER-87677120',
            'StopArea:OCE87673459', // Guéthary
            'StopPoint:OCECar TER-87673459',
            'StopPoint:OCETrain TER-87673459',
            'StopArea:OCE87673400', // Biarritz
            'StopPoint:OCECar TER-87673400',
            'StopPoint:OCEINTERCITES-87673400',
            'StopPoint:OCEOUIGO-87673400',
            'StopPoint:OCETGV INOUI-87673400',
            'StopPoint:OCETrain TER-87673400',
            'StopArea:OCE87673004', // Bayonne
            'StopPoint:OCECar TER-87673004',
            'StopPoint:OCEINTERCITES-87673004',
            'StopPoint:OCEINTERCITES de nuit-87673004',
            'StopPoint:OCENavette-87673004',
            'StopPoint:OCEOUIGO-87673004',
            'StopPoint:OCETGV INOUI-87673004',
            'StopPoint:OCETrain TER-87673004',
            'StopArea:OCE87673657', // Cambo-les-Bains
            'StopPoint:OCECar TER-87673657',
            'StopPoint:OCETrain TER-87673657',
            'StopArea:OCE87673707', // Ossès - Saint-Martin-d'Arrossa
            'StopPoint:OCECar TER-87673707',
            'StopPoint:OCETrain TER-87673707',
            'StopArea:OCE87673723', // Saint-Jean-Pied-de-Port
            'StopPoint:OCECar TER-87673723',
            'StopPoint:OCETrain TER-87673723',
        ],
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
