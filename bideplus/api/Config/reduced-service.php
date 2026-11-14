<?php

// Servicio reducido (huelgas, servicios mínimos): lo común a todas las redes. Lo usa Services\ReducedService; cada red
// pone en su ficha, en reduced_service.live, si su directo vale para verlo y con qué umbrales.
return [
    // Un aviso del operador con alguna de estas palabras (sin tildes ni mayúsculas) dice que no pasan todos los del
    // horario. Van en castellano y euskera, y en francés por las redes de Iparralde.
    'keywords' => [
        'es' => ['huelga', 'servicios minimos', 'servicio minimo', 'servicio reducido', 'paros parciales', 'paro parcial'],
        'eu' => ['greba', 'gutxieneko zerbitzu', 'zerbitzu murriztu', 'lanuzte'],
        'fr' => ['greve', 'service minimum', 'service reduit', 'mouvement social'],
    ],
    // Y con alguna de estas, el aviso es de que ya no hay huelga.
    'exclude' => [
        'es' => ['desconvoca'],
        'eu' => ['desdeitu'],
        'fr' => ['levee', 'leve le preavis'],
    ],
    // Los meses como salen en los avisos: en euskera, la raíz («azaroaren 17an»).
    'months' => [
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        'eu' => ['urtarril', 'otsail', 'martxo', 'apiril', 'maiatz', 'ekain', 'uztail', 'abuztu', 'irail', 'urri', 'azaro', 'abendu'],
        'fr' => ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'],
    ],
    // Hasta cuántos días por delante se marcan las fechas de un aviso, y cuánto dura lo calculado para una red.
    'horizon_days' => 31,
    'cache_seconds' => 60,
];
