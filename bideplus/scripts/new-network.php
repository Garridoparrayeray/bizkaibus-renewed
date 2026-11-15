<?php

// Crea una red nueva entera con una orden y la dirección de su GTFS. Lo baja y saca de él lo que puede (nombre,
// operador, tipo de vehículo, color, zona horaria, rótulos, la zona de sus paradas y los días con servicio); en una
// terminal pregunta lo demás, con eso por defecto (Intro lo acepta). Escribe su ficha (api/Networks/<red>.php) en los
// cuatro idiomas, con «Nuevo» unos días y los festivos del calendario laboral si su ciudad está en él; dibuja su
// icono provisional (icons-<red>/: su inicial y su «+» sobre su color); construye todo lo que sale de la ficha
// (js/networks.js, css/style-networks.css, 404.html, llms.txt, su base, el mapa de la red, los horarios sin conexión y
// el sitemap), y la valida: calidad del código, integridad de las bases, días sin servicio y feeds en directo.
//
// Uso:
//   php scripts/new-network.php --gtfs=https://…/gtfs.zip [--slug=getxo] [--name="Getxo Bus+"] [--color=#E30613]
//       [--kind=bus|metro|tram|train] [--city=Getxo] [--operator="Getxoko Udala"] [--trip-updates=https://…pb]
//       [--vehicle-positions=https://…pb] [--alerts=https://…pb] [--matching=trip|stops] [--si] [--sin-datos]
//   php scripts/new-network.php --rehacer=getxo
// --si no pregunta nada (lo sacado del GTFS y las opciones); sin terminal, tampoco. --sin-datos solo escribe la ficha y
// los iconos. --rehacer, después de retocar la ficha o los iconos (icons-<red>/icon.svg y glyph.svg): vuelve a dibujar
// los PNG, a construir todo y a validarla.
//
// Con horas previstas (--trip-updates) la red sale en directo: retrasos, vehículos en el mapa (--vehicle-positions) e
// incidencias (--alerts, que también valen solas). Con solo posiciones, también: el retraso de cada viaje lo estima la
// app con el GPS de su vehículo y su horario (Services\VehicleDelays). Lo que no se puede saber del GTFS y se pone a
// mano en la ficha (los
// bloques de metro.php sirven de modelo): reduced_service.live (servicio reducido por el directo, si es fiable) y
// gtfs_rt.platform_matching (si los viajes del directo no son los del horario). Un feed que no sea GTFS-Realtime
// necesita su adaptador en api/Services.

declare(strict_types=1);

require __DIR__ . '/../api/autoload.php';
require __DIR__ . '/build/download.php';

use Build\Download;
use Core\Networks;

const ROOT = __DIR__ . '/..';
const KINDS = ['bus', 'metro', 'tram', 'train'];
const MATCHINGS = ['trip', 'stops'];
// Opción del script => clave de gtfs_rt.
const GTFS_RT_FEEDS = ['trip-updates' => 'trip_updates_url', 'vehicle-positions' => 'vehicle_positions_url', 'alerts' => 'alerts_url'];
// route_type de GTFS (y los extendidos, por centenas) => tipo de la ficha.
const ROUTE_KINDS = [0 => 'tram', 1 => 'metro', 2 => 'train', 3 => 'bus', 5 => 'tram', 6 => 'tram', 7 => 'train', 11 => 'bus', 12 => 'train'];
const EXTENDED_KINDS = [1 => 'train', 2 => 'bus', 4 => 'metro', 7 => 'bus', 9 => 'tram'];
// Por tipo e idioma: el vehículo en singular y en plural (para las fichas del menú).
const VEHICLE = [
    'bus' => ['es' => ['autobús', 'Autobuses'], 'eu' => ['autobusa', 'autobusak'], 'en' => ['bus', 'buses'], 'fr' => ['bus', 'Bus']],
    'metro' => ['es' => ['metro', 'Metro'], 'eu' => ['metroa', 'metroa'], 'en' => ['train', 'metro'], 'fr' => ['métro', 'Métro']],
    'tram' => ['es' => ['tranvía', 'Tranvía'], 'eu' => ['tranbia', 'tranbia'], 'en' => ['tram', 'tram'], 'fr' => ['tram', 'Tram']],
    'train' => ['es' => ['tren', 'Trenes'], 'eu' => ['trena', 'trenak'], 'en' => ['train', 'trains'], 'fr' => ['train', 'Trains']],
];
// Sin route_color en el GTFS: el rojo de Bide+.
const DEFAULT_COLOR = '#E30613';
const MIN_CONTRAST = 3.0;
const LUMINANCE_WEIGHTS = [0.2126, 0.7152, 0.0722];
// Margen (en grados) alrededor de las paradas para la zona que comprueba tests/data-integrity.php.
const BOUNDS_MARGIN = 0.2;
const COVERAGE_DAYS = 14;
// La app cuenta las horas con el reloj de Madrid: una red con otra hora (Canarias, Portugal) no cuadraría.
const APP_TIMEZONE = 'Europe/Madrid';
const ICON_SIZES = ['icon-192.png', 'icon-512.png', 'apple-touch-icon.png'];
const CHROME_NAMES = ['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser', 'microsoft-edge'];

function fail(string $sMessage): never
{
    fwrite(STDERR, $sMessage . "\n");
    exit(1);
}

function notice(string $sMessage): void
{
    fwrite(STDERR, 'AVISO: ' . $sMessage . "\n");
}

// Una dirección con curl (que usa el proxy del sistema), o null si no responde.
function fetch(string $sUrl): string|null
{
    $Ch = curl_init($sUrl);
    curl_setopt_array($Ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_FAILONERROR => true]);
    $sBody = curl_exec($Ch);
    if (!is_string($sBody)) {
        return null;
    }
    return $sBody;
}

