<?php
/**
 * Genera data/bizkaibus.sqlite o data/metrobilbao.sqlite a partir del export
 * GTFS oficial de cada red.
 *
 * Uso:
 *   php scripts/build-database.php [--network=bus|metro] [--source=<ruta-o-url>] [--output=<ruta>]
 *
 * Por defecto --network=bus (Bizkaibus, feed GTFS de Lantik/CTB, que sí se
 * mantiene actualizado, a diferencia del NeTEx que usaba antes esta app,
 * congelado desde enero 2025). --network=metro usa el GTFS oficial de Metro
 * Bilbao (cms.metrobilbao.eus), mucho más pequeño (~2.4MB, 42 estaciones) y
 * sin necesidad de geocodificación (los nombres de estación ya son reales).
 * Por eso conviene re-ejecutar este script periódicamente para cada red.
 *
 * Los campos de días de la semana de calendar.txt de Bizkaibus están siempre
 * a cero: el calendario real sale de las fechas explícitas de
 * calendar_dates.txt, generalizadas aquí a una máscara semanal (ver
 * computeWeekdayMask()). El calendar.txt de Metro Bilbao sí trae los días
 * rellenos, pero se procesa igual: computeWeekdayMask() solo mira
 * calendar_dates.txt, así que el resultado es el mismo en ambos casos.
 */

declare(strict_types=1);

ini_set('memory_limit', '1024M');
set_time_limit(0);
date_default_timezone_set('Europe/Madrid');

const NETWORK_DEFAULTS = [
    'bus' => [
        'source' => 'https://ctb-gtfs.s3.eu-south-2.amazonaws.com/bizkaibus.zip',
        'output' => __DIR__ . '/../data/bizkaibus.sqlite',
        'label' => 'BizkaiBus+',
        'agencyId' => '200',
        'skipGeocode' => false,
    ],
    'metro' => [
        'source' => 'https://cms.metrobilbao.eus/es/get/open_data/horarios/es',
        'output' => __DIR__ . '/../data/metrobilbao.sqlite',
        'label' => 'Metro+',
        // routes.txt de Metro Bilbao no trae columna agency_id (solo una
        // agencia, "Metro Bilbao"); null desactiva el filtro por agencia.
        'agencyId' => null,
        // Las 42 estaciones ya tienen nombre real de localidad/barrio; no
        // hace falta resolverlo por geocodificación inversa.
        'skipGeocode' => true,
    ],
];
const GEOCACHE_PATH = __DIR__ . '/geocache.json';
const NOMINATIM_CONTACT = 'garridoparrayeraytx@gmail.com';

function main(array $argv): void
{
    $options = parseArgs($argv);
    $network = 'bus';
    if (isset($options['network'])) {
        $network = $options['network'];
    }
    if (!isset(NETWORK_DEFAULTS[$network])) {
        fwrite(STDERR, "Unknown --network=\"$network\" (expected bus|metro)\n");
        exit(1);
    }
    $defaults = NETWORK_DEFAULTS[$network];

    $source = $defaults['source'];
    if (isset($options['source'])) {
        $source = $options['source'];
    }
    $output = $defaults['output'];
    if (isset($options['output'])) {
        $output = $options['output'];
    }
    $skipGeocode = isset($options['skip-geocode']) || $defaults['skipGeocode'];
    $agencyId = $defaults['agencyId'];

    echo "== {$defaults['label']} database build (GTFS, network=$network) ==\n";
    $zipPath = resolveZipPath($source);
    echo "Reading GTFS export from: $source\n";

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fwrite(STDERR, "Could not open zip: $zipPath\n");
        exit(1);
    }

    echo "Parsing routes.txt...\n";
    $routes = loadRoutes($zip, $agencyId);
    echo '  ' . count($routes) . " routes\n";

    echo "Parsing stops.txt...\n";
    if ($network === 'metro') {
        $stops = loadStopsMetro($zip);
    } else {
        $stops = loadStopsBus($zip);
    }
    echo '  ' . count($stops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $stops = geocodeStops($stops, $skipGeocode);

    echo "Parsing calendar.txt / calendar_dates.txt...\n";
    $generalizeSingleDatesToWeekday = $network !== 'metro';
    $calendars = loadCalendars($zip, $generalizeSingleDatesToWeekday);
    echo '  ' . count($calendars) . " service calendars\n";

    echo "Parsing trips.txt...\n";
    $trips = loadTrips($zip);
    echo '  ' . count($trips) . " trips\n";

    $feedInfo = loadFeedInfo($zip);
    if (isset($feedInfo['feed_version'])) {
        echo "  feed_version: {$feedInfo['feed_version']} (start: {$feedInfo['feed_start_date']}, end: {$feedInfo['feed_end_date']})\n";
    }

    // Feed caducado (el operador aún no publicó la siguiente temporada): abortar en vez de desplegar datos viejos.
    $feedEndIso = '';
    if (!empty($feedInfo['feed_end_date'])) {
        $feedEndIso = gtfsDateToIso($feedInfo['feed_end_date']);
    }
    if ($feedEndIso !== '' && $feedEndIso < date('Y-m-d')) {
        fwrite(STDERR, "ERROR: el GTFS terminó el $feedEndIso, hoy es " . date('Y-m-d') . ". Build abortado.\n");
        exit(1);
    }

    if (file_exists($output)) {
        unlink($output);
    }
    $pdo = new PDO('sqlite:' . $output);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = OFF');

    createSchema($pdo);

    $pdo->beginTransaction();

    insertStops($pdo, $stops);
    insertCalendars($pdo, $calendars);
    insertMeta($pdo, $feedInfo);

    $insertLine = $pdo->prepare('INSERT OR IGNORE INTO lines (id, code, name, name_normalized) VALUES (?, ?, ?, ?)');
    foreach ($routes as $routeId => $route) {
        $insertLine->execute([$routeId, $route['code'], $route['name'], normalize($route['name'])]);
    }

    echo "Processing stop_times.txt (the big one, ~1.1M rows, two bounded-memory passes)...\n";
    $totals = ['patterns' => 0, 'journeys' => 0, 'passingTimes' => 0];
    processStopTimes($pdo, $zip, $trips, $routes, $calendars, $totals, $network);

    $pdo->commit();

    echo "Building indexes...\n";
    createIndexes($pdo);

    $zip->close();

    echo "\n== Summary ==\n";
    $geocodedCount = $pdo->query("SELECT COUNT(*) FROM stops WHERE area != ''")->fetchColumn();
    printf("  stops geocoded (municipality/suburb/neighbourhood): %d / %d\n", $geocodedCount, count($stops));
    printf("  lines:            %d\n", count($routes));
    printf("  patterns:         %d\n", $totals['patterns']);
    printf("  service_journeys: %d\n", $totals['journeys']);
    printf("  passing_times:    %d\n", $totals['passingTimes']);

    echo "\nDatabase written to: $output\n";
    printf("File size: %.1f MB\n", filesize($output) / 1024 / 1024);
}

