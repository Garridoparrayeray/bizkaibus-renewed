<?php

namespace Controllers;

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Models\Station;
use Services\PlaceGeocoder;

class StationsController
{
    private const VALID_FUELS = [
        'gasoleo_a', 'gasolina_95_e5', 'gasoleo_premium',
        'gasolina_98_e5', 'adblue', 'glp',
    ];

    /**
     * Combustibles alternativos/renovables: cobertura real muy por debajo de
     * VALID_FUELS (del 14% del diésel renovable al 0.0% del hidrógeno, 1-2
     * estaciones en todo el país), así que no entran en la gráfica
     * comparativa por carburante (saldrían líneas casi vacías), pero sí son
     * filtrables/ordenables como cualquier otro: cuanto más raro el
     * carburante, más falta le hace a quien lo busca poder filtrar por él.
     */
    private const ALTERNATIVE_FUELS = [
        'diesel_renovable', 'gasolina_renovable', 'biodiesel', 'bioetanol',
        'gnc', 'gnl', 'biogas_natural_comprimido', 'biogas_natural_licuado', 'hidrogeno',
    ];

    /**
     * Gasolineras dentro de un radio circular alrededor de (lat, lon),
     * ordenadas por precio o distancia, ruta /stations/near.
     */
    public function near(Request $request): void
    {
        $lat = $request->query('lat');
        $lon = $request->query('lon');
        if ($lat === null || $lon === null) {
            Response::error('lat y lon son obligatorios', 422);
            return;
        }

        $config = Config::current();
        $radius = $request->queryInt('radius', 5);
        if ($radius === null || $radius <= 0) {
            $radius = 5;
        }
        if ($radius > $config['nearby_max_radius_km']) {
            $radius = $config['nearby_max_radius_km'];
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        $sort = $this->normalizeSort($request->query('sort'));
        [$open24h, $openNow] = $this->parseOpenParam($request->query('open'));
        [$offset, $limit] = $this->parsePageParams($request);

        $model = new Station(Database::connection());
        $page = $model->near(
            (float)$lat,
            (float)$lon,
            (float)$radius,
            $sort,
            $fuel,
            $open24h,
            $openNow,
            (int)$config['stale_station_days'],
            $offset,
            $limit
        );

        Response::json([
            'stations' => $page['items'],
            'total' => $page['total'],
            'hasMore' => ($offset + count($page['items'])) < $page['total'],
            'attribution' => $config['attribution'],
        ]);
    }

    /** Buscador de texto (municipio, dirección o marca), alternativa al radio, ruta /stations/search. */
    public function search(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        if (mb_strlen($q) < 2) {
            Response::json(['stations' => []]);
            return;
        }

        $config = Config::current();
        $lat = $request->query('lat');
        $lon = $request->query('lon');
        $latFloat = null;
        if ($lat !== null) {
            $latFloat = (float)$lat;
        }
        $lonFloat = null;
        if ($lon !== null) {
            $lonFloat = (float)$lon;
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        $sort = $this->normalizeSort($request->query('sort'));
        [$open24h, $openNow] = $this->parseOpenParam($request->query('open'));
        [$offset, $limit] = $this->parsePageParams($request);

        $model = new Station(Database::connection());

        // Si la búsqueda coincide con un municipio real, ancla lat/lon a SU
        // centroide en vez de a la ubicación del usuario: buscar
        // "Amorebieta" tiene que dar distancias a Amorebieta, no a donde
        // esté el usuario en ese momento.
        $place = null;
        if ($model->looksLikePlaceQuery($q)) {
            $place = PlaceGeocoder::resolve($q);
            if ($place !== null) {
                $latFloat = $place['lat'];
                $lonFloat = $place['lon'];
            }
        }

        if ($latFloat === null && $sort === 'distance') {
            $sort = 'price';
        }

        $geocodedFrom = null;

        $page = $model->search(
            $q,
            $latFloat,
            $lonFloat,
            $sort,
            $fuel,
            $open24h,
            $openNow,
            (int)$config['stale_station_days'],
            $offset,
            $limit
        );

        if ($page['total'] === 0 && $offset === 0) {
            $place = $place ?? PlaceGeocoder::resolve($q);
            if ($place !== null) {
                $page = $model->near(
                    $place['lat'],
                    $place['lon'],
                    20.0,
                    $sort,
                    $fuel,
                    $open24h,
                    $openNow,
                    (int)$config['stale_station_days'],
                    0,
                    $limit
                );
                $geocodedFrom = $q;
            }
        }

        Response::json([
            'stations' => $page['items'],
            'total' => $page['total'],
            'hasMore' => ($offset + count($page['items'])) < $page['total'],
            'geocodedFrom' => $geocodedFrom,
            'attribution' => $config['attribution'],
        ]);
    }

    /**
     * Gasolineras dentro de un rectángulo de coordenadas (la vista actual
     * del mapa), sin límite de resultados, ruta /stations/bbox.
     */
    public function bbox(Request $request): void
    {
        $north = $request->query('north');
        $south = $request->query('south');
        $east = $request->query('east');
        $west = $request->query('west');
        if ($north === null || $south === null || $east === null || $west === null) {
            Response::error('north, south, east y west son obligatorios', 422);
            return;
        }

        $fuel = $this->normalizeFuel($request->query('fuel'));
        [$open24h, ] = $this->parseOpenParam($request->query('open'));

        $model = new Station(Database::connection());
        $results = $model->withinBounds((float)$north, (float)$south, (float)$east, (float)$west, $fuel, $open24h);

        Response::json(['stations' => $results]);
    }

    /** Ficha completa de una estación, ruta /stations/{ideess}. */
    public function show(Request $request, array $params): void
    {
        $model = new Station(Database::connection());
        $station = $model->find($params['ideess']);
        if ($station === null) {
            Response::error('Estación no encontrada', 404);
            return;
        }
        $station['attribution'] = Config::current()['attribution'];
        Response::json($station);
    }

    /**
     * Serie temporal de precio de un carburante en una estación, para la
     * gráfica de evolución, ruta /stations/{ideess}/history. group=month
     * agrega por mes (media del carburante ese mes) en vez de dato diario,
     * para ver tendencia en periodos largos sin un punto por día. from/to
     * (YYYY-MM-DD) acotan el rango explícitamente; si no se pasan, cae al
     * comportamiento anterior (últimos 7 días).
     */
    public function history(Request $request, array $params): void
    {
        $fuel = $request->query('fuel', 'gasoleo_a');
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = $group === 'month' ? 730 : 90;
        [$from, $to] = $this->parseDateRange($request, 7, $maxDays);

        $pdo = Database::connection();
        if ($group === 'month') {
            $stmt = $pdo->prepare('
                SELECT strftime(\'%Y-%m\', fecha) AS periodo, ROUND(AVG(precio), 4) AS precio
                FROM price_history
                WHERE ideess = ? AND carburante = ? AND fecha BETWEEN ? AND ?
                GROUP BY periodo
                ORDER BY periodo ASC
            ');
        } else {
            $stmt = $pdo->prepare('
                SELECT fecha AS periodo, precio
                FROM price_history
                WHERE ideess = ? AND carburante = ? AND fecha BETWEEN ? AND ?
                ORDER BY fecha ASC
            ');
        }
        $stmt->execute([$params['ideess'], $fuel, $from, $to]);
        $rows = $stmt->fetchAll();

        $series = array_map(fn($r) => ['fecha' => $r['periodo'], 'precio' => (float)$r['precio']], $rows);
        Response::json(['ideess' => $params['ideess'], 'carburante' => $fuel, 'group' => $group, 'from' => $from, 'to' => $to, 'serie' => $series]);
    }

    /**
     * Precio medio nacional de un carburante día a día (para una gráfica
     * global, no de una estación concreta), ruta /stats/national. Misma
     * fuente que la gráfica de una estación (`price_history`), solo que
     * agregada por fecha sobre todas las estaciones en vez de filtrada por
     * `ideess`. Incluye el titular de hoy (media + número de estaciones que
     * la componen) para no tener que hacer una segunda petición solo para
     * ese dato.
     */
    public function nationalStats(Request $request): void
    {
        $fuel = $this->normalizeFuel($request->query('fuel'));
        if ($fuel === null) {
            $fuel = 'gasoleo_a';
        }
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = $group === 'month' ? 730 : 90;
        [$from, $to] = $this->parseDateRange($request, 14, $maxDays);

        $pdo = Database::connection();
        $stmt = $this->nationalSeriesStatement($pdo, $group);
        $stmt->execute([$fuel, $from, $to]);
        $rows = $stmt->fetchAll();

        $series = array_map(fn($r) => [
            'fecha' => $r['periodo'],
            'media' => (float)$r['media'],
            'estaciones' => (int)$r['estaciones'],
        ], $rows);

        $today = null;
        if (!empty($series)) {
            $today = end($series);
        }

        Response::json(['carburante' => $fuel, 'group' => $group, 'from' => $from, 'to' => $to, 'hoy' => $today, 'serie' => $series]);
    }

    /**
     * Igual que nationalStats() pero con varios carburantes a la vez (una
     * serie por carburante), para el gráfico comparativo entre carburantes,
     * ruta /stats/by-fuel. Una sola consulta agrupando por (periodo,
     * carburante) en vez de una consulta por carburante.
     */
    public function statsByFuel(Request $request): void
    {
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = $group === 'month' ? 730 : 90;
        [$from, $to] = $this->parseDateRange($request, 30, $maxDays);

        $placeholders = implode(',', array_fill(0, count(self::VALID_FUELS), '?'));
        $periodoExpr = $group === 'month' ? 'strftime(\'%Y-%m\', fecha)' : 'fecha';
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT $periodoExpr AS periodo, carburante, ROUND(AVG(precio), 4) AS media
            FROM price_history
            WHERE carburante IN ($placeholders) AND fecha BETWEEN ? AND ?
            GROUP BY periodo, carburante
            ORDER BY periodo ASC
        ");
        $stmt->execute([...self::VALID_FUELS, $from, $to]);
        $rows = $stmt->fetchAll();

        $series = [];
        foreach (self::VALID_FUELS as $slug) {
            $series[$slug] = [];
        }
        foreach ($rows as $row) {
            $series[$row['carburante']][] = ['fecha' => $row['periodo'], 'media' => (float)$row['media']];
        }

        Response::json(['group' => $group, 'from' => $from, 'to' => $to, 'series' => $series]);
    }

    /**
     * Precio medio de hoy por provincia, ruta /stats/by-province. A
     * diferencia de las series temporales, esto sale de current_prices
     * (snapshot de hoy), no de price_history, así que no depende de cuánto
     * histórico haya acumulado todavía.
     */
    public function statsByProvince(Request $request): void
    {
        $fuel = $this->normalizeFuel($request->query('fuel'));
        if ($fuel === null) {
            $fuel = 'gasoleo_a';
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT s.provincia AS provincia, ROUND(AVG(cp.precio), 4) AS media, COUNT(*) AS estaciones
            FROM current_prices cp
            JOIN stations s ON s.ideess = cp.ideess
            WHERE cp.carburante = ? AND s.provincia IS NOT NULL AND s.provincia != \'\'
            GROUP BY s.provincia
            ORDER BY media ASC
        ');
        $stmt->execute([$fuel]);
        $rows = $stmt->fetchAll();

        $provincias = array_map(fn($r) => [
            'provincia' => $r['provincia'],
            'media' => (float)$r['media'],
            'estaciones' => (int)$r['estaciones'],
        ], $rows);

        Response::json(['carburante' => $fuel, 'provincias' => $provincias]);
    }

    /**
     * Histograma de precios de hoy (cuántas estaciones caen en cada franja
     * de precio), ruta /stats/price-distribution. El ancho de franja se
     * calcula a partir del rango real min/max del carburante en vez de ser
     * fijo, para que tenga sentido tanto en carburantes baratos (adblue)
     * como caros.
     */
    public function priceDistribution(Request $request): void
    {
        $fuel = $this->normalizeFuel($request->query('fuel'));
        if ($fuel === null) {
            $fuel = 'gasoleo_a';
        }

        $pdo = Database::connection();
        $rangeStmt = $pdo->prepare('SELECT MIN(precio) AS minimo, MAX(precio) AS maximo, COUNT(*) AS total FROM current_prices WHERE carburante = ?');
        $rangeStmt->execute([$fuel]);
        $range = $rangeStmt->fetch();
        if ($range === false || (int)$range['total'] === 0 || $range['minimo'] === null) {
            Response::json(['carburante' => $fuel, 'buckets' => []]);
            return;
        }

        $minimo = (float)$range['minimo'];
        $maximo = (float)$range['maximo'];
        $bucketWidth = 0.02;
        if ($maximo > $minimo) {
            $bucketCount = 14;
            $bucketWidth = round((($maximo - $minimo) / $bucketCount) * 100) / 100;
            if ($bucketWidth < 0.01) {
                $bucketWidth = 0.01;
            }
        }

        $stmt = $pdo->prepare('SELECT precio FROM current_prices WHERE carburante = ?');
        $stmt->execute([$fuel]);

        $buckets = [];
        while (($row = $stmt->fetch()) !== false) {
            $precio = (float)$row['precio'];
            $index = (int)floor(($precio - $minimo) / $bucketWidth);
            if (!isset($buckets[$index])) {
                $buckets[$index] = 0;
            }
            $buckets[$index]++;
        }
        ksort($buckets);

        $out = [];
        foreach ($buckets as $index => $count) {
            $desde = round($minimo + $index * $bucketWidth, 3);
            $hasta = round($desde + $bucketWidth, 3);
            $out[] = ['desde' => $desde, 'hasta' => $hasta, 'estaciones' => $count];
        }

        Response::json(['carburante' => $fuel, 'buckets' => $out]);
    }

    public function zoneComparison(Request $request, array $params): void
    {
        $fuel = $request->query('fuel', 'gasoleo_a');
        $model = new Station(Database::connection());
        $comparison = $model->zoneComparison($params['ideess'], $fuel);
        if ($comparison === null) {
            Response::error('Sin datos suficientes para comparar', 404);
            return;
        }
        Response::json($comparison);
    }

    private function normalizeFuel(?string $fuel): ?string
    {
        if ($fuel === null || $fuel === '') {
            return null;
        }
        if (!in_array($fuel, self::VALID_FUELS, true) && !in_array($fuel, self::ALTERNATIVE_FUELS, true)) {
            return null;
        }
        return $fuel;
    }

    private function normalizeGroup(?string $group): string
    {
        if ($group === 'month') {
            return 'month';
        }
        return 'day';
    }

    /**
     * Rango explícito from/to (YYYY-MM-DD) si el cliente los manda y son
     * válidos; si no, cae al comportamiento anterior (últimos $defaultDays
     * días desde hoy). En ambos casos se acota a $maxDays de amplitud y a
     * no pasarse de hoy, para no dejar que un rango disparatado dispare una
     * consulta sobre todo price_history.
     *
     * @return array{0:string,1:string} [from, to]
     */
    private function parseDateRange(Request $request, int $defaultDays, int $maxDays): array
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $validFrom = $from !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1;
        $validTo = $to !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1;

        if (!$validFrom || !$validTo) {
            $to = date('Y-m-d');
            $from = date('Y-m-d', strtotime("-$defaultDays days"));
            return [$from, $to];
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $today = date('Y-m-d');
        if ($to > $today) {
            $to = $today;
        }
        $earliestAllowed = date('Y-m-d', strtotime("-$maxDays days"));
        if ($from < $earliestAllowed) {
            $from = $earliestAllowed;
        }

        return [$from, $to];
    }

    private function nationalSeriesStatement(\PDO $pdo, string $group): \PDOStatement
    {
        if ($group === 'month') {
            return $pdo->prepare('
                SELECT strftime(\'%Y-%m\', fecha) AS periodo, ROUND(AVG(precio), 4) AS media, COUNT(DISTINCT ideess) AS estaciones
                FROM price_history
                WHERE carburante = ? AND fecha BETWEEN ? AND ?
                GROUP BY periodo
                ORDER BY periodo ASC
            ');
        }
        return $pdo->prepare('
            SELECT fecha AS periodo, ROUND(AVG(precio), 4) AS media, COUNT(DISTINCT ideess) AS estaciones
            FROM price_history
            WHERE carburante = ? AND fecha BETWEEN ? AND ?
            GROUP BY periodo
            ORDER BY periodo ASC
        ');
    }

    private function normalizeSort(?string $sort): string
    {
        if ($sort === 'distance') {
            return 'distance';
        }
        return 'price';
    }

    /** @return array{0:bool,1:bool} [open24h, openNow] */
    private function parseOpenParam(?string $open): array
    {
        if ($open === '24h') {
            return [true, false];
        }
        if ($open === 'now') {
            return [false, true];
        }
        return [false, false];
    }

    /** @return array{0:int,1:int} [offset, limit] */
    private function parsePageParams(Request $request): array
    {
        $offset = $request->queryInt('offset', 0);
        if ($offset === null || $offset < 0) {
            $offset = 0;
        }
        $limit = $request->queryInt('limit', 30);
        if ($limit === null || $limit <= 0) {
            $limit = 30;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        return [$offset, $limit];
    }
}
