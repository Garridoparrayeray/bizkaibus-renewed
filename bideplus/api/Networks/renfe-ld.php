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
        'theme_color' => '#74349A',
        'title' => ['RENFE LD', ''],
        'logomark' => '<img src="/icons-renfe-ld/glyph.png" alt="Renfe LD+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-renfe-ld/icon-192.png" alt="Renfe LD+" aria-hidden="true">',
        'tile_color' => '#74349A',
        'swatch_color' => '#74349A',
        'palette' => [
            'bg' => '#f8f4fa',
            'border' => '#e6dbec',
            'primary' => '#74349A',
            'primary-light' => '#B98BD1',
            'primary-dark' => '#4A1D66',
            'primary-tint' => '#F3EAF8',
            'text' => '#24192b',
            'text-secondary' => '#65596c',
            'backdrop' => 'rgba(18, 10, 22, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'Renfe',
            'name' => 'Renfe LD+',
            'tile' => 'Larga y media distancia en Euskadi y Navarra',
            'switcher' => 'Horarios y tiempo real de los trenes de larga y media distancia',
            'subtitle' => 'Larga y media distancia en tiempo real',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos y los trenes cancelados son los que publica Renfe en directo.',
        ],
        'eu' => [
            'liveSource' => 'Renfek',
            'name' => 'Renfe LD+',
            'tile' => 'Distantzia luzeko eta ertaineko trenak Euskadin eta Nafarroan',
            'switcher' => 'Distantzia luzeko eta ertaineko trenen ordutegiak eta denbora errealean',
            'subtitle' => 'Distantzia luzeko eta ertaineko trenak denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Renferekin loturarik gabea. Atzerapenak eta bertan behera utzitako trenak Renfek zuzenean argitaratzen dituenak dira.',
            'attribution' => 'Datuak: Renfe, Moveuskadiren bidez (Open Data Euskadi, CC-BY 4.0)',
        ],
        'en' => [
            'liveSource' => 'Renfe',
            'name' => 'Renfe LD+',
            'tile' => 'Long and medium distance trains in the Basque Country and Navarre',
            'switcher' => 'Long and medium distance train timetables and real time',
            'subtitle' => 'Long and medium distance trains in real time',
            'liveEmpty' => 'Search for a station to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Renfe. Delays and cancelled trains are those Renfe publishes live.',
            'attribution' => 'Data: Renfe, via Moveuskadi (Open Data Euskadi, CC-BY 4.0)',
        ],
    ],

    'seo' => [
        'title' => 'Renfe LD+ · Horarios y retrasos en tiempo real de los trenes de larga y media distancia',
        'description' => 'Consulta los horarios de los trenes de larga y media distancia de Renfe (Alvia, Intercity, Media Distancia, Regional…) que paran en Euskadi y Navarra, con su retraso en tiempo real.',
    ],
    'attribution' => 'Datos: Renfe, vía Moveuskadi (Open Data Euskadi, CC-BY 4.0)',

    // Para la prueba de integridad: la zona de sus paradas y, si hace falta, lo que dura como mucho un viaje.
    'checks' => ['max_trip_hours' => 16],

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'stops',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates', 'vehicle-positions'],

    // El workflow «Ritmo de los buses» guarda este directo para que el motor aprenda de él, como en Renfe Cercanías.
    'capture' => 'gtfs-rt',

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

    // Los avisos de Renfe (uno para todos sus trenes): los de sus estaciones de aquí y los trenes cancelados, como en
    // Renfe Cercanías.
    'renfe_alerts' => [
        'url' => 'https://gtfsrt.renfe.com/alerts.json',
        'cache_ttl_seconds' => 120,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 2 * 86400,
    ],

    'gtfs' => [
        'source' => 'https://opendata.euskadi.eus/transport/moveuskadi/renfe_media/gtfs_renfe_media.zip',
        'fallback_source' => null,
        'agency_id' => null,
        'stops' => 'generic',
        'skip_geocode' => true,
        'name_lines_from_stops' => true,
        // Un tren con varios orígenes o destinos viene en un viaje por cada uno: se juntan por el número de tren (5 cifras).
        'merge_same_train_number' => 5,
        // Las estaciones de la red: las de Euskadi y Navarra. Al construir la base quedan solo los trenes en los que se
        // sube en ellas, con esas estaciones y su destino; el destino de fuera no se busca ni sale en el mapa ni en avisos.
        'keep_stops' => [
            '11600', // Irun
            '11515', // Pasaia
            '11511', // San Sebastián-Donostia
            '11516', // Lezo-Rentería
            '05457', // Zorrotza Zorrozgoiti
            '11507', // Hernani-Erdia
            '05459', // Santa Águeda
            '05451', // Bilbao la Concordia
            '13200', // Bilbao-Intermod. Abando Indalecio Prieto
            '05455', // Basurto Hospital
            '05460', // Kastrexana
            '13206', // Ametzola
            '05461', // Irauregui
            '05497', // Karrantza
            '05489', // Artzentales
            '05487', // Traslaviña
            '05463', // Zaramillo
            '05465', // La Quadra
            '05484', // Mimetiz
            '11504', // Andoain-Centro
            '05475', // Zalla
            '05477', // Colegio
            '05473', // Aranguren
            '05474', // Aranguren-Apeadero
            '05471', // Güeñes
            '05470', // Lambarri
            '05479', // Ibarra
            '05481', // La Herrera
            '05467', // Sodupe
            '05469', // Artxube
            '05483', // Balmaseda
            '11503', // Billabona-Zizurkil
            '13106', // Llodio
            '11501', // Tolosa-Centro
            '11500', // Tolosa
            '11400', // Zumarraga
            '11405', // Ordizia
            '11306', // Legazpi
            '11404', // Beasain
            '11402', // Ormáiztegui
            '11305', // Brinkola
            '11213', // Araia
            '11210', // Alegría-Dulantzi
            '11212', // Agurain-Salvatierra de Álava
            '11208', // Vitoria-Gasteiz
            '80100', // Pamplona/Iruña
            '11205', // Nanclares-Langraiz
            '11203', // Manzanos
            '11300', // Altsasu
            '81202', // Tudela de Navarra
            '81200', // Castejón de Ebro
            '80108', // Tafalla
            '81205', // Cortes de Navarra
            '81203', // Ribaforada
            '80115', // Villafranca de Navarra
            '80114', // Marcilla de Navarra
            '80109', // Olite-Erriberri
            '80005', // Uharte-Arakil
            '80003', // Etxarri-Aranatz
            '80001', // Altsasu-Pueblo
            '81106', // Féculas-Navarra
        ],
        // Cada viaje, con las fechas exactas del periodo que publica el operador (y una comprobación contra el GTFS).
        'feed_window' => true,
        'required' => false,
    ],
];