// Pregunta en la terminal con un valor por defecto (Intro lo acepta); sin terminal o con --si, el valor por defecto.
function ask(string $sQuestion, string $sDefault, bool $bAsk): string
{
    if (!$bAsk) {
        return $sDefault;
    }
    fwrite(STDOUT, $sQuestion . ' [' . $sDefault . ']: ');
    $sAnswer = trim((string)fgets(STDIN));
    if ($sAnswer === '') {
        return $sDefault;
    }
    return $sAnswer;
}

function options(): array
{
    $aLong = ['slug:', 'name:', 'color:', 'gtfs:', 'kind:', 'city:', 'operator:', 'trip-updates:', 'vehicle-positions:',
        'alerts:', 'matching:', 'sin-datos', 'si', 'rehacer:'];
    $aOptions = getopt('', $aLong);
    if (isset($aOptions['rehacer'])) {
        return $aOptions;
    }
    if (empty($aOptions['gtfs']) || !preg_match('#^https?://#', $aOptions['gtfs'])) {
        fail('Falta --gtfs con la dirección del ZIP del GTFS. Uso: php scripts/new-network.php --gtfs=https://…/gtfs.zip');
    }
    foreach (array_keys(GTFS_RT_FEEDS) as $sFeed) {
        if (isset($aOptions[$sFeed]) && !preg_match('#^https?://#', $aOptions[$sFeed])) {
            fail("--$sFeed: la dirección del feed GTFS-Realtime (https://…).");
        }
    }
    return $aOptions;
}

// Las filas de un archivo del GTFS (sin BOM), o [] si no lo trae.
function csvRows(ZipArchive $Zip, string $sFile): array
{
    $sBody = $Zip->getFromName($sFile);
    if ($sBody === false) {
        return [];
    }
    $Handle = fopen('php://memory', 'r+');
    fwrite($Handle, preg_replace('/^\xEF\xBB\xBF/', '', $sBody));
    rewind($Handle);
    $aHeader = array_map('trim', (array)fgetcsv($Handle, null, ',', '"', ''));
    $aRows = [];
    while (($aLine = fgetcsv($Handle, null, ',', '"', '')) !== false) {
        if (count($aLine) === count($aHeader)) {
            $aRows[] = array_combine($aHeader, $aLine);
        }
    }
    fclose($Handle);
    return $aRows;
}

function mostCommon(array $aValues, string $sDefault): string
{
    $aValues = array_filter($aValues, fn($sValue) => $sValue !== '');
    if ($aValues === []) {
        return $sDefault;
    }
    $aCounts = array_count_values($aValues);
    arsort($aCounts);
    return (string)array_key_first($aCounts);
}

function kindOf(int $iRouteType): string
{
    if (isset(ROUTE_KINDS[$iRouteType])) {
        return ROUTE_KINDS[$iRouteType];
    }
    return EXTENDED_KINDS[intdiv($iRouteType, 100)] ?? 'bus';
}

// Lo que se saca del GTFS para la ficha: operador, zona horaria, tipo, color, líneas, zona y viajes con rótulo.
function readGtfs(string $sUrl): array
{
    echo "Bajando el GTFS…\n";
    $sZip = Download::resolveZipPath($sUrl);
    $Zip = new ZipArchive();
    if ($Zip->open($sZip) !== true) {
        fail('El GTFS no es un ZIP: ' . $sUrl);
    }
    $aAgencies = csvRows($Zip, 'agency.txt');
    $aRoutes = csvRows($Zip, 'routes.txt');
    $aStops = csvRows($Zip, 'stops.txt');
    $aTrips = csvRows($Zip, 'trips.txt');
    $Zip->close();
    unlink($sZip);
    if ($aRoutes === [] || $aStops === [] || $aTrips === []) {
        fail('El GTFS no trae routes.txt, stops.txt o trips.txt.');
    }
    $aLats = array_map(fn($aStop) => (float)$aStop['stop_lat'], $aStops);
    $aLons = array_map(fn($aStop) => (float)$aStop['stop_lon'], $aStops);
    $iWithHeadsign = count(array_filter($aTrips, fn($aTrip) => trim($aTrip['trip_headsign'] ?? '') !== ''));
    $sColor = mostCommon(array_map(fn($aRoute) => strtoupper(trim($aRoute['route_color'] ?? '')), $aRoutes), '');
    if (preg_match('/^[0-9A-F]{6}$/', $sColor)) {
        $sColor = '#' . $sColor;
    } else {
        $sColor = DEFAULT_COLOR;
    }
    return [
        'agency' => trim($aAgencies[0]['agency_name'] ?? ''),
        'timezone' => trim($aAgencies[0]['agency_timezone'] ?? APP_TIMEZONE),
        'kind' => kindOf((int)mostCommon(array_map(fn($aRoute) => trim($aRoute['route_type']), $aRoutes), '3')),
        'color' => $sColor,
        'routes' => $aRoutes,
        'stops' => count($aStops),
        'bounds' => [min($aLats), max($aLats), min($aLons), max($aLons)],
        'headsign_share' => $iWithHeadsign / count($aTrips),
    ];
}

// Una zona horaria con otra hora que la de Madrid, en invierno o en verano, no vale: la app usa ese reloj.
function checkTimezone(string $sTimezone): void
{
    if (!in_array($sTimezone, timezone_identifiers_list(), true)) {
        notice("el GTFS dice una zona horaria desconocida ($sTimezone); se da por buena la de Madrid");
        return;
    }
    $iYear = (int)date('Y');
    foreach (['-01-15', '-07-15'] as $sDay) {
        $Date = new DateTime($iYear . $sDay . ' 12:00', new DateTimeZone('UTC'));
        if ((new DateTimeZone($sTimezone))->getOffset($Date) !== (new DateTimeZone(APP_TIMEZONE))->getOffset($Date)) {
            fail("La red va con la hora de $sTimezone, distinta de la de Madrid: la app cuenta las horas con " . APP_TIMEZONE . '.');
        }
    }
}

