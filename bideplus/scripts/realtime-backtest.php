<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Madrid');

spl_autoload_register(function (string $sClass): void {
    $sPath = __DIR__ . '/../api/' . str_replace('\\', '/', $sClass) . '.php';
    if (is_file($sPath)) {
        require $sPath;
    }
});

use Core\Config;
use Core\Database;
use Core\Http;
use Models\ServiceJourney;
use Services\PaceFactors;
use Services\RealtimeMatcher;
use Services\SegmentTimes;
use Services\SiriVehicleMonitoringClient;

const MAX_GAP_BETWEEN_SNAPSHOTS_SECONDS = 300;
const OBSERVED_AFTER_PREDICTION_SECONDS = 600;
const NOT_STARTED_GRACE_SECONDS = 60;
const HORIZON_BUCKETS_MINUTES = [[1, 5], [5, 10], [10, 20], [20, 60]];

const MIN_REMAINING_SECONDS_FOR_FACTOR = 120;
const MIN_TRACKS_PER_BAND = 8;
const MIN_TRACKS_PER_LINE = 25;
const MIN_VEHICLES_PER_LINE = 5;
const SHRINKAGE_TRACKS = 10;
const MAX_SAMPLES_PER_TRACK = 30;
const SAMPLES_RETENTION_DAYS = 60;
const MIN_TRACKS_PER_SEGMENT = 3;

function main(array $aArgv): void
{
    $sMode = '';
    if (isset($aArgv[1])) {
        $sMode = $aArgv[1];
    }
    $aFlags = array_values(array_filter($aArgv, fn($sArg) => str_starts_with($sArg, '--')));
    $aPositional = array_values(array_filter(array_slice($aArgv, 2), fn($sArg) => !str_starts_with($sArg, '--')));

    if ($sMode === 'capture' && isset($aPositional[0])) {
        $iMinutes = 30;
        if (isset($aPositional[1])) {
            $iMinutes = (int)$aPositional[1];
        }
        $iIntervalSeconds = 45;
        if (isset($aPositional[2])) {
            $iIntervalSeconds = (int)$aPositional[2];
        }
        capture($aPositional[0], $iMinutes, $iIntervalSeconds);
        return;
    }
    if ($sMode === 'analyze' && isset($aPositional[0])) {
        $Segments = SegmentTimes::fromFile($aPositional[1] ?? null);
        if (in_array('--sin-tramos', $aFlags, true)) {
            $Segments = new SegmentTimes();
        }
        analyze($aPositional[0], !in_array('--sin-k', $aFlags, true), $Segments);
        return;
    }
    if ($sMode === 'segment-samples' && isset($aPositional[0], $aPositional[1])) {
        exportSegmentSamples($aPositional[0], $aPositional[1]);
        return;
    }
    if ($sMode === 'calibrate-segments' && isset($aPositional[0])) {
        calibrateSegments($aPositional[0], in_array('--write', $aFlags, true));
        return;
    }
    if ($sMode === 'samples' && isset($aPositional[0], $aPositional[1])) {
        exportSamples($aPositional[0], $aPositional[1]);
        return;
    }
    if ($sMode === 'calibrate' && isset($aPositional[0])) {
        calibrate($aPositional[0], in_array('--write', $aFlags, true));
        return;
    }
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php capture <carpeta> [minutos=30] [intervalo_s=45]\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php analyze <carpeta> [tiempos-tramos.json] [--sin-k] [--sin-tramos]\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php samples <carpeta> <acumulado.jsonl>\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php calibrate <carpeta|acumulado.jsonl> [--write]\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php segment-samples <carpeta> <tramos.jsonl>\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php calibrate-segments <carpeta|tramos.jsonl> [--write]\n");
    exit(1);
}

