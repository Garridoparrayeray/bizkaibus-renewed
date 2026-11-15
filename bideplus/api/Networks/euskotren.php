<?php

return [
    'slug' => 'euskotren',
    'order' => 3,
    'db' => 'euskotren',
    'kind' => 'train',

    'brand' => [
        'icons' => 'icons-euskotren',
        'theme_color' => '#003F8C',
        'title' => ['EUSKOTREN', ''],
        'logomark' => '<img src="/icons-euskotren/icon-192.png" alt="Euskotren+" aria-hidden="true">',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
        'tile_color' => '#003F8C',
        'swatch_color' => '#003F8C',
        'palette' => [
            'bg' => '#f0f4f8',
            'border' => '#d0dce8',
            'primary' => '#003F8C',
            'primary-light' => '#5B9BD5',
            'primary-dark' => '#002560',
            'primary-tint' => '#E6F0FA',
            'text' => '#171d26',
            'text-secondary' => '#4d5966',
            'backdrop' => 'rgba(10, 14, 20, .5)',
        ],
    ],

    'texts' => [
        'es' => [
            'liveSource' => 'Euskotren',
            'name' => 'Euskotren+',
            'tile' => 'Horarios',
            'switcher' => 'Horarios de Euskotren',
            'subtitle' => 'Horarios de Euskotren',
            'liveEmpty' => 'Busca una estación para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Euskotren S.A. Euskotren no publica el directo de sus trenes: las horas son las del horario oficial.',
            'dataPublished' => 'Solo su horario: su directo repite el horario y no da posiciones.',
            'dataMethod' => 'Horario oficial.',
            'dataLimits' => 'Sin retrasos ni cancelaciones.',
        ],
        'eu' => [
            'liveSource' => 'Euskotrenek',
            'name' => 'Euskotren+',
            'tile' => 'Ordutegiak',
            'switcher' => 'Euskotrenen ordutegiak',
            'subtitle' => 'Euskotrenen ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Euskotren S.A.-rekin loturarik gabea. Euskotrenek ez du bere trenen zuzenekoa argitaratzen: orduak ordutegi ofizialekoak dira.',
            'dataPublished' => 'Bere ordutegia bakarrik: zuzenekoak ordutegia errepikatzen du eta ez du kokapenik ematen.',
            'dataMethod' => 'Ordutegi ofiziala.',
            'dataLimits' => 'Atzerapenik eta bertan behera utzitakorik gabe.',
            'attribution' => 'Datuak: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
        'en' => [
            'liveSource' => 'Euskotren',
            'name' => 'Euskotren+',
            'tile' => 'Timetables',
            'switcher' => 'Euskotren timetables',
            'subtitle' => 'Euskotren timetables',
            'liveEmpty' => 'Search for a station to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Euskotren S.A. Euskotren does not publish live data for its trains: times are from the official timetable.',
            'dataPublished' => 'Only its timetable: its live feed repeats the timetable and gives no positions.',
            'dataMethod' => 'The official timetable.',
            'dataLimits' => 'No delays or cancellations.',
            'attribution' => 'Data: Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
        'fr' => [
            'liveSource' => 'Euskotren',
            'name' => 'Euskotren+',
            'tile' => 'Horaires',
            'switcher' => 'Horaires d’Euskotren',
            'subtitle' => 'Horaires d’Euskotren',
            'liveEmpty' => 'Cherchez une gare pour voir le prochain train.',
            'disclaimer' => 'Projet indépendant et non officiel, sans lien avec Euskotren S.A. Euskotren ne publie pas le temps réel de ses trains : les heures sont celles de l’horaire officiel.',
            'dataPublished' => 'Seulement son horaire : son flux en direct répète l’horaire et ne donne pas de positions.',
            'dataMethod' => 'Horaire officiel.',
            'dataLimits' => 'Ni retards ni suppressions.',
            'attribution' => 'Données : Euskotren / Open Data Euskadi (CC-BY 4.0)',
        ],
    ],

    'seo' => [
        // El nombre del operador, el que se busca: va en los títulos y descripciones de cada parada y línea.
        // Dónde están sus paradas, para la dirección de cada una en los datos estructurados (país en ISO 3166).
        'region' => 'Euskadi',
        'country' => 'ES',
        'operator' => 'Euskotren',
        'title' => 'Euskotren+ · Horarios de trenes y tranvía de Euskotren',
        'description' => 'Horarios de Euskotren por línea y estación en Bizkaia y Gipuzkoa: los trenes, el topo de Donostia a Hendaia y la L3 de Bilbao, con sus próximas salidas.',
    ],
    'attribution' => 'Datos: Euskotren / Open Data Euskadi (CC-BY 4.0)',

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

    // Servicio reducido por el directo (Services\ReducedService): en las 6 paradas con más pasos (40 salidas de
    // cada una), de las de los próximos 20 min cuyo viaje ya ha salido, falta el 60 % o más durante 15 min (lo
    // normal no llega al 20 %).
    'reduced_service' => [
        'live' => [
            'sample_stops' => 6, 'rows_per_stop' => 40, 'window_seconds' => 1200,
            'min_expected' => 8, 'missing_share' => 0.6, 'confirm_seconds' => 900,
        ],
    ],

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

    // Mapa de la red: los colores del plano del operador (el GTFS trae el mismo para todas las líneas).
    'map' => [
        'colors' => ['E1' => '#0057A6', 'E2' => '#B5BD00', 'E3' => '#4FB3E8', 'E3a' => '#4FB3E8', 'E4' => '#1B3F8B', 'L3' => '#E5007E'],
    ],

    'gtfs' => [
        // Publica solo los próximos días y lo renueva a diario: el resumen del flujo avisa y falla con 2 días o menos.
        'expiry_alert_days' => 2,
        // Festivos de los que su GTFS no dice nada (sin calendar_dates o pasado su periodo), con el servicio
        // de domingo: los del calendario laboral (Build\Holidays) de toda Euskadi, del territorio y del
        // municipio; sin él, los que salen de las redes de respaldo.
        'holidays' => [
            'calendar' => 'euskadi', 'territory' => null, 'municipality' => null,
            'fallback' => ['networks' => ['dbus', 'bilbobus', 'alavabus'], 'quorum' => 2],
        ],
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1263',
        'fallback_source' => 'https://opendata.euskadi.eus/transport/moveuskadi/euskotren/gtfs_euskotren.zip',
        'agency_id' => 'ES:Euskotren:Operator:EUS_Tren:',
        'stops' => 'euskotren',
        'skip_geocode' => false,
        // Hay servicios de días sueltos: cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => true,
    ],
];
