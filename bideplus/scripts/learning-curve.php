<?php

declare(strict_types=1);

/*
 * Curva de aprendizaje de los tiempos por tramo: cuánto mejora el sistema al darle más datos.
 *
 * Uso: php scripts/learning-curve.php <tramos.jsonl>   (el acumulado de segment-samples, o el seed descomprimido)
 *
 * La mitad de los buses (por crc32, como en la validación del recalibrado) se aparta para probar. Con la otra mitad se
 * aprenden los tiempos por tramo usando una fracción cada vez mayor de sus buses, y en cada paso se mide, sobre los
 * buses apartados:
 *   - cobertura: qué parte de los tramos medidos tienen tiempo aprendido;
 *   - error de lo que usa la app (lo aprendido si existe, y si no k x horario);
 *   - error de usar solo k x horario.
 */

date_default_timezone_set('Europe/Madrid');

spl_autoload_register(function (string $sClass): void {
    $sPath = __DIR__ . '/../api/' . str_replace('\\', '/', $sClass) . '.php';
    if (is_file($sPath)) {
        require $sPath;
    }
});

use Services\PaceFactors;
use Services\SegmentTimes;

const MIN_TRACKS_PER_SEGMENT = 2;

function median(array $aValues): float
{
    sort($aValues);
    return (float)$aValues[(int)floor(0.5 * (count($aValues) - 1))];
}

function fit(array $aSamples): SegmentTimes
{
    $aByKey = [];
    foreach ($aSamples as $aSample) {
        $sKey = SegmentTimes::key($aSample['from'], $aSample['to']);
        $aByKey[$sKey]['all'][$aSample['track']] = $aSample['actual'];
        $aByKey[$sKey][$aSample['band']][$aSample['track']] = $aSample['actual'];
    }
    $aSegments = [];
    foreach ($aByKey as $sKey => $aGroups) {
        if (count($aGroups['all']) < MIN_TRACKS_PER_SEGMENT) {
            continue;
        }
        $aSegment = ['n' => count($aGroups['all'])];
        $bSeveralBands = count($aGroups) > 2;
        foreach ($aGroups as $sGroup => $aActuals) {
            if ($sGroup === 'all' && !$bSeveralBands) {
                continue;
            }
            if (count($aActuals) >= MIN_TRACKS_PER_SEGMENT) {
                $aSegment[$sGroup] = (int)round(median(array_values($aActuals)));
            }
        }
        if (count($aSegment) > 1) {
            $aSegments[$sKey] = $aSegment;
        }
    }
    return new SegmentTimes(['segments' => $aSegments]);
}

$sPath = $argv[1] ?? '';
if (!is_file($sPath)) {
    fwrite(STDERR, "Uso: php scripts/learning-curve.php <tramos.jsonl>\n");
    exit(1);
}

$aAll = [];
foreach (file($sPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $sLine) {
    $aSample = json_decode($sLine, true);
    if (is_array($aSample) && isset($aSample['track'], $aSample['at'], $aSample['band'], $aSample['from'], $aSample['to'], $aSample['scheduled'], $aSample['actual'], $aSample['line'], $aSample['date'])) {
        $aAll[] = $aSample;
    }
}

$aTest = array_filter($aAll, fn($a) => crc32($a['track']) % 2 === 0);
$aPool = array_filter($aAll, fn($a) => crc32($a['track']) % 2 !== 0);
$Pace = PaceFactors::fromFile();

$aTrainTracks = array_unique(array_column($aPool, 'track'));
$aTestTracks = array_unique(array_column($aTest, 'track'));
printf("Muestras: %d (%d buses). Para probar: %d buses. Para aprender: hasta %d buses.\n\n", count($aAll), count(array_unique(array_column($aAll, 'track'))), count($aTestTracks), count($aTrainTracks));
printf("%-20s %-10s %-24s %-26s %s\n", 'buses para aprender', 'tramos', 'cobertura (tramos medidos)', 'error con lo aprendido + k', 'error con solo k × horario');

foreach ([1 / 32, 1 / 16, 1 / 8, 1 / 4, 1 / 2, 1] as $dFraction) {
    $iBuckets = 64;
    $iLimit = (int)round($dFraction * $iBuckets);
    $aTrain = array_filter($aPool, fn($a) => (crc32('x' . $a['track']) % $iBuckets) < $iLimit);
    $Segments = fit($aTrain);
    $iCovered = 0;
    $dErrApp = 0.0;
    $dErrBase = 0.0;
    $iN = 0;
    foreach ($aTest as $aSample) {
        $dFactor = $Pace->factor($aSample['line'], $aSample['date'], (int)$aSample['at']);
        $dBase = abs($dFactor * $aSample['scheduled'] - $aSample['actual']);
        $dLearned = $Segments->seconds($aSample['from'], $aSample['to'], $aSample['band']);
        $dApp = $dLearned === null ? $dBase : abs($dLearned - $aSample['actual']);
        if ($dLearned !== null) {
            $iCovered++;
        }
        $dErrApp += $dApp;
        $dErrBase += $dBase;
        $iN++;
    }
    printf(
        "%-20d %-10d %-24s %-26s %s\n",
        count(array_unique(array_column($aTrain, 'track'))),
        count(array_unique(array_map(fn($a) => $a['from'] . '>' . $a['to'], $aTrain))),
        sprintf('%.1f %%', 100 * $iCovered / $iN),
        sprintf('%.2f min', $dErrApp / $iN / 60),
        sprintf('%.2f min', $dErrBase / $iN / 60)
    );
}
