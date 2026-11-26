<?php

require __DIR__ . '/../scripts/build-database.php';
require_once __DIR__ . '/../scripts/build/expiry.php';
require_once __DIR__ . '/../api/autoload.php';

use Build\Calendars;
use Build\Expiry;
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
mkdir($sStampDir . '/api/Config', 0777, true);
file_put_contents($sStampDir . '/api/Config/shell-hash.php', "<?php\n\nreturn '';\n");
copy(__DIR__ . '/../sw.js', $sStampDir . '/sw.js');
file_put_contents($sStampDir . '/js/a.js', 'uno');
file_put_contents($sStampDir . '/style-a.css', 'a {}');
$Stamp($sStampDir);
$sFirstHash = $ReadHash($sStampDir);
$Stamp($sStampDir);
$Expect('huella: 12 caracteres y la misma si no cambia nada', \strlen($sFirstHash) === 12 && $ReadHash($sStampDir) === $sFirstHash);
$Expect('huella: las páginas llevan la misma que sw.js', (require $sStampDir . '/api/Config/shell-hash.php') === $sFirstHash);
$Expect('la huella de las páginas va al repositorio vacía', (require __DIR__ . '/../api/Config/shell-hash.php') === '');
file_put_contents($sStampDir . '/js/a.js', 'dos');
$Stamp($sStampDir);
$Expect('huella: cambia si cambia un fichero de la app', $ReadHash($sStampDir) !== $sFirstHash);
array_map('unlink', [$sStampDir . '/sw.js', $sStampDir . '/js/a.js', $sStampDir . '/style-a.css', $sStampDir . '/api/Config/shell-hash.php']);
rmdir($sStampDir . '/js');
rmdir($sStampDir . '/api/Config');
rmdir($sStampDir . '/api');
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
$Expect('un trazado que casa con sus paradas no se toca', $aTotals === ['reversed' => 0, 'remeasured' => 0, 'measured' => 0, 'orphaned' => 0] && $Distances($Pdo) === [0, 1000, 2000, 3000]);

$Pdo = $ShapeFixture(false, [0, 1000, 2000, 3000]);
$Pdo->exec("UPDATE service_journeys SET shape_id = 'NO-EXISTE'");
$aTotals = Shapes::align($Pdo);
$Expect('un viaje con un trazado que el GTFS no trae se queda sin trazado', $aTotals['orphaned'] === 1 && $Pdo->query("SELECT shape_id FROM service_journeys")->fetchColumn() === null);

$Pdo = $ShapeFixture(true, [0, 1000, 2000, 3000]);
$aTotals = Shapes::align($Pdo);
$aFirst = $FirstPoint($Pdo);
$Expect('un trazado dibujado al revés se pone del derecho: empieza en la primera parada', $aTotals['reversed'] === 1 && abs($aFirst[0] - 43.2) < 1e-6 && $aFirst[2] == 0);
$Expect('con el trazado del derecho, cada parada cae a sus metros', $Distances($Pdo) === [0, 1000, 2000, 3000]);

$Pdo = $ShapeFixture(false, [null, null, null, null]);
$aTotals = Shapes::align($Pdo);
$Expect('un viaje sin metros en el GTFS los recibe, medidos sobre su trazado', $aTotals['remeasured'] === 1 && $Distances($Pdo) === [0, 1000, 2000, 3000]);

// Un GTFS sin metros en el trazado (todos a 0) ni en las paradas: el trazado los recibe sumando de punto a punto, y
// las paradas, medidas sobre él; también si está dibujado al revés.
$WithoutMeters = function (PDO $Pdo): void {
    $aPoints = json_decode((string)$Pdo->query('SELECT points FROM shapes')->fetchColumn(), true);
    $Pdo->prepare('UPDATE shapes SET points = ?')->execute([json_encode(array_map(fn($aPoint) => [$aPoint[0], $aPoint[1], 0], $aPoints))]);
};
$LastPoint = function (PDO $Pdo): array {
    $aPoints = json_decode((string)$Pdo->query('SELECT points FROM shapes')->fetchColumn(), true);
    return $aPoints[\count($aPoints) - 1];
};
$Pdo = $ShapeFixture(false, [null, null, null, null]);
$WithoutMeters($Pdo);
$aTotals = Shapes::align($Pdo);
$Expect('un trazado sin metros los recibe: 3000 m al final', $aTotals['measured'] === 1 && abs($LastPoint($Pdo)[2] - 3000) < 1);
$Expect('y sus paradas, medidas sobre él', $Distances($Pdo) === [0, 1000, 2000, 3000]);
$Pdo = $ShapeFixture(false, [null, null, null, null]);
$aPoints = json_decode((string)$Pdo->query('SELECT points FROM shapes')->fetchColumn(), true);
$aPoints = array_map(fn($aPoint) => [$aPoint[0], $aPoint[1], 0], $aPoints);
$aPoints[\count($aPoints) - 1][2] = 3000;
$Pdo->prepare('UPDATE shapes SET points = ?')->execute([json_encode($aPoints)]);
$aTotals = Shapes::align($Pdo);
$Expect('metros a 0 salvo el último (Bilbobus) cuentan como sin metros', $aTotals['measured'] === 1 && $Distances($Pdo) === [0, 1000, 2000, 3000]);
$Pdo = $ShapeFixture(true, [null, null, null, null]);
$WithoutMeters($Pdo);
$aTotals = Shapes::align($Pdo);
$Expect('un trazado sin metros y al revés se pone del derecho y se mide', $aTotals['measured'] === 1 && $aTotals['reversed'] === 1 && abs($FirstPoint($Pdo)[0] - 43.2) < 1e-6 && $Distances($Pdo) === [0, 1000, 2000, 3000]);

