<?php

require __DIR__ . '/../scripts/build-database.php';

$aFailures = [];
$Expect = function (string $sName, bool $bOk) use (&$aFailures): void {
    $sLabel = 'OK  ';
    if (!$bOk) {
        $sLabel = 'MAL ';
    }
    echo $sLabel . $sName . "\n";
    if (!$bOk) {
        $aFailures[] = $sName;
    }
};

/*
 * Dos viajes a la misma hora se fusionan en un solo viaje con la unión de sus calendarios.
 * Lunes 2026-10-05: el calendario de lunes a viernes circula y el de fin de semana (que también
 * incluye el lunes) tiene ese día anulado.
 */
$aSignatures = [
    'laborable' => ['weekdayMask' => 31, 'includedDates' => [], 'excludedDates' => []],
    'finde' => ['weekdayMask' => 97, 'includedDates' => [], 'excludedDates' => ['2026-10-05']],
    'finde_anulado' => ['weekdayMask' => 96, 'includedDates' => [], 'excludedDates' => ['2026-10-04']],
    'especial' => ['weekdayMask' => 0, 'includedDates' => ['2026-10-04'], 'excludedDates' => []],
];
$Group = fn(array $aMembers) => [
    'memberTripIds' => $aMembers,
    'excludedDates' => array_fill_keys(array_merge(...array_map(fn($sSignature) => $aSignatures[$sSignature]['excludedDates'], $aMembers)), true),
];

$Expect('un día anulado en un calendario no anula el viaje fusionado si otro calendario circula ese día', excludedDatesForGroup($Group(['laborable', 'finde']), $aSignatures) === []);
$Expect('si ningún calendario fusionado circula ese día, el día sigue anulado', excludedDatesForGroup($Group(['finde']), $aSignatures) === ['2026-10-05' => true]);
$Expect('una fecha incluida a mano en otro calendario también cuenta como que circula', excludedDatesForGroup($Group(['finde_anulado', 'especial']), $aSignatures) === []);
$Expect('signatureRunsOn: lunes en L-V sí, domingo no', signatureRunsOn($aSignatures['laborable'], '2026-10-05') && !signatureRunsOn($aSignatures['laborable'], '2026-10-04'));

/*
 * Bizkaibus publica el mismo viaje (línea, número, paradas y salida) con horas de paso distintas
 * según el día: el de laborable tarda más que el de festivo. Solo se fusionan si las horas coinciden.
 */
$StopTimes = fn(array $aArrivals) => array_map(fn($iArrival) => ['arrival' => $iArrival, 'departure' => $iArrival + 2], $aArrivals);
$aLaborable = $StopTimes([52200, 53880, 54060]);
$aFestivo = $StopTimes([52200, 53160, 53280]);
$aLaborableUnMinutoDespues = $StopTimes([52260, 53940, 54120]);
$Expect('viajes con distintas horas de paso no se fusionan', timingKeyFor($aLaborable, 52200) !== timingKeyFor($aFestivo, 52200));
$Expect('viajes con las mismas horas de paso relativas sí se fusionan', timingKeyFor($aLaborable, 52200) === timingKeyFor($aLaborableUnMinutoDespues, 52260));
$Expect('las paradas sin hora no rompen la clave', timingKeyFor([['arrival' => 100, 'departure' => 100], ['arrival' => null, 'departure' => null]], 100) !== '');

/*
 * Metro Bilbao (CTB): un calendario Lun-Jue (obranegvia2) y otro solo por fechas (obranegvia1) que lo
 * sustituye esos días. Las fechas sueltas no son un patrón semanal: deducir «todos los jueves» de dos
 * jueves duplicaba todos los trenes los jueves sin sustitución.
 */
$sZipPath = tempnam(sys_get_temp_dir(), 'gtfs');
$Zip = new ZipArchive();
$Zip->open($sZipPath, ZipArchive::OVERWRITE);
$Zip->addFromString('calendar.txt', "service_id,monday,tuesday,wednesday,thursday,friday,saturday,sunday,start_date,end_date\nbase,1,1,1,1,0,0,0,20261008,20261108\n");
$Zip->addFromString('calendar_dates.txt', "service_id,date,exception_type\nbase,20261008,2\nsustituto,20261008,1\nbase,20261013,2\nsustituto,20261013,1\nbase,20261015,2\nsustituto,20261015,1\n");
$Zip->close();
$Zip->open($sZipPath);
$aLiteral = loadCalendars($Zip, true);
$aInferred = loadCalendars($Zip, false);
$Zip->close();
unlink($sZipPath);
$Expect('Metro: un calendario solo por fechas no recibe días de la semana', $aLiteral['sustituto']['weekdayMask'] === 0);
$Expect('Metro: sus fechas quedan tal cual (8, 13 y 15 de octubre)', $aLiteral['sustituto']['includedDates'] === ['2026-10-08', '2026-10-13', '2026-10-15']);
$Expect('Metro: el calendario Lun-Jue conserva su máscara y sus días anulados', $aLiteral['base']['weekdayMask'] === 15 && $aLiteral['base']['excludedDates'] === ['2026-10-08', '2026-10-13', '2026-10-15']);
$Expect('loadCalendars sin modo literal sigue deduciendo el patrón semanal (lo usan otras redes; en Bizkaibus los viajes usan el calendario exacto)', $aInferred['sustituto']['weekdayMask'] === 8);

