<?php

// Calendarios laborales oficiales en datos abiertos (Build\Holidays): de dónde se bajan, año a año, y qué campo dice
// la fecha, el territorio y el municipio. La ficha de cada red elige el suyo en gtfs.holidays.
return [
    'calendars' => [
        // Gobierno Vasco, «Calendario laboral»: festivos de toda Euskadi, de cada territorio y de cada municipio.
        'euskadi' => [
            'url' => 'https://opendata.euskadi.eus/contenidos/ds_eventos/calendario_laboral_{year}/opendata/calendario_laboral_{year}.json',
            'date' => 'date',
            'territory' => 'territory',
            'municipality' => 'municipalityEs',
            'everywhere' => 'Todos/denak',
        ],
    ],
    // Lo bajado se guarda una semana; si no se puede bajar, vale lo guardado aunque sea más viejo.
    'cache_seconds' => 7 * 86400,
    'http_timeout_seconds' => 20,
];