$Pdo = $ShapeFixture(false, [0, 700, 1500, 2600]);
$aTotals = Shapes::align($Pdo);
$Expect('unos metros del GTFS que no caen sobre la parada se miden de nuevo sobre el trazado', $aTotals['remeasured'] === 1 && $Distances($Pdo) === [0, 1000, 2000, 3000]);


// Un tren publicado en varios viajes: el contenido en otro más largo del mismo número y calendario se junta con él.
$MergePdo = new PDO('sqlite::memory:');
Build\Schema::createSchema($MergePdo);
$InsertJourney = $MergePdo->prepare('INSERT INTO service_journeys (id, line_id, journey_pattern_id, trip_number,
    calendar_id, wheelchair_accessible) VALUES (?, ?, ?, ?, ?, ?)');
$InsertTime = $MergePdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
$aJourneys = [
    ['0001112026-11-09', 'c1', [['A', 100], ['B', 200], ['C', 300]], 1],
    ['0001122026-11-09', 'c1', [['B', 200], ['C', 300]], 1],
    ['0001132026-11-09', 'c1', [['A', 100], ['B', 200]], 0],
    ['0001142026-11-09', 'c1', [['B', 250], ['C', 300]], 1],
    ['0001212026-11-09', 'c1', [['B', 200], ['C', 300]], 1],
    ['0001162026-11-09', 'c2', [['B', 200], ['C', 300]], 2],
];
foreach ($aJourneys as [$sId, $sCalendar, $aStops, $iWheelchair]) {
    $InsertJourney->execute([$sId, 'L', 'P', $sId, $sCalendar, $iWheelchair]);
    foreach ($aStops as $iSeq => [$sStop, $iTime]) {
        $InsertTime->execute([$sId, $iSeq + 1, $sStop, $iTime, $iTime]);
    }
}
$iMerged = Build\Schema::mergeSameTrains($MergePdo, 5);
$aLeft = $MergePdo->query('SELECT id FROM service_journeys ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$aAliases = $MergePdo->query('SELECT trip_id, service_journey_id FROM trip_aliases ORDER BY trip_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$Expect('mismo tren: los tramos contenidos en el largo se juntan con él', $iMerged === 2 && $aAliases === ['0001122026-11-09' => '0001112026-11-09', '0001132026-11-09' => '0001112026-11-09']);
$Expect('otra hora, otro número de tren u otro calendario: viaje aparte', $aLeft === ['0001112026-11-09', '0001142026-11-09', '0001162026-11-09', '0001212026-11-09']);
$aMergedWheelchair = $MergePdo->query('SELECT id, wheelchair_accessible FROM service_journeys ORDER BY id')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$Expect('mismo tren: accesible (1) junto con uno sin dato (0) queda sin dato; los que no se juntan, como venían',
    $aMergedWheelchair === ['0001112026-11-09' => 0, '0001142026-11-09' => 1, '0001162026-11-09' => 2,
        '0001212026-11-09' => 1]);
$Expect('viajes fusionados: accesible solo si lo son todos, no accesible si uno no lo es, y si no, sin dato',
    Gtfs::mergedWheelchair([1, 1]) === 1 && Gtfs::mergedWheelchair([1, 2, 0]) === 2
    && Gtfs::mergedWheelchair([1, 0]) === 0 && Gtfs::mergedWheelchair([0]) === 0);

// Una variante sin nombre o sin uno de sus extremos se nombra con sus paradas extremas; las demás, como vienen.
$NamePdo = new PDO('sqlite::memory:');
Build\Schema::createSchema($NamePdo);
$InsertNameLine = $NamePdo->prepare('INSERT INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');
$InsertNameStop = $NamePdo->prepare('INSERT INTO stops (id, name, name_normalized, lat, lon) VALUES (?, ?, ?, 0, 0)');
$InsertNamePattern = $NamePdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
$InsertNameJourney = $NamePdo->prepare('INSERT INTO service_journeys (id, line_id, journey_pattern_id, calendar_id)
    VALUES (?, ?, ?, ?)');
foreach (['K' => 'Karrantza', 'M' => 'Muskiz', 'B' => 'Bilbao la Concordia'] as $sStop => $sStopName) {
    $InsertNameStop->execute([$sStop, $sStopName, $sStopName]);
}
foreach (['P' => ['K', 'M', 'B'], 'Q' => ['B', 'K']] as $sPattern => $aPatternStops) {
    foreach ($aPatternStops as $iSeq => $sStop) {
        $InsertNamePattern->execute([$sPattern, $iSeq + 1, $sStop]);
    }
}
foreach (['L1' => '-Bilbao La Concordia', 'L2' => 'Bilbao La Concordia -', 'L3' => '-', 'L4' => 'Abando -Santurtzi'] as $sLine => $sLineName) {
    $InsertNameLine->execute([$sLine, 'C5', $sLineName, $sLineName]);
}
foreach ([['J1', 'L1', 'P'], ['J2', 'L2', 'Q'], ['J3', 'L4', 'P']] as [$sJourney, $sLine, $sPattern]) {
    $InsertNameJourney->execute([$sJourney, $sLine, $sPattern, 'c']);
}
$iLinesNamed = Build\Schema::nameLinesFromStops($NamePdo);
$aLineNames = $NamePdo->query('SELECT id, name FROM lines ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$Expect('variante sin un extremo: se nombra con sus paradas extremas; sin viajes o con nombre entero, como viene',
    $iLinesNamed === 2 && $aLineNames === ['L1' => 'Karrantza – Bilbao la Concordia', 'L2' => 'Bilbao la Concordia – Karrantza',
        'L3' => '-', 'L4' => 'Abando -Santurtzi']);

// Una línea que el operador publica con otro id y el mismo recorrido: su id viejo lleva a la nueva. Una que desaparece
// no lleva a ninguna; una equivalencia de ayer sigue si su línea sigue, y las de la ficha valen si la línea existe.
$AliasDb = function (string $sPath, array $aLines): PDO {
    $Pdo = new PDO('sqlite:' . $sPath);
    Build\Schema::createSchema($Pdo);
    $InsertLine = $Pdo->prepare('INSERT INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');
    $InsertStop = $Pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $InsertJourney = $Pdo->prepare('INSERT INTO service_journeys (id, line_id, journey_pattern_id, calendar_id) VALUES (?, ?, ?, ?)');
    foreach ($aLines as $sLine => $aStops) {
        $InsertLine->execute([$sLine, $sLine, $sLine, $sLine]);
        foreach ($aStops as $iSeq => $sStop) {
            $InsertStop->execute(['P' . $sLine, $iSeq + 1, $sStop]);
        }
        $InsertJourney->execute(['J' . $sLine, $sLine, 'P' . $sLine, 'c']);
    }
    return $Pdo;
};
$sAliasDir = sys_get_temp_dir() . '/alias-' . getmypid();
mkdir($sAliasDir);
$Yesterday = $AliasDb($sAliasDir . '/ayer.sqlite', ['E1' => ['A', 'B', 'C', 'D'], 'GONE' => ['X', 'Y'], 'SAME' => ['M', 'N']]);
$Yesterday->exec("INSERT INTO line_aliases (old_id, line_id) VALUES ('OLDEST', 'E1'), ('DEAD', 'NONE')");
$Today = $AliasDb($sAliasDir . '/hoy.sqlite', ['L62' => ['A', 'B', 'C', 'E'], 'SAME' => ['M', 'N'], 'OTHER' => ['X', 'Q', 'R', 'S']]);
// IDLE sigue en la base sin viajes hoy: comparte las paradas de L62, pero no ha cambiado de id.
$Yesterday->exec("INSERT INTO lines (id, code, name, name_normalized) VALUES ('IDLE', 'IDLE', 'IDLE', 'IDLE')");
$Yesterday->exec("INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES ('PIDLE', 1, 'A'), ('PIDLE', 2, 'B'), ('PIDLE', 3, 'C'), ('PIDLE', 4, 'E')");
$Yesterday->exec("INSERT INTO service_journeys (id, line_id, journey_pattern_id, calendar_id) VALUES ('JIDLE', 'IDLE', 'PIDLE', 'c')");
$Today->exec("INSERT INTO lines (id, code, name, name_normalized) VALUES ('IDLE', 'IDLE', 'IDLE', 'IDLE')");
$iCarried = Build\Schema::carryLineAliases($Today, $sAliasDir . '/ayer.sqlite');
$iManual = Build\Schema::addLineAliases($Today, ['MANUAL' => 'SAME', 'SAME' => 'L62', 'LOST' => 'NOPE', 'IDLE' => 'L62']);
$aAliases = $Today->query('SELECT old_id, line_id FROM line_aliases ORDER BY old_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$Expect('línea con id nuevo y el mismo recorrido: el viejo lleva a la nueva, también la equivalencia de ayer que iba a él',
    $iCarried === 2 && $aAliases['E1'] === 'L62' && $aAliases['OLDEST'] === 'L62');
$Expect('línea que desaparece o equivalencia sin línea: sin equivalencia',
    !isset($aAliases['GONE']) && !isset($aAliases['DEAD']));
$Expect('una línea que sigue en la base sin viajes hoy no se apunta como otra (ni a mano)', !isset($aAliases['IDLE']));
$Expect('ficha (gtfs.line_aliases): vale si la línea existe y el id viejo no es una línea de hoy',
    $iManual === 1 && $aAliases['MANUAL'] === 'SAME' && !isset($aAliases['SAME']) && !isset($aAliases['LOST']));
$aAliasLine = (new Models\LineModel($Today))->find('E1');
$Expect('LineModel::find con el id viejo da la línea de ahora, con su id nuevo',
    $aAliasLine !== null && $aAliasLine['id'] === 'L62' && (new Models\LineModel($Today))->find('GONE') === null);
// Renfe larga distancia: el id del viaje lleva la fecha de su versión del GTFS; el directo, a veces la de otra.
$Dated = $AliasDb($sAliasDir . '/fechas.sqlite', ['L' => ['A', 'B']]);
$Dated->exec("INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES ('NOV', '', '', 0), ('DIC', '', '', 0)");
$Dated->exec("INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES ('NOV', '2026-11-22', 1), ('DIC', '2026-12-13', 1)");
$Dated->exec("INSERT INTO service_journeys (id, line_id, journey_pattern_id, calendar_id) VALUES ('T-NOV', 'L', 'PL', 'NOV'), ('T-DIC', 'L', 'PL', 'DIC')");
$Dated->exec("INSERT INTO trip_aliases (trip_id, service_journey_id) VALUES ('0053012026-11-22', 'T-NOV'), ('0053012026-12-13', 'T-DIC')");
$DatedModel = new Models\ServiceJourney($Dated);
$aDatedIds = $DatedModel->journeyIdsForTripRefs(['0053012026-11-21', '0099012026-11-21'], new DateTime('2026-11-22'));
$Expect('id con la fecha de otra versión del GTFS: el tren de la base que circula ese día; otro tren, nada',
    $aDatedIds === ['0053012026-11-21' => 'T-NOV']);
$Expect('sin el día, el id con otra fecha no casa', $DatedModel->journeyIdsForTripRefs(['0053012026-11-21']) === []);
$Dated = null;
$DatedModel = null;
$Yesterday = null;
$Today = null;
array_map('unlink', glob($sAliasDir . '/*'));
rmdir($sAliasDir);

// Accesibilidad de las paradas (wheelchair_boarding): un andén sin dato toma el de su estación; con dato, el suyo.
$sZipPath = tempnam(sys_get_temp_dir(), 'gtfs');
$Zip = new ZipArchive();
$Zip->open($sZipPath, ZipArchive::OVERWRITE);
$Zip->addFromString('stops.txt', "stop_id,stop_name,stop_lat,stop_lon,location_type,parent_station,wheelchair_boarding"
    . "\n"
    . "E,Estación,43.2,-2.9,1,,1\nE_Q1,Andén,43.2,-2.9,0,E,0\nE_Q2,Andén,43.2,-2.9,0,E,2\nF,Otra,43.3,-2.9,1,,\n"
    . "F_Q1,Andén,43.3,-2.9,0,F,\nS.0,Suelta,43.4,-2.9,0,,1\n");
$Zip->close();
$Zip->open($sZipPath);
$aWheelchairStops = Gtfs::withWheelchair($Zip, Gtfs::loadStopsEuskotren($Zip) + Gtfs::loadStopsMetro($Zip));
$Zip->close();
unlink($sZipPath);
$Expect('paradas: el andén sin dato hereda de su estación, el que tiene dato se queda el suyo; sin nada, sin dato',
    array_map(fn(array $aStop) => $aStop['wheelchair'], $aWheelchairStops)
    === ['E' => 1, 'F' => 0, 'E_Q1' => 1, 'E_Q2' => 2, 'F_Q1' => 0, 'S' => 1]);

// Una errata del operador (un paso antes que el anterior) se deja a la hora del anterior, y lo de detrás no se toca.
$TimesPdo = new PDO('sqlite::memory:');
Build\Schema::createSchema($TimesPdo);
$InsertBack = $TimesPdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
foreach ([[1, 'A', 100, 100], [2, 'B', 400, 420], [3, 'C', 60, 60], [4, 'D', 500, 500]] as [$iSeq, $sStop, $iArrival, $iDeparture]) {
    $InsertBack->execute(['J', $iSeq, $sStop, $iArrival, $iDeparture]);
}
$iBackFixed = Build\Schema::fixBackwardTimes($TimesPdo);
$aBackTimes = $TimesPdo->query('SELECT arrival_seconds, departure_seconds FROM passing_times ORDER BY seq_order')->fetchAll(PDO::FETCH_NUM);
$Expect('una hora que va hacia atrás se deja a la de la parada anterior', $iBackFixed === 1 && $aBackTimes === [[100, 100], [400, 420], [420, 420], [500, 500]]);

// Festivo entre semana: el martes 2026-12-08 dos redes de la zona van como un domingo (quitan el laborable y añaden
// el de domingo) y en Nochebuena, con servicio especial, no. Una red cuyo GTFS no dice nada de festivos lo coge.
$CalendarDb = function (string $sPath, array $aCalendars, array $aExceptions): PDO {
    $Pdo = new PDO('sqlite:' . $sPath);
    $Pdo->exec('CREATE TABLE service_calendars (id TEXT PRIMARY KEY, from_date TEXT NOT NULL, to_date TEXT NOT NULL, weekday_mask INTEGER NOT NULL);
        CREATE TABLE service_calendar_exceptions (calendar_id TEXT NOT NULL, date TEXT NOT NULL, available INTEGER NOT NULL);
        CREATE TABLE service_journeys (id TEXT PRIMARY KEY, calendar_id TEXT NOT NULL)');
    foreach ($aCalendars as $sId => [$iMask, $iJourneys]) {
        $Pdo->prepare('INSERT INTO service_calendars VALUES (?, \'\', \'\', ?)')->execute([$sId, $iMask]);
        for ($iJourney = 0; $iJourney < $iJourneys; $iJourney++) {
            $Pdo->prepare('INSERT INTO service_journeys VALUES (?, ?)')->execute([$sId . $iJourney, $sId]);
        }
    }
    foreach ($aExceptions as [$sId, $sDate, $iAvailable]) {
        $Pdo->prepare('INSERT INTO service_calendar_exceptions VALUES (?, ?, ?)')->execute([$sId, $sDate, $iAvailable]);
    }
    return $Pdo;
};
$aRefPaths = [];
foreach (['a', 'b'] as $sRef) {
    $aRefPaths[] = $sRefPath = sys_get_temp_dir() . '/bide-festivos-' . $sRef . '-' . getmypid() . '.sqlite';
    $CalendarDb($sRefPath, ['lv' => [31, 10], 'd' => [64, 4], 'nochebuena' => [0, 2], 'reyes' => [0, 1]], [['lv', '2026-12-08', 0], ['d', '2026-12-08', 1], ['nochebuena', '2026-12-24', 1], ['lv', '2027-01-06', 0]]);
}
$aHolidays = Calendars::referenceHolidays($aRefPaths, 2, '2026-11-14');
$aOneRef = Calendars::referenceHolidays([$aRefPaths[0], sys_get_temp_dir() . '/bide-no-existe.sqlite'], 2, '2026-11-14');
array_map('unlink', $aRefPaths);
$Expect('festivo de respaldo: el martes 8 de diciembre y Reyes sin viajes y con anulaciones, no Nochebuena', $aHolidays === ['2026-12-08', '2027-01-06']);
$Expect('festivo de respaldo: con una sola red no hay quórum', $aOneRef === []);
// El calendario laboral de Euskadi tal como lo publica (fechas «2026/12/08» y «2027/1/6»): los de toda Euskadi, los
// del territorio y los del municipio de la red; los de otros municipios y los domingos, no.
$aEuskadiRows = [
    ['date' => '2026/12/08', 'territory' => 'Todos/denak', 'municipalityEs' => 'CAE'],
    ['date' => '2027/1/6', 'territory' => 'Todos/denak', 'municipalityEs' => 'CAE'],
    ['date' => '2027/7/31', 'territory' => 'Gipuzkoa', 'municipalityEs' => 'Gipuzkoa'],
    ['date' => '2027/1/20', 'territory' => 'Gipuzkoa', 'municipalityEs' => 'Donostia-San Sebastian  '],
    ['date' => '2027/4/28', 'territory' => 'Álava - Araba', 'municipalityEs' => 'Álava - Araba'],
    ['date' => '2026/12/06', 'territory' => 'Todos/denak', 'municipalityEs' => 'CAE'],
    ['date' => '2026/01/01', 'territory' => 'Todos/denak', 'municipalityEs' => 'CAE'],
];
$aEuskadi = ['url' => 'prueba-{year}', 'date' => 'date', 'territory' => 'territory', 'municipality' => 'municipalityEs', 'everywhere' => 'Todos/denak'];
$Expect('calendario laboral: toda la comunidad y el territorio para una red de Gipuzkoa', Build\Holidays::fromCalendar($aEuskadiRows, $aEuskadi, 'Gipuzkoa', null, '2026-11-14') === ['2026-12-08', '2027-01-06', '2027-07-31']);
$Expect('calendario laboral: y el del municipio para la red de Donostia', Build\Holidays::fromCalendar($aEuskadiRows, $aEuskadi, 'Gipuzkoa', 'Donostia-San Sebastian', '2026-11-14') === ['2026-12-08', '2027-01-06', '2027-07-31', '2027-01-20']);
$Expect('calendario laboral: una red de toda Euskadi, solo los de toda la comunidad', Build\Holidays::fromCalendar($aEuskadiRows, $aEuskadi, null, null, '2026-11-14') === ['2026-12-08', '2027-01-06']);
array_map('unlink', glob(sys_get_temp_dir() . '/bizkaibusplus_holidays_prueba_*.json'));
$aHolidayConfig = ['calendars' => ['prueba' => $aEuskadi], 'cache_seconds' => 3600, 'http_timeout_seconds' => 1];
$aFetched = Build\Holidays::forNetwork('prueba', ['calendar' => 'prueba', 'territory' => 'Gipuzkoa', 'municipality' => null, 'fallback' => ['networks' => [], 'quorum' => 1]], '2026-11-14', fn(string $sUrl) => match ($sUrl) {
    'prueba-2026' => json_encode(array_slice($aEuskadiRows, 0, 1)),
    default => null,
}, $aHolidayConfig);
array_map('unlink', glob(sys_get_temp_dir() . '/bizkaibusplus_holidays_prueba_*.json'));
$Expect('calendario laboral: se baja año a año; el que no está sale del respaldo (aquí, sin redes: ninguno)', $aFetched === ['2026-12-08']);
$Target = fn() => $CalendarDb(':memory:', ['lv' => [31, 10], 'd' => [64, 4], 'todos' => [127, 1]], []);
$Silent = $Target();
$iApplied = Calendars::applyHolidays($Silent, ['2026-12-08'], '2027-02-08', false);
$Expect('festivo: sin calendar_dates, ese martes va con el de domingo y lo diario sigue', $iApplied === 1
    && Calendars::journeysOn($Silent, new DateTime('2026-12-08')) === 5 && Calendars::journeysOn($Silent, new DateTime('2026-12-01')) === 11);
$Expect('festivo: si el operador publica calendar_dates, dentro de su periodo manda él', Calendars::applyHolidays($Target(), ['2026-12-08'], '2026-12-23', true) === 0);
$Expect('festivo: pasado su periodo, se coge el de la zona', Calendars::applyHolidays($Target(), ['2026-12-08'], '2026-12-01', true) === 1);
// Álavabus: dentro de su periodo, el GTFS solo añade el domingo el martes festivo y no quita el laborable.
$Ambiguous = fn() => $CalendarDb(':memory:', ['lv' => [31, 10], 'd' => [64, 4], 'todos' => [127, 1], 'lv-especial' => [31, 2]], [['d', '2026-12-08', 1], ['lv-especial', '2026-12-08', 1]]);
$AlavaHoliday = $Ambiguous();
$iAmbiguous = Calendars::applyHolidays($AlavaHoliday, ['2026-12-08'], '2027-01-14', true, true);
$Expect('festivo ambiguo: solo el domingo añadido y en el calendario laboral, va como festivo (sin lo laborable sin excepción)', $iAmbiguous === 1
    && Calendars::journeysOn($AlavaHoliday, new DateTime('2026-12-08')) === 7 && Calendars::journeysOn($AlavaHoliday, new DateTime('2026-12-01')) === 13);
$Expect('festivo ambiguo: un día que no está en el calendario laboral no se toca', Calendars::applyHolidays($Ambiguous(), [], '2027-01-14', true, true) === 0);
$Expect('festivo ambiguo: sin la opción de la ficha, dentro de su periodo manda el GTFS', Calendars::applyHolidays($Ambiguous(), ['2026-12-08'], '2027-01-14', true) === 0);
// El 25-12 (viernes) Álavabus añade el domingo y quita uno laborable: ya dice cómo es y se deja como lo publica.
$Christmas = $CalendarDb(':memory:', ['lv' => [31, 10], 'd' => [64, 4], 'lv-otro' => [31, 3]], [['d', '2026-12-25', 1], ['lv', '2026-12-25', 0]]);
$iChristmas = Calendars::applyHolidays($Christmas, ['2026-12-08', '2026-12-25'], '2027-01-14', true, true);
$Expect('festivo ambiguo: el 25-12, con algo quitado por el GTFS, se queda como lo publica el operador', $iChristmas === 0
    && Calendars::journeysOn($Christmas, new DateTime('2026-12-25')) === 7, (string)Calendars::journeysOn($Christmas, new DateTime('2026-12-25')));

// Caducidad del horario publicado (resumen del flujo diario, scripts/published-schedule.php).
$aRequired = ['gtfs' => ['required' => true]];
$aOptional = ['gtfs' => ['required' => false]];
$aRolling = ['gtfs' => ['required' => true, 'expiry_alert_days' => 2]];
$Row = fn(string|null $sTo, string $sSource = 'primary', string $sPublished = '2026-11-10')
    => ['to' => $sTo, 'source' => $sSource, 'published' => $sPublished];
[$aErr, $aWarn] = Expiry::problems($aRequired, $Row('2026-11-21'), false, '2026-11-15');
$Expect('caducidad: una red obligatoria con 6 días falla', count($aErr) === 1 && $aWarn === []);
[$aErr, $aWarn] = Expiry::problems($aOptional, $Row('2026-11-21'), false, '2026-11-15');
$Expect('caducidad: una opcional con 6 días solo avisa', $aErr === [] && count($aWarn) === 1);
[$aErr, $aWarn] = Expiry::problems($aOptional, $Row('2026-11-21'), true, '2026-11-15');
$Expect('caducidad: una opcional sin reconstruir hoy cuya última base caduca en 6 días: falla', count($aErr) === 1);
[$aErr, $aWarn] = Expiry::problems($aRolling, $Row('2026-11-20'), false, '2026-11-15');
$Expect('caducidad: Euskotren, que publica solo los próximos días, con 5 días no avisa', $aErr === [] && $aWarn === []);
[$aErr, $aWarn] = Expiry::problems($aRequired, $Row('2027-01-09', 'fallback', '2026-11-10'), false, '2026-11-15');
$Expect('respaldo reciente: avisa «fuente principal caída, se usa el respaldo»',
    $aErr === [] && $aWarn === ['fuente principal caída, se usa el respaldo']);
[$aErr, $aWarn] = Expiry::problems($aRequired, $Row('2027-01-09', 'fallback', '2026-09-01'), false, '2026-11-15');
$Expect('respaldo de hace más de ' . Expiry::FALLBACK_MAX_AGE_DAYS . ' días: falla', count($aErr) === 1);
// schedule_source_published puede ser el día de la compilación: manda el día del zip (o su feed_start_date).
$aOldCopy = ['issued' => '2026-09-01'] + $Row('2027-01-09', 'fallback', '2026-11-15');
[$aErr, $aWarn] = Expiry::problems($aRequired, $aOldCopy, false, '2026-11-15');
$Expect('respaldo con zip de hace más de ' . Expiry::FALLBACK_MAX_AGE_DAYS . ' días y compilado hoy: falla', count($aErr) === 1, json_encode($aErr));
[$aErr, $aWarn] = Expiry::problems($aRequired, ['issued' => '2026-11-12'] + $Row('2027-01-09', 'fallback', '2026-11-15'), false, '2026-11-15');
$Expect('respaldo con zip reciente: solo el aviso', $aErr === [] && count($aWarn) === 1);
$sZipFile = tempnam(sys_get_temp_dir(), 'bide-zip-');
$DatedZip = new ZipArchive();
$DatedZip->open($sZipFile, ZipArchive::OVERWRITE);
$DatedZip->addFromString('stops.txt', "stop_id\n");
$DatedZip->addFromString('trips.txt', "trip_id\n");
$DatedZip->setMtimeName('stops.txt', (int)strtotime('2026-09-01 10:00'));
$DatedZip->setMtimeName('trips.txt', (int)strtotime('2026-09-03 10:00'));
$DatedZip->close();
$DatedZip->open($sZipFile);
$Expect('el día del zip es el de su fichero más nuevo', Gtfs::zipDate($DatedZip) === '2026-09-03', (string)Gtfs::zipDate($DatedZip));
$DatedZip->close();
unlink($sZipFile);
// Con el cron de la 01:17 Euskotren puede bajar el fichero del día anterior (+4 días): conservado, no falla.
[$aErr, $aWarn] = Expiry::problems($aRolling, $Row('2026-11-19'), true, '2026-11-15');
$Expect('caducidad: Euskotren sin reconstruir hoy con 4 días: aviso, con el umbral de su ficha', $aErr === [] && count($aWarn) === 1, json_encode([$aErr, $aWarn]));
[$aErr, $aWarn] = Expiry::problems($aRolling, $Row('2026-11-19'), false, '2026-11-15');
$Expect('caducidad: Euskotren con el fichero del día anterior (4 días): ni aviso', $aErr === [] && $aWarn === []);

// Filas CSV descuadradas: una coma de más al final vale recortada; otro número de columnas en los ficheros de viajes,
// paradas de paso y días para la construcción (antes se perdían en silencio: una parada o una anulación de menos).
$sCsvZip = tempnam(sys_get_temp_dir(), 'csvrows') . '.zip';
$CsvZip = new ZipArchive();
$CsvZip->open($sCsvZip, ZipArchive::CREATE);
$CsvZip->addFromString('stop_times.txt', "trip_id,arrival_time,departure_time,stop_id,stop_sequence\nT1,08:00:00,08:00:00,A,1\nT1,08:05:00,08:05:00,B,2,\nT1,08:10:00,08:10:00,C,3\n");
$CsvZip->addFromString('calendar_dates.txt', "service_id,date,exception_type\nS1,20261225,2\nS1,20270101,2,\n");
$CsvZip->addFromString('trips.txt', "route_id,service_id,trip_id\nR1,S1,T1\nR1,S1,T2,sobra\n");
$CsvZip->addFromString('stops.txt', "stop_id,stop_name\nA,\"Plaza \"\"Nueva\"\"\"\nB,Uno,dos\n");
$CsvZip->close();
$CsvZip->open($sCsvZip);
$aCsvStops = [];
foreach (Gtfs::streamStopTimesByTrip($CsvZip) as $aCsvRows) {
    $aCsvStops = array_column($aCsvRows, 'stopId');
}
$Expect('CSV: una coma de más al final de stop_times no quita la parada', $aCsvStops === ['A', 'B', 'C'], json_encode($aCsvStops));
// Un tramo inflado (1.052 m en 16 minutos) se deja a 20 km/h y las paradas siguientes se adelantan lo mismo.
$aSlowTrip = [
    ['seqOrder' => 1, 'stopId' => 'A', 'arrival' => 23400, 'departure' => 23405, 'distance' => 0.0],
    ['seqOrder' => 2, 'stopId' => 'B', 'arrival' => 24400, 'departure' => 24405, 'distance' => 1052.0],
    ['seqOrder' => 3, 'stopId' => 'C', 'arrival' => 24500, 'departure' => 24500, 'distance' => 1700.0],
];
$aFast = Gtfs::withoutInflatedSegments($aSlowTrip);
$Expect('tramo inflado: llega a B a 20 km/h (189 s)', $aFast[1]['arrival'] === 23594 && $aFast[1]['departure'] === 23599, json_encode($aFast[1]));
$Expect('tramo inflado: las paradas siguientes se adelantan lo mismo', $aFast[2]['arrival'] === 23694, json_encode($aFast[2]));
$aNormal = [
    ['seqOrder' => 1, 'stopId' => 'A', 'arrival' => 100, 'departure' => 100, 'distance' => 0.0],
    ['seqOrder' => 2, 'stopId' => 'B', 'arrival' => 700, 'departure' => 700, 'distance' => 3000.0],
    ['seqOrder' => 3, 'stopId' => 'C', 'arrival' => 1500, 'departure' => 1500, 'distance' => null],
];
$Expect('tramo normal o sin distancia: no se toca', Gtfs::withoutInflatedSegments($aNormal) === $aNormal);
// Un paso a una parada que stops.txt no define (su fila venía descuadrada) se quita del viaje; si son muchos, se para.
$sManyRows = "trip_id,arrival_time,departure_time,stop_id,stop_sequence\n";
for ($i = 1; $i <= 1000; $i++) {
    $sManyRows .= "T$i,08:00:00,08:00:00,A,1\nT$i,08:05:00,08:05:00,B,2\n";
}
$sManyRows .= "T1,08:10:00,08:10:00,X,3\n";
$sManyZip = tempnam(sys_get_temp_dir(), 'manyrows') . '.zip';
$ManyZip = new ZipArchive();
$ManyZip->open($sManyZip, ZipArchive::CREATE);
$ManyZip->addFromString('stop_times.txt', $sManyRows);
$ManyZip->close();
$ManyZip->open($sManyZip);
$aKnownRows = [];
foreach (Gtfs::streamStopTimesByTrip($ManyZip, ['A' => [], 'B' => []] + array_fill_keys(range(1, 100), [])) as $sKnownTrip => $aKnownTrip) {
    if ($sKnownTrip === 'T1') {
        $aKnownRows = array_column($aKnownTrip, 'stopId');
    }
}
$Expect('CSV: un paso a una parada que no existe se quita del viaje', $aKnownRows === ['A', 'B'], json_encode($aKnownRows));
$sUnknownError = '';
try {
    Gtfs::reportUnknownStops(array_fill_keys(range(1, 20), 5), 1000, 400);
} catch (RuntimeException $Error) {
    $sUnknownError = $Error->getMessage();
}
$Expect('CSV: más de un 2 % de paradas que no existen para la construcción', str_contains($sUnknownError, 'Build abortado') && str_contains($sUnknownError, '1: 5'), $sUnknownError);
$sUnknownOk = '';
try {
    Gtfs::reportUnknownStops(['192' => 1415, '193' => 506, '178' => 506, '293' => 254], 346465, 526);
    $sUnknownOk = 'sin error';
} catch (RuntimeException $Error) {
    $sUnknownOk = $Error->getMessage();
}
$Expect('CSV: un paso suelto a una parada que no existe solo avisa', $sUnknownOk === 'sin error', $sUnknownOk);
$Expect('CSV: una coma de más al final de calendar_dates no quita la anulación', count(iterator_to_array(Gtfs::readCsv($CsvZip, 'calendar_dates.txt'), false)) === 2);
$sCsvError = '';
try {
    iterator_to_array(Gtfs::readCsv($CsvZip, 'trips.txt'), false);
} catch (RuntimeException $Error) {
    $sCsvError = $Error->getMessage();
}
$Expect('CSV: una fila de trips.txt con otro número de columnas (la mitad del fichero) para la construcción', str_contains($sCsvError, 'trips.txt: 1 de 2 filas'), $sCsvError);
$aCsvNames = array_column(iterator_to_array(Gtfs::readCsv($CsvZip, 'stops.txt'), false), 'stop_name');
$Expect('CSV: en stops.txt la fila descuadrada se descarta con aviso y las comillas dobles se leen como en RFC 4180', $aCsvNames === ['Plaza "Nueva"'], json_encode($aCsvNames));
$CsvZip->close();
unlink($sCsvZip);
// Una suelta entre 2.000 (menos del 0,1 %) no para la construcción: aviso y fuera.
$sCsvZip = tempnam(sys_get_temp_dir(), 'csvrows') . '.zip';
$CsvZip = new ZipArchive();
$CsvZip->open($sCsvZip, ZipArchive::CREATE);
$sManyTrips = "route_id,service_id,trip_id\n";
for ($iTrip = 0; $iTrip < 2000; $iTrip++) {
    $sManyTrips .= "R1,S1,T$iTrip\n";
}
$CsvZip->addFromString('trips.txt', $sManyTrips . "R1,S1,T9999,sobra\n");
$CsvZip->close();
$CsvZip->open($sCsvZip);
$sCsvError = '';
$iManyTrips = 0;
try {
    $iManyTrips = count(iterator_to_array(Gtfs::readCsv($CsvZip, 'trips.txt'), false));
} catch (RuntimeException $Error) {
    $sCsvError = $Error->getMessage();
}
$Expect('CSV: una fila descuadrada entre 2.000 de trips.txt no para la construcción (aviso y fuera)', $sCsvError === '' && $iManyTrips === 2000, $sCsvError);
$CsvZip->close();
unlink($sCsvZip);

if (!empty($aFailures)) {
    echo "\nRESULTADO construcción: FALLA -> " . implode('; ', $aFailures) . "\n";
    exit(1);
}
echo "\nRESULTADO construcción: OK\n";
exit(0);