function parseArgs(array $argv): array
{
    $out = [];
    foreach ($argv as $arg) {
        if (preg_match('/^--(network|source|output)=(.+)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        } elseif ($arg === '--skip-geocode') {
            $out['skip-geocode'] = true;
        }
    }
    return $out;
}

/**
 * El GTFS tampoco trae municipio/barrio (stop_desc viene vacío), solo
 * nombre de calle y coordenadas. Se resuelve con geocodificación inversa
 * (OpenStreetMap/Nominatim), agrupando antes por coordenada redondeada para
 * no hacer una petición por cada una de las ~2300 paradas. Cacheado en
 * geocache.json, así que solo se pide lo que aún no esté en caché.
 *
 * Redondeo a 3 decimales (~111m), no 2 (~1.1km): con 2 decimales una parada
 * de Areeta/Las Arenas (Getxo) caía en el mismo cluster que otra al otro
 * lado de la ría en Portugalete: municipios distintos.
 *
 * @return array<int, array{name:string, lat:float, lon:float, area:string}>
 */
function geocodeStops(array $stops, bool $skip): array
{
    if ($skip) {
        foreach ($stops as &$stop) {
            $stop['area'] = '';
        }
        return $stops;
    }

    $cache = [];
    if (is_file(GEOCACHE_PATH)) {
        $cache = json_decode((string)file_get_contents(GEOCACHE_PATH), true);
    }
    if (!is_array($cache)) {
        $cache = [];
    }

    $clusterKeys = [];
    foreach ($stops as $id => $stop) {
        $key = round($stop['lat'], 3) . ',' . round($stop['lon'], 3);
        $clusterKeys[$id] = $key;
    }
    $uniqueKeys = array_unique(array_values($clusterKeys));
    $missing = array_values(array_diff($uniqueKeys, array_keys($cache)));

    echo '  ' . count($uniqueKeys) . ' unique ~1km clusters, ' . count($missing) . " not yet cached\n";

    foreach ($missing as $i => $key) {
        [$lat, $lon] = explode(',', $key);
        $cache[$key] = reverseGeocode((float)$lat, (float)$lon);
        if (($i + 1) % 25 === 0 || $i + 1 === count($missing)) {
            echo '    geocoded ' . ($i + 1) . '/' . count($missing) . "\r";
            file_put_contents(GEOCACHE_PATH, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        if ($i + 1 < count($missing)) {
            usleep(1_100_000); // Nominatim: máximo 1 petición/segundo
        }
    }
    if (!empty($missing)) {
        echo "\n";
    }
    file_put_contents(GEOCACHE_PATH, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    foreach ($stops as $id => &$stop) {
        $stop['area'] = '';
        if (isset($cache[$clusterKeys[$id]])) {
            $stop['area'] = $cache[$clusterKeys[$id]];
        }
    }
    return $stops;
}

function reverseGeocode(float $lat, float $lon): string
{
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat,
        'lon' => $lon,
        'format' => 'jsonv2',
        'zoom' => 16,
        'addressdetails' => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['User-Agent: BizkaiBusPlus-etl/1.0 (' . NOMINATIM_CONTACT . ')'],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        return '';
    }
    $data = json_decode($body, true);
    $address = [];
    if (isset($data['address'])) {
        $address = $data['address'];
    }

    $neighbourhood = null;
    if (isset($address['neighbourhood'])) {
        $neighbourhood = $address['neighbourhood'];
    }
    $suburb = null;
    if (isset($address['suburb'])) {
        $suburb = $address['suburb'];
    }
    $townLevel = null;
    if (isset($address['town'])) {
        $townLevel = $address['town'];
    } elseif (isset($address['city'])) {
        $townLevel = $address['city'];
    } elseif (isset($address['village'])) {
        $townLevel = $address['village'];
    }

    $parts = array_filter([$neighbourhood, $suburb, $townLevel]);
    return implode(', ', array_unique($parts));
}

function resolveZipPath(string $source): string
{
    if (preg_match('#^https?://#i', $source)) {
        $tmp = tempnam(sys_get_temp_dir(), 'bbgtfs') . '.zip';
        $ch = curl_init($source);
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) {
            fwrite(STDERR, 'Download failed: ' . curl_error($ch) . "\n");
            exit(1);
        }
        curl_close($ch);
        fclose($fp);
        return $tmp;
    }
    if (!file_exists($source)) {
        fwrite(STDERR, "Source file not found: $source\n");
        exit(1);
    }
    return $source;
}

function normalize(string $text): string
{
    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
        'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a',
        'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o',
        'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'Ñ' => 'n', 'Ç' => 'c',
    ];
    $lower = mb_strtolower(strtr($text, $map), 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $lower));
}

/** Recorre un CSV del GTFS en streaming, devolviendo un array asociativo por fila. */
function readCsv(ZipArchive $zip, string $name): Generator
{
    $stream = $zip->getStream($name);
    if ($stream === false) {
        throw new RuntimeException("Could not open $name from zip");
    }
    $header = fgetcsv($stream, 0, ',', '"', '\\');
    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($row === null || $row === [null]) {
            continue; // línea en blanco al final
        }
        if (count($row) !== count($header)) {
            continue; // fila mal formada, se descarta
        }
        yield array_combine($header, $row);
    }
    fclose($stream);
}

