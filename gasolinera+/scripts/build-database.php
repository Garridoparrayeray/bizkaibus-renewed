<?php
/**
 * Genera/actualiza data/gasolinera.sqlite a partir del feed oficial de
 * precios de carburantes del Ministerio para la Transición Ecológica
 * (geoportalgasolineras.es, sin API key).
 *
 * Uso:
 *   php scripts/build-database.php --mode=daily
 *   php scripts/build-database.php --mode=backfill --days=30
 *   php scripts/build-database.php --mode=daily --source=<ruta-o-url> --output=<ruta>
 *
 * A diferencia del ETL de bizkaibus+ (que regenera su .sqlite entero cada
 * vez), este ETL es incremental: el histórico de precios se acumula día a
 * día y nunca se borra. --mode=daily descarga el snapshot de hoy y lo
 * añade; --mode=backfill rellena fechas pasadas usando el endpoint
 * histórico del feed, pensado como paso manual de un solo uso antes del
 * primer lanzamiento (ver README), nunca parte del cron diario.
 *
 * El feed devuelve las coordenadas y los precios como strings con COMA
 * decimal (ej. "39,211417"), herencia del formato numérico español; hay que
 * convertirlos a punto decimal antes de castear a float en toda esta clase.
 */

declare(strict_types=1);

// json_decode() de los ~12MB del feed necesita bastante más que el límite
// por defecto de PHP (128M): el árbol PHP resultante pesa varias veces el
// tamaño del JSON de origen. Verificado con un fallo real de memoria en
// --mode=backfill sin este ajuste.
ini_set('memory_limit', '1024M');

require __DIR__ . '/../api/Core/Http.php';

use Core\Http;

const SOURCE_CURRENT = 'https://sedeaplicaciones.minetur.gob.es/ServiciosRESTCarburantes/PreciosCarburantes/EstacionesTerrestres/';
const SOURCE_HIST_PREFIX = 'https://sedeaplicaciones.minetur.gob.es/ServiciosRESTCarburantes/PreciosCarburantes/EstacionesTerrestresHist/';
const DEFAULT_OUTPUT = __DIR__ . '/../data/gasolinera.sqlite';

/**
 * Mapeo explícito y fijo del nombre de campo del feed ("Precio <Carburante>")
 * a una clave normalizada estable. Fijo en código (no heurístico) a
 * propósito: si el feed añade un carburante nuevo que no está aquí,
 * loadFuelPrices() debe avisar en vez de perderlo en silencio (ver abajo).
 *
 * Las seis primeras claves son las que el catálogo de filtros de la API
 * ofrece como selector en la UI (cobertura real >= 9% de las estaciones,
 * verificado contra el feed real); el resto se guarda igual en BD (no se
 * pierde ningún dato) pero no se expone como filtro por baja cobertura.
 */
const FUEL_FIELD_MAP = [
    'Precio Gasoleo A' => 'gasoleo_a',
    'Precio Gasolina 95 E5' => 'gasolina_95_e5',
    'Precio Gasoleo Premium' => 'gasoleo_premium',
    'Precio Gasolina 98 E5' => 'gasolina_98_e5',
    'Precio Adblue' => 'adblue',
    'Precio Gases licuados del petróleo' => 'glp',
    'Precio Gasoleo B' => 'gasoleo_b',
    'Precio Gasolina 95 E5 Premium' => 'gasolina_95_e5_premium',
    'Precio Gasolina 95 E10' => 'gasolina_95_e10',
    'Precio Gasolina 95 E25' => 'gasolina_95_e25',
    'Precio Gasolina 95 E85' => 'gasolina_95_e85',
    'Precio Gasolina 98 E10' => 'gasolina_98_e10',
    'Precio Gas Natural Comprimido' => 'gnc',
    'Precio Gas Natural Licuado' => 'gnl',
    'Precio Hidrogeno' => 'hidrogeno',
    'Precio Biodiesel' => 'biodiesel',
    'Precio Bioetanol' => 'bioetanol',
    'Precio Diésel Renovable' => 'diesel_renovable',
    'Precio Gasolina Renovable' => 'gasolina_renovable',
    'Precio Amoniaco' => 'amoniaco',
    'Precio Metanol' => 'metanol',
    'Precio Biogas Natural Comprimido' => 'biogas_natural_comprimido',
    'Precio Biogas Natural Licuado' => 'biogas_natural_licuado',
];