function slugify(string $sName): string
{
    $sAscii = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', $sName));
    $sSlug = substr(trim((string)preg_replace('/[^a-z0-9]+/', '-', $sAscii), '-'), 0, 30);
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $sSlug)) {
        return 'red-nueva';
    }
    return $sSlug;
}

// Para comparar nombres de municipio: sin tildes ni mayúsculas.
function plain(string $sText): string
{
    return strtolower(trim((string)iconv('UTF-8', 'ASCII//TRANSLIT', $sText)));
}

// Los festivos de la red (gtfs.holidays) si su ciudad está en un calendario laboral de api/Config/holidays.php, con las
// redes de su territorio como respaldo; null si no está.
function holidays(string $sCity): array|null
{
    if ($sCity === '') {
        return null;
    }
    $aConfig = require ROOT . '/api/Config/holidays.php';
    foreach ($aConfig['calendars'] as $sName => $aCalendar) {
        $sBody = fetch(str_replace('{year}', date('Y'), $aCalendar['url']));
        $aRows = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', (string)$sBody), true);
        foreach ((array)$aRows as $aRow) {
            if (plain((string)($aRow[$aCalendar['municipality']] ?? '')) !== plain($sCity)) {
                continue;
            }
            $sTerritory = $aRow[$aCalendar['territory']];
            $aBackups = [];
            foreach (Networks::all() as $sSlug => $aNetwork) {
                if (($aNetwork['gtfs']['holidays']['territory'] ?? null) === $sTerritory) {
                    $aBackups[] = $sSlug;
                }
            }
            return ['calendar' => $sName, 'territory' => $sTerritory, 'municipality' => $aRow[$aCalendar['municipality']],
                'fallback' => ['networks' => $aBackups, 'quorum' => min(2, count($aBackups))]];
        }
    }
    return null;
}

// Lo que pide la ficha: las opciones, lo sacado del GTFS y, en una terminal, lo que se conteste.
function settings(array $aOptions): array
{
    $aGtfs = readGtfs($aOptions['gtfs']);
    checkTimezone($aGtfs['timezone']);
    $bAsk = !isset($aOptions['si']) && stream_isatty(STDIN);
    echo count($aGtfs['routes']) . ' líneas y ' . $aGtfs['stops'] . ' paradas de «' . $aGtfs['agency'] . '» (' . $aGtfs['timezone'] . ")\n";
    foreach (array_slice($aGtfs['routes'], 0, 12) as $aRoute) {
        echo '  ' . trim($aRoute['route_short_name'] ?? '') . '  ' . trim($aRoute['route_long_name'] ?? '') . "\n";
    }
    $aSet = ['gtfs' => $aOptions['gtfs'], 'bounds' => $aGtfs['bounds'], 'headsign_share' => $aGtfs['headsign_share']];
    $aSet['operator'] = ask('Operador', $aOptions['operator'] ?? $aGtfs['agency'], $bAsk);
    $aSet['name'] = ask('Nombre de la app (con su «+»)', $aOptions['name'] ?? $aSet['operator'] . '+', $bAsk);
    $aSet['slug'] = ask('Nombre interno (?red=…)', $aOptions['slug'] ?? slugify(rtrim($aSet['name'], '+')), $bAsk);
    $aSet['city'] = ask('Ciudad o zona (para los textos; vacío si es de muchas)', $aOptions['city'] ?? '', $bAsk);
    $aSet['kind'] = ask('Tipo (' . implode(', ', KINDS) . ')', $aOptions['kind'] ?? $aGtfs['kind'], $bAsk);
    $aSet['color'] = strtoupper(ask('Color principal', $aOptions['color'] ?? $aGtfs['color'], $bAsk));
    foreach (array_keys(GTFS_RT_FEEDS) as $sFeed) {
        $aSet[$sFeed] = ask('Feed GTFS-Realtime ' . $sFeed . ' (vacío si no hay)', $aOptions[$sFeed] ?? '', $bAsk);
    }
    $aSet['matching'] = $aOptions['matching'] ?? 'trip';
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $aSet['slug'])) {
        fail('Nombre interno: minúsculas, números y guiones (por ejemplo, getxo o tranvia-vitoria).');
    }
    if (Networks::exists($aSet['slug']) || is_file(ROOT . '/api/Networks/pending/' . $aSet['slug'] . '.php')) {
        fail("Ya hay una red «{$aSet['slug']}» en api/Networks (o en pending).");
    }
    if (!preg_match('/^#[0-9A-F]{6}$/', $aSet['color'])) {
        fail('Color: hexadecimal de 6 cifras, como #E30613.');
    }
    $aSet['color'] = readableColor($aSet['color']);
    if (!in_array($aSet['kind'], KINDS, true) || !in_array($aSet['matching'], MATCHINGS, true)) {
        fail('Tipo: ' . implode(', ', KINDS) . '; --matching: trip (viajes del horario) o stops (por paradas y hora).');
    }
    if ($aSet['vehicle-positions'] !== '' && $aSet['trip-updates'] === '') {
        notice('posiciones sin horas previstas: la red sale en directo con el retraso que da el GPS de cada vehículo '
            . 'sobre su horario (live = [\'vehicle-positions\']).');
    }
    $aSet['holidays'] = holidays($aSet['city']);
    if ($aSet['holidays'] === null) {
        notice('su ciudad no está en ningún calendario laboral (api/Config/holidays.php): sin gtfs.holidays, los festivos '
            . 'van como diga su GTFS.');
    }
    return $aSet;
}

