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
        'line_map' => false,
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
