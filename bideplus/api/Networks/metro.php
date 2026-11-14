<?php

return [
    'slug' => 'metro',
    'order' => 2,
    'db' => 'metrobilbao',
    'kind' => 'metro',

    'brand' => [
        'icons' => 'icons-metro',
        'theme_color' => '#C8102E',
        'title' => ['METRO', ''],
        'logomark' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9.3" fill="#C8102E"/><circle cx="9.25" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/><circle cx="12" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/><circle cx="14.75" cy="12" r="3.05" fill="#C8102E" stroke="#FF6505" stroke-width="1.55"/></svg>',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#C8102E',
        'swatch_color' => '#C8102E',
        'palette' => [
            'bg' => '#f6f4f4',
            'border' => '#e3ddde',
            'primary' => '#C8102E',
            'primary-light' => '#FF8A80',
            'primary-dark' => '#7A0C1D',
            'primary-tint' => '#FCEBEA',
            'text' => '#211c1c',
            'text-secondary' => '#665c5d',
            'backdrop' => 'rgba(20, 10, 11, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'Metro Bilbao',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Horarios y tiempo real de Metro Bilbao',
            'subtitle' => 'Horarios y tiempo real de Metro Bilbao',
            'liveEmpty' => 'Busca una estación para ver el próximo metro.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A. ni con el Consorcio de Transportes de Bizkaia. Los tiempos en directo son una previsión del operador.',
            'dataPublished' => 'La hora prevista de cada tren en cada estación y su posición.',
            'dataMethod' => 'La previsión de cada andén que da la app oficial de Metro Bilbao; si no responde, la del Consorcio.',
            'dataLimits' => 'Sin limitaciones conocidas.',
        ],
        'eu' => [
            'liveSource' => 'Bilboko Metroak',
            'name' => 'Metro+',
            'tile' => 'Bilboko Metroa',
            'switcher' => 'Bilboko Metroaren ordutegiak eta denbora errealean',
            'subtitle' => 'Bilboko Metroaren ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo metroa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Metro Bilbao S.A.-rekin eta Bizkaiko Garraio Partzuergoarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'dataPublished' => 'Tren bakoitzaren aurreikusitako ordua geltoki bakoitzean, eta haren kokapena.',
            'dataMethod' => 'Bilboko Metroaren aplikazio ofizialak nasa bakoitzeko ematen duen aurreikuspena; erantzuten ez badu, Partzuergoarena.',
            'dataLimits' => 'Ez dago muga ezagunik.',
            'attribution' => 'Datuak: Metro Bilbao / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0): ordutegia eta denbora errealean',
        ],
        'en' => [
            'liveSource' => 'Metro Bilbao',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Metro Bilbao timetables and real time',
            'subtitle' => 'Metro Bilbao timetables and real time',
            'liveEmpty' => 'Search for a station to see the next metro.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Metro Bilbao S.A. or with the Bizkaia Transport Consortium. Live times are the operator\'s forecast.',
            'dataPublished' => 'The predicted time of each train at each station, and its position.',
            'dataMethod' => 'The forecast for each platform from the official Metro Bilbao app; if it does not respond, the Consortium\'s.',
            'dataLimits' => 'No known limitations.',
            'attribution' => 'Data: Metro Bilbao / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0): timetable and real time',
        ],
        'fr' => [
            'liveSource' => 'Metro Bilbao',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Horaires et temps réel de Metro Bilbao',
            'subtitle' => 'Horaires et temps réel de Metro Bilbao',
            'liveEmpty' => 'Cherchez une station pour voir le prochain métro.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Metro Bilbao S.A. ni avec le Consorcio de Transportes de Bizkaia. Les temps en direct sont une prévision de l’opérateur.',
            'dataPublished' => 'L’heure prévue de chaque train à chaque station et sa position.',
            'dataMethod' => 'La prévision de chaque quai donnée par l’app officielle de Metro Bilbao ; sinon, celle du Consorcio.',
            'dataLimits' => 'Aucune limite connue.',
            'attribution' => 'Données : Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0) : horaires et temps réel',
        ],
    ],

    'seo' => [
        'title' => 'Metro+ · Horarios y tiempo real de Metro Bilbao: líneas L1, L2 y L3, estaciones y avisos | Bide+',
        'description' => 'Consulta los horarios y el tiempo real de Metro Bilbao por línea y estación, con los próximos metros y los avisos de servicio.',
    ],
    'attribution' => 'Datos: Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0): horario y tiempo real',

    'features' => [
        'platforms' => true,
        'headsign_from_last_stop' => true,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => false,
    ],

    'live' => ['trip-updates', 'vehicle-positions'],

    'metro_alerts' => [
        'url' => 'https://cms.metrobilbao.eus/es/get/open_data/json/avisos/es',
        'cache_ttl_seconds' => 60,
        'http_timeout_seconds' => 8,
    ],

    'siri' => [
        'vehicle_monitoring_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/metro_bilbao/siri_metro_bilbao_vehicle_monitoring.xml',
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/metro_bilbao/siri_metro_bilbao_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    'gtfs_rt' => [
        'trip_updates_url' => 'https://ctb-gtfs-rt.s3.eu-south-2.amazonaws.com/metro-bilbao-trip-updates.pb',
        'vehicle_positions_url' => 'https://ctb-gtfs-rt.s3.eu-south-2.amazonaws.com/metro-bilbao-vehicle-positions.pb',
        'cache_ttl_seconds' => 15,
        'http_timeout_seconds' => 6,
        'max_age_seconds' => 300,
        'matching' => 'trip',
        'match_tolerance_seconds' => 600,
        'siri_fallback' => true,
        // Un retraso de más de 1 h en el GTFS-Realtime es de otro viaje con ese id (llegaron a salir de 5 h): no vale.
        'max_feed_gap_seconds' => 3600,
        // El feed de posiciones pone en el id de cada tren su estación, su andén y su destino (BSR_BSR1_PLE_1794…).
        'vehicle_destination' => '/^[A-Z0-9]+_[A-Z0-9]+_(?<destination>[A-Z]+)_\\d+$/',
    ],

    // Llegadas por estación y andén de la app oficial de Metro Bilbao, lo que manda en el directo (el GTFS-Realtime y
    // el SIRI quedan de apoyo). Cada andén se pide como el viaje a la estación siguiente, con los códigos de su API.
    'station_arrivals' => [
        'url' => 'https://api.metrobilbao.eus/metro/real-time/{from}/{to}',
        'cache_ttl_seconds' => 20,
        'http_timeout_seconds' => 5,
        'max_age_seconds' => 90,
        // Hasta 10 min tarde un tren es el de su salida del horario; con más, sale sin horario (sus minutos y destino).
        'max_delay_seconds' => 600,
        'probe_stop_id' => 7,
        'codes' => [
            1 => 'ARZ', 2 => 'ETX', 3 => 'BOL', 4 => 'BAS', 5 => 'SAN', 6 => 'CAV', 7 => 'ABA', 8 => 'MOY',
            9 => 'IND', 10 => 'SAM', 11 => 'DEU', 12 => 'SAR', 13 => 'SIN', 14 => 'LUT', 15 => 'ERA', 16 => 'AST',
            17 => 'LEI', 18 => 'LAM', 19 => 'ARE', 20 => 'GOB', 21 => 'NEG', 22 => 'AIB', 23 => 'ALG', 24 => 'BID',
            25 => 'IBB', 26 => 'BER', 27 => 'LAR', 28 => 'SOP', 29 => 'URD', 30 => 'PLE', 32 => 'GUR', 33 => 'ANS',
            34 => 'BAR', 35 => 'BAG', 36 => 'URB', 37 => 'SES', 38 => 'ABT', 39 => 'POR', 40 => 'PEN', 41 => 'STZ',
            42 => 'KAB', 51 => 'BSR',
        ],
    ],

    'direction_reference_stop_id' => 7,

    // Mapa de la red: Metro Bilbao publica L1 y L2 como una sola línea («MB»); cada una es el recorrido entre sus
    // extremos, con los colores de su plano.
    'map' => [
        'label' => 'Metro Bilbao',
        'splits' => [
            ['code' => 'L1', 'name' => 'Etxebarri - Plentzia', 'ends' => ['Etxebarri', 'Plentzia'], 'line' => 'MB'],
            ['code' => 'L2', 'name' => 'Basauri - Kabiezes', 'ends' => ['Basauri', 'Kabiezes'], 'line' => 'MB'],
        ],
        'colors' => ['L1' => '#F26722', 'L2' => '#1D1D1B'],
    ],

    'gtfs' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip',
        'fallback_source' => 'https://nap.transportes.gob.es/api/Fichero/download/1066',
        'agency_id' => null,
        'stops' => 'metro',
        'skip_geocode' => true,
        'merge_calendars' => true,
        'required' => true,
    ],
];