function gtfsDateToIso(string $ymd): string
{
    return substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2);
}

/**
 * $expectedAgencyId = null desactiva el filtro (usado por redes cuyo
 * routes.txt no trae columna agency_id, p.ej. Metro Bilbao).
 *
 * @return array<int, array{code:string, name:string}>
 */
function loadRoutes(ZipArchive $zip, ?string $expectedAgencyId): array
{
    $routes = [];
    foreach (readCsv($zip, 'routes.txt') as $row) {
        $agencyId = '';
        if (isset($row['agency_id'])) {
            $agencyId = $row['agency_id'];
        }
        if ($expectedAgencyId !== null && $agencyId !== $expectedAgencyId) {
            fwrite(STDERR, "  WARNING: skipping route {$row['route_id']} with unexpected agency_id \"{$row['agency_id']}\"\n");
            continue;
        }
        $routeId = (int)$row['route_id'];
        $code = $row['route_short_name'];
        if ($code === '') {
            $code = $row['route_id'];
        }
        $routes[$routeId] = [
            'code' => $code,
            'name' => $row['route_long_name'],
        ];
    }
    return $routes;
}

/**
 * Filas de stops.txt que son paradas reales (location_type vacío o '0'),
 * lo demás son estaciones-padre/entradas/nodos genéricos, comunes a las dos
 * redes. Compartido porque el filtro en sí no difiere entre Bizkaibus y
 * Metro Bilbao; lo que sí difiere es cómo cada una nombra sus paradas (ver
 * loadStopsBus()/loadStopsMetro()).
 *
 * @return Generator<array<string,string>>
 */
function realStopRows(ZipArchive $zip): Generator
{
    foreach (readCsv($zip, 'stops.txt') as $row) {
        $locationType = '';
        if (isset($row['location_type'])) {
            $locationType = $row['location_type'];
        }
        if ($locationType !== '' && $locationType !== '0') {
            continue; // no es una parada real (estación/entrada/nodo genérico/andén)
        }
        yield $row;
    }
}

/**
 * stop_name del GTFS de Bizkaibus lleva siempre un sufijo mecánico
 * " (<stop_id>)" (p.ej. "KANALA (JANTOKIA) (2375)"), se quita para no
 * mostrar el id repetido. Si algún día el feed deja de traerlo, se conserva
 * el nombre tal cual (con aviso en el log) en vez de fallar.
 *
 * @return array<int, array{name:string, lat:float, lon:float}>
 */
function loadStopsBus(ZipArchive $zip): array
{
    $stops = [];
    foreach (realStopRows($zip) as $row) {
        $id = (int)$row['stop_id'];
        $name = $row['stop_name'];
        $stripped = preg_replace('/\s*\(' . preg_quote((string)$id, '/') . '\)$/', '', $name);
        if ($stripped === $name) {
            fwrite(STDERR, "  WARNING: stop $id name \"$name\" lacked the expected trailing \"($id)\" suffix\n");
        } else {
            $name = $stripped;
        }

        $stops[$id] = [
            'name' => $name,
            'lat' => (float)$row['stop_lat'],
            'lon' => (float)$row['stop_lon'],
        ];
    }
    return $stops;
}

/**
 * stop_name del GTFS de Metro Bilbao ya viene limpio, sin el sufijo
 * mecánico " (<stop_id>)" que sí trae Bizkaibus, verificado con datos
 * reales (p.ej. "Basauri", "Abando", sin ningún id al final). Aplicar la
 * misma limpieza de loadStopsBus() aquí solo generaba un warning espurio
 * por cada una de las 42 estaciones, cada build, sin corregir nada real.
 *
 * @return array<int, array{name:string, lat:float, lon:float}>
 */
function loadStopsMetro(ZipArchive $zip): array
{
    $stops = [];
    foreach (realStopRows($zip) as $row) {
        $id = (int)$row['stop_id'];
        $stops[$id] = [
            'name' => $row['stop_name'],
            'lat' => (float)$row['stop_lat'],
            'lon' => (float)$row['stop_lon'],
        ];
    }
    return $stops;
}

/**
 * Combina calendar.txt (patrón semanal base, cuando lo trae: en Bizkaibus
 * siempre está a cero, en Metro Bilbao viene relleno para parte de los
 * servicios) con calendar_dates.txt (excepciones puntuales, exception_type=1
 * añade ese día concreto). Un service_id puede existir solo en uno de los
 * dos ficheros: GTFS lo permite (verificado con datos reales de Metro
 * Bilbao: 11 de sus 15 service_ids usados en trips.txt no tienen fila en
 * calendar.txt, solo fechas sueltas en calendar_dates.txt), así que hay que
 * recorrer la unión de ambos, no solo los service_id de calendar.txt.
 *
 * $generalizeSingleDatesToWeekday controla qué se hace con un service_id
 * SIN fila en calendar.txt (sin patrón semanal declarado por el operador):
 * true (Bizkaibus) generaliza sus fechas puntuales a "este día de la semana,
 * siempre": correcto ahí porque calendar.txt de Bizkaibus está siempre a
 * cero y TODA la información real viene de decenas de fechas puntuales que
 * sí forman un patrón semanal recurrente genuino (p.ej. "todos los lunes de
 * julio a septiembre"). false (Metro Bilbao) NO generaliza, verificado con
 * datos reales que servicios de Aste Nagusia (astnag1d_26.pex) traen UNA
 * sola fecha puntual (23 de agosto de 2026) sin ninguna fila en
 * calendar.txt; generalizarla a "todos los domingos" los hacía aparecer en
 * cualquier domingo del año (incluidos meses después de las fiestas) y, al
 * mismo tiempo, el horario nocturno ampliado de esa fecha concreta quedaba
 * indistinguible del servicio normal de cualquier otro domingo. En vez de
 * eso, esas fechas puntuales se guardan como excepciones de inclusión
 * exactas (ver excludedDates/includedDates más abajo) y el propio
 * weekdayMask del calendario queda en 0: solo activo esas fechas.
 */