// Contraste (WCAG) de un color con el blanco, el de los textos sobre él.
function contrastWithWhite(string $sColor): float
{
    $dLuminance = 0.0;
    foreach (sscanf($sColor, '#%02x%02x%02x') as $i => $iChannel) {
        $dChannel = $iChannel / 255;
        if ($dChannel <= 0.03928) {
            $dChannel = $dChannel / 12.92;
        } else {
            $dChannel = (($dChannel + 0.055) / 1.055) ** 2.4;
        }
        $dLuminance += LUMINANCE_WEIGHTS[$i] * $dChannel;
    }
    return 1.05 / ($dLuminance + 0.05);
}

// Un color claro (amarillo, verde lima) se oscurece hasta que el texto blanco se lea encima (3:1, texto grande).
function readableColor(string $sColor): string
{
    $sOut = $sColor;
    for ($i = 1; contrastWithWhite($sOut) < MIN_CONTRAST && $i <= 20; $i++) {
        $sOut = strtoupper(mix($sColor, '#000000', $i * 0.05));
    }
    if ($sOut !== $sColor) {
        notice("con $sColor el texto blanco no se lee: va $sOut (se puede cambiar en brand)");
    }
    return $sOut;
}

// Mezcla dos colores (#rrggbb): $dWeight es la parte del segundo.
function mix(string $sA, string $sB, float $dWeight): string
{
    $aA = sscanf($sA, '#%02x%02x%02x');
    $aB = sscanf($sB, '#%02x%02x%02x');
    $aOut = [];
    foreach ([0, 1, 2] as $i) {
        $aOut[] = (int)round($aA[$i] * (1 - $dWeight) + $aB[$i] * $dWeight);
    }
    return vsprintf('#%02x%02x%02x', $aOut);
}

// Paleta de la app a partir de un color, con las mismas proporciones que las redes que ya hay. Se puede retocar a mano.
function palette(string $sColor): array
{
    [$iR, $iG, $iB] = sscanf($sColor, '#%02x%02x%02x');
    return [
        'bg' => mix('#f5f5f5', $sColor, 0.03),
        'border' => mix('#e0e0e0', $sColor, 0.06),
        'primary' => strtoupper($sColor),
        'primary-light' => mix($sColor, '#ffffff', 0.55),
        'primary-dark' => mix($sColor, '#000000', 0.38),
        'primary-tint' => mix($sColor, '#ffffff', 0.92),
        'text' => mix('#1e1e1e', $sColor, 0.06),
        'text-secondary' => mix('#606060', $sColor, 0.08),
        'backdrop' => sprintf('rgba(%d, %d, %d, .5)', (int)round($iR * 0.1), (int)round($iG * 0.1), (int)round($iB * 0.1)),
    ];
}

function phpString(string $sValue): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $sValue) . "'";
}

// Un valor de PHP como se escribe en la ficha, con su sangría.
function phpValue(mixed $Value, string $sIndent): string
{
    if (is_string($Value)) {
        return phpString($Value);
    }
    if ($Value === null) {
        return 'null';
    }
    if (!is_array($Value)) {
        return var_export($Value, true);
    }
    if (array_is_list($Value) && array_filter($Value, 'is_array') === []) {
        return '[' . implode(', ', array_map(fn($Item) => phpValue($Item, $sIndent), $Value)) . ']';
    }
    $sOut = "[\n";
    foreach ($Value as $Key => $Item) {
        $sOut .= $sIndent . '    ' . phpString((string)$Key) . ' => ' . phpValue($Item, $sIndent . '    ') . ",\n";
    }
    return $sOut . $sIndent . ']';
}

// «de Barakaldo» en euskera: Barakaldoko, Tarbeseko.
function basqueOf(string $sCity): string
{
    if (preg_match('/[aeiou]$/i', $sCity)) {
        return $sCity . 'ko';
    }
    return $sCity . 'eko';
}

// «de Kbus» (posesivo) en euskera: Kbusen, Tuvisaren.
function basqueGenitive(string $sName): string
{
    if (preg_match('/[aeiou]$/i', $sName)) {
        return $sName . 'ren';
    }
    return $sName . 'en';
}

// «por Tuvisa» en euskera: Tuvisak, Kbusek.
function basqueBy(string $sName): string
{
    if (preg_match('/[aeiou]$/i', $sName)) {
        return $sName . 'k';
    }
    return $sName . 'ek';
}

// «de Oloron» en francés: d’Oloron, de Tarbes.
function frenchOf(string $sCity): string
{
    if (preg_match('/^[aeiouhéèêàâîôûAEIOUHÉÈÊÀÂÎÔÛ]/u', $sCity)) {
        return 'd’' . $sCity;
    }
    return 'de ' . $sCity;
}