/** Valor string de un campo del feed, o cadena vacía si no viene informado. */
function fieldStr(array $row, string $key): string
{
    if (isset($row[$key])) {
        return (string)$row[$key];
    }
    return '';
}

function main(array $argv): void
{
    $opts = parseArgs($argv);
    $mode = $opts['mode'];
    if ($mode !== 'daily' && $mode !== 'backfill') {
        fwrite(STDERR, "Error: --mode debe ser 'daily' o 'backfill'\n");
        exit(1);
    }

    $output = $opts['output'];
    if ($output === null) {
        $output = DEFAULT_OUTPUT;
    }

    echo "== Gasolinera+ database build (mode=$mode) ==\n";
    echo "Output: $output\n";

    $pdo = openDatabase($output);
    ensureSchema($pdo);

    if ($mode === 'daily') {
        runDaily($pdo, $opts['source']);
    } else {
        $days = $opts['days'];
        if ($days === null || $days < 1) {
            fwrite(STDERR, "Error: --mode=backfill requiere --days=N (N >= 1)\n");
            exit(1);
        }
        runBackfill($pdo, $days);
    }

    $size = filesize($output);
    printf("\nDatabase written to: %s\nFile size: %.1f MB\n", $output, $size / 1024 / 1024);
}

/** @return array{mode:string, source:?string, output:?string, days:?int} */
function parseArgs(array $argv): array
{
    $opts = ['mode' => 'daily', 'source' => null, 'output' => null, 'days' => null];
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--mode=')) {
            $opts['mode'] = substr($arg, 7);
        } elseif (str_starts_with($arg, '--source=')) {
            $opts['source'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--output=')) {
            $opts['output'] = substr($arg, 9);
        } elseif (str_starts_with($arg, '--days=')) {
            $opts['days'] = (int)substr($arg, 7);
        }
    }
    return $opts;
}

function openDatabase(string $path): \PDO
{
    $pdo = new \PDO('sqlite:' . $path);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    return $pdo;
}

/** Crea el esquema si no existe. Nunca hace DROP: el histórico es acumulativo. */
function ensureSchema(\PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS stations (
            ideess TEXT PRIMARY KEY,
            rotulo TEXT NOT NULL,
            direccion TEXT NOT NULL,
            localidad TEXT NOT NULL,
            municipio TEXT NOT NULL,
            municipio_id TEXT NOT NULL,
            provincia TEXT NOT NULL,
            provincia_id TEXT NOT NULL,
            ccaa_id TEXT NOT NULL,
            cp TEXT NOT NULL,
            margen TEXT NOT NULL DEFAULT \'\',
            tipo_venta TEXT NOT NULL DEFAULT \'\',
            horario_raw TEXT NOT NULL DEFAULT \'\',
            is_24h INTEGER NOT NULL DEFAULT 0,
            lat REAL NOT NULL,
            lon REAL NOT NULL,
            municipio_normalizado TEXT NOT NULL,
            direccion_normalizada TEXT NOT NULL,
            rotulo_normalizado TEXT NOT NULL,
            last_seen_date TEXT NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_lat ON stations (lat)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_lon ON stations (lon)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_municipio ON stations (municipio_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_municipio_norm ON stations (municipio_normalizado)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_stations_rotulo_norm ON stations (rotulo_normalizado)');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS current_prices (
            ideess TEXT NOT NULL,
            carburante TEXT NOT NULL,
            precio REAL NOT NULL,
            fecha TEXT NOT NULL,
            PRIMARY KEY (ideess, carburante)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_current_prices_carburante ON current_prices (carburante, precio)');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS price_history (
            ideess TEXT NOT NULL,
            fecha TEXT NOT NULL,
            carburante TEXT NOT NULL,
            precio REAL NOT NULL,
            PRIMARY KEY (ideess, fecha, carburante)
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_station_fuel ON price_history (ideess, carburante, fecha DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_zone_lookup ON price_history (fecha, carburante)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
}

/**
 * Convierte "39,211417" (formato numérico español del feed) a 39.211417.
 * Devuelve null para el string vacío, que el feed usa quando un carburante
 * no está disponible en esa estación.
 */
function parseSpanishDecimal(string $value): ?float
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    return (float)str_replace(',', '.', $value);
}

/** "dd/mm/yyyy HH:ii:ss" (raíz "Fecha" del feed) -> "YYYY-MM-DD". */
function feedDateToIso(string $fecha): string
{
    $datePart = explode(' ', $fecha)[0];
    [$d, $m, $y] = explode('/', $datePart);
    return sprintf('%04d-%02d-%02d', (int)$y, (int)$m, (int)$d);
}

/** "dd-mm-yyyy", formato que exige el endpoint EstacionesTerrestresHist. */
function isoDateToHistParam(string $isoDate): string
{
    [$y, $m, $d] = explode('-', $isoDate);
    return sprintf('%s-%s-%s', $d, $m, $y);
}

/**
 * Descarga y decodifica un snapshot del feed (actual o histórico). El feed
 * responde con Content-Type: text/html aunque el cuerpo es JSON real, así
 * que se parsea igual sin fijarse en la cabecera.
 *
 * @return array{fecha:string, estaciones:array<int,array<string,string>>}
 */
function fetchSnapshot(string $url): array
{
    $body = Http::get($url, 60);
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['ListaEESSPrecio']) || !is_array($data['ListaEESSPrecio'])) {
        fwrite(STDERR, "Error: respuesta inesperada de $url (no es el JSON de precios esperado)\n");
        exit(1);
    }
    $fecha = '';
    if (isset($data['Fecha'])) {
        $fecha = $data['Fecha'];
    }
    return ['fecha' => $fecha, 'estaciones' => $data['ListaEESSPrecio']];
}