function loadCalendars(ZipArchive $zip, bool $generalizeSingleDatesToWeekday): array
{
    $ranges = [];
    $baseWeekdayMask = [];
    $weekdayColumns = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    foreach (readCsv($zip, 'calendar.txt') as $row) {
        $ranges[$row['service_id']] = [
            'from' => gtfsDateToIso($row['start_date']),
            'to' => gtfsDateToIso($row['end_date']),
        ];
        $mask = 0;
        foreach ($weekdayColumns as $i => $column) {
            $columnValue = 0;
            if (isset($row[$column])) {
                $columnValue = (int)$row[$column];
            }
            if ($columnValue === 1) {
                $mask |= (1 << $i);
            }
        }
        $baseWeekdayMask[$row['service_id']] = $mask;
    }

    $activeDates = [];
    foreach (readCsv($zip, 'calendar_dates.txt') as $row) {
        $date = gtfsDateToIso($row['date']);
        $activeDates[$row['service_id']][$date] = ((int)$row['exception_type']) === 1;
    }

    $calendars = [];
    foreach (array_unique(array_merge(array_keys($ranges), array_keys($activeDates))) as $id) {
        $dates = [];
        if (isset($activeDates[$id])) {
            $dates = $activeDates[$id];
        }
        $baseMask = 0;
        $hasCalendarRow = isset($baseWeekdayMask[$id]);
        if ($hasCalendarRow) {
            $baseMask = $baseWeekdayMask[$id];
        }

        // Solo se generalizan a "este día de la semana, siempre" las fechas
        // puntuales de un service_id SIN fila propia en calendar.txt cuando
        // $generalizeSingleDatesToWeekday lo permite (ver docblock). Un
        // service_id CON fila en calendar.txt ya declaró su propio patrón
        // semanal explícitamente, sus fechas puntuales en calendar_dates.txt
        // son excepciones sobre ESE patrón (añadir/quitar días sueltos), no
        // una fuente alternativa de patrón semanal, así que siempre se
        // generalizan igual en ambas redes.
        $weekdayMask = $baseMask;
        if ($hasCalendarRow || $generalizeSingleDatesToWeekday) {
            $weekdayMask |= computeWeekdayMask($dates);
        }

        $from = '';
        $to = '';
        if (isset($ranges[$id])) {
            $from = $ranges[$id]['from'];
            $to = $ranges[$id]['to'];
        }

        // Fechas de calendar_dates.txt con exception_type=2 (día concreto EN
        // que este servicio, aunque su weekday_mask lo cubra, NO circula:
        // p.ej. un service de obras que corre "todos los sábados" pero el
        // operador excluye dos sábados sueltos por cambio de planificación).
        // weekday_mask no puede representar esto por sí solo, así que se
        // guarda la lista de fechas excluidas aparte.
        $excludedDates = [];
        // Fechas de exception_type=1 para un service_id SIN fila en
        // calendar.txt, cuando NO se generalizan a weekday_mask (metro): se
        // guardan como inclusiones puntuales exactas: el servicio solo
        // está activo esas fechas concretas, no "ese día de la semana
        // siempre". Ver ServiceJourney::upcomingAtStop()/timetableForLine(),
        // que comprueban esta tabla con available=1 como alternativa al
        // weekday_mask (que aquí se queda en 0).
        $includedDates = [];
        foreach ($dates as $date => $isAvailable) {
            if (!$isAvailable) {
                $excludedDates[] = $date;
            } elseif (!$hasCalendarRow && !$generalizeSingleDatesToWeekday) {
                $includedDates[] = $date;
            }
        }

        $calendars[$id] = [
            'from' => $from,
            'to' => $to,
            'weekdayMask' => $weekdayMask,
            'activeDateCount' => count(array_filter($dates)),
            'excludedDates' => $excludedDates,
            'includedDates' => $includedDates,
        ];
    }
    return $calendars;
}

/** Calcula en qué días de la semana ISO (1=lunes..7=domingo) cae este calendario. */
function computeWeekdayMask(array $dateAvailability): int
{
    $mask = 0;
    foreach ($dateAvailability as $date => $isAvailable) {
        if (!$isAvailable) {
            continue;
        }
        $weekday = (int)(new DateTime($date))->format('N');
        $mask |= (1 << ($weekday - 1));
    }
    return $mask;
}

/**
 * trip_number es el segundo token del trip_id de Bizkaibus (formato
 * "trp_A123_456_..."), usado para el matching con el feed SIRI en vivo y
 * como salvaguarda extra al fusionar variantes de calendario del mismo viaje
 * (ver processStopTimes()). Metro Bilbao no tiene tiempo real y su trip_id es
 * un simple entero ("876714") sin ese formato, la regex no matchea, así que
 * queda null; processStopTimes() ya sabe agrupar solo por (línea, patrón) en
 * ese caso.
 *
 * @return array<string, array{routeId:int, serviceId:string, headsign:string, tripNumber:?string}>
 */
function loadTrips(ZipArchive $zip): array
{
    $trips = [];
    foreach (readCsv($zip, 'trips.txt') as $row) {
        $tripId = $row['trip_id'];
        $tripNumber = null;
        if (preg_match('/^trp_[A-Za-z]*\d+_(\d+)_/', $tripId, $m)) {
            $tripNumber = $m[1];
        }
        $headsign = '';
        if (isset($row['trip_headsign'])) {
            $headsign = $row['trip_headsign'];
        }
        $trips[$tripId] = [
            'routeId' => (int)$row['route_id'],
            'serviceId' => $row['service_id'],
            'headsign' => $headsign,
            'tripNumber' => $tripNumber,
        ];
    }
    return $trips;
}

