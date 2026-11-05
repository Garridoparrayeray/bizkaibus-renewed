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
        'logomark' => '<img src="/icons-renfe/glyph.png" alt="Renfe Cercanías+" aria-hidden="true">',
        'card_logomark' => '<img src="/icons-renfe/icon-192.png" alt="Renfe Cercanías+" aria-hidden="true">',
        // Colores (scripts/build-networks-css.php los pasa a css/style-networks.css): la ficha del menú, el cuadradito de
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
            'switcher' => 'Horarios y tiempo real de Cercanías en Bilbao y Donostia',
            'subtitle' => 'Horarios y tiempo real de Cercanías',
            'liveEmpty' => 'Busca una parada para ver el próximo tren.',
            'disclaimer' => 'Proyecto independiente y no oficial, sin relación con Renfe. Los retrasos y los trenes cancelados son los que publica Renfe en directo.',
        ],
        'eu' => [
            'name' => 'Renfe Aldiriak+',
            'tile' => 'Bilbo eta Donostiako aldiriak',
            'switcher' => 'Bilbo eta Donostiako Aldirien ordutegiak eta denbora errealean',
            'subtitle' => 'Aldirien ordutegiak eta denbora errealean',
            'liveEmpty' => 'Bilatu geltoki bat hurrengo trena ikusteko.',
            'disclaimer' => 'Proiektu independentea eta ez-ofiziala, Renferekin loturarik gabea. Atzerapenak eta bertan behera utzitako trenak Renfek zuzenean argitaratzen dituenak dira.',
            'attribution' => 'Datuak: Renfe Aldiriak',
        ],
        'en' => [
            'name' => 'Renfe Cercanías+',
            'tile' => 'Commuter trains in Bilbao and Donostia',
            'switcher' => 'Commuter train timetables and real time in Bilbao and Donostia',
            'subtitle' => 'Commuter train timetables and real time',
            'liveEmpty' => 'Search for a stop to see the next train.',
            'disclaimer' => 'An independent, unofficial project, not affiliated with Renfe. Delays and cancelled trains are those Renfe publishes live.',
            'attribution' => 'Data: Renfe Cercanías',
        ],
    ],

    'seo' => [
        'title' => 'Renfe Cercanías+ · Horarios y retrasos en tiempo real de Cercanías en Bilbao y Donostia',
        'description' => 'Consulta los horarios de Cercanías Renfe en Bilbao y Donostia/Irun por parada, con los retrasos y los trenes cancelados en tiempo real.',
    ],
    'attribution' => 'Datos: Renfe Cercanías',

    'features' => [
        'platforms' => true,
        'line_buttons' => true,
        'headsign_from_last_stop' => true,
        'stop_hints' => false,
        'line_map' => true,
        'schedule_text' => false,
        'trip_detail' => 'vehicle',
        'alerts' => 'stops',
        'alerts_detail' => true,
    ],

    'live' => ['trip-updates', 'vehicle-positions'],

    // El workflow «Ritmo de los buses» guarda este directo para que el motor aprenda de él (engine/renfe). La app todavía
    // no lo usa: primero se mide si acierta más que el retraso del feed.
    'capture' => 'gtfs-rt',

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
        // Hay trenes de días sueltos (obras, festivos): cada viaje lleva las fechas exactas del periodo publicado.
        'feed_window' => true,
        'required' => false,
    ],
];