/**
 * Separa los ~20 campos "Precio <Carburante>" de una fila del feed en un
 * mapa slug->precio, solo para los que tienen valor. Avisa (sin abortar) si
 * aparece un campo "Precio ..." que no está en FUEL_FIELD_MAP, para que un
 * carburante nuevo del feed no se pierda en silencio.
 *
 * @return array<string,float>
 */
function loadFuelPrices(array $row): array
{
    $prices = [];
    foreach ($row as $field => $value) {
        if (!str_starts_with($field, 'Precio ')) {
            continue;
        }
        $price = parseSpanishDecimal((string)$value);
        if ($price === null) {
            continue;
        }
        if (!isset(FUEL_FIELD_MAP[$field])) {
            fwrite(STDERR, "Aviso: campo de carburante no mapeado en FUEL_FIELD_MAP: \"$field\" (ignorado)\n");
            continue;
        }
        $prices[FUEL_FIELD_MAP[$field]] = $price;
    }
    return $prices;
}

/** true si horario_raw indica apertura 24 horas, heurística simple sobre el texto libre del feed. */
function detectIs24h(string $horario): bool
{
    $normalized = mb_strtoupper(trim($horario), 'UTF-8');
    return str_contains($normalized, '24H') || str_contains($normalized, '24 H') || $normalized === 'L-D: 00:00-24:00';
}

/**
 * Procesa el snapshot de HOY: upsert de stations, sustitución completa de
 * current_prices, e inserción idempotente en price_history. Si $source es
 * null usa el feed oficial en vivo; si se pasa, permite apuntar a un fichero
 * local para pruebas sin golpear el servicio real en cada ejecución.
 */
function runDaily(\PDO $pdo, ?string $source): void
{
    $url = $source;
    if ($url === null) {
        $url = SOURCE_CURRENT;
    }

    echo "Descargando snapshot actual...\n";
    $snapshot = fetchLocalOrRemote($url);
    $fechaIso = feedDateToIso($snapshot['fecha']);
    echo "Fecha del snapshot: $fechaIso (" . count($snapshot['estaciones']) . " estaciones)\n";

    applySnapshot($pdo, $snapshot['estaciones'], $fechaIso, true);

    $stmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute(['last_snapshot_date', $fechaIso]);
    $stmt->execute(['source_updated_at', $snapshot['fecha']]);
}