function capture(string $sDir, int $iMinutes, int $iIntervalSeconds): void
{
    Config::set('bus');
    $sUrl = Config::current()['siri']['vehicle_monitoring_url'];
    if (!is_dir($sDir)) {
        mkdir($sDir, 0777, true);
    }

    $iEnd = time() + $iMinutes * 60;
    $sLastStamp = '';
    $iSaved = 0;
    while (time() < $iEnd) {
        try {
            $sXml = Http::get($sUrl, 20);
            if (preg_match('/<RecordedAtTime>([^<]+)</', $sXml, $aM) && $aM[1] !== $sLastStamp) {
                $sLastStamp = $aM[1];
                file_put_contents($sDir . '/vm_' . date('Ymd_His') . '.xml', $sXml);
                $iSaved++;
                echo date('H:i:s') . " captura guardada (feed {$aM[1]})\n";
            }
        } catch (RuntimeException $Ex) {
            fwrite(STDERR, date('H:i:s') . ' ' . $Ex->getMessage() . "\n");
        }
        sleep($iIntervalSeconds);
    }
    echo "$iSaved capturas en $sDir\n";
}

function collect(string $sDir, PaceFactors $Pace, SegmentTimes|null $Segments = null): array
{
    $Segments = $Segments ?? new SegmentTimes();
    Config::set('bus');
    $JourneyModel = new ServiceJourney(Database::connection());
    $aSnapshots = loadSnapshots($sDir);

    $aTracks = [];
    $aTrackInfo = [];
    $aPredictions = [];
    $aOffRoute = [];
    $aCoverage = ['posicionados' => 0, 'viaje sin empezar' => 0, 'fuera de ruta o sin GPS' => 0, 'sin viaje en la BD' => 0];

    foreach ($aSnapshots as $aSnapshot) {
        $sXml = (string)file_get_contents($aSnapshot['file']);
        $aVmMap = SiriVehicleMonitoringClient::parse($sXml, $aSnapshot['date']);
        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel, $aSnapshot['seconds'], $Pace, $aSnapshot['date'], $Segments);

        foreach ($aVmMap as $aEntries) {
            foreach ($aEntries as $aEntry) {
                $sJourneyId = $Matcher->journeyIdFor($aEntry);
                if ($sJourneyId === null) {
                    $aCoverage['sin viaje en la BD']++;
                    continue;
                }
                $aStops = journeyStops($JourneyModel, $sJourneyId);
                $aPosition = $Matcher->positionFor($sJourneyId, $aEntry);
                if ($aPosition === null) {
                    if (isset($aEntry['locationSeconds'], $aStops[0]) && $aEntry['locationSeconds'] < (int)$aStops[0]['departure_seconds'] - NOT_STARTED_GRACE_SECONDS) {
                        $aCoverage['viaje sin empezar']++;
                    } else {
                        $aCoverage['fuera de ruta o sin GPS']++;
                    }
                    continue;
                }
                $aCoverage['posicionados']++;
                $aOffRoute[$aPosition['method']][] = $aPosition['offRouteMeters'];

                $sTrackKey = $aEntry['vehicleRef'] . '|' . $sJourneyId;
                $aTracks[$sTrackKey][] = ['t' => $aPosition['locationSeconds'], 'sched' => $aPosition['scheduledSeconds']];
                $aTrackInfo[$sTrackKey] = ['journey' => $sJourneyId, 'line' => (string)($aEntry['lineId'] ?? ''), 'date' => $aSnapshot['date']];
                foreach ($aStops as $aStop) {
                    $iArrival = (int)$aStop['arrival_seconds'];
                    if ($iArrival <= $aPosition['scheduledSeconds']) {
                        continue;
                    }
                    [$iEta] = $Matcher->etaForStop($sJourneyId, $iArrival, $aEntry);
                    $aPredictions[] = [
                        'track' => $sTrackKey,
                        'vehicle' => (string)$aEntry['vehicleRef'],
                        'line' => (string)($aEntry['lineId'] ?? ''),
                        'date' => $aSnapshot['date'],
                        'at' => $aPosition['locationSeconds'],
                        'sched' => $aPosition['scheduledSeconds'],
                        'arrival' => $iArrival,
                        'eta' => $iEta,
                        'windowEnd' => $aSnapshot['windowEnd'],
                    ];
                }
            }
        }
    }

    foreach ($aPredictions as $iIndex => $aPrediction) {
        $aPredictions[$iIndex]['actual'] = actualPassingTime($aTracks[$aPrediction['track']], $aPrediction['arrival']);
    }

    $aSegmentSamples = [];
    foreach ($aTracks as $sTrackKey => $aTrack) {
        $aInfo = $aTrackInfo[$sTrackKey];
        $aStops = journeyStops($JourneyModel, $aInfo['journey']);
        $aPassing = array_map(fn($aStop) => actualPassingTime($aTrack, (int)$aStop['arrival_seconds']), $aStops);
        for ($i = 0; $i < count($aStops) - 1; $i++) {
            if ($aPassing[$i] === null || $aPassing[$i + 1] === null || $aPassing[$i + 1] <= $aPassing[$i]) {
                continue;
            }
            $aSegmentSamples[] = [
                'track' => $aInfo['date'] . '|' . $sTrackKey,
                'line' => $aInfo['line'],
                'date' => $aInfo['date'],
                'at' => (int)$aPassing[$i],
                'band' => PaceFactors::bandFor($aInfo['date'], (int)$aPassing[$i]),
                'from' => (string)$aStops[$i]['stop_id'],
                'to' => (string)$aStops[$i + 1]['stop_id'],
                'scheduled' => (int)$aStops[$i + 1]['arrival_seconds'] - (int)$aStops[$i]['arrival_seconds'],
                'actual' => round($aPassing[$i + 1] - $aPassing[$i], 1),
            ];
        }
    }

    return [
        'snapshots' => $aSnapshots,
        'predictions' => $aPredictions,
        'segmentSamples' => $aSegmentSamples,
        'coverage' => $aCoverage,
        'offRoute' => $aOffRoute,
    ];
}

