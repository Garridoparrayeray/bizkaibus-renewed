<?php

require __DIR__ . '/../scripts/build-database.php';

use Build\Calendars;
use Build\Gtfs;
use Build\Journeys;
use Build\Shapes;

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

$Expect('un día anulado en un calendario no anula el viaje fusionado si otro calendario circula ese día', Calendars::excludedDatesForGroup($Group(['laborable', 'finde']), $aSignatures) === []);
$Expect('si ningún calendario fusionado circula ese día, el día sigue anulado', Calendars::excludedDatesForGroup($Group(['finde']), $aSignatures) === ['2026-10-05' => true]);
$Expect('una fecha incluida a mano en otro calendario también cuenta como que circula', Calendars::excludedDatesForGroup($Group(['finde_anulado', 'especial']), $aSignatures) === []);
$Expect('signatureRunsOn: lunes en L-V sí, domingo no', Calendars::signatureRunsOn($aSignatures['laborable'], '2026-10-05') && !Calendars::signatureRunsOn($aSignatures['laborable'], '2026-10-04'));

// Bizkaibus publica el mismo viaje (línea, número, paradas y salida) con horas de paso distintas según el día: el de
// laborable tarda más que el de festivo. Solo se fusionan si las horas coinciden.
$StopTimes = fn(array $aArrivals) => array_map(fn($iArrival) => ['arrival' => $iArrival, 'departure' => $iArrival + 2], $aArrivals);
$aLaborable = $StopTimes([52200, 53880, 54060]);
$aFestivo = $StopTimes([52200, 53160, 53280]);
$aLaborableUnMinutoDespues = $StopTimes([52260, 53940, 54120]);
$Expect('viajes con distintas horas de paso no se fusionan', Journeys::timingKeyFor($aLaborable, 52200) !== Journeys::timingKeyFor($aFestivo, 52200));
$Expect('viajes con las mismas horas de paso relativas sí se fusionan', Journeys::timingKeyFor($aLaborable, 52200) === Journeys::timingKeyFor($aLaborableUnMinutoDespues, 52260));
$Expect('las paradas sin hora no rompen la clave', Journeys::timingKeyFor([['arrival' => 100, 'departure' => 100], ['arrival' => null, 'departure' => null]], 100) !== '');

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
$aLiteral = Calendars::loadCalendars($Zip, true);
$aInferred = Calendars::loadCalendars($Zip, false);
$Zip->close();
unlink($sZipPath);
$Expect('Metro: un calendario solo por fechas no recibe días de la semana', $aLiteral['sustituto']['weekdayMask'] === 0);
$Expect('Metro: sus fechas quedan tal cual (8, 13 y 15 de octubre)', $aLiteral['sustituto']['includedDates'] === ['2026-10-08', '2026-10-13', '2026-10-15']);
$Expect('Metro: el calendario Lun-Jue conserva su máscara y sus días anulados', $aLiteral['base']['weekdayMask'] === 15 && $aLiteral['base']['excludedDates'] === ['2026-10-08', '2026-10-13', '2026-10-15']);
$Expect('loadCalendars sin modo literal sigue deduciendo el patrón semanal (lo usan otras redes; en Bizkaibus los viajes usan el calendario exacto)', $aInferred['sustituto']['weekdayMask'] === 8);

// Bizkaibus: el calendario de cada viaje es exacto dentro del periodo publicado. Antes, dos fechas de un mismo día de
// la semana bastaban para deducir «todos esos días», y el viaje salía también en festivos y semanas en las que no va.
$aWindow = Calendars::datesBetween('2026-10-14', '2026-12-14');
$Expect('datesBetween: 62 fechas del 14 oct al 14 dic, ambas incluidas', count($aWindow) === 62 && $aWindow[0] === '2026-10-14' && $aWindow[61] === '2026-12-14');
$Runs = fn(array $aDates) => Calendars::exactServiceDates(['mask' => 0, 'from' => '', 'to' => '', 'available' => $aDates, 'excluded' => []], $aWindow);

// Lunes a jueves todo el periodo salvo el festivo del martes 8 de diciembre (no figura en calendar_dates).
$aMonThu = [];
foreach ($aWindow as $sDate) {
    if ((int)(new DateTime($sDate))->format('N') <= 4 && $sDate !== '2026-12-08') {
        $aMonThu[] = $sDate;
    }
}
[$iMask, $aInc, $aExc] = Calendars::exactCalendarForWindow($Runs($aMonThu), $aWindow);
$Expect('lunes a jueves con el 8 de diciembre sin servicio: máscara L-J y ese festivo excluido', $iMask === 15 && $aInc === [] && $aExc === ['2026-12-08']);

