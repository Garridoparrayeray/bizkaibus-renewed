<?php

return [
    'network' => 'metro',
    'db_path' => __DIR__ . '/../../data/metrobilbao.sqlite',

    // Metro Bilbao no publica feed SIRI en vivo (posición de vehículos) ni
    // endpoint de horario legado en texto libre — sin bloque 'siri' ni
    // 'schedule_text', los controllers que los necesitan (RealtimeController,
    // schedule-text de LinesController) simplemente no se registran para esta
    // red (ver api/index.php). Sí tiene avisos/incidencias, pero por un
    // endpoint JSON propio (ver 'metro_alerts' abajo), no SIRI-SX — por eso
    // AlertsController sí se registra para las dos redes.
    'metro_alerts' => [
        'url' => 'https://cms.metrobilbao.eus/es/get/open_data/json/avisos/es',
        'cache_ttl_seconds' => 60,
        'http_timeout_seconds' => 8,
    ],

    // Fallback si la tabla meta del sqlite no trajera el dato (siempre lo trae, ver scripts/build-database.php::insertMeta).
    'schedule_source_published' => date('Y-m-d'),

    // stop_id de Abando en el GTFS de Metro Bilbao — viene directamente del
    // propio dato del operador, estable mientras no cambien sus IDs. Usado
    // como referencia para agrupar las salidas de una estación por sentido
    // de circulación (panel de andén, ver ServiceJourney::upcomingAtStop()).
    // Si algún día deja de coincidir: SELECT id FROM stops WHERE name = 'Abando'.
    'direction_reference_stop_id' => 7,

    'attribution' => 'Datos: Metro Bilbao / Open Data Metro Bilbao (metrobilbao.eus)',
];
