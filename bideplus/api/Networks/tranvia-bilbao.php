<?php

return [
    'slug' => 'tranvia-bilbao',
    'order' => 6,
    'db' => 'tranviabilbao',
    'kind' => 'tram',

    'brand' => [
        'icons' => 'icons-tranvia-bilbao',
        'theme_color' => '#4EB848',
        'title' => ['TRANVÍA', 'BILBAO'],
        'logomark' => '<img src="/icons-tranvia-bilbao/glyph.png" alt="Tranvía Bilbao+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-tranvia-bilbao/icon-192.png" alt="Tranvía Bilbao+" aria-hidden="true">',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#4EB848',
        'swatch_color' => '#2f7a2a',
        'palette' => [
            'bg' => '#f4f8f4',
            'border' => '#dde8dc',
            'primary' => '#2F7A2A',
            'primary-light' => '#D8F5C8',
            'primary-dark' => '#1E5420',
            'primary-tint' => '#EAF7E6',
            'text' => '#1c2a1d',
            'text-secondary' => '#5c6b5e',
            'backdrop' => 'rgba(10, 20, 12, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'Euskotren',
            'name' => 'Tranvía Bilbao+',
            'tile' => 'Horarios y tiempo real',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Bilbao',
            'switcher' => 'Horarios y tiempo real del tranvía de Bilbao',
            'subtitle' => 'Horarios y tiempo real del tranvía',
            'liveEmpty' => 'Busca una parada para ver el próximo tranvía.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Los tiempos en directo son una previsión del operador.',
            'dataPublished' => 'Solo su horario: su directo, en el feed de Euskotren, repite el horario y no da posiciones.',
            'dataMethod' => 'Horario oficial; si un día el directo se aparta del horario, la previsión del operador.',
            'dataLimits' => 'Sin retrasos ni cancelaciones.',
        ],
        'eu' => [
            'liveSource' => 'Euskotrenek',
            'name' => 'Tranbia Bilbo+',
            'tile_title' => 'Tranbia',
            'tile' => 'Ordutegiak eta zuzenekoa',
            'tile_city' => 'Bilbo',
            'switcher' => 'Bilboko tranbiaren ordutegiak eta denbora errealean',
            'subtitle' => 'Tranbiaren ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geraleku bat hurrengo tranbia ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Zuzeneko denborak operadorearen aurreikuspena dira.',
            'dataPublished' => 'Bere ordutegia bakarrik: zuzenekoak, Euskotrenen jarioan, ordutegia errepikatzen du eta ez du kokapenik ematen.',
            'dataMethod' => 'Ordutegi ofiziala; egunen batean zuzenekoa ordutegitik aldentzen bada, operadorearen aurreikuspena.',
            'dataLimits' => 'Atzerapenik eta bertan behera utzitakorik gabe.',
            'attribution' => 'Datuak: Euskotren (Bilboko tranbia) / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'liveSource' => 'Euskotren',
            'name' => 'Tranvía Bilbao+',
            'tile' => 'Timetables and real time',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Bilbao',
            'switcher' => 'Bilbao tram timetables and real time',
            'subtitle' => 'Tram timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next tram.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Live times are the operator\'s forecast.',
            'dataPublished' => 'Only its timetable: its live feed, in Euskotren\'s, repeats the timetable and gives no positions.',
            'dataMethod' => 'The official timetable; if one day the live feed departs from the timetable, the operator\'s forecast.',
            'dataLimits' => 'No delays or cancellations.',
            'attribution' => 'Data: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)',
        ],
        'fr' => [
            'liveSource' => 'Euskotren',
            'name' => 'Tranvía Bilbao+',
            'tile' => 'Horaires et temps réel',
            'tile_title' => 'Tranvía',
            'tile_city' => 'Bilbao',
            'switcher' => 'Horaires et temps réel du tramway de Bilbao',
            'subtitle' => 'Horaires et temps réel du tramway',
            'liveEmpty' => 'Cherchez un arrêt pour voir le prochain tramway.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Euskotren S.A. Les temps en direct sont une prévision de l’opérateur.',
            'dataPublished' => 'Seulement son horaire : son flux en direct, dans celui d’Euskotren, répète l’horaire et ne donne pas de positions.',
            'dataMethod' => 'Horaire officiel ; si un jour le direct s’écarte de l’horaire, la prévision de l’opérateur.',
            'dataLimits' => 'Ni retards ni suppressions.',
            'attribution' => 'Données : Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        // El nombre del operador, el que se busca: va en los títulos y descripciones de cada parada y línea.
        // Dónde están sus paradas, para la dirección de cada una en los datos estructurados (país en ISO 3166).
        'region' => 'Bizkaia',
        'country' => 'ES',
        'operator' => 'Tranvía de Bilbao',
        'title' => 'Tranvía Bilbao+ · Horarios y tiempo real del tranvía',
        'description' => 'Horarios y tiempo real del tranvía de Bilbao de Euskotren: las próximas salidas en cada parada, el horario de hoy y el recorrido de la línea.',
    ],
    'attribution' => 'Datos: Euskotren (Tranvía Bilbao) / Open Data Euskadi (CC-BY 4.0)',

    'features' => [
        'platforms' => true,
        'headsign_from_last_stop' => false,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'network',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates'],

    // Previsión de Euskotren (GTFS-Realtime, cada 1-2 min): trenes y los dos tranvías van en el mismo fichero.
    'gtfs_rt' => [
        'trip_updates_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfsrt_euskotren_trip_updates.pb',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
        // Un retraso de más de 1 h (el tope de Metro) es de otro viaje con ese id: no vale y el viaje sale con su
        // horario. Renfe y SNCF no tienen tope: sus trenes de largo recorrido sí llevan retrasos así.
        'max_feed_gap_seconds' => 3600,
        'matching' => 'trip',
        'match_tolerance_seconds' => 600,
    ],

    'siri' => [
        'alerts_url' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/siri_euskotren_situation_exchange.xml',
        'cache_ttl_seconds' => 25,
        'http_timeout_seconds' => 8,
    ],

    // Mapa de la red: el color del plano del operador.
    'map' => [
        'colors' => ['A' => '#78BE20', 'TR' => '#78BE20'],
    ],

    // Silla de ruedas, para el planificador (build-packs): fleet y stops, all|some|unknown; gtfs, trust|ignore.
    // El GTFS (1 o 2) manda salvo con ignore, y esto nunca dice «no». Con unknown_stops_with_accessible_fleet = yes,
    // las paradas sin dato de una flota toda accesible cuentan como accesibles.
    'accessibility' => [
        'fleet' => 'all',
        'stops' => 'all',
        'gtfs' => 'trust',
        'unknown_stops_with_accessible_fleet' => 'no',
        'source' => 'https://www.euskotren.eus/es/tranvia/accesibilidad',
        'checked' => '2026-11-15',
        'note' => 'Todas las paradas son accesibles al cien por cien y los tranvías son de piso bajo.',
    ],

    'gtfs' => [
        // Publica solo los próximos días y lo renueva a diario: el resumen del flujo avisa y falla con 2 días o menos.
        'expiry_alert_days' => 2,
        // Festivos de los que su GTFS no dice nada (sin calendar_dates o pasado su periodo), con el servicio
        // de domingo: los del calendario laboral (Build\Holidays) de toda Euskadi, del territorio y del
        // municipio; sin él, los que salen de las redes de respaldo.
        'holidays' => [
            'calendar' => 'euskadi', 'territory' => 'Bizkaia', 'municipality' => 'Bilbao',
            'fallback' => ['networks' => ['dbus', 'bilbobus', 'alavabus'], 'quorum' => 2],
        ],
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1263',
        'fallback_source' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfs_euskotren.zip',
        'agency_id' => 'ES:Euskotren:Operator:EUS_TrBi:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => false,
    ],
];
