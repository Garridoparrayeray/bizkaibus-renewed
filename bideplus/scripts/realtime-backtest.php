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
use Services\RealtimeMatcher;
use Services\SiriVehicleMonitoringClient;

const MAX_GAP_BETWEEN_SNAPSHOTS_SECONDS = 300;
const OBSERVED_AFTER_PREDICTION_SECONDS = 600;
const NOT_STARTED_GRACE_SECONDS = 60;
const HORIZON_BUCKETS_MINUTES = [[1, 5], [5, 10], [10, 20], [20, 60]];

function main(array $aArgv): void
{
    $sMode = '';
    if (isset($aArgv[1])) {
        $sMode = $aArgv[1];
    }
    if ($sMode === 'capture' && isset($aArgv[2])) {
        $iMinutes = 30;
        if (isset($aArgv[3])) {
            $iMinutes = (int)$aArgv[3];
        }
        $iIntervalSeconds = 45;
        if (isset($aArgv[4])) {
            $iIntervalSeconds = (int)$aArgv[4];
        }
        capture($aArgv[2], $iMinutes, $iIntervalSeconds);
        return;
    }
    if ($sMode === 'analyze' && isset($aArgv[2])) {
        analyze($aArgv[2]);
        return;
    }
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php capture <carpeta> [minutos=30] [intervalo_s=45]\n");
    fwrite(STDERR, "  php scripts/realtime-backtest.php analyze <carpeta>\n");
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

function analyze(string $sDir): void
{
    Config::set('bus');
    $JourneyModel = new ServiceJourney(Database::connection());
    $aSnapshots = loadSnapshots($sDir);

    $aTracks = [];
    $aPredictions = [];
    $aCoverage = ['posicionados' => 0, 'viaje sin empezar' => 0, 'fuera de ruta o sin GPS' => 0, 'sin viaje en la BD' => 0];

    foreach ($aSnapshots as $aSnapshot) {
        $sXml = (string)file_get_contents($aSnapshot['file']);
        $iFeedSeconds = $aSnapshot['seconds'];
        $aVmMap = SiriVehicleMonitoringClient::parse($sXml, $aSnapshot['date']);
        $Matcher = new RealtimeMatcher($aVmMap, $JourneyModel, $iFeedSeconds);

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

                $sTrackKey = $aEntry['vehicleRef'] . '|' . $sJourneyId;
                $aTracks[$sTrackKey][] = ['t' => $aPosition['locationSeconds'], 'sched' => $aPosition['scheduledSeconds']];
                foreach ($aStops as $aStop) {
                    $iArrival = (int)$aStop['arrival_seconds'];
                    if ($iArrival <= $aPosition['scheduledSeconds']) {
                        continue;
                    }
                    [$iEta] = $Matcher->etaForStop($sJourneyId, $iArrival, $aEntry);
                    $aPredictions[] = [$sTrackKey, $aPosition['locationSeconds'], $iArrival, $iEta, $aSnapshot['windowEnd']];
                }
            }
        }
    }

    $aErrors = [];
    $iNotSeenPassing = 0;
    foreach ($aPredictions as [$sTrackKey, $iPredictedAt, $iArrival, $iEta, $iWindowEnd]) {
        $sBucket = bucketFor(($iEta - $iPredictedAt) / 60);
        if ($sBucket === null || $iWindowEnd < $iEta + OBSERVED_AFTER_PREDICTION_SECONDS) {
            continue;
        }
        $dActual = actualPassingTime($aTracks[$sTrackKey], $iArrival);
        if ($dActual === null) {
            $iNotSeenPassing++;
            continue;
        }
        foreach (['antes' => $iArrival, 'ahora' => $iEta] as $sMethod => $iShown) {
            $aErrors[$sBucket][$sMethod][] = ($iShown - $dActual) / 60;
            $aErrors['total'][$sMethod][] = ($iShown - $dActual) / 60;
        }
    }

    echo 'Capturas distintas: ' . count($aSnapshots) . "\n";
    echo "Buses por captura (suma de todas):\n";
    foreach ($aCoverage as $sLabel => $iCount) {
        printf("  %-26s %d\n", $sLabel, $iCount);
    }
    echo "\nError = tiempo mostrado - llegada real, en minutos. Positivo: el bus llega antes de lo que dice la app.\n";
    echo "antes = horario + 0 (lo que hacia la app); ahora = posicion GPS + horario restante.\n";
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
