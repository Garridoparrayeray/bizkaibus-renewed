<?php

require __DIR__ . '/../scripts/build-database.php';

$failures = [];
$expect = function (string $name, bool $ok) use (&$failures): void {
    echo ($ok ? 'OK  ' : 'MAL ') . $name . "\n";
    if (!$ok) {
        $failures[] = $name;
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
$group = fn(array $aMembers) => [
    'memberTripIds' => $aMembers,
    'excludedDates' => array_fill_keys(array_merge(...array_map(fn($s) => $aSignatures[$s]['excludedDates'], $aMembers)), true),
];

$expect('un día anulado en un calendario no anula el viaje fusionado si otro calendario circula ese día', excludedDatesForGroup($group(['laborable', 'finde']), $aSignatures) === []);
$expect('si ningún calendario fusionado circula ese día, el día sigue anulado', excludedDatesForGroup($group(['finde']), $aSignatures) === ['2026-10-05' => true]);
$expect('una fecha incluida a mano en otro calendario también cuenta como que circula', excludedDatesForGroup($group(['finde_anulado', 'especial']), $aSignatures) === []);
$expect('signatureRunsOn: lunes en L-V sí, domingo no', signatureRunsOn($aSignatures['laborable'], '2026-10-05') && !signatureRunsOn($aSignatures['laborable'], '2026-10-04'));

/*
 * Bizkaibus publica el mismo viaje (línea, número, paradas y salida) con horas de paso distintas
 * según el día: el de laborable tarda más que el de festivo. Solo se fusionan si las horas coinciden.
 */
$stopTimes = fn(array $aArrivals) => array_map(fn($iArrival) => ['arrival' => $iArrival, 'departure' => $iArrival + 2], $aArrivals);
$aLaborable = $stopTimes([52200, 53880, 54060]);
$aFestivo = $stopTimes([52200, 53160, 53280]);
$aLaborableUnMinutoDespues = $stopTimes([52260, 53940, 54120]);
$expect('viajes con distintas horas de paso no se fusionan', timingKeyFor($aLaborable, 52200) !== timingKeyFor($aFestivo, 52200));
$expect('viajes con las mismas horas de paso relativas sí se fusionan', timingKeyFor($aLaborable, 52200) === timingKeyFor($aLaborableUnMinutoDespues, 52260));
$expect('las paradas sin hora no rompen la clave', timingKeyFor([['arrival' => 100, 'departure' => 100], ['arrival' => null, 'departure' => null]], 100) !== '');

echo "\nRESULTADO construcción: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
