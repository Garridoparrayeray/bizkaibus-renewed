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

echo "\nRESULTADO construcción: " . (empty($failures) ? 'OK' : 'FALLA -> ' . implode('; ', $failures)) . "\n";
exit(empty($failures) ? 0 : 1);
