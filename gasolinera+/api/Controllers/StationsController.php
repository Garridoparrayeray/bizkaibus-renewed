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

    
    private const ALTERNATIVE_FUELS = [
        'diesel_renovable', 'gasolina_renovable', 'biodiesel', 'bioetanol',
        'gnc', 'gnl', 'biogas_natural_comprimido', 'biogas_natural_licuado', 'hidrogeno',
    ];

    
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

        $nearbyMergeRadiusKm = null;
        if ($place !== null) {
            $nearbyMergeRadiusKm = 10.0;
        }

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
            $limit,
            $nearbyMergeRadiusKm
        );

        if ($page['total'] === 0 && $offset === 0) {
            if ($place === null) {
                $place = PlaceGeocoder::resolve($q);
            }
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

    
    public function suggestPlaces(Request $request): void
    {
        $q = trim((string)$request->query('q', ''));
        if (mb_strlen($q) < 2) {
            Response::json(['places' => []]);
            return;
        }

        $model = new Station(Database::connection());
        $places = $model->suggestPlaces($q, 8);

        Response::json(['places' => $places]);
    }

    
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

    
    public function history(Request $request, array $params): void
    {
        $fuel = $request->query('fuel', 'gasoleo_a');
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = 90;
        if ($group === 'month') {
            $maxDays = 730;
        }
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

    
    public function nationalStats(Request $request): void
    {
        $fuel = $this->normalizeFuel($request->query('fuel'));
        if ($fuel === null) {
            $fuel = 'gasoleo_a';
        }
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = 90;
        if ($group === 'month') {
            $maxDays = 730;
        }
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

    
    public function statsByFuel(Request $request): void
    {
        $group = $this->normalizeGroup($request->query('group'));
        $maxDays = 90;
        if ($group === 'month') {
            $maxDays = 730;
        }
        [$from, $to] = $this->parseDateRange($request, 30, $maxDays);

        $placeholders = implode(',', array_fill(0, count(self::VALID_FUELS), '?'));
        $periodoExpr = 'fecha';
        if ($group === 'month') {
            $periodoExpr = 'strftime(\'%Y-%m\', fecha)';
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT $periodoExpr AS periodo, carburante, ROUND(AVG(media), 4) AS media
            FROM national_price_history
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
                SELECT strftime(\'%Y-%m\', fecha) AS periodo, ROUND(AVG(media), 4) AS media, MAX(estaciones) AS estaciones
                FROM national_price_history
                WHERE carburante = ? AND fecha BETWEEN ? AND ?
                GROUP BY periodo
                ORDER BY periodo ASC
            ');
        }
        return $pdo->prepare('
            SELECT fecha AS periodo, media, estaciones
            FROM national_price_history
            WHERE carburante = ? AND fecha BETWEEN ? AND ?
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
