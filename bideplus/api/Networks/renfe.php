<?php

return [
    'slug' => 'renfe',
    'order' => 4,
    'db' => 'renfe',
    'kind' => 'train',

    'brand' => [
        'icons' => 'icons-renfe',
        'theme_color' => '#74349A',
        'title' => ['RENFE', ''],
        'logomark' => '<img src="/icons-renfe/glyph.png" alt="">',
        'card_logomark' => '<img src="/icons-renfe/icon-192.png" alt="">',
        // Colores (scripts/build-networks-css.php los pasa a style-networks.css): la ficha del menú, el cuadradito de
        // Bide+ y la paleta de la app. Sin paleta ni cuadradito, los de Bide+ (verde).
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
            'name' => 'Renfe Cercanías+',
            'tile' => 'Cercanías en Bilbao y Donostia',
            'switcher' => 'Horarios de Cercanías en Bilbao y Donostia',
            'subtitle' => 'Horarios de Cercanías',
            'liveEmpty' => 'Busca una parada para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Renfe. Horario programado oficial: no refleja retrasos ni cancelaciones, salvo los avisos de incidencias que publique el operador.',
        ],
        'eu' => [
            'name' => 'Renfe Aldiriak+',
            'tile' => 'Bilbo eta Donostiako aldiriak',
            'switcher' => 'Bilbo eta Donostiako Aldirien ordutegiak',
            'subtitle' => 'Aldirien ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
        ],
        'en' => [
            'name' => 'Renfe Cercanías+',
            'tile' => 'Commuter trains in Bilbao and Donostia',
            'switcher' => 'Commuter train timetables in Bilbao and Donostia',
            'subtitle' => 'Commuter train timetables',
            'liveEmpty' => 'Search for a stop to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Renfe. Official scheduled timetable: it does not reflect delays or cancellations, except for the disruption alerts the operator publishes.',
        ],
    ],

    'seo' => [
        'title' => 'Renfe Cercanías+ · Horarios de Cercanías en Bilbao y Donostia',
        'description' => 'Consulta los horarios de Cercanías Renfe en Bilbao y Donostia/Irun por parada.',
    ],
    'attribution' => 'Datos: Renfe Cercanías / NAP (Punto de Acceso Nacional de Transporte)',

    'features' => [
        'platforms' => false,
        'headsign_from_last_stop' => true,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'stops',
        'alerts_detail' => true,
    ],

    // Sin datos en directo fiables: se muestra el horario oficial.
    'live' => [],

    'renfe_alerts' => [
        'url' => 'https://gtfsrt.renfe.com/alerts.json',
        'cache_ttl_seconds' => 120,
        'http_timeout_seconds' => 8,
    ],

    'gtfs' => [
        'source' => 'https://nap.transportes.gob.es/api/Fichero/download/1130',
        'fallback_source' => null,
        'agency_id' => null,
        'route_id_prefixes' => ['60T', '61T'],
        'stops' => 'renfe',
        'skip_geocode' => false,
        'required' => false,
    ],
];