// Los textos de la ficha en los cuatro idiomas. $aLive: las fuentes del directo (live de la ficha).
function texts(array $aSet, array $aLive): array
{
    $bLive = $aLive !== [];
    $bGps = $aLive === ['vehicle-positions'];
    $sName = $aSet['name'];
    $sCity = $aSet['city'];
    $sOperator = $aSet['operator'];
    $aV = VEHICLE[$aSet['kind']];
    $aTile = ['es' => 'Horarios', 'eu' => 'Ordutegiak', 'en' => 'Timetables', 'fr' => 'Horaires'];
    if ($sCity !== '') {
        $aTile = ['es' => $aV['es'][1] . ' de ' . $sCity, 'eu' => basqueOf($sCity) . ' ' . $aV['eu'][1],
            'en' => $sCity . ' ' . $aV['en'][1], 'fr' => $aV['fr'][1] . ' ' . frenchOf($sCity)];
    }
    $aWhat = ['es' => 'Horarios de ', 'eu' => '', 'en' => '', 'fr' => 'Horaires de '];
    $aWhatEnd = ['es' => '', 'eu' => ' ordutegiak', 'en' => ' timetables', 'fr' => ''];
    $aDisclaimer = [
        'es' => 'Horario programado oficial: no refleja retrasos ni cancelaciones.',
        'eu' => 'Ordutegi ofizial programatua: ez ditu atzerapenak eta bertan behera utzitakoak jasotzen.',
        'en' => 'Official scheduled timetable: it does not reflect delays or cancellations.',
        'fr' => 'Horaires théoriques officiels : ils ne tiennent compte ni des retards ni des suppressions.',
    ];
    // Qué publica la red, cómo calcula Bide+ y sus límites (Info y Estado de las redes).
    $aData = [
        'es' => ['Solo su horario.', 'Horario oficial.', 'Sin retrasos ni cancelaciones.'],
        'eu' => ['Bere ordutegia bakarrik.', 'Ordutegi ofiziala.', 'Atzerapenik eta bertan behera utzitakorik gabe.'],
        'en' => ['Only its timetable.', 'The official timetable.', 'No delays or cancellations.'],
        'fr' => ['Seulement ses horaires.', 'Les horaires officiels.', 'Ni retards ni suppressions.'],
    ];
    if ($bLive) {
        $aWhat = ['es' => 'Horarios y tiempo real de ', 'eu' => '', 'en' => '', 'fr' => 'Horaires et temps réel de '];
        $aWhatEnd = ['es' => '', 'eu' => ' ordutegiak eta denbora errealean', 'en' => ' timetables and real time', 'fr' => ''];
        $aDisclaimer = [
            'es' => 'Los tiempos en directo son una previsión del operador.',
            'eu' => 'Zuzeneko denborak operadorearen aurreikuspena dira.',
            'en' => 'Live times are the operator\'s forecast.',
            'fr' => 'Les temps en direct sont une prévision de l’opérateur.',
        ];
        $aData = [
            'es' => ['La hora prevista de cada vehículo.', 'La previsión del operador.', 'Sin limitaciones conocidas.'],
            'eu' => ['Ibilgailu bakoitzaren aurreikusitako ordua.', 'Operadorearen aurreikuspena.', 'Ez dago muga ezagunik.'],
            'en' => ['The predicted time of each vehicle.', 'The operator\'s forecast.', 'No known limitations.'],
            'fr' => ['L’heure prévue de chaque véhicule.', 'La prévision de l’opérateur.', 'Aucune limite connue.'],
        ];
    }
    if ($bGps) {
        $aDisclaimer = [
            'es' => 'Los tiempos en directo los estima Bide+ con la posición GPS de cada vehículo y su horario.',
            'eu' => 'Zuzeneko denborak Bide+-ek kalkulatzen ditu, ibilgailu bakoitzaren GPS kokapenarekin eta '
                . 'ordutegiarekin.',
            'en' => 'Live times are estimated by Bide+ from the GPS position of each vehicle and its timetable.',
            'fr' => 'Les temps en direct sont estimés par Bide+ à partir de la position GPS de chaque véhicule et de '
                . 'ses horaires.',
        ];
        $aData = [
            'es' => ['Dónde va cada vehículo (GPS), sin horas previstas.',
                'Su retraso, por su posición sobre su horario.', 'Un vehículo sin GPS va con su horario.'],
            'eu' => ['Ibilgailu bakoitza non doan (GPS), aurreikusitako ordurik gabe.',
                'Bere atzerapena, bere kokapena ordutegiarekin alderatuta.',
                'GPSrik gabeko ibilgailua bere ordutegiarekin doa.'],
            'en' => ['Where each vehicle is (GPS), without predicted times.',
                'Its delay, from its position against its timetable.', 'A vehicle without GPS runs on its timetable.'],
            'fr' => ['La position de chaque véhicule (GPS), sans heures prévues.',
                'Son retard, d’après sa position par rapport à ses horaires.',
                'Un véhicule sans GPS suit ses horaires.'],
        ];
    }
    $aSwitcherEnd = ['es' => '', 'eu' => '', 'en' => '', 'fr' => ''];
    if ($sCity !== '') {
        $aSwitcherEnd = ['es' => ' en ' . $sCity, 'eu' => ' (' . $sCity . ')', 'en' => ' in ' . $sCity, 'fr' => ' à ' . $sCity];
    }
    $aNotOfficial = [
        'es' => 'Proyecto independiente y no oficial, sin relación con ' . $sOperator . '. ',
        'eu' => 'Proiektu independentea eta ez-ofiziala, ' . $sOperator . ' erakundearekin loturarik gabea. ',
        'en' => 'An independent, unofficial project, not affiliated with ' . $sOperator . '. ',
        'fr' => 'Projet indépendant et non officiel, sans lien avec ' . $sOperator . '. ',
    ];
    $aEmpty = [
        'es' => 'Busca una parada para ver el próximo ' . $aV['es'][0] . '.',
        'eu' => 'Bilatu geraleku bat hurrengo ' . $aV['eu'][0] . ' ikusteko.',
        'en' => 'Search for a stop to see the next ' . $aV['en'][0] . '.',
        'fr' => 'Cherchez un arrêt pour voir le prochain ' . $aV['fr'][0] . '.',
    ];
    $aAttribution = ['es' => 'Datos: ', 'eu' => 'Datuak: ', 'en' => 'Data: ', 'fr' => 'Données : '];
    $aTexts = [];
    foreach (array_keys($aTile) as $sLang) {
        $sSubtitle = $aWhat[$sLang] . $sOperator . $aWhatEnd[$sLang];
        $aTexts[$sLang] = [
            'name' => $sName,
            'tile' => $aTile[$sLang],
            'switcher' => $sSubtitle . $aSwitcherEnd[$sLang],
            'subtitle' => $sSubtitle,
            'liveEmpty' => $aEmpty[$sLang],
            'disclaimer' => $aNotOfficial[$sLang] . $aDisclaimer[$sLang],
            'dataPublished' => $aData[$sLang][0],
            'dataMethod' => $aData[$sLang][1],
            'dataLimits' => $aData[$sLang][2],
            'attribution' => $aAttribution[$sLang] . $sOperator . ' (GTFS)',
        ];
        // Quién da las horas previstas, en la ficha del vehículo («horas previstas por Tuvisa», «Tuvisak»).
        if ($bLive) {
            $aTexts[$sLang] = ['liveSource' => $sOperator] + $aTexts[$sLang];
        }
    }
    if ($bLive) {
        $aTexts['eu']['liveSource'] = basqueBy($sOperator);
    }
    // Sin horas del operador: las calcula Bide+ con su GPS («horas previstas por Bide+ con el GPS de Kbus»).
    if ($bGps) {
        $aTexts['es']['liveSource'] = 'Bide+ con el GPS de ' . $sOperator;
        $aTexts['eu']['liveSource'] = 'Bide+-ek ' . basqueGenitive($sOperator) . ' GPSarekin';
        $aTexts['en']['liveSource'] = 'Bide+ from ' . $sOperator . ' GPS data';
        $aTexts['fr']['liveSource'] = 'Bide+ d’après le GPS ' . frenchOf($sOperator);
    }
    return $aTexts;
}

