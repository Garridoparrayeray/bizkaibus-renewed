<?php
/**
 * Genera data/bizkaibus.sqlite o data/metrobilbao.sqlite a partir del export
 * GTFS oficial de cada red.
 *
 * Uso:
 *   php scripts/build-database.php [--network=bus|metro] [--source=<ruta-o-url>] [--output=<ruta>]
 *
 * Por defecto --network=bus (Bizkaibus, feed GTFS de Lantik/CTB, que sí se
 * mantiene actualizado — a diferencia del NeTEx que usaba antes esta app,
 * congelado desde enero 2025). --network=metro usa el GTFS oficial de Metro
 * Bilbao (cms.metrobilbao.eus), mucho más pequeño (~2.4MB, 42 estaciones) y
 * sin necesidad de geocodificación (los nombres de estación ya son reales).
 * Por eso conviene re-ejecutar este script periódicamente para cada red.
 *
 * Los campos de días de la semana de calendar.txt de Bizkaibus están siempre
 * a cero — el calendario real sale de las fechas explícitas de
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
        // agencia, "Metro Bilbao") — null desactiva el filtro por agencia.
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
    $network = $options['network'] ?? 'bus';
    if (!isset(NETWORK_DEFAULTS[$network])) {
        fwrite(STDERR, "Unknown --network=\"$network\" (expected bus|metro)\n");
        exit(1);
    }
    $defaults = NETWORK_DEFAULTS[$network];

    $source = $options['source'] ?? $defaults['source'];
    $output = $options['output'] ?? $defaults['output'];
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
    $stops = loadStops($zip);
    echo '  ' . count($stops) . " stops\n";

    echo "Resolving municipality/neighbourhood names (OpenStreetMap reverse geocoding, cached)...\n";
    $stops = geocodeStops($stops, $skipGeocode);

    echo "Parsing calendar.txt / calendar_dates.txt...\n";
    $calendars = loadCalendars($zip);
    echo '  ' . count($calendars) . " service calendars\n";

    echo "Parsing trips.txt...\n";
    $trips = loadTrips($zip);
    echo '  ' . count($trips) . " trips\n";

    $feedInfo = loadFeedInfo($zip);
    if (isset($feedInfo['feed_version'])) {
        echo "  feed_version: {$feedInfo['feed_version']} (start: {$feedInfo['feed_start_date']}, end: {$feedInfo['feed_end_date']})\n";
    }

    // Feed caducado (el operador aún no publicó la siguiente temporada) — abortar en vez de desplegar datos viejos.
    $feedEndIso = !empty($feedInfo['feed_end_date']) ? gtfsDateToIso($feedInfo['feed_end_date']) : '';
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

    echo "Processing stop_times.txt (the big one, ~1.1M rows — two bounded-memory passes)...\n";
    $totals = ['patterns' => 0, 'journeys' => 0, 'passingTimes' => 0];
    processStopTimes($pdo, $zip, $trips, $routes, $calendars, $totals);

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
 * El GTFS tampoco trae municipio/barrio (stop_desc viene vacío) — solo
 * nombre de calle y coordenadas. Se resuelve con geocodificación inversa
 * (OpenStreetMap/Nominatim), agrupando antes por coordenada redondeada para
 * no hacer una petición por cada una de las ~2300 paradas. Cacheado en
 * geocache.json, así que solo se pide lo que aún no esté en caché.
 *
 * Redondeo a 3 decimales (~111m), no 2 (~1.1km): con 2 decimales una parada
 * de Areeta/Las Arenas (Getxo) caía en el mismo cluster que otra al otro
 * lado de la ría en Portugalete — municipios distintos.
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

    $cache = is_file(GEOCACHE_PATH) ? json_decode((string)file_get_contents(GEOCACHE_PATH), true) : [];
    $cache = is_array($cache) ? $cache : [];

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
        $stop['area'] = $cache[$clusterKeys[$id]] ?? '';
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
    $address = $data['address'] ?? [];
    $parts = array_filter([
        $address['neighbourhood'] ?? null,
        $address['suburb'] ?? null,
        $address['town'] ?? $address['city'] ?? $address['village'] ?? null,
    ]);
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
        if ($expectedAgencyId !== null && ($row['agency_id'] ?? '') !== $expectedAgencyId) {
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
 * stop_name del GTFS lleva siempre un sufijo mecánico " (<stop_id>)" (p.ej.
 * "KANALA (JANTOKIA) (2375)") — se quita para no mostrar el id repetido. Si
 * algún día el feed deja de traerlo, se conserva el nombre tal cual (con
 * aviso en el log) en vez de fallar.
 *
 * @return array<int, array{name:string, lat:float, lon:float}>
 */
