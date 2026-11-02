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
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos y los trenes cancelados son los que publica Renfe en directo.',
        ],
        'eu' => [
            'name' => 'Renfe Aldiriak+',
            'tile' => 'Bilbo eta Donostiako aldiriak',
            'switcher' => 'Bilbo eta Donostiako Aldirien ordutegiak',
            'subtitle' => 'Aldirien ordutegiak',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'attribution' => 'Datuak: Renfe Aldiriak',
        ],
        'en' => [
            'name' => 'Renfe Cercanías+',
            'tile' => 'Commuter trains in Bilbao and Donostia',
            'switcher' => 'Commuter train timetables in Bilbao and Donostia',
            'subtitle' => 'Commuter train timetables',
            'liveEmpty' => 'Search for a stop to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Renfe. Delays and cancelled trains are those Renfe publishes live.',
            'attribution' => 'Data: Renfe Cercanías',
        ],
    ],

    'seo' => [
        'title' => 'Renfe Cercanías+ · Horarios de Cercanías en Bilbao y Donostia',
        'description' => 'Consulta los horarios de Cercanías Renfe en Bilbao y Donostia/Irun por parada.',
    ],
    'attribution' => 'Datos: Renfe Cercanías',

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

    'live' => ['trip-updates', 'vehicle-positions'],

    // Directo de Renfe (GTFS-Realtime, cada minuto): el retraso de cada tren, los cancelados y dónde va cada tren. Sus
    // viajes son los del horario, así que se empareja por viaje.
    'gtfs_rt' => [
        'trip_updates_url' => 'https://gtfsrt.renfe.com/trip_updates.pb',
        'vehicle_positions_url' => 'https://gtfsrt.renfe.com/vehicle_positions.pb',
        'matching' => 'trip',
        'cache_ttl_seconds' => 30,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 300,
    ],

    // Renfe a veces deja de actualizar el fichero de avisos: si tiene más de 2 días, sus avisos no salen.
    'renfe_alerts' => [
        'url' => 'https://gtfsrt.renfe.com/alerts.json',
        'cache_ttl_seconds' => 120,
        'http_timeout_seconds' => 8,
        'max_age_seconds' => 2 * 86400,
    ],

    // El horario lo publica Renfe a diario, con los mismos viajes que su directo. La copia del NAP llega con semanas de
    // retraso y solo queda de reserva.
    'gtfs' => [
        'source' => 'https://ssl.renfe.com/ftransit/Fichero_CER_FOMENTO/fomento_transit.zip',
        'fallback_source' => 'https://nap.transportes.gob.es/api/Fichero/download/1130',
        'agency_id' => null,
        'route_id_prefixes' => ['60T', '61T'],
        'stops' => 'renfe',
        'skip_geocode' => false,
        'required' => false,
    ],
];