/**
 * Recorre stop_times.txt (hasta ~114MB / ~1.15M filas en Bizkaibus) y agrupa
 * todas las filas de cada trip_id, devolviendo un grupo [tripId, filas] por
 * cada trip_id distinto.
 *
 * NO asume que el fichero viene ordenado con cada trip_id en un bloque
 * contiguo, verificado con datos reales de Metro Bilbao que NO es así: el
 * mismo trip_id puede reaparecer en bloques separados a lo largo del
 * fichero. Una versión anterior de este código sí asumía contigüidad
 * (cerraba el grupo en cuanto veía cambiar el trip_id) y descartaba como
 * "duplicado" cualquier reaparición posterior del mismo trip_id, perdiendo
 * en silencio las paradas de ese segundo bloque. Eso producía
 * journey_patterns truncados (p.ej. un trip real de 29 paradas quedaba
 * registrado con solo las 22 primeras) que además, por mala suerte,
 * coincidían en representante con otros trips que sí tenían la secuencia
 * completa, mostrando el mismo destino repetido dos veces a la misma hora
 * con recorridos de longitud distinta. Por eso aquí se agrupa por trip_id de
 * verdad (un array indexado por trip_id) antes de generar nada.
 *
 * @return Generator<string, array<int, array{seqOrder:int, stopId:int, arrival:?int, departure:?int}>>
 */
function streamStopTimesByTrip(ZipArchive $zip): Generator
{
    $stream = $zip->getStream('stop_times.txt');
    if ($stream === false) {
        fwrite(STDERR, "Could not open stop_times.txt stream\n");
        exit(1);
    }
    $header = fgetcsv($stream, 0, ',', '"', '\\');

    $byTrip = [];

    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($row === null || $row === [null] || count($row) !== count($header)) {
            continue;
        }
        $assoc = array_combine($header, $row);
        $tripId = $assoc['trip_id'];

        $arrival = null;
        if ($assoc['arrival_time'] !== '') {
            $arrival = timeToSeconds($assoc['arrival_time']);
        }
        $departure = $arrival;
        if ($assoc['departure_time'] !== '') {
            $departure = timeToSeconds($assoc['departure_time']);
        }
        if ($arrival === null) {
            $arrival = $departure;
        }

        $byTrip[$tripId][] = [
            'seqOrder' => (int)$assoc['stop_sequence'],
            'stopId' => (int)$assoc['stop_id'],
            'arrival' => $arrival,
            'departure' => $departure,
        ];
    }
    fclose($stream);

    foreach ($byTrip as $tripId => $buffer) {
        yield $tripId => $buffer;
        unset($byTrip[$tripId]); // libera memoria trip a trip, no todo de golpe al final
    }
}

/**
 * El GTFS da un trip_id por variante de calendario, así que el mismo viaje
 * real (misma línea, mismo trip_number, mismas paradas, misma hora) aparece
 * repetido, una vez por cada tipo de día en que circula (p.ej. el
 * trip_number 921 de la A3526 aparece 7 veces). La app ya lo deduplicaba en
 * consulta (ServiceJourney::dedupeByTrip()), pero guardar todas las filas
 * era pura redundancia: ~4.5x más filas de las necesarias, lo que hacía que
 * la base de datos superara el límite de 100MB de Vercel (164.7MB sin
 * fusionar).
 *
 * Se fusiona ya en el build: se agrupan los viajes por (línea, trip_number,
 * hash de la secuencia de paradas, primera salida): el hash es lo que evita
 * fusionar un desvío real (p.ej. el de Elantxobe en verano) con la versión
 * normal del "mismo" trip_number, y se hace OR de las máscaras semanales de
 * cada variante en un calendario sintético. Solo el viaje representante de
 * cada grupo inserta sus passing_times; el resto solo aporta su máscara.
 */
/**
 * Clave extra para separar, dentro de un mismo (línea, trip_number, patrón),
 * viajes que NO deben fusionarse entre sí aunque su horario coincida dentro
 * del margen de clustering, porque no son variantes de calendario del
 * mismo viaje real, solo una coincidencia de horario entre campañas
 * independientes. Vacío = sin restricción extra (se fusiona como siempre).
 *
 * Solo aplica a metro, en dos casos:
 *
 * 1) service_id con from_date/to_date explícito en calendar.txt
 *    (obranegvia1_*): campañas de vigencia acotada (obras, desvíos
 *    estacionales). Verificado con datos reales que un tren de obras de
 *    domingo se fusionaba con uno de Aste Nagusia porque ambos, tras el OR
 *    final de máscaras del grupo, caían a <90s de diferencia; el tren de
 *    obras terminaba pareciendo "todos los días" en vez de solo domingos,
 *    colándose en días que no le tocan.
 *
 * 2) service_id sin fila en calendar.txt cuya única presencia es una o
 *    pocas fechas puntuales en calendar_dates.txt (weekdayMask=0, ver
 *    loadCalendars()/$generalizeSingleDatesToWeekday): cada uno de estos
 *    (p.ej. astnag1d_26.pex = solo 23 de agosto de 2026, astnag2l_26.pex =
 *    solo el 24) es una fecha real distinta. Verificado que sin separarlos,
 *    el trip de un día de Aste Nagusia se fusionaba con el de otro día
 *    porque ambos, con weekdayMask=0, caían dentro de la ventana de
 *    clustering: el representante elegido se quedaba con el calendar_id
 *    de UN solo día, y las fechas de inclusión puntual de los demás
 *    service_id fusionados se perdían sin más (solo el OR de weekday_mask
 *    se propaga entre miembros del grupo, no las fechas de inclusión
 *    individuales): el horario especial de un día concreto desaparecía
 *    por completo en vez de solo mostrarse ese día.
 *
 * Bus NO usa esta regla: ahí casi todos sus 94+ calendarios (94/105
 * verificado) tienen from_date/to_date por cómo el operador publica sus
 * temporadas, y su weekdayMask siempre se generaliza desde fechas puntuales
 * (comportamiento correcto y necesario ahí, ver
 * $generalizeSingleDatesToWeekday), aplicar el caso 1) deshace casi toda
 * la fusión legítima entre variantes reales del mismo viaje (43803 trips →
 * 43705 journeys en vez de ~6673, 153MB en vez de ~24MB, por encima del
 * límite de 100MB de Vercel). Bus ya tiene una señal fuerte y correcta para
 * esto (trip_number, ver más abajo), que metro no tiene.
 */
function calendarGroupKeyFor(string $network, string $serviceId, array $calendars): string
{
    if ($network !== 'metro') {
        return '';
    }
    if (!isset($calendars[$serviceId])) {
        return '';
    }
    $cal = $calendars[$serviceId];
    if ($cal['from'] !== '') {
        return $serviceId;
    }
    if ($cal['weekdayMask'] === 0 && !empty($cal['includedDates'])) {
        return $serviceId;
    }
    return '';
}