/**
 * Rellena price_history con los últimos $days días anteriores a hoy,
 * usando el endpoint histórico del feed. Nunca toca current_prices (esa
 * tabla solo la actualiza runDaily con el snapshot de HOY). Pensado como
 * paso manual de un solo uso antes del primer lanzamiento, no como parte
 * del cron diario recurrente.
 */
function runBackfill(\PDO $pdo, int $days): void
{
    $today = new \DateTime('now', new \DateTimeZone('Europe/Madrid'));
    $lastCovered = '';

    for ($i = $days; $i >= 1; $i--) {
        $date = (clone $today)->modify("-$i day");
        $isoDate = $date->format('Y-m-d');
        $histParam = isoDateToHistParam($isoDate);
        $url = SOURCE_HIST_PREFIX . $histParam;

        echo "Backfill $isoDate ($i de $days)...\n";
        try {
            $snapshot = fetchSnapshot($url);
        } catch (\Throwable $e) {
            fwrite(STDERR, "Aviso: no se pudo descargar $isoDate ($url): {$e->getMessage()}. Se omite este día.\n");
            continue;
        }

        // El histórico también puede traer un valor distinto de $isoDate si
        // el operador no publicó datos exactos ese día; se usa siempre la
        // fecha real devuelta por el feed, nunca la que pedimos, para no
        // desalinear price_history con lo que el operador dice que es.
        $realIso = feedDateToIso($snapshot['fecha']);
        applySnapshot($pdo, $snapshot['estaciones'], $realIso, false);
        $lastCovered = $realIso;

        // Pausa breve entre descargas de ~12MB para no saturar el endpoint
        // del Ministerio con peticiones seguidas.
        usleep(300000);
    }

    if ($lastCovered !== '') {
        $stmt = $pdo->prepare('INSERT INTO meta (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute(['backfill_completed_through', $lastCovered]);
    }
}

/**
 * Aplica un snapshot (de hoy o de un día pasado) a la base de datos.
 * $updateCurrentAndStations controla si además de price_history se
 * actualiza stations/current_prices: solo debe ser true para el snapshot de
 * HOY (runDaily). El backfill de fechas pasadas solo aporta histórico, y
 * usa INSERT OR IGNORE en stations para no pisar el estado actual real con
 * datos antiguos si una estación cambió de nombre/rótulo entretanto.
 */
function applySnapshot(\PDO $pdo, array $estaciones, string $fechaIso, bool $updateCurrentAndStations): void
{
    $pdo->beginTransaction();

    if ($updateCurrentAndStations) {
        $upsertStation = $pdo->prepare('
            INSERT INTO stations (
                ideess, rotulo, direccion, localidad, municipio, municipio_id,
                provincia, provincia_id, ccaa_id, cp, margen, tipo_venta,
                horario_raw, is_24h, lat, lon,
                municipio_normalizado, direccion_normalizada, rotulo_normalizado,
                last_seen_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(ideess) DO UPDATE SET
                rotulo = excluded.rotulo,
                direccion = excluded.direccion,
                localidad = excluded.localidad,
                municipio = excluded.municipio,
                municipio_id = excluded.municipio_id,
                provincia = excluded.provincia,
                provincia_id = excluded.provincia_id,
                ccaa_id = excluded.ccaa_id,
                cp = excluded.cp,
                margen = excluded.margen,
                tipo_venta = excluded.tipo_venta,
                horario_raw = excluded.horario_raw,
                is_24h = excluded.is_24h,
                lat = excluded.lat,
                lon = excluded.lon,
                municipio_normalizado = excluded.municipio_normalizado,
                direccion_normalizada = excluded.direccion_normalizada,
                rotulo_normalizado = excluded.rotulo_normalizado,
                last_seen_date = excluded.last_seen_date
        ');
    } else {
        $insertStationIfMissing = $pdo->prepare('
            INSERT OR IGNORE INTO stations (
                ideess, rotulo, direccion, localidad, municipio, municipio_id,
                provincia, provincia_id, ccaa_id, cp, margen, tipo_venta,
                horario_raw, is_24h, lat, lon,
                municipio_normalizado, direccion_normalizada, rotulo_normalizado,
                last_seen_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
    }

    $deleteCurrentForStation = null;
    $insertCurrentPrice = null;
    if ($updateCurrentAndStations) {
        $deleteCurrentForStation = $pdo->prepare('DELETE FROM current_prices WHERE ideess = ?');
        $insertCurrentPrice = $pdo->prepare('INSERT INTO current_prices (ideess, carburante, precio, fecha) VALUES (?, ?, ?, ?)');
    }

    $insertHistory = $pdo->prepare('INSERT OR IGNORE INTO price_history (ideess, fecha, carburante, precio) VALUES (?, ?, ?, ?)');

    $count = 0;
    foreach ($estaciones as $row) {
        $ideess = trim(fieldStr($row, 'IDEESS'));
        if ($ideess === '') {
            continue;
        }

        $lat = parseSpanishDecimal(fieldStr($row, 'Latitud'));
        $lon = parseSpanishDecimal(fieldStr($row, 'Longitud (WGS84)'));
        if ($lat === null || $lon === null) {
            continue;
        }

        $rotulo = trim(fieldStr($row, 'Rótulo'));
        $direccion = trim(fieldStr($row, 'Dirección'));
        $localidad = trim(fieldStr($row, 'Localidad'));
        $municipio = trim(fieldStr($row, 'Municipio'));
        $horario = trim(fieldStr($row, 'Horario'));

        $is24h = 0;
        if (detectIs24h($horario)) {
            $is24h = 1;
        }

        if ($updateCurrentAndStations) {
            $upsertStation->execute([
                $ideess,
                $rotulo,
                $direccion,
                $localidad,
                $municipio,
                fieldStr($row, 'IDMunicipio'),
                trim(fieldStr($row, 'Provincia')),
                fieldStr($row, 'IDProvincia'),
                fieldStr($row, 'IDCCAA'),
                fieldStr($row, 'C.P.'),
                fieldStr($row, 'Margen'),
                fieldStr($row, 'Tipo Venta'),
                $horario,
                $is24h,
                $lat,
                $lon,
                \Models\Search::normalize($municipio),
                \Models\Search::normalize($direccion),
                \Models\Search::normalize($rotulo),
                $fechaIso,
            ]);
        } else {
            $insertStationIfMissing->execute([
                $ideess,
                $rotulo,
                $direccion,
                $localidad,
                $municipio,
                fieldStr($row, 'IDMunicipio'),
                trim(fieldStr($row, 'Provincia')),
                fieldStr($row, 'IDProvincia'),
                fieldStr($row, 'IDCCAA'),
                fieldStr($row, 'C.P.'),
                fieldStr($row, 'Margen'),
                fieldStr($row, 'Tipo Venta'),
                $horario,
                $is24h,
                $lat,
                $lon,
                \Models\Search::normalize($municipio),
                \Models\Search::normalize($direccion),
                \Models\Search::normalize($rotulo),
                $fechaIso,
            ]);
        }

        $prices = loadFuelPrices($row);

        if ($updateCurrentAndStations) {
            $deleteCurrentForStation->execute([$ideess]);
        }

        foreach ($prices as $slug => $precio) {
            if ($updateCurrentAndStations) {
                $insertCurrentPrice->execute([$ideess, $slug, $precio, $fechaIso]);
            }
            $insertHistory->execute([$ideess, $fechaIso, $slug, $precio]);
        }

        $count++;
        if ($count % 2000 === 0) {
            echo "  procesadas $count estaciones\n";
        }
    }

    $pdo->commit();
    echo "  $count estaciones procesadas para $fechaIso\n";
}

/** Descarga de una URL remota, o lee de disco si $source apunta a un fichero local existente (para pruebas). */
function fetchLocalOrRemote(string $source): array
{
    if (is_file($source)) {
        $body = file_get_contents($source);
        $data = json_decode((string)$body, true);
        if (!is_array($data) || !isset($data['ListaEESSPrecio'])) {
            fwrite(STDERR, "Error: $source no contiene el JSON de precios esperado\n");
            exit(1);
        }
        $fecha = '';
        if (isset($data['Fecha'])) {
            $fecha = $data['Fecha'];
        }
        return ['fecha' => $fecha, 'estaciones' => $data['ListaEESSPrecio']];
    }
    return fetchSnapshot($source);
}

require __DIR__ . '/../api/Models/Search.php';

main(array_slice($argv, 1));