// Solo dos viernes de nueve: no se deduce «todos los viernes».
[$iMask, $aInc, $aExc] = Calendars::exactCalendarForWindow($Runs(['2026-10-16', '2026-10-23']), $aWindow);
$Expect('solo dos viernes de nueve: sin máscara semanal, solo esas dos fechas', $iMask === 0 && $aInc === ['2026-10-16', '2026-10-23'] && $aExc === []);

// Todos los viernes del periodo: patrón semanal puro, sin fechas sueltas.
$aFridays = array_values(array_filter($aWindow, fn($sDay) => (int)(new DateTime($sDay))->format('N') === 5));
[$iMask, $aInc, $aExc] = Calendars::exactCalendarForWindow($Runs($aFridays), $aWindow);
$Expect('todos los viernes del periodo: máscara de viernes y ninguna fecha suelta', $iMask === 16 && $aInc === [] && $aExc === []);

// Un servicio que empieza a mitad de periodo no se rellena hacia atrás.
$aFromLater = array_values(array_filter($aMonThu, fn($sDay) => $sDay >= '2026-10-19'));
[$iMask, $aInc, $aExc] = Calendars::exactCalendarForWindow($Runs($aFromLater), $aWindow);
$Expect('servicio que empieza el lunes 19: el 14 y el 15 de octubre quedan excluidos', $iMask === 15 && in_array('2026-10-14', $aExc, true) && in_array('2026-10-15', $aExc, true));

// El calendario de calendar.txt cuenta dentro de su rango y las anulaciones lo recortan.
$aBase = Calendars::exactServiceDates(['mask' => 1, 'from' => '2026-10-19', 'to' => '2026-11-02', 'available' => [], 'excluded' => ['2026-10-26']], $aWindow);
$Expect('calendar.txt: los lunes de su rango menos el anulado (19 oct y 2 nov)', array_keys($aBase) === ['2026-10-19', '2026-11-02']);

// Variantes fusionadas (lunes a jueves + viernes): se unen sin perder el festivo.
$aUnion = $Runs($aMonThu) + $Runs($aFridays);
[$iMask, $aInc, $aExc] = Calendars::exactCalendarForWindow($aUnion, $aWindow);
$Expect('variantes L-J y V fusionadas: lunes a viernes menos el 8 de diciembre', $iMask === 31 && $aExc === ['2026-12-08'] && $aInc === []);

// Huella de sw.js (scripts/stamp-sw.php, la pone el despliegue): la misma si no cambia nada, otra si cambia un
// fichero, y cubre todo lo que el service worker guarda para no volver a pedirlo.
$sStampScript = __DIR__ . '/../scripts/stamp-sw.php';
$Stamp = function (string $sRoot, string $sArgs = '') use ($sStampScript): string {
    return (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sStampScript) . ' --root=' . escapeshellarg($sRoot) . ' ' . $sArgs . ' 2>&1');
};
$ReadHash = function (string $sRoot): string {
    preg_match("/^const SHELL_HASH = '([0-9a-f]*)';$/m", (string)file_get_contents($sRoot . '/sw.js'), $aMatch);
    return $aMatch[1] ?? 'sin línea';
};
$sWorker = (string)file_get_contents(__DIR__ . '/../sw.js');
$Expect('sw.js va al repositorio sin huella (la pone el despliegue)', preg_match("/^const SHELL_HASH = '';$/m", $sWorker) === 1);

$sStampDir = sys_get_temp_dir() . '/bide-stamp-' . getmypid();
mkdir($sStampDir . '/js', 0777, true);
copy(__DIR__ . '/../sw.js', $sStampDir . '/sw.js');
file_put_contents($sStampDir . '/js/a.js', 'uno');
file_put_contents($sStampDir . '/style-a.css', 'a {}');
$Stamp($sStampDir);
$sFirstHash = $ReadHash($sStampDir);
$Stamp($sStampDir);
$Expect('huella: 12 caracteres y la misma si no cambia nada', \strlen($sFirstHash) === 12 && $ReadHash($sStampDir) === $sFirstHash);
file_put_contents($sStampDir . '/js/a.js', 'dos');
$Stamp($sStampDir);
$Expect('huella: cambia si cambia un fichero de la app', $ReadHash($sStampDir) !== $sFirstHash);
array_map('unlink', [$sStampDir . '/sw.js', $sStampDir . '/js/a.js', $sStampDir . '/style-a.css']);
rmdir($sStampDir . '/js');
rmdir($sStampDir);

