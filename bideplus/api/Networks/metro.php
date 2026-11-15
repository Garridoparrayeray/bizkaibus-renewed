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
            'liveSource' => 'el Consorcio de Transportes de Bizkaia',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Horarios y tiempo real de Metro Bilbao',
            'subtitle' => 'Horarios y tiempo real de Metro Bilbao',
            'liveEmpty' => 'Busca una estación para ver el próximo metro.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Metro Bilbao S.A. ni con el Consorcio de Transportes de Bizkaia. Los tiempos en directo son una previsión del operador.',
            'dataPublished' => 'La hora prevista de cada tren en sus próximas estaciones y su posición, con números de viaje que no son los del horario.',
            'dataMethod' => 'Cada tren se casa, en orden, con la salida del horario de su andén y su destino, sacados de su recorrido; si el GTFS-Realtime no trae trenes, los del SIRI.',
            'dataLimits' => 'Un tren que no encaja con ninguna salida (más de 10 min tarde o 3 min antes) sale sin horario, con sus minutos y su destino. Con el SIRI, va un par de minutos por detrás.',
        ],
        'eu' => [
            'liveSource' => 'Bizkaiko Garraio Partzuergoak',
            'name' => 'Metro+',
            'tile' => 'Bilboko Metroa',
            'switcher' => 'Bilboko Metroaren ordutegiak eta denbora errealean',
            'subtitle' => 'Bilboko Metroaren ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo metroa ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Metro Bilbao S.A.-rekin eta Bizkaiko Garraio Partzuergoarekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'dataPublished' => 'Tren bakoitzaren aurreikusitako ordua hurrengo geltokietan eta haren kokapena, ordutegikoak ez diren bidaia-zenbakiekin.',
            'dataMethod' => 'Tren bakoitza, ordenan, bere nasako eta helmugako ordutegiko irteerarekin lotzen da, ibilbidetik ateratakoak; GTFS-Realtimeak trenik ez badakar, SIRIkoak.',
            'dataLimits' => 'Inongo irteerarekin bat ez datorren trena (10 min berandu baino gehiago edo 3 min lehenago) ordutegirik gabe ateratzen da, bere minutuekin eta helmugarekin. SIRIarekin, minutu pare bat atzerago doa.',
            'attribution' => 'Datuak: Metro Bilbao / Bizkaiko Garraio Partzuergoa (data.ctb.eus, CC-BY 4.0): ordutegia eta denbora errealean',
        ],
        'en' => [
            'liveSource' => 'the Bizkaia Transport Consortium',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Metro Bilbao timetables and real time',
            'subtitle' => 'Metro Bilbao timetables and real time',
            'liveEmpty' => 'Search for a station to see the next metro.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Metro Bilbao S.A. or with the Bizkaia Transport Consortium. Live times are the operator\'s forecast.',
            'dataPublished' => 'The predicted time of each train at its next stations, and its position, with trip numbers that are not the timetable\'s.',
            'dataMethod' => 'Each train is matched, in order, to the timetable departure of its platform and destination, worked out from its route; if the GTFS-Realtime feed has no trains, those from the SIRI.',
            'dataLimits' => 'A train that fits no departure (more than 10 min late or 3 min early) shows without a timetable, with its minutes and destination. With the SIRI, it runs a couple of minutes behind.',
            'attribution' => 'Data: Metro Bilbao / Bizkaia Transport Consortium (data.ctb.eus, CC-BY 4.0): timetable and real time',
        ],
        'fr' => [
            'liveSource' => 'le Consorcio de Transportes de Bizkaia',
            'name' => 'Metro+',
            'tile' => 'Metro Bilbao',
            'switcher' => 'Horaires et temps réel de Metro Bilbao',
            'subtitle' => 'Horaires et temps réel de Metro Bilbao',
            'liveEmpty' => 'Cherchez une station pour voir le prochain métro.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Metro Bilbao S.A. ni avec le Consorcio de Transportes de Bizkaia. Les temps en direct sont une prévision de l’opérateur.',
            'dataPublished' => 'L’heure prévue de chaque train à ses prochaines stations et sa position, avec des numéros de trajet qui ne sont pas ceux de l’horaire.',
            'dataMethod' => 'Chaque train est associé, dans l’ordre, au départ de l’horaire de son quai et de sa destination, déduits de son parcours ; si le GTFS-Realtime n’a aucun train, ceux du SIRI.',
            'dataLimits' => 'Un train qui ne correspond à aucun départ (plus de 10 min de retard ou 3 min d’avance) s’affiche sans horaire, avec ses minutes et sa destination. Avec le SIRI, il a quelques minutes de décalage.',
            'attribution' => 'Données : Metro Bilbao / Consorcio de Transportes de Bizkaia (data.ctb.eus, CC-BY 4.0) : horaires et temps réel',
        ],
    ],

    'seo' => [
        // El nombre del operador, el que se busca: va en los títulos y descripciones de cada parada y línea.
        // Dónde están sus paradas, para la dirección de cada una en los datos estructurados (país en ISO 3166).
        'region' => 'Bizkaia',
        'country' => 'ES',
        'operator' => 'Metro Bilbao',
        'title' => 'Metro+ · Horarios y tiempo real de Metro Bilbao L1 y L2',
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

    // Servicio reducido por el directo (Services\ReducedService): en las 6 paradas con más pasos (40 salidas de
    // cada una), de las de los próximos 20 min cuyo viaje ya ha salido, falta el 60 % o más durante 15 min (lo
    // normal no llega al 20 %).
    'reduced_service' => [
        'live' => [
            'sample_stops' => 6, 'rows_per_stop' => 40, 'window_seconds' => 1200,
            'min_expected' => 8, 'missing_share' => 0.6, 'confirm_seconds' => 900,
        ],
    ],

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
        // Sin el GTFS-Realtime, los trenes salen del SIRI de llegadas (cada fila lleva el número del tren).
        'siri_fallback' => true,
        // Un retraso de más de 1 h en el GTFS-Realtime es de otro viaje con ese id (llegaron a salir de 5 h): no vale.
        'max_feed_gap_seconds' => 3600,
        // Los ids del feed son de viajes de otra generación del horario: cada tren se casa por andén y destino
        // (Services\PlatformMatcher).
        'platform_matching' => [
            // Hasta 10 min tarde o 3 min antes, un tren es el de su salida; si no, sale sin horario (sus minutos y su
            // destino).
            'max_delay_seconds' => 600,
            'max_early_seconds' => 180,
            // Hasta dónde trae el feed todos los trenes de un andén: más allá no se quita ninguna salida del horario.
            'covered_seconds' => 1800,
            // Una lista de paradas que acaba más allá de esto puede estar cortada: no dice el destino.
            'truncated_after_seconds' => 2400,
            // El feed da las paradas de cada tren menos la terminal: la que sigue a su última parada es su destino.
            'omits_terminal' => true,
            // Un tren no puede ir a menos de la mitad de lo que tarda el horario entre dos paradas: si lo hace,
            // es que una parada viene con el id de otra y el tren no va por ese sentido.
            'min_travel_share' => 0.5,
            // Un recorrido con menos de 1 de cada 4 viajes del que más tiene (Urduliz, Bidezabal) es raro: si el
            // tren encaja en él, el feed puede haber cortado la lista justo antes, y decide el horario.
            'usual_pattern_share' => 4,
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
        // Festivos de los que su GTFS no dice nada (sin calendar_dates o pasado su periodo), con el servicio
        // de domingo: los del calendario laboral (Build\Holidays) de toda Euskadi, del territorio y del
        // municipio; sin él, los que salen de las redes de respaldo.
        'holidays' => [
            'calendar' => 'euskadi', 'territory' => 'Bizkaia', 'municipality' => null,
            'fallback' => ['networks' => ['dbus', 'bilbobus', 'alavabus'], 'quorum' => 2],
        ],
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/metrobilbao.zip',
        'fallback_source' => 'https://nap.transportes.gob.es/api/Fichero/download/1066',
        'agency_id' => null,
        'stops' => 'metro',
        'skip_geocode' => true,
        'merge_calendars' => true,
        'required' => true,
    ],
];