// El bloque gtfs_rt de los feeds que haya, o null sin ninguno.
function gtfsRt(array $aSet): array|null
{
    $aRt = [];
    foreach (GTFS_RT_FEEDS as $sFeed => $sKey) {
        if ($aSet[$sFeed] !== '') {
            $aRt[$sKey] = $aSet[$sFeed];
        }
    }
    if ($aRt === []) {
        return null;
    }
    // Solo posiciones: cada vehículo se casa con su viaje por su GPS (Services\VehicleDelays), sin matching.
    if ($aSet['trip-updates'] === '') {
        return $aRt + ['cache_ttl_seconds' => 30, 'http_timeout_seconds' => 8, 'max_age_seconds' => 300];
    }
    $aRt['matching'] = $aSet['matching'];
    if ($aSet['matching'] === 'stops') {
        $aRt['match_tolerance_seconds'] = 600;
    }
    return $aRt + ['cache_ttl_seconds' => 30, 'http_timeout_seconds' => 8, 'max_age_seconds' => 300];
}

// El siguiente order libre, contando las redes preparadas de api/Networks/pending.
function nextOrder(): int
{
    $iMax = 0;
    foreach (array_merge(glob(ROOT . '/api/Networks/*.php'), glob(ROOT . '/api/Networks/pending/*.php')) as $sFile) {
        $iMax = max($iMax, (int)((require $sFile)['order'] ?? 0));
    }
    return $iMax + 1;
}

function config(array $aSet): string
{
    $sSlug = $aSet['slug'];
    $aLive = [];
    if ($aSet['trip-updates'] !== '') {
        $aLive[] = 'trip-updates';
    }
    if ($aSet['vehicle-positions'] !== '') {
        $aLive[] = 'vehicle-positions';
    }
    $aTexts = texts($aSet, $aLive);
    $aSeoWhat = ['Horarios', 'por línea y parada'];
    if ($aLive !== []) {
        $aSeoWhat = ['Horarios y tiempo real', 'en tiempo real por línea y parada'];
    }
    $sWhere = '';
    if ($aSet['city'] !== '') {
        $sWhere = ' en ' . $aSet['city'];
    }
    $sAlt = htmlspecialchars($aSet['name'], ENT_QUOTES);
    // En la cabecera, la marca en blanco (glyph.png) si se ha podido dibujar; si no, el icono entero.
    $sLogomark = '<img class="is-icon" src="/icons-' . $sSlug . '/icon-192.png" alt="' . $sAlt . '" aria-hidden="true">';
    if (is_file(ROOT . '/icons-' . $sSlug . '/glyph.png')) {
        $sLogomark = '<img src="/icons-' . $sSlug . '/glyph.png" alt="' . $sAlt . '" aria-hidden="true">';
    }
    [$dLatMin, $dLatMax, $dLonMin, $dLonMax] = $aSet['bounds'];
    $aGtfs = [];
    if ($aSet['holidays'] !== null) {
        $aGtfs['holidays'] = $aSet['holidays'];
    }
    $aGtfs += ['source' => $aSet['gtfs'], 'fallback_source' => null, 'agency_id' => null, 'stops' => 'generic',
        'skip_geocode' => false, 'feed_window' => true, 'required' => false];
    $aConfig = [
        'slug' => $sSlug,
        'order' => nextOrder(),
        'db' => $sSlug,
        'kind' => $aSet['kind'],
        'added' => (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d'),
        'brand' => [
            'icons' => 'icons-' . $sSlug,
            'theme_color' => $aSet['color'],
            'title' => [mb_strtoupper(rtrim($aSet['name'], '+')), mb_strtoupper($aSet['city'])],
            'logomark' => $sLogomark,
            'card_logomark' => '<img src="/icons-' . $sSlug . '/icon-192.png" alt="' . $sAlt . '" aria-hidden="true">',
            'tile_color' => $aSet['color'],
            'swatch_color' => $aSet['color'],
            'palette' => palette($aSet['color']),
        ],
        'texts' => $aTexts,
        'seo' => [
            'title' => $aSet['name'] . ' · ' . $aSeoWhat[0] . ' de ' . $aSet['operator'] . $sWhere . ' | Bide+',
            'description' => 'Consulta los ' . mb_strtolower($aSeoWhat[0]) . ' de ' . $aSet['operator'] . $sWhere . ' ' . $aSeoWhat[1] . '.',
        ],
        'attribution' => $aTexts['es']['attribution'],
        'checks' => ['bounds' => [round($dLatMin - BOUNDS_MARGIN, 2), round($dLatMax + BOUNDS_MARGIN, 2),
            round($dLonMin - BOUNDS_MARGIN, 2), round($dLonMax + BOUNDS_MARGIN, 2)]],
        'features' => [
            'platforms' => false,
            'headsign_from_last_stop' => $aSet['headsign_share'] < 0.5,
            'stop_hints' => $aSet['kind'] === 'bus',
            'line_map' => true,
            'schedule_text' => false,
            'trip_detail' => 'stops',
            'alerts' => 'network',
            'alerts_detail' => true,
        ],
        'live' => $aLive,
    ];
    unset($aConfig['texts']['es']['attribution']);
    if ($aLive !== []) {
        $aConfig['features']['trip_detail'] = 'vehicle';
    }
    $aRt = gtfsRt($aSet);
    if ($aRt !== null) {
        $aConfig['gtfs_rt'] = $aRt;
    }
    $aConfig['gtfs'] = $aGtfs;
    return "<?php\n\n// Creada con scripts/new-network.php. Revisa los textos (sobre todo los de euskera y francés) y, tras cualquier\n"
        . "// cambio, php scripts/new-network.php --rehacer=$sSlug.\nreturn " . phpValue($aConfig, '') . ";\n";
}

// Lanza scripts de scripts/ con sus argumentos, uno detrás de otro; para si alguno falla.
function run(array $aScripts): void
{
    foreach ($aScripts as $sScript) {
        [$sFile, $sArgs] = array_pad(explode(' ', $sScript, 2), 2, '');
        $sCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $sFile);
        if ($sArgs !== '') {
            $sCommand .= ' ' . escapeshellarg($sArgs);
        }
        passthru($sCommand, $iStatus);
        if ($iStatus !== 0) {
            fail("Falló scripts/$sScript");
        }
    }
}

