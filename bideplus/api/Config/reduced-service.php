<?php

// Servicio reducido (huelgas, servicios mínimos): lo común a todas las redes. Lo usa Services\ReducedService; cada red
// pone en su ficha, en reduced_service.live, si su directo vale para verlo y con qué umbrales.
return [
    // Las palabras (sin tildes ni mayúsculas) que dicen que no pasan todos los del horario, por causa: «strike» es una
    // huelga con servicios mínimos; «reduced», un servicio reducido por otra cosa (obras, un acto).
    'causes' => [
        'strike' => [
            'es' => ['huelga', 'servicios minimos', 'servicio minimo', 'paros parciales', 'paro parcial'],
            'eu' => ['greba', 'gutxieneko zerbitzu', 'lanuzte'],
            'fr' => ['greve', 'service minimum', 'mouvement social'],
        ],
        'reduced' => [
            'es' => ['servicio reducido'],
            'eu' => ['zerbitzu murriztu'],
            'fr' => ['service reduit'],
        ],
    ],
    // En el trozo de frase (entre comas) donde salen, el aviso es de que esa huelga ya no va: sus fechas no cuentan.
    'exclude' => [
        'es' => ['desconvoca', 'finaliz'],
        'eu' => ['desdeitu', 'amaitu'],
        'fr' => ['levee', 'leve le preavis'],
    ],
    // En toda la frase donde salen: vuelta a la normalidad o el refuerzo de una red por la huelga de otra.
    'exclude_sentence' => [
        'es' => ['recupera', 'normalidad', 'servicio normal', 'servicio habitual', 'refuerz'],
        'eu' => ['berreskura', 'ohiko zerbitzu', 'zerbitzu arrunt', 'normaltasun', 'indartu', 'indartze'],
        'fr' => ['reprise', 'retour a la normale', 'trafic normal', 'renfort'],
    ],
    // Una de estas justo antes (dos palabras como mucho) anula la exclusión: «no desconvocan», «ez dute desdeitu».
    'negations' => ['no', 'ez', 'ne', 'pas'],
    // Los meses como salen en los avisos: en euskera, la raíz («azaroaren 17an»).
    'months' => [
        'es' => ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
        'eu' => ['urtarril', 'otsail', 'martxo', 'apiril', 'maiatz', 'ekain', 'uztail', 'abuztu', 'irail', 'urri', 'azaro', 'abendu'],
        'fr' => ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'],
    ],
    // Lo que va con el nombre del mes: en euskera, sus terminaciones («azaroaren», «azaroak») y que los días van
    // detrás; en castellano, el «de» que lo une a su día.
    'month_suffixes' => ['eu' => ['a', 'ak', 'aren', 'ren', 'ean', 'eko', 'ko']],
    'days_after_month' => ['eu'],
    'month_links' => ['de'],
    // Lo que une dos días en un aviso: un rango («del 17 al 19», «17tik 19ra») o días sueltos («17, 18 y 19»).
    'range_words' => ['al', 'a', 'au', 'hasta', '-'],
    'range_suffixes' => ['tik', 'etik'],
    'list_words' => ['y', 'e', 'et', 'eta', ','],
    // Delante de una fecha sin mes escrito («el 17/11»): sin una de estas, «1/2 jornada» no es una fecha.
    'date_words' => ['el', 'del', 'dia', 'dias', 'desde', 'hasta', 'al', 'le', 'du', 'au', 'egun', 'eguna'],
    // Delante de la fecha: la huelga sigue desde ese día hasta donde se mira («indefinida desde el 1 de diciembre»).
    'open_words' => ['a partir', 'desde', 'indefinida', 'indefinido', 'mugagabea', 'aurrera', 'a compter', 'depuis'],
    // Hasta cuántos días por delante se marcan las fechas de un aviso.
    'horizon_days' => 31,
    // Cuánto vale lo calculado para una red y cuánto se guarda lo último bueno si la fuente de avisos falla.
    'cache_seconds' => 60,
    'keep_last_good_seconds' => 900,
    // Hasta esta hora de la madrugada el día de servicio es el de ayer (los últimos trenes pasan de las 24:00).
    'service_day_start_seconds' => 4 * 3600,
];