$aStamped = array_flip(array_filter(explode("\n", $Stamp(__DIR__ . '/..', '--list'))));
preg_match('/SHELL_FILES = \[(.*?)\];/s', $sWorker, $aShell);
preg_match_all("/^\s*'\/([^']+)',$/m", $aShell[1] ?? '', $aShellPaths);
foreach (Core\Networks::all() as $aNetwork) {
    $aShellPaths[1][] = $aNetwork['brand']['icons'] . '/icon-192.png';
    $aShellPaths[1][] = $aNetwork['brand']['icons'] . '/icon-512.png';
}
$aUncovered = array_values(array_filter($aShellPaths[1], fn($sPath) => !isset($aStamped[$sPath])));
$sCoverage = 'huella: cubre todo lo que guarda el service worker (' . \count($aShellPaths[1]) . ' ficheros)';
if ($aUncovered !== []) {
    $sCoverage .= ': faltan ' . implode(', ', $aUncovered);
}
$Expect($sCoverage, \count($aShellPaths[1]) > 50 && $aUncovered === []);

// Sin feed_info.txt (Renfe), el periodo publicado es el que cubren los calendarios: del primer día al último.
$aSpanCalendars = [
    'L' => ['exact' => ['from' => '2026-11-02', 'to' => '2026-11-30', 'available' => []]],
    'X' => ['exact' => ['from' => '', 'to' => '', 'available' => ['2026-12-01']]],
];
$aSpan = Calendars::publishedWindow([], $aSpanCalendars);
$Expect('sin feed_info, el periodo publicado va del primer al último día de los calendarios', ($aSpan[0] ?? null) === '2026-11-02' && end($aSpan) === '2026-12-01' && count($aSpan) === 30);
$Expect('con feed_info, el periodo es el suyo', Calendars::publishedWindow(['feed_start_date' => '20261102', 'feed_end_date' => '20261104'], $aSpanCalendars) === ['2026-11-02', '2026-11-03', '2026-11-04']);

// GTFS de otros operadores: cabeceras con BOM (Álavabus), sin calendar.txt (Tuvisa) y rótulos con el destino entre
// paréntesis (Bilbobus). Un viaje sin rótulo va hacia su última parada.
$Stream = fopen('php://memory', 'r+');
fwrite($Stream, "\xEF\xBB\xBFtrip_id,arrival_time\n1,08:00:00\n");
rewind($Stream);
$Expect('la cabecera de un CSV con BOM se lee sin él', Gtfs::readCsvHeader($Stream) === ['trip_id', 'arrival_time']);
fclose($Stream);

$sZipPath = tempnam(sys_get_temp_dir(), 'bide-gtfs-');
$Zip = new ZipArchive();
$Zip->open($sZipPath, ZipArchive::OVERWRITE);
$Zip->addFromString('calendar_dates.txt', "service_id,date,exception_type\nLAB,20261102,1\nLAB,20261103,1\n");
$Zip->close();
$Zip->open($sZipPath);
$aDatesOnly = Calendars::loadCalendars($Zip);
$Zip->close();
unlink($sZipPath);
$Expect('sin calendar.txt, cada servicio sale de calendar_dates.txt', isset($aDatesOnly['LAB']) && $aDatesOnly['LAB']['activeDateCount'] === 2);

$sBilbobusPattern = '/\(([^)]+)\)\s*$/';
$Expect('rótulo con patrón: el destino es su primer grupo', Gtfs::headsignByPattern($sBilbobusPattern, 'PT-Semana / (PLAZA BIRIBILA)') === 'PLAZA BIRIBILA');
$Expect('rótulo que no encaja en el patrón: vacío, para usar la última parada', Gtfs::headsignByPattern($sBilbobusPattern, 'PT-Semana/Incorporación') === '');

$aNightBus = Gtfs::withoutDayJumps([
    ['seqOrder' => 2, 'stopId' => 'B', 'arrival' => 1330, 'departure' => 1330, 'distance' => null],
    ['seqOrder' => 1, 'stopId' => 'A', 'arrival' => 0, 'departure' => 0, 'distance' => null],
    ['seqOrder' => 3, 'stopId' => 'C', 'arrival' => 87790, 'departure' => 87790, 'distance' => null],
]);
$Expect('una hora escrita como del día siguiente («24:23» tras «00:22») se corrige', array_column($aNightBus, 'arrival') === [0, 1330, 1390]);
$aLateNight = Gtfs::withoutDayJumps([
    ['seqOrder' => 1, 'stopId' => 'A', 'arrival' => 85800, 'departure' => 85800, 'distance' => null],
    ['seqOrder' => 2, 'stopId' => 'B', 'arrival' => 87000, 'departure' => 87000, 'distance' => null],
]);
$Expect('un viaje que de verdad pasa la medianoche no cambia', array_column($aLateNight, 'arrival') === [85800, 87000]);