/*
 * Bizkaibus: el calendario de cada viaje es exacto dentro del periodo publicado. Antes, dos fechas de un mismo día de la
 * semana bastaban para deducir «todos esos días» de todo el periodo, y el viaje se mostraba también en festivos y en
 * semanas en las que no circula.
 */
$aWindow = datesBetween('2026-10-14', '2026-12-14');
$Expect('datesBetween: 62 fechas del 14 oct al 14 dic, ambas incluidas', count($aWindow) === 62 && $aWindow[0] === '2026-10-14' && $aWindow[61] === '2026-12-14');
$Runs = fn(array $aDates) => exactServiceDates(['mask' => 0, 'from' => '', 'to' => '', 'available' => $aDates, 'excluded' => []], $aWindow);

// Lunes a jueves todo el periodo salvo el festivo del martes 8 de diciembre (no figura en calendar_dates).
$aMonThu = [];
foreach ($aWindow as $sDate) {
    if ((int)(new DateTime($sDate))->format('N') <= 4 && $sDate !== '2026-12-08') {
        $aMonThu[] = $sDate;
    }
}
[$iMask, $aInc, $aExc] = exactCalendarForWindow($Runs($aMonThu), $aWindow);
$Expect('lunes a jueves con el 8 de diciembre sin servicio: máscara L-J y ese festivo excluido', $iMask === 15 && $aInc === [] && $aExc === ['2026-12-08']);

// Solo dos viernes de nueve: no se deduce «todos los viernes».
[$iMask, $aInc, $aExc] = exactCalendarForWindow($Runs(['2026-10-16', '2026-10-23']), $aWindow);
$Expect('solo dos viernes de nueve: sin máscara semanal, solo esas dos fechas', $iMask === 0 && $aInc === ['2026-10-16', '2026-10-23'] && $aExc === []);

// Todos los viernes del periodo: patrón semanal puro, sin fechas sueltas.
$aFridays = array_values(array_filter($aWindow, fn($sDay) => (int)(new DateTime($sDay))->format('N') === 5));
[$iMask, $aInc, $aExc] = exactCalendarForWindow($Runs($aFridays), $aWindow);
$Expect('todos los viernes del periodo: máscara de viernes y ninguna fecha suelta', $iMask === 16 && $aInc === [] && $aExc === []);

// Un servicio que empieza a mitad de periodo no se rellena hacia atrás.
$aFromLater = array_values(array_filter($aMonThu, fn($sDay) => $sDay >= '2026-10-19'));
[$iMask, $aInc, $aExc] = exactCalendarForWindow($Runs($aFromLater), $aWindow);
$Expect('servicio que empieza el lunes 19: el 14 y el 15 de octubre quedan excluidos', $iMask === 15 && in_array('2026-10-14', $aExc, true) && in_array('2026-10-15', $aExc, true));

// El calendario de calendar.txt cuenta dentro de su rango y las anulaciones lo recortan.
$aBase = exactServiceDates(['mask' => 1, 'from' => '2026-10-19', 'to' => '2026-11-02', 'available' => [], 'excluded' => ['2026-10-26']], $aWindow);
$Expect('calendar.txt: los lunes de su rango menos el anulado (19 oct y 2 nov)', array_keys($aBase) === ['2026-10-19', '2026-11-02']);

// Variantes fusionadas (lunes a jueves + viernes): se unen sin perder el festivo.
$aUnion = $Runs($aMonThu) + $Runs($aFridays);
[$iMask, $aInc, $aExc] = exactCalendarForWindow($aUnion, $aWindow);
$Expect('variantes L-J y V fusionadas: lunes a viernes menos el 8 de diciembre', $iMask === 31 && $aExc === ['2026-12-08'] && $aInc === []);

if (!empty($aFailures)) {
    echo "\nRESULTADO construcción: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO construcción: OK\n";
exit(0);