function processStopTimes(PDO $pdo, ZipArchive $zip, array $trips, array $routes, array $calendars, array &$totals, string $network): void
{
    echo "  Pass 1/2: computing trip signatures and merge groups...\n";

    $signatures = [];
    $seenTripIds = [];
    $skippedUnknownTrip = 0;
    $skippedUnknownRoute = 0;
    $skippedDuplicateTrip = 0;

    foreach (streamStopTimesByTrip($zip) as $tripId => $buffer) {
        if (isset($seenTripIds[$tripId])) {
            $skippedDuplicateTrip++;
            continue;
        }
        $seenTripIds[$tripId] = true;

        $trip = null;
        if (isset($trips[$tripId])) {
            $trip = $trips[$tripId];
        }
        if ($trip === null) {
            $skippedUnknownTrip++;
            continue;
        }
        if (!isset($routes[$trip['routeId']])) {
            $skippedUnknownRoute++;
            continue;
        }

        usort($buffer, fn($a, $b) => $a['seqOrder'] <=> $b['seqOrder']);
        $stopIds = array_column($buffer, 'stopId');
        $patternKey = 'gp_' . $trip['routeId'] . '_' . substr(md5(implode(',', $stopIds)), 0, 12);
        $firstDeparture = $buffer[0]['arrival'];
        if (isset($buffer[0]['departure'])) {
            $firstDeparture = $buffer[0]['departure'];
        }

        $weekdayMask = 0;
        if (isset($calendars[$trip['serviceId']])) {
            $weekdayMask = $calendars[$trip['serviceId']]['weekdayMask'];
        }
        $calendarGroupKey = calendarGroupKeyFor($network, $trip['serviceId'], $calendars);

        $signatures[$tripId] = [
            'routeId' => $trip['routeId'],
            'tripNumber' => $trip['tripNumber'],
            'headsign' => $trip['headsign'],
            'patternKey' => $patternKey,
            'firstDeparture' => $firstDeparture,
            'weekdayMask' => $weekdayMask,
            'calendarGroupKey' => $calendarGroupKey,
        ];
    }

    // Se agrupa por (línea, trip_number, patrón) y luego se agrupan las
    // salidas cercanas entre sí (con OR de las máscaras). El mismo viaje
    // real puede tener un first_departure_seconds ligeramente distinto entre
    // variantes de calendario (hasta ~80s de diferencia verificados). Un
    // match exacto no las fusiona y salían como duplicados visuales en la
    // lista de salidas.
    //
    // Redondear a un grid fijo tampoco vale: dos valores a 80s pueden caer
    // en buckets distintos si la línea del grid cae entre medias (verificado:
    // 65200 y 65280 en buckets de 120s distintos). Por eso se agrupa por
    // hueco real: se ordenan las salidas de cada grupo y solo se abre un
    // cluster nuevo cuando el hueco con la anterior supera la tolerancia.
    //
    // trip_number es null cuando el trip_id de la red no trae ese formato
    // (Metro Bilbao): en ese caso se agrupa solo por (línea, patrón); el
    // trip_id de Metro Bilbao ya es único por variante de calendario (no se
    // repite como en Bizkaibus), así que trip_number no aporta nada como
    // salvaguarda extra ahí y solo haría que cada variante quedara en su
    // propio grupo sin fusionar.
    $departureClusterGapSeconds = 90;

    $byRoutePattern = [];
    foreach ($signatures as $tripId => $sig) {
        $tripNumberPart = '';
        if (isset($sig['tripNumber'])) {
            $tripNumberPart = $sig['tripNumber'];
        }
        // calendarGroupKey separa campañas de vigencia acotada (obras,
        // desvíos estacionales) entre sí y del servicio base, ver el
        // comentario donde se calcula, más arriba. Vacío para el servicio
        // base y para excepciones puntuales sin from_date/to_date propio,
        // que sí deben poder fusionarse entre ellas como hasta ahora.
        $key = $sig['routeId'] . '|' . $tripNumberPart . '|' . $sig['patternKey'] . '|' . $sig['calendarGroupKey'];
        $byRoutePattern[$key][] = $tripId;
    }

    $groups = [];
    foreach ($byRoutePattern as $tripIds) {
        usort($tripIds, fn($a, $b) => $signatures[$a]['firstDeparture'] <=> $signatures[$b]['firstDeparture']);

        $clusterKey = null;
        $previousDeparture = null;
        foreach ($tripIds as $tripId) {
            $sig = $signatures[$tripId];
            if ($previousDeparture === null || ($sig['firstDeparture'] - $previousDeparture) > $departureClusterGapSeconds) {
                $clusterKey = $tripId; // el primer viaje del cluster le da nombre
                $groups[$clusterKey] = $sig + ['representativeTripId' => $tripId, 'weekdayMask' => 0];
            }
            $groups[$clusterKey]['weekdayMask'] |= $sig['weekdayMask'];
            $previousDeparture = $sig['firstDeparture'];
        }
    }

    // Se reutiliza un calendario real existente si su máscara ya coincide;
    // solo se crea uno sintético "merged_<mask>" si no hay ninguno. Nunca se
    // reutiliza 'PRUEBA': es un service_id real (no un dummy vacío tipo
    // NeTEx) que ServiceJourney.php excluye siempre, y su weekday_mask es
    // 127 (todos los días). Sin esta exclusión, cualquier grupo fusionado
    // que también saliera "todos los días" heredaba calendar_id 'PRUEBA' y
    // desaparecía de toda consulta, pasaba en 2.110 de 6.966 viajes (30%)
    // antes de este fix.
    //
    // Tampoco se reutiliza ningún calendario con from_date/to_date propio
    // (campañas de vigencia acotada como obras/desvíos, ver
    // calendarGroupKeyFor()), verificado con datos reales de metro que
    // varios grupos de Aste Nagusia (astnag1d_26.pex, sin fecha, servicio
    // normal) terminaban heredando el calendar_id de un calendario de obras
    // acotado (obranegvia1_invd_26.pex, 22 ago-22 sep) solo porque ambos
    // compartían la misma weekday_mask (domingo): el tren de Aste Nagusia
    // quedaba invisible fuera de ese mes de obras sin ninguna razón real.
    $maskToCalendarId = [];
    foreach ($calendars as $calId => $cal) {
        if ($calId === 'PRUEBA') {
            continue;
        }
        if ($cal['from'] !== '') {
            continue;
        }
        if (!isset($maskToCalendarId[$cal['weekdayMask']])) {
            $maskToCalendarId[$cal['weekdayMask']] = $calId;
        }
    }
    $syntheticCalendars = [];
    $representatives = [];
    foreach ($groups as $group) {
        // Un grupo que viene de un calendario de vigencia acotada
        // (calendarGroupKey no vacío, ver calendarGroupKeyFor()) conserva
        // SIEMPRE su propio service_id original como calendar_id final,
        // nunca reutiliza ni un calendario real de otro grupo ni crea uno
        // sintético. Solo el service_id original tiene las filas de
        // service_calendar_exceptions y el from_date/to_date correctos;
        // reutilizar otro calendario con la misma weekday_mask (p.ej. un
        // calendario de Aste Nagusia que también cae en domingo) le hacía
        // perder sus exclusiones puntuales y aparecer en días donde el
        // propio operador había dicho explícitamente que no circulaba.
        if ($group['calendarGroupKey'] !== '') {
            $group['calendarId'] = $group['calendarGroupKey'];
            $representatives[$group['representativeTripId']] = $group;
            continue;
        }

        $mask = $group['weekdayMask'];
        if (!isset($maskToCalendarId[$mask])) {
            $newId = 'merged_' . $mask;
            $maskToCalendarId[$mask] = $newId;
            $syntheticCalendars[$newId] = $mask;
        }
        $group['calendarId'] = $maskToCalendarId[$mask];
        $representatives[$group['representativeTripId']] = $group;
    }

    printf("  %d raw trips merged into %d distinct journeys (%d synthetic calendars for OR'd weekday masks)\n", count($signatures), count($groups), count($syntheticCalendars));

    if (!empty($syntheticCalendars)) {
        $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
        foreach ($syntheticCalendars as $id => $mask) {
            $stmt->execute([$id, '', '', $mask]);
        }
    }

    echo "  Pass 2/2: inserting merged journeys + passing_times...\n";

    $insertPattern = $pdo->prepare('INSERT OR IGNORE INTO journey_patterns (id, line_id, headsign) VALUES (?, ?, ?)');
    $insertPatternStop = $pdo->prepare('INSERT INTO journey_pattern_stops (journey_pattern_id, seq_order, stop_id) VALUES (?, ?, ?)');
    $insertJourney = $pdo->prepare('INSERT OR IGNORE INTO service_journeys (id, line_id, journey_pattern_id, trip_number, calendar_id, first_departure_seconds) VALUES (?, ?, ?, ?, ?, ?)');
    $insertPassingTime = $pdo->prepare('INSERT INTO passing_times (service_journey_id, seq_order, stop_id, arrival_seconds, departure_seconds) VALUES (?, ?, ?, ?, ?)');
    $seenPatterns = [];
    $rowCount = 0;

    foreach (streamStopTimesByTrip($zip) as $tripId => $buffer) {
        $group = null;
        if (isset($representatives[$tripId])) {
            $group = $representatives[$tripId];
        }
        if ($group === null) {
            continue; // no es el representante elegido de su grupo
        }

        usort($buffer, fn($a, $b) => $a['seqOrder'] <=> $b['seqOrder']);
        $patternKey = $group['patternKey'];

        if (!isset($seenPatterns[$patternKey])) {
            $seenPatterns[$patternKey] = true;
            $insertPattern->execute([$patternKey, $group['routeId'], $group['headsign']]);
            foreach ($buffer as $row) {
                $insertPatternStop->execute([$patternKey, $row['seqOrder'], $row['stopId']]);
            }
            $totals['patterns']++;
        }

        // trip_number es NULL cuando la red no tiene el formato de trip_id de
        // Bizkaibus (ver loadTrips()), sin él, dos service_journeys distintos
        // con la misma first_departure_seconds serían indistinguibles para
        // findByLineAndTrip()/tripKey en el frontend. $tripId aquí es el
        // representative trip id del grupo fusionado (único por journey), así
        // que sirve de identificador estable cuando no hay trip_number real.
        $tripNumber = $group['tripNumber'];
        if ($tripNumber === null) {
            $tripNumber = $tripId;
        }
        $insertJourney->execute([$tripId, $group['routeId'], $patternKey, $tripNumber, $group['calendarId'], $group['firstDeparture']]);
        $totals['journeys']++;

        foreach ($buffer as $row) {
            $insertPassingTime->execute([$tripId, $row['seqOrder'], $row['stopId'], $row['arrival'], $row['departure']]);
            $totals['passingTimes']++;
        }

        $rowCount += count($buffer);
        if ($rowCount % 50000 < 40) {
            printf("  processed ~%d passing_times rows\r", $rowCount);
        }
    }
    echo "\n";

    if ($skippedUnknownTrip > 0) {
        fwrite(STDERR, "  WARNING: $skippedUnknownTrip stop_times groups skipped (trip_id not found in trips.txt)\n");
    }
    if ($skippedUnknownRoute > 0) {
        fwrite(STDERR, "  WARNING: $skippedUnknownRoute stop_times groups skipped (route_id not found in routes.txt)\n");
    }
    if ($skippedDuplicateTrip > 0) {
        fwrite(STDERR, "  WARNING: $skippedDuplicateTrip duplicate/non-contiguous trip_id groups skipped\n");
    }
}