function loadStops(ZipArchive $zip): array
{
    $stops = [];
    foreach (readCsv($zip, 'stops.txt') as $row) {
        $locationType = $row['location_type'] ?? '';
        if ($locationType !== '' && $locationType !== '0') {
            continue; // no es una parada real (estación/entrada/nodo genérico/andén)
        }

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
 * Combina calendar.txt (patrón semanal base, cuando lo trae — en Bizkaibus
 * siempre está a cero, en Metro Bilbao viene relleno para parte de los
 * servicios) con calendar_dates.txt (excepciones puntuales, exception_type=1
 * añade ese día concreto). Un service_id puede existir solo en uno de los
 * dos ficheros — GTFS lo permite (verificado con datos reales de Metro
 * Bilbao: 11 de sus 15 service_ids usados en trips.txt no tienen fila en
 * calendar.txt, solo fechas sueltas en calendar_dates.txt) — así que hay que
 * recorrer la unión de ambos, no solo los service_id de calendar.txt.
 */
function loadCalendars(ZipArchive $zip): array
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
            if ((int)($row[$column] ?? 0) === 1) {
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
        $dates = $activeDates[$id] ?? [];
        $weekdayMask = ($baseWeekdayMask[$id] ?? 0) | computeWeekdayMask($dates);
        $calendars[$id] = [
            'from' => $ranges[$id]['from'] ?? '',
            'to' => $ranges[$id]['to'] ?? '',
            'weekdayMask' => $weekdayMask,
            'activeDateCount' => count(array_filter($dates)),
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
 * un simple entero ("876714") sin ese formato — la regex no matchea, así que
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
        $trips[$tripId] = [
            'routeId' => (int)$row['route_id'],
            'serviceId' => $row['service_id'],
            'headsign' => $row['trip_headsign'] ?? '',
            'tripNumber' => $tripNumber,
        ];
    }
    return $trips;
}

/**
 * Recorre stop_times.txt (114MB / ~1.15M filas) en streaming, guardando solo
 * las filas del trip_id actual (el fichero viene agrupado por trip_id) y
 * devolviendo un grupo [tripId, filas] cada vez. Nunca carga el fichero
 * entero en memoria. Se usa en las dos pasadas de processStopTimes(), así
 * que el fichero se lee dos veces en vez de guardarlo entero una sola vez.
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

    $currentTripId = null;
    $buffer = [];

    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
        if ($row === null || $row === [null] || count($row) !== count($header)) {
            continue;
        }
        $assoc = array_combine($header, $row);
        $tripId = $assoc['trip_id'];

        if ($tripId !== $currentTripId) {
            if ($currentTripId !== null) {
                yield $currentTripId => $buffer;
            }
            $currentTripId = $tripId;
            $buffer = [];
        }

        $arrival = $assoc['arrival_time'] !== '' ? timeToSeconds($assoc['arrival_time']) : null;
        $departure = $assoc['departure_time'] !== '' ? timeToSeconds($assoc['departure_time']) : $arrival;
        if ($arrival === null) {
            $arrival = $departure;
        }

        $buffer[] = [
            'seqOrder' => (int)$assoc['stop_sequence'],
            'stopId' => (int)$assoc['stop_id'],
            'arrival' => $arrival,
            'departure' => $departure,
        ];
    }
    if ($currentTripId !== null) {
        yield $currentTripId => $buffer;
    }
    fclose($stream);
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
 * hash de la secuencia de paradas, primera salida) — el hash es lo que evita
 * fusionar un desvío real (p.ej. el de Elantxobe en verano) con la versión
 * normal del "mismo" trip_number — y se hace OR de las máscaras semanales de
 * cada variante en un calendario sintético. Solo el viaje representante de
 * cada grupo inserta sus passing_times; el resto solo aporta su máscara.
 */
function processStopTimes(PDO $pdo, ZipArchive $zip, array $trips, array $routes, array $calendars, array &$totals): void
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

        $trip = $trips[$tripId] ?? null;
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
        $firstDeparture = $buffer[0]['departure'] ?? $buffer[0]['arrival'];

        $signatures[$tripId] = [
            'routeId' => $trip['routeId'],
            'tripNumber' => $trip['tripNumber'],
            'headsign' => $trip['headsign'],
            'patternKey' => $patternKey,
            'firstDeparture' => $firstDeparture,
            'weekdayMask' => $calendars[$trip['serviceId']]['weekdayMask'] ?? 0,
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
    // (Metro Bilbao) — en ese caso se agrupa solo por (línea, patrón); el
    // trip_id de Metro Bilbao ya es único por variante de calendario (no se
    // repite como en Bizkaibus), así que trip_number no aporta nada como
    // salvaguarda extra ahí y solo haría que cada variante quedara en su
    // propio grupo sin fusionar.
    $departureClusterGapSeconds = 90;

    $byRoutePattern = [];
    foreach ($signatures as $tripId => $sig) {
        $key = $sig['routeId'] . '|' . ($sig['tripNumber'] ?? '') . '|' . $sig['patternKey'];
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
    // reutiliza 'PRUEBA' — es un service_id real (no un dummy vacío tipo
    // NeTEx) que ServiceJourney.php excluye siempre, y su weekday_mask es
    // 127 (todos los días). Sin esta exclusión, cualquier grupo fusionado
    // que también saliera "todos los días" heredaba calendar_id 'PRUEBA' y
    // desaparecía de toda consulta — pasaba en 2.110 de 6.966 viajes (30%)
    // antes de este fix.
    $maskToCalendarId = [];
    foreach ($calendars as $calId => $cal) {
        if ($calId === 'PRUEBA') {
            continue;
        }
        if (!isset($maskToCalendarId[$cal['weekdayMask']])) {
            $maskToCalendarId[$cal['weekdayMask']] = $calId;
        }
    }
    $syntheticCalendars = [];
    $representatives = [];
    foreach ($groups as $group) {
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
        $group = $representatives[$tripId] ?? null;
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
        // Bizkaibus (ver loadTrips()) — sin él, dos service_journeys distintos
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
    $pdo->exec('
        CREATE TABLE meta (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ');
}

/**
 * feed_version de feed_info.txt es la fecha en que se generó este GTFS
 * concreto (p.ej. "20260716") — se lee de aquí en vez de fijarla a mano en
 * config.php, que es justo el tipo de dato desfasado que dejó el build con
 * NeTEx mal durante año y medio sin que nadie lo notara.
 *
 * @return array<string, string>
 */
function loadFeedInfo(ZipArchive $zip): array
{
    // feed_info.txt es opcional en GTFS — Metro Bilbao no lo publica (Bizkaibus sí).
    if ($zip->locateName('feed_info.txt') === false) {
        return [];
    }
    foreach (readCsv($zip, 'feed_info.txt') as $row) {
        return [
            'feed_version' => $row['feed_version'] ?? '',
            'feed_start_date' => $row['feed_start_date'] ?? '',
            'feed_end_date' => $row['feed_end_date'] ?? '',
        ];
    }
    return [];
}

function insertMeta(PDO $pdo, array $feedInfo): void
{
    $publishedDate = isset($feedInfo['feed_version']) && preg_match('/^\d{8}$/', $feedInfo['feed_version'])
        ? gtfsDateToIso($feedInfo['feed_version'])
        : date('Y-m-d');

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
}

function insertStops(PDO $pdo, array $stops): void
{
    $stmt = $pdo->prepare('INSERT INTO stops (id, name, name_normalized, area, area_normalized, lat, lon) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($stops as $id => $stop) {
        $area = $stop['area'] ?? '';
        $stmt->execute([$id, $stop['name'], normalize($stop['name']), $area, normalize($area), $stop['lat'], $stop['lon']]);
    }
}

function insertCalendars(PDO $pdo, array $calendars): void
{
    $stmt = $pdo->prepare('INSERT INTO service_calendars (id, from_date, to_date, weekday_mask) VALUES (?, ?, ?, ?)');
    foreach ($calendars as $id => $cal) {
        $stmt->execute([$id, $cal['from'], $cal['to'], $cal['weekdayMask']]);
    }
}

main(array_slice($argv, 1));
