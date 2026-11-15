<?php

declare(strict_types=1);

/*
 * Resumen del horario publicado de cada red para el flujo diario: hasta cuándo vale, los días que quedan y de qué
 * fuente salió. Escribe una tabla en Markdown y las anotaciones de GitHub Actions (::error:: y ::warning::), y sale con
 * 1 si hay algún error: una red obligatoria a punto de caducar, la última base buena de una red que hoy no se pudo
 * reconstruir a punto de caducar, o una fuente de respaldo demasiado vieja.
 *
 * Uso: php scripts/published-schedule.php [--kept=red,red] [--today=AAAA-MM-DD] [--summary=fichero]
 * Con --summary, la tabla se añade a ese fichero (el resumen de la ejecución) en vez de salir por pantalla.
 */

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/build/expiry.php';

use Build\Expiry;
use Core\Networks;
use Services\ScheduleRange;

$aOptions = getopt('', ['kept::', 'today::', 'summary::']);
$aKept = array_filter(explode(',', (string)($aOptions['kept'] ?? '')));
$sToday = (string)($aOptions['today'] ?? (new DateTime('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d'));

$aLines = ['### Horario publicado de cada red', '', '| Red | Publicado | Vale hasta | Quedan | Fuente | Estado |',
    '| --- | --- | --- | --- | --- | --- |'];
$bFailed = false;
foreach (Networks::all() as $sSlug => $aNetwork) {
    $aRow = scheduleRow($aNetwork);
    [$aErrors, $aWarnings] = Expiry::problems($aNetwork, $aRow, in_array($sSlug, $aKept, true), $sToday);
    foreach ($aErrors as $sError) {
        echo "::error::$sSlug: $sError\n";
    }
    foreach ($aWarnings as $sWarning) {
        echo "::warning::$sSlug: $sWarning\n";
    }
    $bFailed = $bFailed || !empty($aErrors);
    $sState = implode('; ', array_merge($aErrors, $aWarnings));
    if ($sState === '') {
        $sState = 'bien';
    }
    $sLeft = '?';
    if ($aRow['to'] !== null) {
        $sLeft = Expiry::daysBetween($sToday, $aRow['to']) . ' días';
    }
    $sTo = $aRow['to'] ?? '?';
    $aLines[] = "| $sSlug | {$aRow['published']} | $sTo | $sLeft | {$aRow['source']} | $sState |";
}
$sTable = implode("\n", $aLines) . "\n";
if (isset($aOptions['summary'])) {
    file_put_contents($aOptions['summary'], $sTable, FILE_APPEND);
} else {
    echo $sTable;
}
exit((int)$bFailed);

// Lo que dice la base de la red: hasta cuándo vale, cuándo se publicó y de qué fuente salió.
function scheduleRow(array $aNetwork): array
{
    $aRow = ['to' => null, 'published' => '?', 'source' => 'primary'];
    if (!is_file($aNetwork['db_path'])) {
        return $aRow;
    }
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $aMeta = $Pdo->query('SELECT key, value FROM meta')->fetchAll(PDO::FETCH_KEY_PAIR);
    $aRow['to'] = ScheduleRange::forDatabase($Pdo)['to'];
    $aRow['published'] = (string)($aMeta['schedule_source_published'] ?? '?');
    $aRow['source'] = (string)($aMeta['schedule_source'] ?? 'primary');
    return $aRow;
}