function timeToSeconds(string $hms): int
{
    [$h, $m, $s] = array_map('intval', explode(':', $hms));
    return $h * 3600 + $m * 60 + $s;
}

function createSchema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE stops (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL,
            area TEXT NOT NULL DEFAULT \'\',
            area_normalized TEXT NOT NULL DEFAULT \'\',
            lat REAL NOT NULL,
            lon REAL NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE lines (
            id INTEGER PRIMARY KEY,
            code TEXT NOT NULL,
            name TEXT NOT NULL,
            name_normalized TEXT NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE journey_patterns (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            headsign TEXT
        )
    ');
    $pdo->exec('
        CREATE TABLE journey_pattern_stops (
            journey_pattern_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE service_journeys (
            id TEXT PRIMARY KEY,
            line_id INTEGER NOT NULL,
            journey_pattern_id TEXT NOT NULL,
            trip_number TEXT,
            calendar_id TEXT NOT NULL,
            first_departure_seconds INTEGER
        )
    ');
    $pdo->exec('
        CREATE TABLE passing_times (
            service_journey_id TEXT NOT NULL,
            seq_order INTEGER NOT NULL,
            stop_id INTEGER NOT NULL,
            arrival_seconds INTEGER,
            departure_seconds INTEGER
        )
    ');
    $pdo->exec('
        CREATE TABLE service_calendars (
            id TEXT PRIMARY KEY,
            from_date TEXT NOT NULL,
            to_date TEXT NOT NULL,
            weekday_mask INTEGER NOT NULL
        )
    ');
    // Excepciones puntuales de calendar_dates.txt: un service_id puede tener
    // días sueltos añadidos (exception_type=1, ya cubiertos por weekday_mask
    // vía computeWeekdayMask) o RESTADOS (exception_type=2) sobre su patrón
    // semanal base, esto último no se puede representar con weekday_mask +
    // rango de fechas solo, así que se guarda aparte. Solo interesan las
    // exclusiones (available=0); las de tipo 1 puntuales que no formen parte
    // ya del weekday_mask base tampoco se guardan aquí, se resuelven con
    // OR en computeWeekdayMask() como siempre. Ver ServiceJourney::isDateExcluded().
    $pdo->exec('
        CREATE TABLE service_calendar_exceptions (
            calendar_id TEXT NOT NULL,
            date TEXT NOT NULL,
            available INTEGER NOT NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ');
}

/**
 * feed_version de feed_info.txt es la fecha en que se generó este GTFS
 * concreto (p.ej. "20260716"), se lee de aquí en vez de fijarla a mano en
 * config.php, que es justo el tipo de dato desfasado que dejó el build con
 * NeTEx mal durante año y medio sin que nadie lo notara.
 *
 * @return array<string, string>
 */
function loadFeedInfo(ZipArchive $zip): array
{
    // feed_info.txt es opcional en GTFS: Metro Bilbao no lo publica (Bizkaibus sí).
    if ($zip->locateName('feed_info.txt') === false) {
        return [];
    }
    foreach (readCsv($zip, 'feed_info.txt') as $row) {
        $feedVersion = '';
        if (isset($row['feed_version'])) {
            $feedVersion = $row['feed_version'];
        }
        $feedStartDate = '';
        if (isset($row['feed_start_date'])) {
            $feedStartDate = $row['feed_start_date'];
        }
        $feedEndDate = '';
        if (isset($row['feed_end_date'])) {
            $feedEndDate = $row['feed_end_date'];
        }
        return [
            'feed_version' => $feedVersion,
            'feed_start_date' => $feedStartDate,
            'feed_end_date' => $feedEndDate,
        ];
    }
    return [];
}

function insertMeta(PDO $pdo, array $feedInfo): void
{
    $publishedDate = date('Y-m-d');
    if (isset($feedInfo['feed_version']) && preg_match('/^\d{8}$/', $feedInfo['feed_version'])) {
        $publishedDate = gtfsDateToIso($feedInfo['feed_version']);
    }

    $stmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?)');
    $stmt->execute(['schedule_source_published', $publishedDate]);
    if (isset($feedInfo['feed_start_date']) && $feedInfo['feed_start_date'] !== '') {
        $stmt->execute(['feed_start_date', gtfsDateToIso($feedInfo['feed_start_date'])]);
    }
    if (isset($feedInfo['feed_end_date']) && $feedInfo['feed_end_date'] !== '') {
        $stmt->execute(['feed_end_date', gtfsDateToIso($feedInfo['feed_end_date'])]);
    }
}

function createIndexes(PDO $pdo): void
{
    $pdo->exec('CREATE INDEX idx_passing_times_stop ON passing_times (stop_id, departure_seconds)');
    $pdo->exec('CREATE INDEX idx_passing_times_journey ON passing_times (service_journey_id)');
    $pdo->exec('CREATE INDEX idx_journeys_line ON service_journeys (line_id, trip_number, first_departure_seconds)');
    $pdo->exec('CREATE INDEX idx_journeys_calendar ON service_journeys (calendar_id)');
    $pdo->exec('CREATE INDEX idx_pattern_stops ON journey_pattern_stops (journey_pattern_id, seq_order)');
    $pdo->exec('CREATE INDEX idx_stops_normalized ON stops (name_normalized)');
    $pdo->exec('CREATE INDEX idx_lines_normalized ON lines (name_normalized)');
    $pdo->exec('CREATE INDEX idx_calendar_exceptions ON service_calendar_exceptions (calendar_id, date)');
}

function insertStops(PDO $pdo, array $stops): void
{
    $stmt = $pdo->prepare('INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stops as $id => $stop) {
        $area = '';
        if (isset($stop['area'])) {
            $area = $stop['area'];
        }
        $stmt->execute([$id, $stop['name'], normalize($stop['name']), $area, normalize($area), $stop['lat'], $stop['lon']]);
    }
}

function insertCalendars(PDO $pdo, array $calendars): void
{
    $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
    $excludeStmt = $pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 0)');
    $includeStmt = $pdo->prepare('INSERT INTO service_calendar_exceptions (calendar_id, date, available) VALUES (?, ?, 1)');
    foreach ($calendars as $id => $cal) {
        $stmt->execute([$id, $cal['from'], $cal['to'], $cal['weekdayMask']]);
        foreach ($cal['excludedDates'] as $date) {
            $excludeStmt->execute([$id, $date]);
        }
        foreach ($cal['includedDates'] as $date) {
            $includeStmt->execute([$id, $date]);
        }
    }
}

main(array_slice($argv, 1));