// El navegador para dibujar los iconos: CHROME_PATH, uno del PATH o el Chromium de Playwright; null si no hay.
function chrome(): string|null
{
    if (getenv('CHROME_PATH') && is_file(getenv('CHROME_PATH'))) {
        return getenv('CHROME_PATH');
    }
    foreach (CHROME_NAMES as $sName) {
        $sPath = trim((string)shell_exec('command -v ' . escapeshellarg($sName) . ' 2>/dev/null'));
        if ($sPath !== '') {
            return $sPath;
        }
    }
    if (!getenv('PLAYWRIGHT_BROWSERS_PATH')) {
        return null;
    }
    $aPlaywright = glob(getenv('PLAYWRIGHT_BROWSERS_PATH') . '/chromium-*/chrome-*/chrome');
    if ($aPlaywright === []) {
        return null;
    }
    return end($aPlaywright);
}

// Los PNG de icons-<red>/ desde su icon.svg y glyph.svg (scripts/render-icons.mjs), y la imagen para compartir, que
// lleva todas las apps. Sin navegador, los de Bide+ hasta que se pueda.
function renderIcons(string $sSlug): void
{
    $sChrome = chrome();
    if ($sChrome !== null) {
        putenv('CHROME_PATH=' . $sChrome);
        passthru('node ' . escapeshellarg(__DIR__ . '/render-icons.mjs') . ' ' . escapeshellarg($sSlug), $iStatus);
        if ($iStatus === 0) {
            passthru('node ' . escapeshellarg(__DIR__ . '/render-images.mjs') . ' og');
            return;
        }
    }
    notice("sin navegador para dibujar el icono: van los de Bide+ (CHROME_PATH=… y --rehacer=$sSlug más tarde).");
    foreach (ICON_SIZES as $sIcon) {
        copy(ROOT . '/icons-bide-rojo/' . $sIcon, ROOT . '/icons-' . $sSlug . '/' . $sIcon);
    }
}

// Icono provisional: la inicial de la red y su «+» en blanco sobre su color (icon.svg) y en negro (glyph.svg).
function makeIcons(string $sSlug, string $sName, string $sColor): void
{
    $sDir = ROOT . '/icons-' . $sSlug;
    if (!is_dir($sDir)) {
        mkdir($sDir);
    }
    $sLetter = htmlspecialchars(mb_strtoupper(mb_substr(trim($sName), 0, 1)));
    $sMark = '<text x="226" y="372" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="300" font-weight="700">'
        . $sLetter . '</text><rect x="354" y="113" width="104" height="30" rx="5"/><rect x="391" y="76" width="30" height="104" rx="5"/>';
    $sSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">';
    file_put_contents($sDir . '/icon.svg', $sSvg . '<rect width="512" height="512" rx="112" fill="' . $sColor . '"/><g fill="#FFFFFF">' . $sMark . '</g></svg>' . "\n");
    file_put_contents($sDir . '/glyph.svg', $sSvg . '<g fill="#000000">' . $sMark . '</g></svg>' . "\n");
    renderIcons($sSlug);
}

// Todo lo que sale de las fichas; con $sSlug, también su base y lo que sale de las bases.
function build(string|null $sSlug): void
{
    run(['build-networks-js.php', 'build-networks-css.php', 'build-404.php', 'build-llms.php']);
    if ($sSlug !== null) {
        run(['build-database.php --network=' . $sSlug, 'build-network-map.php', 'build-offline-data.php', 'build-sitemap.php']);
    }
}