function analyze(string $sDir, bool $bWithFactors, SegmentTimes $Segments): void
{
    $Pace = new PaceFactors();
    if ($bWithFactors) {
        $Pace = PaceFactors::fromFile();
    }
    $aData = collect($sDir, $Pace, $Segments);

    $aErrors = [];
    $iNotSeenPassing = 0;
    foreach ($aData['predictions'] as $aPrediction) {
        $sBucket = bucketFor(($aPrediction['eta'] - $aPrediction['at']) / 60);
        if ($sBucket === null || $aPrediction['windowEnd'] < $aPrediction['eta'] + OBSERVED_AFTER_PREDICTION_SECONDS) {
            continue;
        }
        if ($aPrediction['actual'] === null) {
            $iNotSeenPassing++;
            continue;
        }
        foreach (['antes' => $aPrediction['arrival'], 'ahora' => $aPrediction['eta']] as $sMethod => $iShown) {
            $aErrors[$sBucket][$sMethod][] = ($iShown - $aPrediction['actual']) / 60;
            $aErrors['total'][$sMethod][] = ($iShown - $aPrediction['actual']) / 60;
        }
    }

    echo 'Capturas distintas: ' . count($aData['snapshots']) . "\n";
    echo "Buses por captura (suma de todas):\n";
    foreach ($aData['coverage'] as $sLabel => $iCount) {
        printf("  %-26s %d\n", $sLabel, $iCount);
    }
    foreach ($aData['offRoute'] as $sMethod => $aMeters) {
        $iWithinTen = count(array_filter($aMeters, fn($dMeters) => $dMeters <= 10));
        printf(
            "Distancia del GPS a la ruta (%s, %d posiciones): mediana %.1f m, p75 %.1f m, p90 %.1f m, <=10 m %d%%\n",
            $sMethod,
            count($aMeters),
            percentile($aMeters, 0.5),
            percentile($aMeters, 0.75),
            percentile($aMeters, 0.9),
            intdiv(100 * $iWithinTen, count($aMeters))
        );
    }
    $sFactorsLabel = 'con factor k';
    if (!$bWithFactors) {
        $sFactorsLabel = 'sin factor k (k = 1)';
    }
    echo "\nError = tiempo mostrado - llegada real, en minutos. Positivo: el bus llega antes de lo que dice la app.\n";
    $sSegmentsLabel = 'sin tiempos por tramo';
    if (!$Segments->isEmpty()) {
        $sSegmentsLabel = 'con tiempos aprendidos por tramo';
    }
    echo "antes = horario + 0 (lo que hacia la app); ahora = posicion GPS + horario restante, $sFactorsLabel, $sSegmentsLabel.\n";
    echo 'Filas: minutos que muestra la app nueva. Solo predicciones con la captura abierta ' . (OBSERVED_AFTER_PREDICTION_SECONDS / 60) . " min mas alla de la hora mostrada.\n";
    echo "Predicciones descartadas porque no se vio pasar al bus: $iNotSeenPassing\n\n";
    printf("%-10s | %-40s | %-40s\n", 'muestra', 'antes:  n    sesgo  |err| p90  <=1m <=2m', 'ahora:  n    sesgo  |err| p90  <=1m <=2m');
    $aOrder = [];
    foreach (HORIZON_BUCKETS_MINUTES as [$iFrom, $iTo]) {
        $aOrder[] = "$iFrom-$iTo min";
    }
    $aOrder[] = 'total';
    foreach ($aOrder as $sBucket) {
        if (!isset($aErrors[$sBucket])) {
            continue;
        }
        $aColumns = [];
        foreach (['antes', 'ahora'] as $sMethod) {
            $aColumns[$sMethod] = str_pad('-', 40);
            if (!empty($aErrors[$sBucket][$sMethod])) {
                $aColumns[$sMethod] = summary($aErrors[$sBucket][$sMethod]);
            }
        }
        printf("%-10s | %s | %s\n", $sBucket, $aColumns['antes'], $aColumns['ahora']);
    }
}