$PdoStops = new PDO('sqlite::memory:');
$PdoStops->exec('CREATE TABLE stops (id TEXT PRIMARY KEY, name TEXT)');
$PdoStops->exec("INSERT INTO stops VALUES ('9', 'Atxuri')");
$LastStopName = $PdoStops->prepare('SELECT name FROM stops WHERE id = ?');
$Expect('un viaje sin rótulo va hacia su última parada', Journeys::patternHeadsign('', '9', $LastStopName) === 'Atxuri');
$Expect('un viaje con rótulo lo conserva', Journeys::patternHeadsign('Miribilla', '9', $LastStopName) === 'Miribilla');

/*
 * Trazados del GTFS que no casan con sus paradas: una línea recta hacia el norte, 4 paradas cada 1000 m. Un trazado
 * dibujado del final al principio se pone del derecho, y unos metros del GTFS que no caen sobre la parada se miden de
 * nuevo sobre el trazado.
 */
$ShapeFixture = function (bool $bReversed, array $aStopMeters): PDO {
    $Pdo = new PDO('sqlite::memory:');
    $Pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $Pdo->exec('
        CREATE TABLE stops (id TEXT PRIMARY KEY, lat REAL, lon REAL);
        CREATE TABLE service_journeys (id TEXT PRIMARY KEY, shape_id TEXT);
        CREATE TABLE passing_times (service_journey_id TEXT, seq_order INTEGER, stop_id TEXT, dist_m REAL);
        CREATE TABLE shapes (id TEXT PRIMARY KEY, points TEXT);
    ');
    $dLatPerMeter = 1 / 111320;
    $aPoints = [];
    for ($iMeters = 0; $iMeters <= 3000; $iMeters += 100) {
        $aPoints[] = [43.2 + $iMeters * $dLatPerMeter, -2.9, (float)$iMeters];
    }
    if ($bReversed) {
        $aPoints = array_map(fn($aPoint) => [43.2 + (3000 - $aPoint[2]) * $dLatPerMeter, -2.9, $aPoint[2]], $aPoints);
    }
    $Pdo->prepare('INSERT INTO shapes VALUES (?, ?)')->execute(['SH', json_encode($aPoints)]);
    $Pdo->exec("INSERT INTO service_journeys VALUES ('J', 'SH')");
    foreach ($aStopMeters as $i => $dMeters) {
        $Pdo->prepare('INSERT INTO stops VALUES (?, ?, ?)')->execute(['S' . $i, 43.2 + $i * 1000 * $dLatPerMeter, -2.9]);
        $Pdo->prepare('INSERT INTO passing_times VALUES (?, ?, ?, ?)')->execute(['J', $i + 1, 'S' . $i, $dMeters]);
    }
    return $Pdo;
};
$Distances = fn(PDO $Pdo) => array_map(fn($dMeters) => (int)round((float)$dMeters), $Pdo->query("SELECT dist_m FROM passing_times ORDER BY seq_order")->fetchAll(PDO::FETCH_COLUMN));
$FirstPoint = fn(PDO $Pdo) => json_decode((string)$Pdo->query("SELECT points FROM shapes")->fetchColumn(), true)[0];

$Pdo = $ShapeFixture(false, [0, 1000, 2000, 3000]);
$aTotals = Shapes::align($Pdo);
$Expect('un trazado que casa con sus paradas no se toca', $aTotals === ['reversed' => 0, 'remeasured' => 0] && $Distances($Pdo) === [0, 1000, 2000, 3000]);

$Pdo = $ShapeFixture(true, [0, 1000, 2000, 3000]);
$aTotals = Shapes::align($Pdo);
$aFirst = $FirstPoint($Pdo);
$Expect('un trazado dibujado al revés se pone del derecho: empieza en la primera parada', $aTotals['reversed'] === 1 && abs($aFirst[0] - 43.2) < 1e-6 && $aFirst[2] == 0);
$Expect('con el trazado del derecho, cada parada cae a sus metros', $Distances($Pdo) === [0, 1000, 2000, 3000]);

$Pdo = $ShapeFixture(false, [null, null, null, null]);
$aTotals = Shapes::align($Pdo);
$Expect('un viaje sin metros en el GTFS se queda sin ellos, como antes', $aTotals['remeasured'] === 0 && (int)$Pdo->query('SELECT COUNT(*) FROM passing_times WHERE dist_m IS NOT NULL')->fetchColumn() === 0);

$Pdo = $ShapeFixture(false, [0, 700, 1500, 2600]);
$aTotals = Shapes::align($Pdo);
$Expect('unos metros del GTFS que no caen sobre la parada se miden de nuevo sobre el trazado', $aTotals['remeasured'] === 1 && $Distances($Pdo) === [0, 1000, 2000, 3000]);


if (!empty($aFailures)) {
    echo "\nRESULTADO construcción: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO construcción: OK\n";
exit(0);