// Cuántos viajes circulan cada uno de los próximos días; los días sin ninguno, con un aviso.
function coverage(array $aNetwork): void
{
    $Pdo = new PDO('sqlite:' . $aNetwork['db_path']);
    $Day = Services\Calendar::todayMadrid();
    $aEmpty = [];
    for ($i = 0; $i < COVERAGE_DAYS; $i++) {
        $Stmt = $Pdo->prepare('
            SELECT COUNT(*) FROM service_journeys sj JOIN service_calendars sc ON sc.id = sj.calendar_id
            WHERE ((sc.weekday_mask & :bit) != 0
                OR EXISTS (SELECT 1 FROM service_calendar_exceptions e WHERE e.calendar_id = sc.id AND e.date = :d AND e.available = 1))
              AND NOT EXISTS (SELECT 1 FROM service_calendar_exceptions e WHERE e.calendar_id = sc.id AND e.date = :d2 AND e.available = 0)
        ');
        $Stmt->execute(['bit' => Services\Calendar::weekdayBitFor($Day), 'd' => $Day->format('Y-m-d'), 'd2' => $Day->format('Y-m-d')]);
        if ((int)$Stmt->fetchColumn() === 0) {
            $aEmpty[] = $Day->format('Y-m-d');
        }
        $Day->modify('+1 day');
    }
    if ($aEmpty !== []) {
        notice('días sin ningún viaje en las próximas dos semanas: ' . implode(', ', $aEmpty)
            . '. Si la red circula esos días, su GTFS está incompleto.');
    }
}

// Cada feed en directo de la ficha responde y se puede leer.
function feeds(array $aNetwork): void
{
    $aParsers = ['trip_updates_url' => 'parseTripUpdates', 'vehicle_positions_url' => 'parseVehiclePositions', 'alerts_url' => 'parseAlerts'];
    foreach ($aParsers as $sKey => $sParser) {
        $sUrl = $aNetwork['gtfs_rt'][$sKey] ?? null;
        if ($sUrl === null) {
            continue;
        }
        $sBody = fetch($sUrl);
        if ($sBody === null) {
            notice("el feed $sKey no responde: $sUrl");
            continue;
        }
        try {
            $aParsed = Services\GtfsRealtimeClient::$sParser($sBody);
            echo "  $sKey: responde (" . strlen($sBody) . ' bytes, ' . count($aParsed['trips'] ?? $aParsed['vehicles'] ?? $aParsed['alerts'] ?? []) . " elementos)\n";
        } catch (Throwable $Ex) {
            notice("el feed $sKey no es GTFS-Realtime: necesita su adaptador en api/Services");
        }
    }
}

// La validación final: las pautas de la ficha, la base y lo que se puede comprobar de sus datos.
function validate(string $sSlug): void
{
    echo "\nValidando…\n";
    // De su archivo: el registro ya se leyó al empezar, sin la ficha nueva.
    $aNetwork = require ROOT . '/api/Networks/' . $sSlug . '.php';
    $aNetwork['db_path'] = ROOT . '/data/' . $aNetwork['db'] . '.sqlite';
    $bOk = true;
    foreach (['tests/code-quality.php', 'tests/data-integrity.php'] as $sTest) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/' . $sTest) . ' 2>&1', $aOutput, $iStatus);
        echo '  ' . $sTest . ': ' . (string)end($aOutput) . "\n";
        if ($iStatus !== 0) {
            $bOk = false;
            echo '    ' . implode("\n    ", preg_grep('/^MAL|^\s+\S/', $aOutput)) . "\n";
        }
        $aOutput = [];
    }
    coverage($aNetwork);
    feeds($aNetwork);
    if (!$bOk) {
        fail("La red «{$sSlug}» no pasa la validación: arregla su ficha y php scripts/new-network.php --rehacer=$sSlug");
    }
}

$aOptions = options();
if (isset($aOptions['rehacer'])) {
    $sSlug = $aOptions['rehacer'];
    if (!Networks::exists($sSlug)) {
        fail("No hay ninguna red «{$sSlug}» en api/Networks.");
    }
    $sIcons = ROOT . '/' . Networks::get($sSlug)['brand']['icons'];
    if (is_file($sIcons . '/icon.svg')) {
        renderIcons($sSlug);
    }
    build($sSlug);
    validate($sSlug);
    echo "\nRed «{$sSlug}» rehecha y validada: /?red=$sSlug\n";
    exit(0);
}

$aSet = settings($aOptions);
$sSlug = $aSet['slug'];
makeIcons($sSlug, $aSet['name'], $aSet['color']);
file_put_contents(ROOT . '/api/Networks/' . $sSlug . '.php', config($aSet));
if (isset($aOptions['sin-datos'])) {
    build(null);
    echo "\nFicha e iconos de «{$aSet['name']}» listos; los datos, con php scripts/new-network.php --rehacer=$sSlug\n";
    exit(0);
}
build($sSlug);
validate($sSlug);

echo <<<TXT

Red «{$aSet['name']}» creada, construida y validada: /?red=$sSlug
  api/Networks/$sSlug.php   su ficha: revisa los textos (euskera y francés salen de una plantilla)
  icons-$sSlug/             icono provisional: para el de verdad, cambia icon.svg y glyph.svg
  data/$sSlug.sqlite        su horario, con el mapa, los horarios sin conexión y el sitemap ya al día
Tras cualquier cambio: php scripts/new-network.php --rehacer=$sSlug
Para verla: php -S 127.0.0.1:8011 dev-router.php y abre http://127.0.0.1:8011/?red=$sSlug

TXT;