function buildSamples(array $aData): array
{
    $aSamples = [];
    foreach ($aData['predictions'] as $aPrediction) {
        if ($aPrediction['actual'] === null || $aPrediction['windowEnd'] < $aPrediction['arrival'] + OBSERVED_AFTER_PREDICTION_SECONDS) {
            continue;
        }
        $iScheduledRemaining = $aPrediction['arrival'] - $aPrediction['sched'];
        $dActualRemaining = $aPrediction['actual'] - $aPrediction['at'];
        if ($iScheduledRemaining < MIN_REMAINING_SECONDS_FOR_FACTOR || $dActualRemaining <= 0) {
            continue;
        }
        $aSamples[] = [
            'track' => $aPrediction['date'] . '|' . $aPrediction['track'],
            'vehicle' => $aPrediction['vehicle'],
            'line' => $aPrediction['line'],
            'date' => $aPrediction['date'],
            'at' => $aPrediction['at'],
            'band' => PaceFactors::bandFor($aPrediction['date'], $aPrediction['at']),
            'scheduledRemaining' => $iScheduledRemaining,
            'actualRemaining' => round($dActualRemaining, 1),
            'actual' => round($aPrediction['actual'], 1),
        ];
    }
    return $aSamples;
}

function thinSamples(array $aSamples): array
{
    $aByTrack = [];
    foreach ($aSamples as $aSample) {
        $aByTrack[$aSample['track']][] = $aSample;
    }
    $aKept = [];
    foreach ($aByTrack as $aTrackSamples) {
        $iCount = count($aTrackSamples);
        if ($iCount <= MAX_SAMPLES_PER_TRACK) {
            array_push($aKept, ...$aTrackSamples);
            continue;
        }
        for ($i = 0; $i < MAX_SAMPLES_PER_TRACK; $i++) {
            $aKept[] = $aTrackSamples[(int)floor($i * $iCount / MAX_SAMPLES_PER_TRACK)];
        }
    }
    return $aKept;
}

function loadSamplesFile(string $sPath): array
{
    $aSamples = [];
    foreach (file($sPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $sLine) {
        $aSample = json_decode($sLine, true);
        if (is_array($aSample) && isset($aSample['track'], $aSample['band'], $aSample['scheduledRemaining'], $aSample['actualRemaining'])) {
            $aSamples[] = $aSample;
        }
    }
    return $aSamples;
}

function exportSamples(string $sDir, string $sOutput): void
{
    $aData = collect($sDir, new PaceFactors());
    $aNew = thinSamples(buildSamples($aData));

    $aAll = [];
    if (is_file($sOutput)) {
        $sLimit = date('Y-m-d', strtotime('-' . SAMPLES_RETENTION_DAYS . ' days'));
        foreach (loadSamplesFile($sOutput) as $aSample) {
            if ($aSample['date'] >= $sLimit) {
                $aAll[] = $aSample;
            }
        }
    }
    $iBefore = count($aAll);
    $aAll = array_merge($aAll, $aNew);

    $sBody = '';
    foreach ($aAll as $aSample) {
        $sBody .= json_encode($aSample, JSON_UNESCAPED_UNICODE) . "\n";
    }
    file_put_contents($sOutput, $sBody);
    $iTracks = count(array_unique(array_column($aNew, 'track')));
    echo 'Capturas: ' . count($aData['snapshots']) . ', muestras nuevas: ' . count($aNew) . " ($iTracks buses), acumulado: $iBefore -> " . count($aAll) . " muestras en $sOutput\n";
}

function calibrate(string $sSource, bool $bWrite): void
{
    $iSnapshots = 0;
    if (is_file($sSource)) {
        $aSamples = loadSamplesFile($sSource);
    } else {
        $aData = collect($sSource, new PaceFactors());
        $aSamples = buildSamples($aData);
        $iSnapshots = count($aData['snapshots']);
    }

    echo 'Capturas distintas: ' . $iSnapshots . ', muestras utiles para calibrar: ' . count($aSamples) . "\n\n";
    if (empty($aSamples)) {
        echo "No hay muestras suficientes.\n";
        return;
    }

    $aFactors = fitFactors($aSamples);
    $aHeldOut = crossValidate($aSamples);

    echo "Factor k por franja (global) y por linea. m = buses distintos usados.\n";
    foreach ($aFactors['global'] as $sBand => $dFactor) {
        printf("  %-26s k=%.3f  (m=%d)\n", $sBand, $dFactor, $aFactors['meta']['global'][$sBand]);
    }
    foreach ($aFactors['lines'] as $sLine => $aBands) {
        foreach ($aBands as $sBand => $dFactor) {
            printf("  linea %-8s %-18s k=%.3f  (m=%d)\n", $sLine, $sBand, $dFactor, $aFactors['meta']['lines'][$sLine][$sBand]);
        }
    }
    if (empty($aFactors['global']) && empty($aFactors['lines'])) {
        echo "  (ninguna franja ni linea alcanza el minimo de " . MIN_TRACKS_PER_BAND . " buses distintos)\n";
    }

    echo "\nValidacion cruzada (se calibra con la mitad de los buses y se mide en la otra mitad):\n";
    if ($aHeldOut['n'] === 0) {
        echo "  sin muestras en la mitad de prueba con factor aplicable\n";
    } else {
        printf(
            "  n=%d  error absoluto medio: %.2f min con k=1  ->  %.2f min con k   (|err| mediana %.2f -> %.2f)\n",
            $aHeldOut['n'],
            $aHeldOut['meanBefore'],
            $aHeldOut['meanAfter'],
            $aHeldOut['medianBefore'],
            $aHeldOut['medianAfter']
        );
    }

    if (!$bWrite) {
        echo "\nNo se ha escrito nada (usa --write para guardar data/pace-factors.json).\n";
        return;
    }
    if ($aHeldOut['n'] === 0 || $aHeldOut['meanAfter'] >= $aHeldOut['meanBefore']) {
        echo "\nNo se escribe: el factor no mejora el error en la mitad de prueba.\n";
        return;
    }
    if (is_file(PaceFactors::defaultPath())) {
        $aCurrent = json_decode((string)file_get_contents(PaceFactors::defaultPath()), true);
        if (is_array($aCurrent) && ($aCurrent['global'] ?? null) == $aFactors['global'] && ($aCurrent['lines'] ?? null) == $aFactors['lines']) {
            echo "\nSin cambios: los factores son los mismos que los del fichero actual.\n";
            return;
        }
    }
    $aOutput = [
        'generated' => date('Y-m-d H:i'),
        'captures' => $iSnapshots,
        'samples' => count($aSamples),
        'global' => $aFactors['global'],
        'lines' => $aFactors['lines'],
        'meta' => $aFactors['meta'],
    ];
    file_put_contents(PaceFactors::defaultPath(), json_encode($aOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\nEscrito " . realpath(PaceFactors::defaultPath()) . "\n";
}

function fitFactors(array $aSamples): array
{
    $aByBand = [];
    $aByLineBand = [];
    foreach ($aSamples as $aSample) {
        $aByBand[$aSample['band']][$aSample['track']][] = $aSample;
        $aByLineBand[$aSample['line']][$aSample['band']][$aSample['track']][] = $aSample;
    }

    $aResult = ['global' => [], 'lines' => [], 'meta' => ['global' => [], 'lines' => []]];
    foreach ($aByBand as $sBand => $aTracks) {
        $iTracks = count($aTracks);
        if ($iTracks < MIN_TRACKS_PER_BAND) {
            continue;
        }
        $aResult['global'][$sBand] = shrunkFactor($aTracks);
        $aResult['meta']['global'][$sBand] = $iTracks;
    }
    foreach ($aByLineBand as $sLine => $aBands) {
        if ($sLine === '') {
            continue;
        }
        foreach ($aBands as $sBand => $aTracks) {
            $iVehicles = count(array_unique(array_column(array_merge(...array_values($aTracks)), 'vehicle')));
            if (count($aTracks) < MIN_TRACKS_PER_LINE || $iVehicles < MIN_VEHICLES_PER_LINE) {
                continue;
            }
            $aResult['lines'][$sLine][$sBand] = shrunkFactor($aTracks);
            $aResult['meta']['lines'][$sLine][$sBand] = count($aTracks);
        }
    }
    return $aResult;
}

function shrunkFactor(array $aTracks): float
{
    $aPerTrack = [];
    foreach ($aTracks as $aSamples) {
        $dSumActual = 0.0;
        $dSumScheduled = 0.0;
        foreach ($aSamples as $aSample) {
            $dSumActual += $aSample['actualRemaining'];
            $dSumScheduled += $aSample['scheduledRemaining'];
        }
        $aPerTrack[] = $dSumActual / $dSumScheduled;
    }
    $iTracks = count($aPerTrack);
    $dRaw = percentile($aPerTrack, 0.5);
    $dShrunk = 1.0 + ($dRaw - 1.0) * $iTracks / ($iTracks + SHRINKAGE_TRACKS);
    return round(max(PaceFactors::MIN_FACTOR, min(PaceFactors::MAX_FACTOR, $dShrunk)), 3);
}

function crossValidate(array $aSamples): array
{
    $aBefore = [];
    $aAfter = [];
    foreach ([0, 1] as $iHeldOutFold) {
        $aTrain = [];
        $aTest = [];
        foreach ($aSamples as $aSample) {
            if (crc32($aSample['track']) % 2 === $iHeldOutFold) {
                $aTest[] = $aSample;
            } else {
                $aTrain[] = $aSample;
            }
        }
        $aFactors = fitFactors($aTrain);
        $Pace = new PaceFactors($aFactors);
        foreach ($aTest as $aSample) {
            $dFactor = $Pace->factor($aSample['line'], $aSample['date'], $aSample['at']);
            if ($dFactor === 1.0) {
                continue;
            }
            $aBefore[] = abs($aSample['at'] + $aSample['scheduledRemaining'] - $aSample['actual']) / 60;
            $aAfter[] = abs($aSample['at'] + $dFactor * $aSample['scheduledRemaining'] - $aSample['actual']) / 60;
        }
    }
    if (empty($aBefore)) {
        return ['n' => 0];
    }
    return [
        'n' => count($aBefore),
        'meanBefore' => array_sum($aBefore) / count($aBefore),
        'meanAfter' => array_sum($aAfter) / count($aAfter),
        'medianBefore' => percentile($aBefore, 0.5),
        'medianAfter' => percentile($aAfter, 0.5),
    ];
}

function loadSegmentSamplesFile(string $sPath): array
{
    $aSamples = [];
    foreach (file($sPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $sLine) {
        $aSample = json_decode($sLine, true);
        if (is_array($aSample) && isset($aSample['track'], $aSample['at'], $aSample['band'], $aSample['from'], $aSample['to'], $aSample['scheduled'], $aSample['actual'])) {
            $aSamples[] = $aSample;
        }
    }
    return $aSamples;
}

function exportSegmentSamples(string $sDir, string $sOutput): void
{
    $aNew = collect($sDir, new PaceFactors())['segmentSamples'];

    $aAll = [];
    if (is_file($sOutput)) {
        $sLimit = date('Y-m-d', strtotime('-' . SAMPLES_RETENTION_DAYS . ' days'));
        foreach (loadSegmentSamplesFile($sOutput) as $aSample) {
            if ($aSample['date'] >= $sLimit) {
                $aAll[] = $aSample;
            }
        }
    }
    $iBefore = count($aAll);
    $aAll = array_merge($aAll, $aNew);

    $sBody = '';
    foreach ($aAll as $aSample) {
        $sBody .= json_encode($aSample, JSON_UNESCAPED_UNICODE) . "\n";
    }
    file_put_contents($sOutput, $sBody);
    echo 'Tramos nuevos medidos: ' . count($aNew) . ", acumulado: $iBefore -> " . count($aAll) . " en $sOutput\n";
}

function fitSegments(array $aSamples): array
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
                $aSegment[$sGroup] = (int)round(percentile(array_values($aActuals), 0.5));
            }
        }
        if (count($aSegment) > 1) {
            $aSegments[$sKey] = $aSegment;
        }
    }
    ksort($aSegments);
    return $aSegments;
}

function calibrateSegments(string $sSource, bool $bWrite): void
{
    if (is_file($sSource)) {
        $aSamples = loadSegmentSamplesFile($sSource);
    } else {
        $aSamples = collect($sSource, new PaceFactors())['segmentSamples'];
    }
    echo 'Tramos medidos: ' . count($aSamples) . ' (' . count(array_unique(array_column($aSamples, 'track'))) . " buses)\n";

    $Pace = PaceFactors::fromFile();
    $aBefore = [];
    $aAfter = [];
    foreach ([0, 1] as $iHeldOutFold) {
        $aTrain = array_filter($aSamples, fn($aSample) => crc32($aSample['track']) % 2 !== $iHeldOutFold);
        $Segments = new SegmentTimes(['segments' => fitSegments($aTrain)]);
        foreach ($aSamples as $aSample) {
            if (crc32($aSample['track']) % 2 !== $iHeldOutFold) {
                continue;
            }
            $dLearned = $Segments->seconds($aSample['from'], $aSample['to'], $aSample['band']);
            if ($dLearned === null) {
                continue;
            }
            $dFactor = $Pace->factor($aSample['line'], $aSample['date'], (int)$aSample['at']);
            $aBefore[] = abs($dFactor * $aSample['scheduled'] - $aSample['actual']) / 60;
            $aAfter[] = abs($dLearned - $aSample['actual']) / 60;
        }
    }

    $aSegments = fitSegments($aSamples);
    echo 'Tramos con tiempo aprendido (minimo ' . MIN_TRACKS_PER_SEGMENT . ' buses distintos): ' . count($aSegments) . "\n";
    if (empty($aBefore)) {
        echo "Validacion cruzada: sin tramos repetidos en las dos mitades.\n";
    } else {
        printf(
            "Validacion cruzada (n=%d tramos): error medio por tramo %.2f min con horario y k -> %.2f min con lo aprendido (suma de errores %.0f -> %.0f min)\n",
            count($aBefore),
            array_sum($aBefore) / count($aBefore),
            array_sum($aAfter) / count($aAfter),
            array_sum($aBefore),
            array_sum($aAfter)
        );
    }

    if (!$bWrite) {
        echo "No se ha escrito nada (usa --write para guardar data/segment-times.json).\n";
        return;
    }
    if (empty($aBefore) || array_sum($aAfter) >= array_sum($aBefore)) {
        echo "No se escribe: lo aprendido no mejora el error en la mitad de prueba.\n";
        return;
    }
    if (is_file(SegmentTimes::defaultPath())) {
        $aCurrent = json_decode((string)file_get_contents(SegmentTimes::defaultPath()), true);
        if (is_array($aCurrent) && ($aCurrent['segments'] ?? null) == $aSegments) {
            echo "Sin cambios: los tiempos son los mismos que los del fichero actual.\n";
            return;
        }
    }
    $aOutput = [
        'generated' => date('Y-m-d H:i'),
        'samples' => count($aSamples),
        'segments' => $aSegments,
    ];
    file_put_contents(SegmentTimes::defaultPath(), json_encode($aOutput, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo 'Escrito ' . realpath(SegmentTimes::defaultPath()) . "\n";
}

function loadSnapshots(string $sDir): array
{
    $aByStamp = [];
    foreach (glob($sDir . '/*.xml') as $sFile) {
        $sHead = (string)file_get_contents($sFile, false, null, 0, 4096);
        if (!preg_match('/<RecordedAtTime>([^<]+)</', $sHead, $aM) || isset($aByStamp[$aM[1]])) {
            continue;
        }
        $FeedTime = new DateTime($aM[1]);
        $FeedTime->setTimezone(new DateTimeZone('Europe/Madrid'));
        $aByStamp[$aM[1]] = [
            'file' => $sFile,
            'timestamp' => $FeedTime->getTimestamp(),
            'date' => $FeedTime->format('Y-m-d'),
            'seconds' => ((int)$FeedTime->format('H')) * 3600 + ((int)$FeedTime->format('i')) * 60 + (int)$FeedTime->format('s'),
        ];
    }
    $aSnapshots = array_values($aByStamp);
    usort($aSnapshots, fn($aA, $aB) => $aA['timestamp'] <=> $aB['timestamp']);

    $iWindowStart = 0;
    for ($i = 1; $i <= count($aSnapshots); $i++) {
        $bWindowEnds = $i === count($aSnapshots) || $aSnapshots[$i]['timestamp'] - $aSnapshots[$i - 1]['timestamp'] > MAX_GAP_BETWEEN_SNAPSHOTS_SECONDS;
        if (!$bWindowEnds) {
            continue;
        }
        for ($j = $iWindowStart; $j < $i; $j++) {
            $aSnapshots[$j]['windowEnd'] = $aSnapshots[$i - 1]['seconds'];
        }
        $iWindowStart = $i;
    }
    return $aSnapshots;
}

function journeyStops(ServiceJourney $JourneyModel, string $sJourneyId): array
{
    static $aCache = [];
    if (!isset($aCache[$sJourneyId])) {
        $aCache[$sJourneyId] = $JourneyModel->stopsWithCoordinates($sJourneyId);
    }
    return $aCache[$sJourneyId];
}

function actualPassingTime(array $aTrack, int $iArrival): float|null
{
    for ($i = 1; $i < count($aTrack); $i++) {
        $aBefore = $aTrack[$i - 1];
        $aAfter = $aTrack[$i];
        if ($aAfter['sched'] <= $aBefore['sched'] || $aAfter['t'] - $aBefore['t'] > MAX_GAP_BETWEEN_SNAPSHOTS_SECONDS) {
            continue;
        }
        if ($aBefore['sched'] <= $iArrival && $iArrival <= $aAfter['sched']) {
            $dFraction = ($iArrival - $aBefore['sched']) / ($aAfter['sched'] - $aBefore['sched']);
            return $aBefore['t'] + $dFraction * ($aAfter['t'] - $aBefore['t']);
        }
    }
    return null;
}

function bucketFor(float $dMinutes): string|null
{
    foreach (HORIZON_BUCKETS_MINUTES as [$iFrom, $iTo]) {
        if ($dMinutes >= $iFrom && $dMinutes < $iTo) {
            return "$iFrom-$iTo min";
        }
    }
    return null;
}

function summary(array $aValues): string
{
    $aAbsolute = array_map('abs', $aValues);
    $iWithinOne = count(array_filter($aAbsolute, fn($dValue) => $dValue <= 1));
    $iWithinTwo = count(array_filter($aAbsolute, fn($dValue) => $dValue <= 2));
    return sprintf(
        '%6d  %+5.1f  %5.1f %5.1f  %3d%% %3d%%',
        count($aValues),
        percentile($aValues, 0.5),
        percentile($aAbsolute, 0.5),
        percentile($aAbsolute, 0.9),
        intdiv(100 * $iWithinOne, count($aValues)),
        intdiv(100 * $iWithinTwo, count($aValues))
    );
}

function percentile(array $aValues, float $dRank): float
{
    sort($aValues);
    return (float)$aValues[(int)floor($dRank * (count($aValues) - 1))];
}

main($argv);
